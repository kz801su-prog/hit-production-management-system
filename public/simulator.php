<?php
// =====================================================
// 人員シミュレーター
// 目的: 作業指示に対して「何人で何時間か」と評価上位/下位構成を試算する
// 接続テーブル: chair_type_process_standards, chair_types, monthly_worker_scores
// 権限: process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/chair_type_service.php';
require_once __DIR__ . '/../app/standard_time_service.php';
require_once __DIR__ . '/../app/evaluation_service.php';

requireLogin();
requireRole('process_leader');
$pageTitle = '人員シミュレーター';

$chairTypeId = getInt('chair_type_id');
$quantity = max(1, getInt('quantity', 10));
$workerCount = max(1, getInt('workers', 2));
$workHoursDay = max(1, (float)($_GET['work_hours'] ?? 8));

$availableScoreMonths = getSimulationAvailableMonths();
$scoreMonth = $_GET['score_month'] ?? ($availableScoreMonths[0] ?? date('Y-m'));

$result = [];
$simTotal = 0.0;
$chairType = null;
$teamCandidates = [];
$bestScenario = null;
$worstScenario = null;

if ($chairTypeId && $quantity > 0) {
    $chairType = dbFetchOne("SELECT * FROM chair_types WHERE id = ?", [$chairTypeId]);
    $calcResult = calcStandardTimes($chairTypeId, $quantity);

    foreach ($calcResult as $pid => $calc) {
        $processTotal = (float)$calc['total_minutes'];
        $perWorkerMinutes = $processTotal / $workerCount;
        $days = $perWorkerMinutes / ($workHoursDay * 60);
        $result[$pid] = array_merge($calc, [
            'per_worker_minutes' => round($perWorkerMinutes, 1),
            'days'               => round($days, 2),
        ]);
        $simTotal += $processTotal;
    }

    if ($scoreMonth) {
        $teamCandidates = getSimulationTeamCandidates($scoreMonth);
        if (!empty($teamCandidates)) {
            $bestScenario = buildTeamScenario($teamCandidates, $workerCount, $simTotal, $workHoursDay, 'best');
            $worstScenario = buildTeamScenario($teamCandidates, $workerCount, $simTotal, $workHoursDay, 'worst');
        }
    }
}

$chartLabels = $result ? json_encode(array_column($result, 'process_name'), JSON_UNESCAPED_UNICODE) : '[]';
$chartValues = $result ? json_encode(array_map(fn($r) => $r['total_minutes'], $result), JSON_UNESCAPED_UNICODE) : '[]';

$chairTypes = dbFetchAll(
    "SELECT ct.id, ct.chair_type_code, ct.chair_type_name
     FROM chair_types ct
     WHERE ct.is_active = 1
     ORDER BY ct.chair_type_code"
);

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-people"></i> 人員シミュレーター</h2>
<p class="text-muted">製品タイプ・数量・人数・稼働時間を設定し、標準時間と総合点ベースの最良/最悪チームを試算します。</p>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">製品タイプ</label>
        <select name="chair_type_id" class="form-select" required>
          <option value="">― 選択 ―</option>
          <?php foreach ($chairTypes as $ct): ?>
          <option value="<?= $ct['id'] ?>" <?= $chairTypeId == $ct['id'] ? 'selected' : '' ?>>
            <?= h($ct['chair_type_code']) ?> / <?= h($ct['chair_type_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">数量（本）</label>
        <input type="number" name="quantity" class="form-control" value="<?= $quantity ?>" min="1">
      </div>
      <div class="col-md-2">
        <label class="form-label">作業者数（人）</label>
        <input type="number" name="workers" class="form-control" value="<?= $workerCount ?>" min="1">
      </div>
      <div class="col-md-2">
        <label class="form-label">1日稼働時間（時間）</label>
        <input type="number" name="work_hours" class="form-control" value="<?= $workHoursDay ?>" min="1" step="0.5">
      </div>
      <div class="col-md-3">
        <label class="form-label">評価基準月</label>
        <select name="score_month" class="form-select">
          <?php if (empty($availableScoreMonths)): ?>
          <option value="">評価データなし</option>
          <?php else: ?>
            <?php foreach ($availableScoreMonths as $month): ?>
            <option value="<?= h($month) ?>" <?= $scoreMonth === $month ? 'selected' : '' ?>><?= h($month) ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-calculator"></i> 試算
        </button>
      </div>
    </form>
  </div>
</div>

<?php if (!empty($result) && $chairType): ?>
<div class="alert alert-info">
  <strong><?= h($chairType['chair_type_code']) ?></strong> <?= $quantity ?>本 ×
  <?= $workerCount ?>人 (<?= $workHoursDay ?>時間/日) の試算結果
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card border-0 bg-light h-100">
      <div class="card-body">
        <div class="small text-muted">標準総工数</div>
        <div class="fs-4 fw-bold"><?= formatMinutes($simTotal) ?></div>
        <div class="text-muted small">単純均等配分なら <?= round($simTotal / $workerCount / 60, 2) ?>時間</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 bg-success-subtle h-100">
      <div class="card-body">
        <div class="small text-muted">最速かつ最高品質の構成</div>
        <div class="fs-4 fw-bold"><?= $bestScenario ? round($bestScenario['team_hours'], 2) . '時間' : '―' ?></div>
        <div class="text-muted small">
          <?= $bestScenario ? '平均総合点 ' . round($bestScenario['avg_score'], 1) . ' / ' . round($bestScenario['team_days'], 2) . '日' : '評価データ不足' ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 bg-danger-subtle h-100">
      <div class="card-body">
        <div class="small text-muted">反対条件の構成</div>
        <div class="fs-4 fw-bold"><?= $worstScenario ? round($worstScenario['team_hours'], 2) . '時間' : '―' ?></div>
        <div class="text-muted small">
          <?= $worstScenario ? '平均総合点 ' . round($worstScenario['avg_score'], 1) . ' / ' . round($worstScenario['team_days'], 2) . '日' : '評価データ不足' ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($bestScenario && $worstScenario): ?>
<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card h-100 border-success">
      <div class="card-header bg-success-subtle">最速かつ最高品質のメンバー構成</div>
      <div class="card-body">
        <div class="mb-2 text-muted small">評価基準月: <?= h($scoreMonth) ?></div>
        <div class="mb-3">
          <?php foreach ($bestScenario['members'] as $member): ?>
          <span class="badge bg-success me-1 mb-1"><?= h($member['name']) ?> / <?= round((float)$member['total_score'], 1) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted">総合点平均を速度係数に換算し、標準総工数へ反映しています。</div>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100 border-danger">
      <div class="card-header bg-danger-subtle">反対条件のメンバー構成</div>
      <div class="card-body">
        <div class="mb-2 text-muted small">評価基準月: <?= h($scoreMonth) ?></div>
        <div class="mb-3">
          <?php foreach ($worstScenario['members'] as $member): ?>
          <span class="badge bg-danger me-1 mb-1"><?= h($member['name']) ?> / <?= round((float)$member['total_score'], 1) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted">総合点の低い順に同人数を自動選抜しています。</div>
      </div>
    </div>
  </div>
</div>
<?php elseif ($scoreMonth): ?>
<div class="alert alert-warning">評価基準月 <?= h($scoreMonth) ?> の月次評価データが不足しているため、最良/最悪チーム比較は表示できません。</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-md-7">
    <div class="card">
      <div class="card-header">工程別所要時間</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-dark">
            <tr>
              <th>工程</th>
              <th>標準合計時間</th>
              <th><?= $workerCount ?>人での工程時間</th>
              <th>日数換算</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($result as $r): ?>
            <tr>
              <td><?= h($r['process_name']) ?></td>
              <td><?= formatMinutes((float)$r['total_minutes']) ?></td>
              <td><?= formatMinutes((float)$r['per_worker_minutes']) ?></td>
              <td><?= $r['days'] ?>日</td>
            </tr>
          <?php endforeach; ?>
          <tr class="table-warning fw-bold">
            <td class="text-end">合計</td>
            <td><?= formatMinutes($simTotal) ?></td>
            <td><?= formatMinutes($simTotal / $workerCount) ?></td>
            <td><?= round($simTotal / $workerCount / ($workHoursDay * 60), 2) ?>日</td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header">工程別時間比率</div>
      <div class="card-body">
        <canvas id="simChart"></canvas>
      </div>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">人数別 所要日数シミュレーション</div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-bordered">
        <thead class="table-dark">
          <tr>
            <th>人数</th>
            <?php foreach ($result as $r): ?>
              <th class="text-center"><?= h($r['process_name']) ?></th>
            <?php endforeach; ?>
            <th class="text-center">合計</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ([1, 2, 3, 4, 5, 6] as $w): ?>
          <tr class="<?= $w == $workerCount ? 'table-warning fw-bold' : '' ?>">
            <td><?= $w ?>人</td>
            <?php $wTotal = 0.0; foreach ($result as $r):
              $wMin = round((float)$r['total_minutes'] / $w, 1);
              $wDays = round($wMin / ($workHoursDay * 60), 2);
              $wTotal += $wMin;
            ?>
              <td class="text-center"><small><?= $wDays ?>日</small></td>
            <?php endforeach; ?>
            <td class="text-center"><strong><?= round($wTotal / ($workHoursDay * 60), 2) ?>日</strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = <<<JS
(function(){
  const ctx = document.getElementById('simChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: {$chartLabels},
      datasets: [{ data: {$chartValues}, backgroundColor: [
        '#0d6efd','#198754','#ffc107','#dc3545','#0dcaf0','#6f42c1','#fd7e14','#20c997'
      ]}]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
  });
})();
JS;
require __DIR__ . '/parts/footer.php';
