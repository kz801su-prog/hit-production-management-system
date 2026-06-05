<?php
// =====================================================
// 標準時間算出サービス
// 目的: 椅子タイプ・数量から工程別の標準時間を計算する
// 接続テーブル: chair_type_process_standards, chair_type_process_adjustments
// 呼び出し元: order_service.php（作業指示作成時）
//
// 計算式:
//   数量換算正味時間 = base_work_minutes ÷ base_quantity × 注文数量
//   差分反映後      = 数量換算 + Σ差分時間（add/subtract）
//   アローアンス率  = 差分後 × (1 + allowance_rate/100)
//   合計           = 段取り + アローアンス率後 + 固定アローアンス
// =====================================================

/**
 * 指定椅子タイプ・数量の全工程標準時間を計算して返す
 *
 * @param int $chairTypeId 椅子タイプID
 * @param int $quantity    製造数量
 * @return array [
 *   process_id => [
 *     'process_id', 'process_name', 'setup_minutes',
 *     'net_work_minutes', 'adjustment_minutes', 'allowance_minutes',
 *     'total_minutes', 'standard_workers', 'difficulty_level',
 *     'can_start_parallel', 'display_order', 'adjustments'
 *   ]
 * ]
 */
function calcStandardTimes(int $chairTypeId, int $quantity): array {
    // 工程標準マスターを取得
    $standards = dbFetchAll(
        "SELECT s.*, p.process_name, p.process_code
         FROM chair_type_process_standards s
         JOIN processes p ON s.process_id = p.id
         WHERE s.chair_type_id = ? AND s.is_active = 1
         ORDER BY s.display_order, p.display_order",
        [$chairTypeId]
    );

    if (empty($standards)) {
        return [];
    }

    // 差分調整マスターを取得
    $adjustments = dbFetchAll(
        "SELECT a.*, p.process_name, p.process_code
         FROM chair_type_process_adjustments a
         LEFT JOIN processes p ON a.process_id = p.id
         WHERE a.chair_type_id = ? AND a.is_active = 1",
        [$chairTypeId]
    );

    // 工程IDごとに差分をグループ化
    $adjByProcess = [];
    foreach ($adjustments as $adj) {
        $adjByProcess[$adj['process_id']][] = $adj;
    }

    $result = [];
    foreach ($standards as $std) {
        $pid = $std['process_id'];

        // 数量換算正味作業時間
        $baseQty     = max(1, (int)$std['base_quantity']);
        $netWork     = safeFloat($std['base_work_minutes']) / $baseQty * $quantity;

        // 差分の加算・減算
        $adjTotal    = 0.0;
        $adjDetails  = [];
        foreach ($adjByProcess[$pid] ?? [] as $adj) {
            $adjMin = calcAdjustmentMinutes($adj, $quantity);
            if ($adj['adjustment_type'] === 'subtract') {
                $adjTotal -= $adjMin;
            } elseif (in_array($adj['adjustment_type'], ['add', 'replace'])) {
                $adjTotal += $adjMin;
            }
            $adjDetails[] = $adj;
        }

        // アローアンス率適用
        $afterAdj      = max(0, $netWork + $adjTotal);
        $allowRate     = safeFloat($std['allowance_rate']);
        $afterRate     = $afterAdj * (1 + $allowRate / 100);
        $fixedAllow    = safeFloat($std['allowance_minutes']);
        $setup         = safeFloat($std['setup_minutes']);
        $totalMinutes  = $setup + $afterRate + $fixedAllow;

        $result[$pid] = [
            'process_id'          => $pid,
            'process_code'        => $std['process_code'],
            'process_name'        => $std['process_name'],
            'setup_minutes'       => $setup,
            'net_work_minutes'    => round($netWork, 2),
            'adjustment_minutes'  => round($adjTotal, 2),
            'allowance_rate'      => $allowRate,
            'allowance_minutes'   => round($afterRate - $afterAdj + $fixedAllow, 2),
            'total_minutes'       => round($totalMinutes, 2),
            'standard_workers'    => (int)$std['standard_workers'],
            'difficulty_level'    => (int)$std['difficulty_level'],
            'can_start_parallel'  => (bool)$std['can_start_parallel'],
            'display_order'       => (int)$std['display_order'],
            'adjustments'         => $adjDetails,
        ];
    }

    return $result;
}

/**
 * 1差分レコードの調整時間を計算する
 * applies_per（適用単位）に応じて計算方法を変える
 */
function calcAdjustmentMinutes(array $adj, int $quantity): float {
    $baseMin = safeFloat($adj['adjustment_minutes']);
    return match($adj['applies_per']) {
        'unit'  => $baseMin * $quantity,
        'order' => $baseMin,
        default => $baseMin,
    };
}

/**
 * 標準時間の計算結果を manufacturing_order_processes へ保存する
 * 作業指示作成時に呼び出す
 *
 * @param int   $orderId     作業指示ID
 * @param int   $chairTypeId 椅子タイプID
 * @param int   $quantity    製造数量
 * @param array $timings     ['process_id' => ['planned_start' => '...', 'planned_end' => '...']]
 */
function saveOrderProcessStandards(int $orderId, int $chairTypeId, int $quantity, array $timings = []): void {
    $calcResult = calcStandardTimes($chairTypeId, $quantity);

    foreach ($calcResult as $pid => $calc) {
        $snapshot = json_encode($calc, JSON_UNESCAPED_UNICODE);
        $ps       = $timings[$pid] ?? [];

        dbExecute(
            "INSERT INTO manufacturing_order_processes
                (manufacturing_order_id, process_id, process_sequence,
                 can_start_parallel,
                 planned_setup_minutes, planned_work_minutes,
                 planned_adjustment_minutes, planned_allowance_minutes, planned_total_minutes,
                 standard_snapshot, assigned_worker_count,
                 planned_start, planned_end)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                planned_total_minutes = VALUES(planned_total_minutes),
                standard_snapshot     = VALUES(standard_snapshot)",
            [
                $orderId,
                $pid,
                $calc['display_order'],
                $calc['can_start_parallel'] ? 1 : 0,
                $calc['setup_minutes'],
                $calc['net_work_minutes'],
                $calc['adjustment_minutes'],
                $calc['allowance_minutes'],
                $calc['total_minutes'],
                $snapshot,
                $calc['standard_workers'],
                $ps['planned_start'] ?? null,
                $ps['planned_end']   ?? null,
            ]
        );
    }
}
