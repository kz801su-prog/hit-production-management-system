<?php
// =====================================================
// バーコード印刷ページ
// 目的: 作業指示番号をCode128バーコードで印刷
// 接続テーブル: manufacturing_orders, chair_types
// 権限: process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('process_leader');

$orderId = getInt('order_id');
$orders  = [];

if ($orderId) {
    // 単一指示
    $row = dbFetchOne(
        "SELECT mo.*, ct.chair_type_code, ct.chair_type_name
         FROM manufacturing_orders mo
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         WHERE mo.id = ?",
        [$orderId]
    );
    if ($row) $orders = [$row];
} else {
    // 未完了 + 取消 + 完了（2ヶ月以内）を表示
    $twoMonthsAgo = date('Y-m-d H:i:s', strtotime('-2 months'));
    $orders = dbFetchAll(
        "SELECT mo.*, ct.chair_type_code, ct.chair_type_name
         FROM manufacturing_orders mo
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         WHERE mo.status NOT IN ('completed','cancelled')
            OR (mo.status = 'cancelled')
            OR (mo.status = 'completed' AND mo.updated_at >= ?)
         ORDER BY mo.created_at DESC LIMIT 60",
        [$twoMonthsAgo]
    );
}

$pageTitle = 'バーコード印刷';
require __DIR__ . '/parts/header.php';
?>

<div class="d-print-none mb-3 d-flex gap-2 align-items-center">
  <h2 class="mb-0"><i class="bi bi-upc-scan"></i> バーコード印刷</h2>
  <button class="btn btn-primary ms-auto" onclick="window.print()">
    <i class="bi bi-printer"></i> 印刷
  </button>
  <a href="orders.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> 一覧へ
  </a>
</div>

<?php if (!$orders): ?>
  <div class="alert alert-warning">対象の作業指示が見つかりません。</div>
<?php endif; ?>

<div class="row g-3" id="barcodeContainer">
<?php foreach ($orders as $o):
    $isCancelled = ($o['status'] === 'cancelled');
    $isCompleted = ($o['status'] === 'completed');
    $cardClass   = $isCancelled ? 'border-danger' : ($isCompleted ? 'border-success' : 'border-dark');
?>
  <div class="col-md-6 col-lg-4">
    <div class="card <?= $cardClass ?> barcode-card">
      <div class="card-body p-2 text-center barcode-wrap">
        <div class="fw-bold small mb-1"><?= h($o['chair_type_code']) ?> — <?= h($o['chair_type_name']) ?></div>
        <?php if (!empty($o['customer_name']) || !empty($o['project_name'])): ?>
          <div class="small text-muted mb-1">
            <?= h($o['customer_name'] ?? '') ?>
            <?= (!empty($o['customer_name']) && !empty($o['project_name'])) ? ' / ' : '' ?>
            <?= h($o['project_name'] ?? '') ?>
          </div>
        <?php endif; ?>

        <div class="barcode-svg-wrap">
          <svg class="barcode" id="bc-<?= $o['id'] ?>"></svg>
          <?php if ($isCancelled): ?>
            <div class="bc-cancel-overlay"><span>取消</span></div>
          <?php elseif ($isCompleted): ?>
            <div class="bc-done-overlay"><span>終了</span></div>
          <?php endif; ?>
        </div>

        <div class="mt-1 small">
          <span class="fw-bold"><?= h($o['order_no']) ?></span>
          <?php if ($isCancelled): ?>
            <span class="badge bg-danger ms-1">取消済</span>
          <?php elseif ($isCompleted): ?>
            <span class="badge bg-success ms-1">完了</span>
          <?php endif; ?>
        </div>
        <div class="row text-start small mt-1 px-1">
          <div class="col-6">
            <span class="text-muted">受注日:</span>
            <?= $o['order_date'] ? formatDate($o['order_date']) : '―' ?>
          </div>
          <div class="col-6">
            <span class="text-muted">納期:</span>
            <?php if ($o['due_date']): ?>
              <span class="<?= (strtotime($o['due_date']) < time() && !$isCompleted) ? 'text-danger fw-bold' : '' ?>">
                <?= formatDate($o['due_date']) ?>
              </span>
            <?php else: ?>―<?php endif; ?>
          </div>
          <div class="col-6">
            <span class="text-muted">数量:</span>
            <?= h($o['quantity']) ?>本
          </div>
          <div class="col-6">
            <span class="text-muted">優先:</span>
            <?php
              $badge = match($o['priority'] ?? 'normal') {
                'urgent' => '<span class="badge bg-danger">緊急</span>',
                'high'   => '<span class="badge bg-warning text-dark">高</span>',
                default  => '<span class="badge bg-secondary">通常</span>',
              };
              echo $badge;
            ?>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- JsBarcode CDN -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
<?php foreach ($orders as $o): ?>
    JsBarcode('#bc-<?= $o['id'] ?>', <?= json_encode($o['order_no']) ?>, {
        format: 'CODE128',
        lineColor: '#000',
        width: 2,
        height: 60,
        displayValue: false,
        margin: 0
    });
<?php endforeach; ?>
});
</script>

<style>
@media print {
  .d-print-none { display: none !important; }
  .barcode-card { break-inside: avoid; page-break-inside: avoid; }
  body { padding: 0; margin: 0; }
  .container-fluid { padding: 0; }
  nav, footer { display: none !important; }
}
.barcode-card svg { max-width: 100%; height: 65px; }

/* バーコードSVG + オーバーレイのコンテナ */
.barcode-svg-wrap {
  position: relative;
  display: inline-block;
  width: 100%;
}

/* 取消: 赤の斜め横線 */
.bc-cancel-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}
.bc-cancel-overlay::before,
.bc-cancel-overlay::after {
  content: '';
  position: absolute;
  left: 5%;
  width: 90%;
  height: 4px;
  background: rgba(220,38,38,.85);
  border-radius: 2px;
}
.bc-cancel-overlay::before { transform: rotate(12deg);  }
.bc-cancel-overlay::after  { transform: rotate(-12deg); }
.bc-cancel-overlay span {
  position: relative;
  font-size: 1.1rem;
  font-weight: 900;
  color: #dc2626;
  background: rgba(255,255,255,.8);
  padding: 0 6px;
  border: 2px solid #dc2626;
  border-radius: 3px;
  letter-spacing: .05em;
}

/* 完了: 赤の「終了」スタンプ */
.bc-done-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}
.bc-done-overlay span {
  font-size: 2rem;
  font-weight: 900;
  color: rgba(220,38,38,.75);
  border: 3px solid rgba(220,38,38,.75);
  border-radius: 6px;
  padding: 2px 10px;
  transform: rotate(-15deg);
  letter-spacing: .1em;
  background: rgba(255,255,255,.35);
}
</style>

<?php require __DIR__ . '/parts/footer.php'; ?>
