<?php
// =====================================================
// バーコードスキャンステーション
// 目的: バーコードを読み込み作業指示の工程進捗を確認・更新
// 接続テーブル: manufacturing_orders, manufacturing_order_processes
// 権限: worker以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('worker');

$pageTitle = 'バーコードスキャン';
$order     = null;
$processes = [];
$message   = '';
$msgType   = 'info';

// =====================================================
// POST処理：工程ステータス更新
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action    = postStr('action');
    $mopId     = postInt('mop_id');
    $orderId   = postInt('order_id');

    if ($action === 'start_process' && $mopId) {
        dbExecute(
            "UPDATE manufacturing_order_processes
             SET status='in_progress', actual_start_time=NOW()
             WHERE id=? AND status='pending'",
            [$mopId]
        );
        dbExecute(
            "UPDATE manufacturing_orders SET status='in_progress' WHERE id=? AND status='pending'",
            [$orderId]
        );
        setFlash('工程を開始しました。', 'success');
    }

    if ($action === 'complete_process' && $mopId) {
        $endTime   = postStr('end_datetime') ?: date('Y-m-d H:i:s');
        dbExecute(
            "UPDATE manufacturing_order_processes
             SET status='completed', actual_end_time=?
             WHERE id=? AND status IN ('pending','in_progress')",
            [$endTime, $mopId]
        );
        // 全工程完了チェック
        $remaining = dbFetchOne(
            "SELECT COUNT(*) AS cnt FROM manufacturing_order_processes
             WHERE manufacturing_order_id=? AND status != 'completed'",
            [$orderId]
        );
        if ($remaining && $remaining['cnt'] == 0) {
            dbExecute("UPDATE manufacturing_orders SET status='completed' WHERE id=?", [$orderId]);
        }
        setFlash('工程を完了しました。', 'success');
        auditLog('complete_process', 'manufacturing_order_processes', $mopId);
    }

    header('Location: ' . APP_URL . '/barcode_scan.php?order_id=' . $orderId);
    exit;
}

// =====================================================
// GETパラメータでの検索
// =====================================================
$scanInput = trim($_GET['scan'] ?? '');
$orderId   = getInt('order_id');

if ($scanInput) {
    $order = dbFetchOne(
        "SELECT mo.*, ct.chair_type_code, ct.chair_type_name
         FROM manufacturing_orders mo
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         WHERE mo.order_no = ?",
        [$scanInput]
    );
    if (!$order) {
        $message = '「' . h($scanInput) . '」に一致する作業指示が見つかりません。';
        $msgType = 'warning';
    } else {
        $orderId = $order['id'];
    }
} elseif ($orderId) {
    $order = dbFetchOne(
        "SELECT mo.*, ct.chair_type_code, ct.chair_type_name
         FROM manufacturing_orders mo
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         WHERE mo.id = ?",
        [$orderId]
    );
}

if ($order) {
    $processes = dbFetchAll(
        "SELECT mop.*, p.process_name, p.process_code
         FROM manufacturing_order_processes mop
         JOIN processes p ON mop.process_id = p.id
         WHERE mop.manufacturing_order_id = ?
         ORDER BY mop.process_sequence, p.display_order",
        [$order['id']]
    );
}

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3 align-items-center">
  <div class="col"><h2><i class="bi bi-upc-scan"></i> バーコードスキャン</h2></div>
  <?php if ($order): ?>
  <div class="col-auto">
    <a href="barcode_print.php?order_id=<?= $order['id'] ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
      <i class="bi bi-printer"></i> 再印刷
    </a>
  </div>
  <?php endif; ?>
</div>

<?= getFlashHtml() ?>

<!-- スキャン入力フォーム -->
<div class="card mb-4 border-primary">
  <div class="card-body">
    <form method="get" id="scanForm" class="d-flex gap-2">
      <input type="text" name="scan" id="scanInput"
             class="form-control form-control-lg"
             placeholder="バーコードをスキャン または 指示番号を入力..."
             value="<?= h($scanInput) ?>"
             autofocus autocomplete="off">
      <button type="submit" class="btn btn-primary px-4">
        <i class="bi bi-search"></i>
      </button>
    </form>
    <div class="form-text mt-1">
      <i class="bi bi-info-circle"></i>
      バーコードリーダーで読み込むと自動で検索されます（Enter送信）
    </div>
  </div>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
<?php endif; ?>

<?php if ($order): ?>
<!-- 作業指示情報カード -->
<div class="card mb-3 border-info">
  <div class="card-header bg-info text-white">
    <strong><?= h($order['order_no']) ?></strong>
    &nbsp;
    <?= orderStatusBadge($order['status']) ?>
    &nbsp;
    <?= priorityBadge($order['priority']) ?>
  </div>
  <div class="card-body py-2">
    <div class="row g-2 small">
      <div class="col-6 col-md-3">
        <span class="text-muted">椅子タイプ:</span><br>
        <strong><?= h($order['chair_type_code']) ?></strong><br>
        <span class="text-muted"><?= h($order['chair_type_name']) ?></span>
      </div>
      <div class="col-6 col-md-3">
        <span class="text-muted">顧客 / 物件:</span><br>
        <?= h($order['customer_name'] ?? '―') ?><br>
        <span class="text-muted"><?= h($order['project_name'] ?? '―') ?></span>
      </div>
      <div class="col-6 col-md-3">
        <span class="text-muted">数量:</span><br>
        <strong><?= h($order['quantity']) ?>本</strong>
      </div>
      <div class="col-6 col-md-3">
        <span class="text-muted">納期:</span><br>
        <?php if ($order['due_date']): ?>
          <strong class="<?= (strtotime($order['due_date']) < time()) ? 'text-danger' : '' ?>">
            <?= formatDate($order['due_date']) ?>
          </strong>
        <?php else: ?>―<?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- 工程一覧 -->
<div class="card">
  <div class="card-header fw-bold">工程進捗</div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead class="table-light">
        <tr>
          <th>工程</th>
          <th>ステータス</th>
          <th>開始</th>
          <th>完了</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($processes as $mop): ?>
        <tr class="<?= $mop['status'] === 'completed' ? 'table-success' : ($mop['status'] === 'in_progress' ? 'table-warning' : '') ?>">
          <td>
            <strong><?= h($mop['process_name']) ?></strong>
            <small class="text-muted">(<?= h($mop['process_code']) ?>)</small>
          </td>
          <td><?= processStatusBadge($mop['status']) ?></td>
          <td class="small"><?= $mop['actual_start_time'] ? formatDatetime($mop['actual_start_time']) : '―' ?></td>
          <td class="small"><?= $mop['actual_end_time'] ? formatDatetime($mop['actual_end_time']) : '―' ?></td>
          <td>
            <?php if ($mop['status'] === 'pending'): ?>
              <form method="post" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action"   value="start_process">
                <input type="hidden" name="mop_id"   value="<?= $mop['id'] ?>">
                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-play-fill"></i> 開始
                </button>
              </form>
            <?php elseif ($mop['status'] === 'in_progress'): ?>
              <button type="button" class="btn btn-sm btn-success"
                      data-bs-toggle="modal"
                      data-bs-target="#completeModal"
                      data-mop-id="<?= $mop['id'] ?>"
                      data-order-id="<?= $order['id'] ?>"
                      data-process-name="<?= h($mop['process_name']) ?>">
                <i class="bi bi-check-lg"></i> 完了
              </button>
            <?php else: ?>
              <span class="text-success small"><i class="bi bi-check-circle"></i> 完了済</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- 工程完了モーダル -->
<div class="modal fade" id="completeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="complete_process">
        <input type="hidden" name="mop_id" id="modalMopId">
        <input type="hidden" name="order_id" id="modalOrderId">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-check-circle"></i> 工程完了</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p id="modalProcessName" class="fw-bold"></p>
          <div class="mb-3">
            <label class="form-label">完了日時</label>
            <input type="datetime-local" name="end_datetime" id="modalEndDatetime"
                   class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg"></i> 完了登録
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<JS
// スキャン入力: Enterで自動サブミット（バーコードリーダー対応）
document.getElementById('scanInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('scanForm').submit();
    }
});

// 完了モーダル: 現在時刻をセット
var completeModal = document.getElementById('completeModal');
completeModal.addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('modalMopId').value    = btn.dataset.mopId;
    document.getElementById('modalOrderId').value  = btn.dataset.orderId;
    document.getElementById('modalProcessName').textContent = btn.dataset.processName + ' の完了時刻を確認してください。';
    var now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('modalEndDatetime').value = now.toISOString().slice(0,16);
});
JS;
require __DIR__ . '/parts/footer.php'; ?>
