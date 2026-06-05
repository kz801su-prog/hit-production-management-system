<?php
// =====================================================
// 人員シミュレーター
// 目的: 作業指示に対して「何人で何時間か」を試算する
// 接続テーブル: chair_type_process_standards, chair_types
// 権限: process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/chair_type_service.php';
require_once __DIR__ . '/../app/standard_time_service.php';

requireLogin();
requireRole('process_leader');
$pageTitle = '人員シミュレーター';

$chairTypeId  = getInt('chair_type_id');
$quantity     = max(1, getInt('quantity', 10));
$workerCount  = max(1, getInt('workers', 2));
$workHoursDay = max(1, (float)($_GET['work_hours'] ?? 8));

$result   = [];
$simTotal = 0;
$chairType = null;

if ($chairTypeId && $quantity > 0) {
    $chairType  = dbFetchOne("SELECT * FROM chair_types WHERE id = ?", [$chairTypeId]);
    $calcResult = calcStandardTimes($chairTypeId, $quantity);

    foreach ($calcResult as $pid => $calc) {
        $processTotal     = $calc['total_minutes'];
        $perWorkerMinutes = $processTotal / $workerCount;
        $days             = $perWorkerMinutes / ($workHoursDay * 60);
        $result[$pid] = array_merge($calc, [
            'per_worker_minutes' => round($perWorkerMinutes, 1),
            'days'               => round($days, 2),
        ]);
        $simTotal += $processTotal;
    }
}

$chartLabels = $result ? json_encode(array_column($result, 'process_name')) : '[]';
$chartValues = $result ? json_encode(array_map(fn($r) => $r['total_minutes'], $result)) : '[]';

$chairTypes = dbFetchAll(
    "SELECT ct.id, ct.chair_type_code, ct.chair_type_name FROM chair_types ct
     WHERE ct.is_active = 1 ORDER BY ct.chair_type_code"
);

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-people"></i> 人員シミュレーター</h2>
<p class="text-muted">椅子タイプ・数量・人数・稼働時間を設定して所要時間を試算します。</p>

<!-- 条件設定フォーム -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">椅子タイプ</label>
        <select name="chair_type_id" class="form-select" required>
          <option value="">― 選択 ―</option>
          <?php foreach ($chairTypes as $ct): ?>
          <option value="<?= $ct['id'] ?>" <?= $chairTypeId == $ct['id'] ? 'selected' : '' ?>>
            <?= h($ct['chair_type_code']) ?>
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

<div class="row g-3">
  <!-- 工程別試算表 -->
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
              <td><?= formatMinutes($r['total_minutes']) ?></td>
              <td><?= formatMinutes($r['per_worker_minutes']) ?></td>
              <td><?= $r['days'] ?>日</td>
            </tr>
          <?php endforeach; ?>
          <tr class="table-warning fw-bold">
            <td colspan="1" class="text-end">合計</td>
            <td><?= formatMinutes($simTotal) ?></td>
            <td><?= formatMinutes($simTotal / $workerCount) ?></td>
            <td><?= round($simTotal / $workerCount / ($workHoursDay * 60), 2) ?>日</td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- グラフ -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header">工程別時間比率</div>
      <div class="card-body">
        <canvas id="simChart"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- 人数変更シミュレーション -->
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
        <?php foreach ([1,2,3,4,5,6] as $w): ?>
          <tr class="<?= $w == $workerCount ? 'table-warning fw-bold' : '' ?>">
            <td><?= $w ?>人</td>
            <?php $wTotal = 0; foreach ($result as $r):
              $wMin  = round($r['total_minutes'] / $w, 1);
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
?>
