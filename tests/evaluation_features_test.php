<?php

require_once __DIR__ . '/../app/evaluation_service.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual:   ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assertAlmostSame(float $expected, float $actual, string $message, float $delta = 0.01): void {
    if (abs($expected - $actual) > $delta) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . $expected . PHP_EOL);
        fwrite(STDERR, 'Actual:   ' . $actual . PHP_EOL);
        exit(1);
    }
}

$monthlyRows = [
    ['target_month' => '2026-04', 'total_score' => 80, 'manager_comment' => '安定'],
    ['target_month' => '2026-05', 'total_score' => 100, 'manager_comment' => '良い'],
    ['target_month' => '2026-06', 'total_score' => 90, 'manager_comment' => '改善'],
];

$summary = summarizeMonthlyScoreHistory($monthlyRows);
assertSameValue(3, $summary['month_count'], '月数集計が正しくありません。');
assertAlmostSame(270.0, $summary['total_score_sum'], '年間合計点が正しくありません。');
assertAlmostSame(90.0, $summary['total_score_avg'], '年間平均点が正しくありません。');
assertSameValue('2026-05', $summary['best_month'], '最高点の月が正しくありません。');
assertAlmostSame(100.0, $summary['best_score'], '最高点が正しくありません。');

$teamMembers = [
    ['employee_id' => 1, 'name' => 'A', 'total_score' => 120],
    ['employee_id' => 2, 'name' => 'B', 'total_score' => 110],
    ['employee_id' => 3, 'name' => 'C', 'total_score' => 70],
    ['employee_id' => 4, 'name' => 'D', 'total_score' => 50],
];

$best = buildTeamScenario($teamMembers, 2, 600, 8, 'best');
assertSameValue(['A', 'B'], array_column($best['members'], 'name'), '上位メンバー選抜が正しくありません。');
assertAlmostSame(260.87, $best['team_minutes'], '最良チームの時間計算が正しくありません。');
assertAlmostSame(4.35, $best['team_hours'], '最良チームの時間換算が正しくありません。');

$worst = buildTeamScenario($teamMembers, 2, 600, 8, 'worst');
assertSameValue(['D', 'C'], array_column($worst['members'], 'name'), '下位メンバー選抜が正しくありません。');
assertAlmostSame(500.0, $worst['team_minutes'], '最悪チームの時間計算が正しくありません。');
assertAlmostSame(8.33, $worst['team_hours'], '最悪チームの時間換算が正しくありません。');

echo "OK\n";
