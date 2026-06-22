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

    // 社長入力の加算減算を適用
    $adjPoints = (float)(dbFetchOne(
        "SELECT COALESCE(SUM(points), 0) AS adj
         FROM eval_score_adjustments
         WHERE employee_id = ? AND target_month = ?",
        [$employeeId, $targetMonth]
    )['adj'] ?? 0);
    $totalWithAdj = round(max(0, min(150, $scores['total'] + $adjPoints)), 2);

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
            $totalWithAdj,
        ]
    );
    $scores['adjustment'] = $adjPoints;
    $scores['total']      = $totalWithAdj;

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
    // 上司がS/A/B/C/Dグレードを入力した場合はそちらを優先、未入力は不良数から算出
    $gradeMap  = ['S' => 100, 'A' => 80, 'B' => 60, 'C' => 40, 'D' => 20];
    $qualityPerLog = [];
    foreach ($logs as $log) {
        $grade = $log['quality_grade'] ?? null;
        if ($grade && isset($gradeMap[$grade])) {
            $qualityPerLog[] = $gradeMap[$grade];
        } elseif ((int)($log['completed_qty'] ?? 0) > 0) {
            $c = (int)$log['completed_qty'];
            $d = (int)($log['defect_qty'] ?? 0);
            $r = (int)($log['rework_qty'] ?? 0);
            $qualityPerLog[] = max(0, ($c - $d - $r * 0.5) / $c * 100);
        }
    }
    $qualityScore = !empty($qualityPerLog) ? array_sum($qualityPerLog) / count($qualityPerLog) : 0;

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
         ORDER BY ISNULL(s.total_score), s.total_score DESC, e.employee_code",
        [$targetMonth]
    );
}

/**
 * 個人カルテで使う社員一覧を返す
 */
function getEvaluationEmployees(): array {
    return dbFetchAll(
        "SELECT e.id, e.employee_code, e.name
         FROM employees e
         WHERE e.is_active = 1 AND e.employment_status = 'active'
         ORDER BY e.employee_code, e.name"
    );
}

/**
 * 個人カルテの対象社員情報を返す
 */
function getEvaluationEmployeeDetail(int $employeeId): array|false {
    return dbFetchOne(
        "SELECT e.*,
                d.dept_name,
                s.section_name,
                p.position_name
         FROM employees e
         LEFT JOIN departments d ON e.department_id = d.id
         LEFT JOIN sections s ON e.section_id = s.id
         LEFT JOIN positions p ON e.position_id = p.id
         WHERE e.id = ?",
        [$employeeId]
    );
}

/**
 * 個人カルテで選択可能な年一覧を返す
 */
function getEmployeeCarteYears(int $employeeId): array {
    $rows = dbFetchAll(
        "SELECT DISTINCT y FROM (
            SELECT CAST(LEFT(target_month, 4) AS UNSIGNED) AS y
            FROM monthly_worker_scores
            WHERE employee_id = ?
            UNION
            SELECT evaluation_year AS y
            FROM annual_employee_evaluations
            WHERE employee_id = ?
         ) years
         WHERE y IS NOT NULL
         ORDER BY y DESC",
        [$employeeId, $employeeId]
    );

    $years = array_map(static fn(array $row): int => (int)$row['y'], $rows);
    if (empty($years)) {
        $years[] = (int)date('Y');
    }
    return $years;
}

/**
 * 指定年の月次スコア履歴を返す
 */
function getEmployeeMonthlyScoreHistory(int $employeeId, int $year): array {
    return dbFetchAll(
        "SELECT target_month, efficiency_score, quality_score, stability_score,
                difficulty_score, improvement_score, total_score, manager_comment,
                updated_at
         FROM monthly_worker_scores
         WHERE employee_id = ?
           AND target_month BETWEEN ? AND ?
         ORDER BY target_month",
        [$employeeId, sprintf('%04d-01', $year), sprintf('%04d-12', $year)]
    );
}

/**
 * 指定社員の年度評価一覧を返す
 */
function getEmployeeAnnualEvaluations(int $employeeId): array {
    return dbFetchAll(
        "SELECT ae.*, ev.name AS evaluator_name
         FROM annual_employee_evaluations ae
         LEFT JOIN employees ev ON ae.evaluator_employee_id = ev.id
         WHERE ae.employee_id = ?
         ORDER BY ae.evaluation_year DESC",
        [$employeeId]
    );
}

/**
 * 月次履歴から年間サマリーを作る
 */
function summarizeMonthlyScoreHistory(array $monthlyRows): array {
    if (empty($monthlyRows)) {
        return [
            'month_count'      => 0,
            'total_score_sum'  => 0.0,
            'total_score_avg'  => 0.0,
            'best_month'       => null,
            'best_score'       => 0.0,
            'comment_count'    => 0,
        ];
    }

    $sum = 0.0;
    $bestMonth = null;
    $bestScore = null;
    $commentCount = 0;

    foreach ($monthlyRows as $row) {
        $score = (float)($row['total_score'] ?? 0);
        $sum += $score;
        if ($bestScore === null || $score > $bestScore) {
            $bestScore = $score;
            $bestMonth = $row['target_month'];
        }
        if (trim((string)($row['manager_comment'] ?? '')) !== '') {
            $commentCount++;
        }
    }

    return [
        'month_count'      => count($monthlyRows),
        'total_score_sum'  => round($sum, 2),
        'total_score_avg'  => round($sum / count($monthlyRows), 2),
        'best_month'       => $bestMonth,
        'best_score'       => round((float)$bestScore, 2),
        'comment_count'    => $commentCount,
    ];
}

/**
 * 個人カルテ表示に必要な情報をまとめて返す
 */
function getEmployeeCarteData(int $employeeId, int $year): array {
    $employee = getEvaluationEmployeeDetail($employeeId);
    $monthlyRows = getEmployeeMonthlyScoreHistory($employeeId, $year);
    $annualRows = getEmployeeAnnualEvaluations($employeeId);

    return [
        'employee' => $employee,
        'monthly_rows' => $monthlyRows,
        'monthly_summary' => summarizeMonthlyScoreHistory($monthlyRows),
        'annual_rows' => $annualRows,
    ];
}

/**
 * シミュレーター用の評価月候補を返す
 */
function getSimulationAvailableMonths(): array {
    $rows = dbFetchAll(
        "SELECT DISTINCT target_month
         FROM monthly_worker_scores
         ORDER BY target_month DESC"
    );
    return array_map(static fn(array $row): string => $row['target_month'], $rows);
}

/**
 * シミュレーター用のメンバー候補を返す
 */
function getSimulationTeamCandidates(string $targetMonth): array {
    return dbFetchAll(
        "SELECT e.id AS employee_id, e.employee_code, e.name,
                COALESCE(mws.total_score, 0) AS total_score,
                COALESCE(mws.manager_comment, '') AS manager_comment
         FROM employees e
         JOIN monthly_worker_scores mws
           ON mws.employee_id = e.id
          AND mws.target_month = ?
         WHERE e.is_active = 1
           AND e.employment_status = 'active'
         ORDER BY mws.total_score DESC, e.employee_code",
        [$targetMonth]
    );
}

/**
 * スコアを速度係数へ変換する
 */
function scoreToPerformanceFactor(float $score): float {
    return max(0.3, min(1.5, $score / 100));
}

/**
 * ベスト/ワースト構成の所要時間を算出する
 */
function buildTeamScenario(
    array $teamMembers,
    int $workerCount,
    float $baseTotalMinutes,
    float $workHoursDay,
    string $mode = 'best'
): array {
    if (empty($teamMembers) || $workerCount < 1) {
        return [
            'members' => [],
            'avg_score' => 0.0,
            'performance_factor' => 0.0,
            'team_minutes' => 0.0,
            'team_hours' => 0.0,
            'team_days' => 0.0,
        ];
    }

    usort($teamMembers, static function (array $a, array $b) use ($mode): int {
        $scoreA = (float)($a['total_score'] ?? 0);
        $scoreB = (float)($b['total_score'] ?? 0);
        if ($scoreA === $scoreB) {
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        }
        return $mode === 'worst'
            ? ($scoreA <=> $scoreB)
            : ($scoreB <=> $scoreA);
    });

    $selected = array_slice($teamMembers, 0, min($workerCount, count($teamMembers)));
    $selectedCount = count($selected);
    $avgScore = array_sum(array_map(
        static fn(array $row): float => (float)($row['total_score'] ?? 0),
        $selected
    )) / max(1, $selectedCount);
    $performanceFactor = scoreToPerformanceFactor($avgScore);
    $teamMinutes = $selectedCount > 0
        ? round($baseTotalMinutes / $selectedCount / $performanceFactor, 2)
        : 0.0;

    return [
        'members'             => $selected,
        'avg_score'           => round($avgScore, 2),
        'performance_factor'  => round($performanceFactor, 4),
        'team_minutes'        => $teamMinutes,
        'team_hours'          => round($teamMinutes / 60, 2),
        'team_days'           => round($teamMinutes / max(1, $workHoursDay * 60), 2),
    ];
}
