<?php
// =====================================================
// 工程バーコード印刷
// 目的: 各工程のprocess_codeをCode128バーコードで印刷
// バーコードスキャンステーションで工程スキャンに使用
// 接続テーブル: processes
// 権限: process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';

requireLogin();
requireRole('process_leader');

$processes  = dbFetchAll("SELECT * FROM processes WHERE is_active = 1 ORDER BY display_order, process_code");
$pageTitle  = '工程バーコード印刷';
require __DIR__ . '/parts/header.php';
?>

<div class="d-print-none mb-3 d-flex gap-2 align-items-center">
  <h2 class="mb-0"><i class="bi bi-upc"></i> 工程バーコード印刷</h2>
  <div class="ms-auto d-flex gap-2">
    <button class="btn btn-primary" onclick="window.print()">
      <i class="bi bi-printer"></i> 印刷
    </button>
    <a href="employee_barcodes.php" class="btn btn-outline-secondary">
      <i class="bi bi-person-badge"></i> 社員コードへ
    </a>
    <a href="barcode_station.php" class="btn btn-outline-dark">
      <i class="bi bi-upc-scan"></i> スキャンステーション
    </a>
  </div>
</div>

<div class="d-print-none alert alert-info py-2 mb-3">
  <i class="bi bi-info-circle"></i>
  <strong>使い方：</strong>
  バーコードスキャンステーションで <strong>工程バーコード</strong> として読み込みます。
  各工程カードを切り取り、作業エリアに掲示してください。
</div>

<?php if (empty($processes)): ?>
  <div class="alert alert-warning">工程が登録されていません。まず工程マスターに登録してください。</div>
<?php endif; ?>

<div class="row g-3" id="barcodeContainer">
<?php foreach ($processes as $p): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card border-dark barcode-card h-100">
      <div class="card-body p-2 text-center">
        <div class="fw-bold fs-6 mb-1"><?= h($p['process_name']) ?></div>
        <div class="barcode-svg-wrap">
          <svg class="barcode w-100" id="pbc-<?= $p['id'] ?>"></svg>
        </div>
        <div class="small text-muted mt-1 font-monospace"><?= h($p['process_code']) ?></div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
<?php foreach ($processes as $p): ?>
    JsBarcode('#pbc-<?= $p['id'] ?>', <?= json_encode($p['process_code']) ?>, {
        format: 'CODE128', lineColor: '#000', width: 2, height: 60,
        displayValue: false, margin: 0
    });
<?php endforeach; ?>
});
</script>

<style>
@media print {
  .d-print-none { display: none !important; }
  .barcode-card { break-inside: avoid; page-break-inside: avoid; }
  body, .container-fluid { padding: 0 !important; margin: 0 !important; }
  nav, footer { display: none !important; }
  .row { display: flex; flex-wrap: wrap; }
  .col-6 { width: 50%; }
}
.barcode-svg-wrap svg { height: 65px; }
</style>

<?php require __DIR__ . '/parts/footer.php'; ?>
