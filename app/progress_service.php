<?php
// =====================================================
// 工程進捗サービス
// 目的: 作業開始・終了・遅延計算・進捗集計を担う
// 接続テーブル: manufacturing_order_processes, work_logs
// 呼び出し元: work_start.php, work_finish.php, progress_board.php
// =====================================================

/**
 * 作業を開始する（work_logsにINSERT＋工程状態をin_progressに更新）
 *
 * @param int $orderId    作業指示ID
 * @param int $processId  工程ID
 * @param int $employeeId 作業者社員ID
 * @return int 作成されたwork_log ID
 */
function startWork(int $orderId, int $processId, int $employeeId): int {
    // すでに開始済みの場合はエラー
    $existing = dbFetchOne(
        "SELECT id FROM work_logs
         WHERE manufacturing_order_id = ? AND process_id = ? AND employee_id = ? AND ended_at IS NULL",
        [$orderId, $processId, $employeeId]
    );
    if ($existing) {
        throw new RuntimeException('この工程はすでに作業中です。');
    }

    $logId = (int)dbExecute(
        "INSERT INTO work_logs (manufacturing_order_id, process_id, employee_id, started_at)
         VALUES (?, ?, ?, NOW())",
        [$orderId, $processId, $employeeId]
    );

    // 工程状態を更新
    dbExecute(
        "UPDATE manufacturing_order_processes
         SET status = 'in_progress', actual_start = COALESCE(actual_start, NOW())
         WHERE manufacturing_order_id = ? AND process_id = ?",
        [$orderId, $processId]
    );

    // 作業指示状態を in_progress に更新
    dbExecute(
        "UPDATE manufacturing_orders SET status = 'in_progress'
         WHERE id = ? AND status = 'planned'",
        [$orderId]
    );

    auditLog('start_work', 'work_logs', $logId, null, [
        'order_id' => $orderId, 'process_id' => $processId, 'employee_id' => $employeeId
    ]);

    return $logId;
}

/**
 * 作業を終了する
 *
 * @param int   $workLogId      work_log ID
 * @param array $data           ['completed_qty', 'defect_qty', 'rework_qty', 'break_minutes', 'memo']
 */
function finishWork(int $workLogId, array $data): void {
    $log = dbFetchOne("SELECT * FROM work_logs WHERE id = ?", [$workLogId]);
    if (!$log || $log['ended_at'] !== null) {
        throw new RuntimeException('作業ログが見つからないか、すでに終了しています。');
    }

    $breakMin   = (float)($data['break_minutes'] ?? 0);
    $startedAt  = strtotime($log['started_at']);
    $endedAt    = time();
    $actualMin  = max(0, ($endedAt - $startedAt) / 60 - $breakMin);

    dbExecute(
        "UPDATE work_logs SET
            ended_at = NOW(), break_minutes = ?, actual_minutes = ?,
            completed_qty = ?, defect_qty = ?, rework_qty = ?, memo = ?
         WHERE id = ?",
        [
            $breakMin, round($actualMin, 2),
            (int)($data['completed_qty'] ?? 0),
            (int)($data['defect_qty']    ?? 0),
            (int)($data['rework_qty']    ?? 0),
            $data['memo'] ?? '',
            $workLogId,
        ]
    );

    // 工程の実績時間・達成率を再集計
    recalcProcessActuals($log['manufacturing_order_id'], $log['process_id']);

    auditLog('finish_work', 'work_logs', $workLogId, $log, $data);
}

/**
 * 工程の実績時間・達成率・遅延を再計算してDB更新
 */
function recalcProcessActuals(int $orderId, int $processId): void {
    // work_logsの集計
    $agg = dbFetchOne(
        "SELECT SUM(actual_minutes) AS total_actual, MIN(started_at) AS first_start, MAX(ended_at) AS last_end,
                SUM(completed_qty) AS total_qty, SUM(defect_qty) AS total_defect,
                COUNT(CASE WHEN ended_at IS NULL THEN 1 END) AS open_count
         FROM work_logs
         WHERE manufacturing_order_id = ? AND process_id = ?",
        [$orderId, $processId]
    );

    $mop = dbFetchOne(
        "SELECT * FROM manufacturing_order_processes
         WHERE manufacturing_order_id = ? AND process_id = ?",
        [$orderId, $processId]
    );

    if (!$mop) return;

    $actualMin  = (float)($agg['total_actual'] ?? 0);
    $planned    = (float)$mop['planned_total_minutes'];
    $perfRate   = $planned > 0 ? round($planned / max(1, $actualMin) * 100, 2) : null;
    $delayMin   = $actualMin > 0 ? round($actualMin - $planned, 2) : 0;
    $isOpen     = (int)($agg['open_count'] ?? 0) > 0;

    $status = $isOpen ? 'in_progress' : 'completed';
    if ($status === 'completed' && $delayMin > DELAY_WARNING) {
        $delayStatus = $delayMin >= DELAY_CRITICAL ? 'critical' : 'delayed';
    } elseif ($status === 'completed' && $delayMin > 0) {
        $delayStatus = 'warning';
    } else {
        $delayStatus = 'normal';
    }

    dbExecute(
        "UPDATE manufacturing_order_processes SET
            actual_minutes = ?, actual_start = ?, actual_end = ?,
            status = ?, delay_minutes = ?, delay_status = ?, performance_rate = ?
         WHERE manufacturing_order_id = ? AND process_id = ?",
        [
            $actualMin,
            $agg['first_start'] ?? null,
            ($status === 'completed') ? $agg['last_end'] : null,
            $status,
            $delayMin,
            $delayStatus,
            $perfRate,
            $orderId, $processId,
        ]
    );

    // 全工程完了なら作業指示も完了に
    $pendingCount = dbFetchOne(
        "SELECT COUNT(*) AS cnt FROM manufacturing_order_processes
         WHERE manufacturing_order_id = ? AND status != 'completed'",
        [$orderId]
    )['cnt'] ?? 1;

    if ((int)$pendingCount === 0) {
        dbExecute(
            "UPDATE manufacturing_orders SET status = 'completed' WHERE id = ?",
            [$orderId]
        );
    }
}

/**
 * 進捗ボード用データ（全有効作業指示の工程マトリックス）を取得
 */
function getProgressBoardData(array $filters = []): array {
    $statusFilter = $filters['status'] ?? ['planned', 'in_progress'];

    $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
    $orders = dbFetchAll(
        "SELECT mo.*, ct.chair_type_code, ct.chair_type_name
         FROM manufacturing_orders mo
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         WHERE mo.status IN ({$placeholders})
         ORDER BY FIELD(mo.priority,'urgent','high','normal'), mo.due_date, mo.id",
        $statusFilter
    );

    $processes = dbFetchAll(
        "SELECT * FROM processes WHERE is_active = 1 ORDER BY display_order"
    );

    // 各作業指示の工程状態マップを構築
    $progressMap = [];
    if (!empty($orders)) {
        $orderIds    = array_column($orders, 'id');
        $pholders    = implode(',', array_fill(0, count($orderIds), '?'));
        $mopList = dbFetchAll(
            "SELECT * FROM manufacturing_order_processes
             WHERE manufacturing_order_id IN ({$pholders})",
            $orderIds
        );
        foreach ($mopList as $mop) {
            $progressMap[$mop['manufacturing_order_id']][$mop['process_id']] = $mop;
        }
    }

    return [
        'orders'      => $orders,
        'processes'   => $processes,
        'progress_map'=> $progressMap,
    ];
}

/**
 * 遅延中の工程一覧を取得
 */
function getDelayedProcesses(): array {
    return dbFetchAll(
        "SELECT mop.*, mo.order_no, mo.due_date, mo.priority,
                ct.chair_type_name, p.process_name,
                e.name AS worker_name
         FROM manufacturing_order_processes mop
         JOIN manufacturing_orders mo ON mop.manufacturing_order_id = mo.id
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         JOIN processes p ON mop.process_id = p.id
         LEFT JOIN work_logs wl ON wl.manufacturing_order_id = mop.manufacturing_order_id
             AND wl.process_id = mop.process_id AND wl.ended_at IS NULL
         LEFT JOIN employees e ON wl.employee_id = e.id
         WHERE mop.delay_status IN ('delayed','critical')
           AND mo.status NOT IN ('completed','cancelled')
         ORDER BY FIELD(mop.delay_status,'critical','delayed'), mop.delay_minutes DESC"
    );
}
