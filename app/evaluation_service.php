<?php
// =====================================================
// 個人評価サービス
// 目的: 作業実績から月別評価スコアを自動計算する
// 接続テーブル: work_logs, manufacturing_order_processes,
//              monthly_worker_scores, improvement_actions
// 呼び出し元: evaluations.php
//
// 評価5軸:
//   効率点   35%: 標準時間÷実績時間×100の平均
//   品質点   30%: (完了数-不良数-手直し数)÷完了数×100
//   安定性点 15%: 達成率のバラつき（標準偏差逆算）
//   難易度点 10%: 担当した椅子タイプの難易度加重平均
//   改善貢献 10%: 改善アクションの件数・効果
// =====================================================

/**
 * 指定社員・月の評価スコアを計算してDBに保存する
 *
 * @param int    $employeeId  社員ID
 * @param string $targetMonth 対象月（YYYY-MM形式）
 * @return array 計算済みスコア配列
 */
function calcAndSaveMonthlyScore(int $employeeId, string $targetMonth): array {
    $dateFrom = $targetMonth . '-01';
    $dateTo   = date('Y-m-t', strtotime($dateFrom));

    // 当月の作業ログを取得
    $logs = dbFetchAll(
        "SELECT wl.*, mop.planned_total_minutes, mop.difficulty_level
         FROM work_logs wl
         JOIN manufacturing_order_processes mop
             ON mop.manufacturing_order_id = wl.manufacturing_order_id
             AND mop.process_id = wl.process_id
         WHERE wl.employee_id = ?
           AND DATE(wl.started_at) BETWEEN ? AND ?
           AND wl.ended_at IS NOT NULL",
        [$employeeId, $dateFrom, $dateTo]
    );

    $scores = calcScores($logs, $employeeId, $dateFrom, $dateTo);

    // DB保存（ON DUPLICATE KEY UPDATE）
    dbExecute(
        "INSERT INTO monthly_worker_scores
            (employee_id, target_month, efficiency_score, quality_score,
             stability_score, difficulty_score, improvement_score, total_score)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            efficiency_score   = VALUES(efficiency_score),
            quality_score      = VALUES(quality_score),
            stability_score    = VALUES(stability_score),
            difficulty_score   = VALUES(difficulty_score),
            improvement_score  = VALUES(improvement_score),
            total_score        = VALUES(total_score)",
        [
            $employeeId,
            $targetMonth,
            $scores['efficiency'],
            $scores['quality'],
            $scores['stability'],
            $scores['difficulty'],
            $scores['improvement'],
            $scores['total'],
        ]
    );

    return $scores;
}

/**
 * スコアを計算する（内部関数）
 */
function calcScores(array $logs, int $employeeId, string $dateFrom, string $dateTo): array {
    if (empty($logs)) {
        return ['efficiency' => 0, 'quality' => 0, 'stability' => 0,
                'difficulty' => 0, 'improvement' => 0, 'total' => 0, 'work_count' => 0];
    }

    // --- 効率点 ---
    $perfRates = [];
    foreach ($logs as $log) {
        $planned = (float)$log['planned_total_minutes'];
        $actual  = (float)$log['actual_minutes'];
        if ($planned > 0 && $actual > 0) {
            $perfRates[] = min(150, $planned / $actual * 100);
        }
    }
    $efficiencyScore = !empty($perfRates) ? array_sum($perfRates) / count($perfRates) : 0;

    // --- 品質点 ---
    $totalCompleted = array_sum(array_column($logs, 'completed_qty'));
    $totalDefect    = array_sum(array_column($logs, 'defect_qty'));
    $totalRework    = array_sum(array_column($logs, 'rework_qty'));
    $qualityScore   = $totalCompleted > 0
        ? max(0, ($totalCompleted - $totalDefect - $totalRework * 0.5) / $totalCompleted * 100)
        : 0;

    // --- 安定性点（バラつきが少ないほど高い）---
    $stabilityScore = 100;
    if (count($perfRates) > 1) {
        $avg     = array_sum($perfRates) / count($perfRates);
        $variance= array_sum(array_map(fn($r) => ($r - $avg) ** 2, $perfRates)) / count($perfRates);
        $stddev  = sqrt($variance);
        $stabilityScore = max(0, 100 - $stddev);
    }

    // --- 難易度点 ---
    $diffLevels = array_column($logs, 'difficulty_level');
    $diffScore  = !empty($diffLevels) ? array_sum($diffLevels) / count($diffLevels) * 20 : 50;

    // --- 改善貢献点 ---
    $improvCount = (int)(dbFetchOne(
        "SELECT COUNT(*) AS cnt FROM improvement_actions
         WHERE responsible_employee_id = ?
           AND created_at BETWEEN ? AND ?
           AND status IN ('done','doing')",
        [$employeeId, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']
    )['cnt'] ?? 0);
    $improvScore = min(100, $improvCount * 20);

    // --- 加重合計 ---
    $total = round(
        $efficiencyScore * 0.35 +
        $qualityScore    * 0.30 +
        $stabilityScore  * 0.15 +
        $diffScore       * 0.10 +
        $improvScore     * 0.10,
        2
    );

    return [
        'efficiency'  => round($efficiencyScore, 2),
        'quality'     => round($qualityScore, 2),
        'stability'   => round($stabilityScore, 2),
        'difficulty'  => round($diffScore, 2),
        'improvement' => round($improvScore, 2),
        'total'       => $total,
        'work_count'  => count($logs),
    ];
}

/**
 * 社員一覧と最新月スコアをまとめて取得
 */
function getEvaluationList(string $targetMonth): array {
    return dbFetchAll(
        "SELECT e.id, e.employee_code, e.name, e.name_kana,
                d.dept_name, p.position_name,
                s.efficiency_score, s.quality_score, s.stability_score,
                s.difficulty_score, s.improvement_score, s.total_score,
                s.manager_comment
         FROM employees e
         LEFT JOIN departments d ON e.department_id = d.id
         LEFT JOIN positions p ON e.position_id = p.id
         LEFT JOIN monthly_worker_scores s
             ON s.employee_id = e.id AND s.target_month = ?
         WHERE e.is_active = 1 AND e.employment_status = 'active'
         ORDER BY s.total_score DESC NULLS LAST, e.employee_code",
        [$targetMonth]
    );
}
