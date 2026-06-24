<?php
// =====================================================
// ガントチャート
// 目的: 時間軸で作業指示×工程の予定・実績を可視化
// 接続テーブル: manufacturing_orders, manufacturing_order_processes, processes
// JavaScript/CSS でガントを描画する
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';

requireLogin();
$pageTitle = 'ガントチャート';
$embed     = !empty($_GET['embed']);

$orderId   = getInt('order_id');
$dateFrom  = $_GET['date_from'] ?? date('Y-m-d');
$dateTo    = $_GET['date_to']   ?? date('Y-m-d', strtotime('+7 days'));

// 表示対象の作業指示を取得（期間にかかる納期のもの）
$sql = "SELECT mo.id, mo.order_no, mo.priority, ct.chair_type_name
        FROM manufacturing_orders mo
        JOIN chair_types ct ON mo.chair_type_id = ct.id
        WHERE mo.status IN ('planned','in_progress')
          AND (mo.due_date IS NULL OR mo.due_date >= ?)
          AND (mo.created_at <= ? OR mo.status = 'in_progress')";
$params = [$dateFrom, $dateTo . ' 23:59:59'];
if ($orderId) {
    $sql .= " AND mo.id = ?";
    $params[] = $orderId;
}
$sql .= " ORDER BY FIELD(mo.priority,'urgent','high','normal'), mo.due_date LIMIT 30";
$orders = dbFetchAll($sql, $params);

// 各作業指示の工程データを取得
$ganttData = [];
foreach ($orders as $o) {
    $processes = dbFetchAll(
        "SELECT mop.*, p.process_name, p.process_code
         FROM manufacturing_order_processes mop
         JOIN processes p ON mop.process_id = p.id
         WHERE mop.manufacturing_order_id = ?
         ORDER BY mop.process_sequence, p.display_order",
        [$o['id']]
    );
    $ganttData[] = [
        'order'     => $o,
        'processes' => $processes,
    ];
}
?>
<?php if ($embed): ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <link rel="manifest" href="<?= APP_URL ?>/manifest.webmanifest">
  <link rel="icon" href="<?= APP_URL ?>/assets/icons/pwa-icon.svg" type="image/svg+xml">
  <meta name="theme-color" content="#0d6efd">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="<?= h(APP_NAME) ?>">
  <title>ガントチャート</title>
  <style>body{overflow:hidden;background:#fff;}</style>
</head>
<body class="p-2">
<?php else: require __DIR__ . '/parts/header.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2><i class="bi bi-bar-chart-steps"></i> ガントチャート</h2>
  <div>
    <form method="get" class="d-flex gap-2">
      <?php if ($orderId): ?><input type="hidden" name="order_id" value="<?= $orderId ?>"><?php endif; ?>
      <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
      <span class="align-self-center">〜</span>
      <input type="date" name="date_to"   class="form-control form-control-sm" value="<?= h($dateTo) ?>">
      <button type="submit" class="btn btn-sm btn-primary">表示</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- 凡例 -->
<div class="mb-3 d-flex flex-wrap gap-2">
  <span><span class="gantt-bar" style="background:#0d6efd">&nbsp;&nbsp;&nbsp;&nbsp;</span> 予定</span>
  <span><span class="gantt-bar" style="background:#198754">&nbsp;&nbsp;&nbsp;&nbsp;</span> 完了</span>
  <span><span class="gantt-bar" style="background:#ffc107">&nbsp;&nbsp;&nbsp;&nbsp;</span> 作業中</span>
  <span><span class="gantt-bar" style="background:#dc3545">&nbsp;&nbsp;&nbsp;&nbsp;</span> 遅延</span>
</div>

<div id="ganttContainer" class="gantt-container">
  <!-- ガントチャートはJSで描画 -->
  <div class="text-muted text-center py-3" id="ganttLoading">チャートを描画中...</div>
</div>

<!-- ガントチャート用データをJSへ渡す -->
<?php
$jsData = json_encode([
    'dateFrom'  => $dateFrom,
    'dateTo'    => $dateTo,
    'ganttData' => $ganttData,
], JSON_UNESCAPED_UNICODE);
if ($embed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?= APP_URL ?>/sw.js');
  });
}
</script>
<script>
(function(){
  const raw = <?= $jsData ?>;
  if (typeof renderGantt === 'function') renderGantt('ganttContainer', raw);
})();
</script>
</body></html>
<?php else:
$extraJs = <<<JS
(function(){
  const raw = {$jsData};
  renderGantt('ganttContainer', raw);
})();
JS;
require __DIR__ . '/parts/footer.php';
endif;
?>
