<?php
// =====================================================
// 作業指示一覧
// 目的: 全作業指示の状態・優先度・遅延を一覧で確認
// 接続テーブル: manufacturing_orders, chair_types, manufacturing_order_processes
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/order_service.php';

requireLogin();
$pageTitle = '作業指示一覧';

// フィルタ
$filters = [
    'status'   => $_GET['status']   ?? '',
    'priority' => $_GET['priority'] ?? '',
    'due_from' => $_GET['due_from'] ?? '',
    'due_to'   => $_GET['due_to']   ?? '',
];

// ステータス変更（管理者以上）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLeader()) {
    verifyCsrf();
    $action  = postStr('action');
    $orderId = postInt('order_id');

    if ($action === 'cancel' && $orderId) {
        updateOrderStatus($orderId, 'cancelled');
        setFlash('作業指示をキャンセルしました。', 'warning');
    }
    if ($action === 'complete' && $orderId) {
        updateOrderStatus($orderId, 'completed');
        setFlash('作業指示を完了にしました。');
    }
    header('Location: ' . APP_URL . '/orders.php');
    exit;
}

$orders = getOrderList($filters);

// 品質評価済みのorder_idセット
$evaluatedIds = [];
if ($orders) {
    $ids = implode(',', array_map('intval', array_column($orders, 'id')));
    if ($ids) {
        $rows = dbFetchAll("SELECT manufacturing_order_id FROM order_quality_evaluations WHERE manufacturing_order_id IN ($ids)");
        foreach ($rows as $r) $evaluatedIds[$r['manufacturing_order_id']] = true;
    }
}

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col"><h2><i class="bi bi-clipboard-list"></i> 作業指示一覧</h2></div>
  <?php if (isLeader()): ?>
  <div class="col-auto">
    <a href="<?= APP_URL ?>/order_form.php" class="btn btn-primary">
      <i class="bi bi-plus-circle"></i> 新規作成
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- フィルタ -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2">
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">全ステータス</option>
          <?php foreach (['planned'=>'計画中','in_progress'=>'進行中','completed'=>'完了','on_hold'=>'保留','cancelled'=>'キャンセル'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="priority" class="form-select form-select-sm">
          <option value="">全優先度</option>
          <option value="urgent" <?= $filters['priority'] === 'urgent' ? 'selected' : '' ?>>緊急</option>
          <option value="high"   <?= $filters['priority'] === 'high'   ? 'selected' : '' ?>>高</option>
          <option value="normal" <?= $filters['priority'] === 'normal' ? 'selected' : '' ?>>通常</option>
        </select>
      </div>
      <div class="col-md-2">
        <input type="date" name="due_from" class="form-control form-control-sm"
               placeholder="納期From" value="<?= h($filters['due_from']) ?>">
      </div>
      <div class="col-md-2">
        <input type="date" name="due_to" class="form-control form-control-sm"
               placeholder="納期To" value="<?= h($filters['due_to']) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">絞込</button>
        <a href="orders.php" class="btn btn-sm btn-outline-secondary">リセット</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-dark">
          <tr>
            <th>作業番号</th><th>製品タイプ</th><th>数量</th>
            <th>納期</th><th>優先度</th><th>ステータス</th>
            <th>工程進捗</th><th>遅延</th><th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <?php
          $st = orderStatusLabel($o['status']);
          $pr = priorityLabel($o['priority']);
          $delay = (float)($o['total_delay'] ?? 0);
          $doneProcesses  = (int)($o['done_processes'] ?? 0);
          $totalProcesses = (int)($o['total_processes'] ?? 1);
          $pct = $totalProcesses > 0 ? round($doneProcesses / $totalProcesses * 100) : 0;
          ?>
          <tr>
            <td>
              <a href="progress_board.php?order_id=<?= $o['id'] ?>">
                <strong><?= h($o['order_no']) ?></strong>
              </a><br>
              <a href="barcode_print.php?order_id=<?= $o['id'] ?>"
                 class="text-decoration-none barcode-link"
                 target="_blank"
                 title="クリックでバーコード印刷">
                <svg class="mini-bc" data-value="<?= h($o['order_no']) ?>"></svg>
                <span><?= h($o['order_no']) ?></span>
              </a>
            </td>
            <td><?= h($o['chair_type_name']) ?><br>
                <small class="text-muted"><?= h($o['chair_type_code']) ?></small></td>
            <td><?= h($o['quantity']) ?>本</td>
            <td><?= formatDate($o['due_date']) ?></td>
            <td><span class="badge bg-<?= $pr['class'] ?>"><?= $pr['label'] ?></span></td>
            <td><span class="badge bg-<?= $st['class'] ?>"><?= $st['label'] ?></span></td>
            <td style="min-width:120px">
              <div class="progress" style="height:18px">
                <div class="progress-bar" role="progressbar" style="width:<?= $pct ?>%">
                  <?= $doneProcesses ?>/<?= $totalProcesses ?>
                </div>
              </div>
            </td>
            <td>
              <?php if ($delay > 0): ?>
                <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> +<?= formatMinutes($delay) ?></span>
              <?php else: ?>
                <span class="text-success"><i class="bi bi-check"></i></span>
              <?php endif; ?>
            </td>
            <td>
              <a href="progress_board.php?order_id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary">進捗</a>
              <?php if (isLeader() && $o['status'] === 'completed'): ?>
                <?php if (!isset($evaluatedIds[$o['id']])): ?>
                  <a href="quality_eval.php?order_id=<?= $o['id'] ?>" class="btn btn-sm btn-warning">
                    <i class="bi bi-star"></i> 品質評価
                  </a>
                <?php else: ?>
                  <a href="quality_eval.php?order_id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-success" title="評価済み（修正可）">
                    <i class="bi bi-star-fill"></i>
                  </a>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (isLeader() && $o['status'] !== 'completed' && $o['status'] !== 'cancelled'): ?>
              <form method="post" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('キャンセルしますか？')">取消</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($orders)): ?>
          <tr><td colspan="9" class="text-center text-muted py-3">作業指示がありません</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- JsBarcode for inline mini barcodes -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('svg.mini-bc').forEach(function(el) {
        JsBarcode(el, el.dataset.value, {
            format: 'CODE128',
            lineColor: '#374151',
            width: 1.2,
            height: 28,
            displayValue: false,
            margin: 0
        });
    });
});
</script>
<style>
.barcode-link { display:inline-flex; align-items:center; gap:4px; font-size:.72rem; color:#6b7280 !important; }
.barcode-link:hover { color:#1d4ed8 !important; }
svg.mini-bc { display:block; max-width:120px; height:28px; }
</style>

<?php require __DIR__ . '/parts/footer.php'; ?>
