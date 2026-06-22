<?php
// =====================================================
// 個人評価ページ
// 目的: 月別作業者スコアの確認・計算・コメント入力・個人カルテ表示
// 接続テーブル: monthly_worker_scores, annual_employee_evaluations, employees
// 権限: ログインユーザー
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/evaluation_service.php';

requireLogin();
$pageTitle = '個人評価';

$user = getCurrentUser();
$targetMonth = $_GET['month'] ?? date('Y-m');
$activeTab = $_GET['tab'] ?? 'monthly';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');
    $redirectMonth = postStr('month', $targetMonth);
    $redirectTab = postStr('tab', 'monthly');
    $redirectEmployeeId = postInt('redirect_employee_id', (int)($user['employee_id'] ?? 0));
    $redirectYear = postInt('redirect_year', (int)date('Y'));

    if ($postAction === 'calc_all' && isLeader()) {
        $employees = dbFetchAll(
            "SELECT id FROM employees WHERE is_active = 1 AND employment_status = 'active'"
        );
        foreach ($employees as $e) {
            calcAndSaveMonthlyScore($e['id'], $redirectMonth);
        }
        setFlash("{$redirectMonth} の全社員スコアを再計算しました。");
    }

    if ($postAction === 'save_comment' && isLeader()) {
        $empId = postInt('employee_id');
        $comment = postStr('comment');
        dbExecute(
            "UPDATE monthly_worker_scores SET manager_comment = ?
             WHERE employee_id = ? AND target_month = ?",
            [$comment, $empId, $redirectMonth]
        );
        setFlash('コメントを保存しました。');
    }

    header(
        'Location: ' . APP_URL . '/evaluations.php?month=' . urlencode($redirectMonth)
        . '&tab=' . urlencode($redirectTab)
        . '&employee_id=' . $redirectEmployeeId
        . '&year=' . $redirectYear
    );
    exit;
}

$evalList = getEvaluationList($targetMonth);
$employeeOptions = getEvaluationEmployees();
$employeeIds = array_map(static fn(array $row): int => (int)$row['id'], $employeeOptions);

$selectedEmployeeId = getInt('employee_id', (int)($user['employee_id'] ?? 0));
if (!in_array($selectedEmployeeId, $employeeIds, true)) {
    $selectedEmployeeId = $employeeIds[0] ?? 0;
}

$carteYears = $selectedEmployeeId ? getEmployeeCarteYears($selectedEmployeeId) : [(int)date('Y')];
$selectedYear = getInt('year', $carteYears[0] ?? (int)date('Y'));
if (!in_array($selectedYear, $carteYears, true)) {
    $selectedYear = $carteYears[0] ?? (int)date('Y');
}

$carteData = $selectedEmployeeId ? getEmployeeCarteData($selectedEmployeeId, $selectedYear) : null;
$monthlyRows = $carteData['monthly_rows'] ?? [];
$monthlySummary = $carteData['monthly_summary'] ?? [];
$annualRows = $carteData['annual_rows'] ?? [];
$carteEmployee = $carteData['employee'] ?? null;

$carteLabels = json_encode(array_map(
    static fn(array $row): string => $row['target_month'],
    $monthlyRows
), JSON_UNESCAPED_UNICODE);
$carteScores = json_encode(array_map(
    static fn(array $row): float => (float)($row['total_score'] ?? 0),
    $monthlyRows
), JSON_UNESCAPED_UNICODE);

require __DIR__ . '/parts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h2 class="mb-1"><i class="bi bi-star"></i> 個人評価</h2>
    <p class="text-muted mb-0 small">月次評価と、一人ひとりの年間カルテを確認できます。</p>
  </div>
  <div class="d-flex gap-2">
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="tab" value="monthly">
      <input type="hidden" name="employee_id" value="<?= $selectedEmployeeId ?>">
      <input type="hidden" name="year" value="<?= $selectedYear ?>">
      <input type="month" name="month" class="form-control form-control-sm" value="<?= h($targetMonth) ?>">
      <button type="submit" class="btn btn-sm btn-primary">表示</button>
    </form>
    <?php if (isLeader()): ?>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="calc_all">
      <input type="hidden" name="month" value="<?= h($targetMonth) ?>">
      <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
      <input type="hidden" name="redirect_employee_id" value="<?= $selectedEmployeeId ?>">
      <input type="hidden" name="redirect_year" value="<?= $selectedYear ?>">
      <button type="submit" class="btn btn-sm btn-warning"
              onclick="return confirm('全社員のスコアを再計算しますか？')">
        <i class="bi bi-arrow-clockwise"></i> 再計算
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'monthly' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/evaluations.php?tab=monthly&month=<?= urlencode($targetMonth) ?>&employee_id=<?= $selectedEmployeeId ?>&year=<?= $selectedYear ?>">
      <i class="bi bi-calendar3"></i> 月次評価
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'carte' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/evaluations.php?tab=carte&month=<?= urlencode($targetMonth) ?>&employee_id=<?= $selectedEmployeeId ?>&year=<?= $selectedYear ?>">
      <i class="bi bi-person-vcard"></i> 個人カルテ
    </a>
  </li>
</ul>

<?php if ($activeTab === 'carte'): ?>
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <input type="hidden" name="tab" value="carte">
      <input type="hidden" name="month" value="<?= h($targetMonth) ?>">
      <div class="col-md-4">
        <label class="form-label">対象社員</label>
        <select name="employee_id" class="form-select">
          <?php foreach ($employeeOptions as $emp): ?>
          <option value="<?= $emp['id'] ?>" <?= $selectedEmployeeId === (int)$emp['id'] ? 'selected' : '' ?>>
            <?= h($emp['employee_code']) ?> / <?= h($emp['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">対象年度</label>
        <select name="year" class="form-select">
          <?php foreach ($carteYears as $year): ?>
          <option value="<?= $year ?>" <?= $selectedYear === (int)$year ? 'selected' : '' ?>><?= $year ?>年度</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search"></i> カルテ表示
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($carteEmployee): ?>
<div class="card mb-3">
  <div class="card-body">
    <div class="row g-3 align-items-center">
      <div class="col-md-4">
        <div class="small text-muted">社員</div>
        <div class="fs-4 fw-bold"><?= h($carteEmployee['name']) ?></div>
        <div class="text-muted"><?= h($carteEmployee['employee_code']) ?></div>
      </div>
      <div class="col-md-4">
        <div class="small text-muted">所属</div>
        <div><?= h($carteEmployee['dept_name'] ?? '―') ?></div>
        <div class="text-muted small"><?= h($carteEmployee['section_name'] ?? '―') ?></div>
      </div>
      <div class="col-md-4">
        <div class="small text-muted">役職 / 入社日</div>
        <div><?= h($carteEmployee['position_name'] ?? '―') ?></div>
        <div class="text-muted small"><?= formatDate($carteEmployee['joined_date'] ?? null) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card border-0 bg-light h-100">
      <div class="card-body">
        <div class="small text-muted">月次評価回数</div>
        <div class="fs-3 fw-bold"><?= (int)($monthlySummary['month_count'] ?? 0) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card border-0 bg-light h-100">
      <div class="card-body">
        <div class="small text-muted">年間合計点</div>
        <div class="fs-3 fw-bold"><?= round((float)($monthlySummary['total_score_sum'] ?? 0), 1) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card border-0 bg-light h-100">
      <div class="card-body">
        <div class="small text-muted">年間平均点</div>
        <div class="fs-3 fw-bold"><?= round((float)($monthlySummary['total_score_avg'] ?? 0), 1) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card border-0 bg-light h-100">
      <div class="card-body">
        <div class="small text-muted">最高点 / コメント月数</div>
        <div class="fw-bold">
          <?= ($monthlySummary['best_month'] ?? null) ? h($monthlySummary['best_month']) . ' : ' . round((float)$monthlySummary['best_score'], 1) : '―' ?>
        </div>
        <div class="text-muted small">コメントあり <?= (int)($monthlySummary['comment_count'] ?? 0) ?>ヶ月</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">月次総合点の推移</div>
      <div class="card-body">
        <?php if (!empty($monthlyRows)): ?>
        <canvas id="carteScoreChart" height="140"></canvas>
        <?php else: ?>
        <div class="text-muted">この年度の月次評価データはありません。</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">年度別 個人能力評価</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-dark">
              <tr>
                <th>年度</th>
                <th class="text-center">点数</th>
                <th class="text-center">評価</th>
                <th>コメント</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($annualRows)): ?>
              <?php foreach ($annualRows as $row): ?>
              <tr class="<?= (int)$row['evaluation_year'] === $selectedYear ? 'table-warning' : '' ?>">
                <td><?= h($row['evaluation_year']) ?>年度</td>
                <td class="text-center"><?= $row['score'] !== null ? round((float)$row['score'], 1) : '―' ?></td>
                <td class="text-center"><?= h($row['grade'] ?: '―') ?></td>
                <td>
                  <div><?= h($row['evaluation_comment'] ?: '―') ?></div>
                  <div class="text-muted very-small small"><?= h($row['evaluator_name'] ?: '評価者未設定') ?></div>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="4" class="text-center text-muted py-3">年度評価は未登録です。</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">月次点数と上司コメント</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-dark">
          <tr>
            <th>月</th>
            <th class="text-center">効率</th>
            <th class="text-center">品質</th>
            <th class="text-center">安定</th>
            <th class="text-center">難易度</th>
            <th class="text-center">改善</th>
            <th class="text-center">総合点</th>
            <th>上司コメント</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($monthlyRows)): ?>
          <?php foreach ($monthlyRows as $row): ?>
          <tr>
            <td><?= h($row['target_month']) ?></td>
            <td class="text-center"><?= round((float)$row['efficiency_score'], 1) ?></td>
            <td class="text-center"><?= round((float)$row['quality_score'], 1) ?></td>
            <td class="text-center"><?= round((float)$row['stability_score'], 1) ?></td>
            <td class="text-center"><?= round((float)$row['difficulty_score'], 1) ?></td>
            <td class="text-center"><?= round((float)$row['improvement_score'], 1) ?></td>
            <td class="text-center"><span class="badge bg-primary"><?= round((float)$row['total_score'], 1) ?></span></td>
            <td><?= h($row['manager_comment'] ?: '―') ?></td>
          </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="8" class="text-center text-muted py-3">この年度の月次評価データはありません。</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-dark">
          <tr>
            <th>社員</th><th>部署</th><th>役職</th>
            <th class="text-center">効率<br><small>35%</small></th>
            <th class="text-center">品質<br><small>30%</small></th>
            <th class="text-center">安定<br><small>15%</small></th>
            <th class="text-center">難易度<br><small>10%</small></th>
            <th class="text-center">改善<br><small>10%</small></th>
            <th class="text-center">総合点</th>
            <th>コメント</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($evalList as $ev): ?>
          <?php
          $total = (float)($ev['total_score'] ?? 0);
          $totalClass = $total >= 90 ? 'success' : ($total >= 70 ? 'primary' : ($total >= 50 ? 'warning' : 'danger'));
          $isMe = (int)$ev['id'] === (int)($user['employee_id'] ?? 0);
          ?>
          <tr class="<?= $isMe ? 'table-light fw-bold' : '' ?>">
            <td>
              <?= h($ev['name']) ?>
              <?php if ($isMe): ?><span class="badge bg-info ms-1">自分</span><?php endif; ?>
            </td>
            <td><small><?= h($ev['dept_name'] ?? '―') ?></small></td>
            <td><small><?= h($ev['position_name'] ?? '―') ?></small></td>
            <td class="text-center"><?= $ev['efficiency_score'] ? round((float)$ev['efficiency_score'], 1) : '―' ?></td>
            <td class="text-center"><?= $ev['quality_score'] ? round((float)$ev['quality_score'], 1) : '―' ?></td>
            <td class="text-center"><?= $ev['stability_score'] ? round((float)$ev['stability_score'], 1) : '―' ?></td>
            <td class="text-center"><?= $ev['difficulty_score'] ? round((float)$ev['difficulty_score'], 1) : '―' ?></td>
            <td class="text-center"><?= $ev['improvement_score'] ? round((float)$ev['improvement_score'], 1) : '―' ?></td>
            <td class="text-center">
              <?php if ($total > 0): ?>
                <span class="badge bg-<?= $totalClass ?> fs-6"><?= round($total, 1) ?></span>
              <?php else: ?>
                <span class="text-muted">未計算</span>
              <?php endif; ?>
            </td>
            <td><small class="text-muted"><?= h(mb_substr($ev['manager_comment'] ?? '', 0, 30)) ?: '―' ?></small></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= APP_URL ?>/evaluations.php?tab=carte&month=<?= urlencode($targetMonth) ?>&employee_id=<?= $ev['id'] ?>&year=<?= $selectedYear ?>">
                  カルテ
                </a>
                <?php if (isLeader()): ?>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                        data-bs-target="#commentModal<?= $ev['id'] ?>">コメント</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>

          <?php if (isLeader()): ?>
          <div class="modal fade" id="commentModal<?= $ev['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title"><?= h($ev['name']) ?> へのコメント</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="save_comment">
                  <input type="hidden" name="month" value="<?= h($targetMonth) ?>">
                  <input type="hidden" name="tab" value="monthly">
                  <input type="hidden" name="redirect_employee_id" value="<?= $selectedEmployeeId ?>">
                  <input type="hidden" name="redirect_year" value="<?= $selectedYear ?>">
                  <input type="hidden" name="employee_id" value="<?= $ev['id'] ?>">
                  <div class="modal-body">
                    <textarea name="comment" class="form-control" rows="4"><?= h($ev['manager_comment'] ?? '') ?></textarea>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">評価基準</div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th>評価軸</th><th>比重</th><th>算出方法</th></tr>
          <tr><td>作業効率</td><td>35%</td><td>標準時間÷実績時間×100 の平均</td></tr>
          <tr><td>品質</td><td>30%</td><td>(完了数−不良数−手直し×0.5)÷完了数×100</td></tr>
          <tr><td>安定性</td><td>15%</td><td>達成率のバラつき（標準偏差）逆算</td></tr>
          <tr><td>難易度</td><td>10%</td><td>担当製品タイプの難易度加重平均</td></tr>
          <tr><td>改善貢献</td><td>10%</td><td>改善アクションの件数×20点（上限100）</td></tr>
        </table>
      </div>
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th>達成率</th><th>判定</th></tr>
          <tr><td>120%以上</td><td><span class="badge bg-success">かなり早い</span></td></tr>
          <tr><td>100〜119%</td><td><span class="badge bg-primary">早い</span></td></tr>
          <tr><td>90〜99%</td><td><span class="badge bg-info">標準</span></td></tr>
          <tr><td>75〜89%</td><td><span class="badge bg-warning">遅れ気味</span></td></tr>
          <tr><td>74%以下</td><td><span class="badge bg-danger">大幅遅れ</span></td></tr>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = '';
if ($activeTab === 'carte' && !empty($monthlyRows)) {
    $extraJs = <<<JS
(function(){
  const ctx = document.getElementById('carteScoreChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: {$carteLabels},
      datasets: [{
        label: '総合点',
        data: {$carteScores},
        borderColor: '#0d6efd',
        backgroundColor: 'rgba(13, 110, 253, 0.12)',
        fill: true,
        tension: 0.25
      }]
    },
    options: {
      scales: {
        y: {
          beginAtZero: true
        }
      },
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
})();
JS;
}
require __DIR__ . '/parts/footer.php';
