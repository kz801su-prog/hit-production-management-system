<?php
// =====================================================
// 作業指示登録フォーム
// 目的: 椅子タイプを選択し、標準時間プレビュー付きで作業指示を作成
// 接続テーブル: manufacturing_orders, chair_types
// 権限: process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/chair_type_service.php';
require_once __DIR__ . '/../app/standard_time_service.php';
require_once __DIR__ . '/../app/order_service.php';

requireLogin();
requireRole('process_leader');
$pageTitle = '作業指示登録';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'chair_type_id' => postInt('chair_type_id'),
        'quantity'      => postInt('quantity', 1),
        'due_date'      => postStr('due_date'),
        'priority'      => postStr('priority', 'normal'),
        'memo'          => postStr('memo'),
    ];

    if (!$data['chair_type_id'] || $data['quantity'] < 1) {
        setFlash('椅子タイプと数量は必須です。', 'danger');
        header('Location: ' . APP_URL . '/order_form.php');
        exit;
    }

    try {
        $orderId = createOrder($data);
        setFlash('作業指示を作成しました（' . generateOrderNo() . '）');
        header('Location: ' . APP_URL . '/progress_board.php?order_id=' . $orderId);
        exit;
    } catch (Throwable $e) {
        setFlash('作成失敗: ' . $e->getMessage(), 'danger');
    }
}

// 標準時間プレビュー（AJAXまたはフォーム送信で計算）
$previewChairTypeId = getInt('preview_ct');
$previewQty         = getInt('preview_qty', 1);
$previewResult      = [];
$previewChairType   = null;
if ($previewChairTypeId && $previewQty > 0) {
    $previewResult    = calcStandardTimes($previewChairTypeId, $previewQty);
    $previewChairType = dbFetchOne("SELECT * FROM chair_types WHERE id = ?", [$previewChairTypeId]);
}

$chairTypes = dbFetchAll(
    "SELECT ct.id, ct.chair_type_code, ct.chair_type_name, ct.base_quantity,
            g.group_name
     FROM chair_types ct
     JOIN chair_type_groups g ON ct.chair_type_group_id = g.id
     WHERE ct.is_active = 1 ORDER BY ct.chair_type_code"
);

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-clipboard-plus"></i> 作業指示登録</h2>

<div class="row">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header bg-primary text-white">作業指示情報</div>
      <div class="card-body">
        <form method="post" id="orderForm">
          <?= csrfField() ?>
          <div class="mb-3">
            <label class="form-label fw-bold">椅子タイプ <span class="text-danger">*</span></label>
            <select name="chair_type_id" id="chairTypeSelect" class="form-select" required>
              <option value="">― 選択してください ―</option>
              <?php foreach ($chairTypes as $ct): ?>
              <option value="<?= $ct['id'] ?>"
                data-base-qty="<?= $ct['base_quantity'] ?>"
                <?= ($previewChairTypeId == $ct['id']) ? 'selected' : '' ?>>
                <?= h($ct['chair_type_code']) ?> - <?= h($ct['chair_type_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">数量 <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="number" name="quantity" id="quantityInput" class="form-control"
                     value="<?= $previewQty ?>" min="1" required>
              <span class="input-group-text">本</span>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">納期</label>
            <input type="date" name="due_date" class="form-control"
                   min="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">優先度</label>
            <select name="priority" class="form-select">
              <option value="normal">通常</option>
              <option value="high">高</option>
              <option value="urgent">緊急</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">メモ</label>
            <textarea name="memo" class="form-control" rows="2"
                      placeholder="特記事項・顧客情報など"></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" onclick="previewStdTime()">
              <i class="bi bi-calculator"></i> 標準時間プレビュー
            </button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check-circle"></i> 作業指示を作成
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 標準時間プレビュー -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header">標準時間プレビュー</div>
      <div class="card-body" id="previewArea">
        <?php if (!empty($previewResult) && $previewChairType): ?>
          <p class="text-muted small">
            <strong><?= h($previewChairType['chair_type_code']) ?></strong>
            × <?= $previewQty ?>本
          </p>
          <table class="table table-sm">
            <thead><tr><th>工程</th><th>段取</th><th>正味</th><th>差分</th><th>アローアンス</th><th class="text-end">合計</th></tr></thead>
            <tbody>
            <?php $total = 0; foreach ($previewResult as $calc): $total += $calc['total_minutes']; ?>
              <tr>
                <td><?= h($calc['process_name']) ?></td>
                <td><?= $calc['setup_minutes'] ?>分</td>
                <td><?= $calc['net_work_minutes'] ?>分</td>
                <td><?= $calc['adjustment_minutes'] >= 0 ? '+' : '' ?><?= $calc['adjustment_minutes'] ?>分</td>
                <td><?= $calc['allowance_minutes'] ?>分</td>
                <td class="text-end fw-bold"><?= formatMinutes($calc['total_minutes']) ?></td>
              </tr>
            <?php endforeach; ?>
            <tr class="table-info fw-bold">
              <td colspan="5" class="text-end">総合計</td>
              <td class="text-end"><?= formatMinutes($total) ?></td>
            </tr>
            </tbody>
          </table>
        <?php else: ?>
          <p class="text-muted text-center py-4">
            椅子タイプと数量を選択して「標準時間プレビュー」ボタンを押してください。
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<JS
function previewStdTime() {
  const ctId = document.getElementById('chairTypeSelect').value;
  const qty  = document.getElementById('quantityInput').value;
  if (!ctId || !qty) { alert('椅子タイプと数量を選択してください'); return; }
  window.location.href = '?preview_ct=' + ctId + '&preview_qty=' + qty;
}
JS;
require __DIR__ . '/parts/footer.php'; ?>
