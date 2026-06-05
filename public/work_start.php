<?php
// =====================================================
// 作業開始入力ページ
// 目的: 作業者が担当する作業指示・工程の開始を記録
// 接続テーブル: work_logs, manufacturing_order_processes
// 呼び出し元: progress_board.php のリンクから
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/progress_service.php';

requireLogin();
$pageTitle = '作業開始';

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $orderId    = postInt('manufacturing_order_id');
    $processId  = postInt('process_id');
    $employeeId = postInt('employee_id');

    if (!$orderId || !$processId || !$employeeId) {
        setFlash('必須項目を入力してください。', 'danger');
        header('Location: ' . APP_URL . '/work_start.php');
        exit;
    }

    try {
        $logId = startWork($orderId, $processId, $employeeId);
        setFlash('作業を開始しました。');
        header('Location: ' . APP_URL . '/progress_board.php?order_id=' . $orderId);
        exit;
    } catch (RuntimeException $e) {
        setFlash($e->getMessage(), 'danger');
    }
}

// 作業可能な工程を取得（現在進行中または未着手）
$preOrderId   = getInt('order_id');
$preProcessId = getInt('process_id');

$availableOrders = dbFetchAll(
    "SELECT mo.id, mo.order_no, ct.chair_type_name FROM manufacturing_orders mo
     JOIN chair_types ct ON mo.chair_type_id = ct.id
     WHERE mo.status IN ('planned','in_progress')
     ORDER BY FIELD(mo.priority,'urgent','high','normal'), mo.due_date, mo.id
     LIMIT 50"
);

// 作業者一覧（自分は選択済みにする）
$employees = dbFetchAll(
    "SELECT e.id, e.name, e.employee_code FROM employees e
     WHERE e.is_active = 1 AND e.employment_status = 'active'
     ORDER BY e.employee_code"
);

// 選択された作業指示の工程一覧
$availableProcesses = [];
if ($preOrderId) {
    $availableProcesses = dbFetchAll(
        "SELECT mop.*, p.process_name FROM manufacturing_order_processes mop
         JOIN processes p ON mop.process_id = p.id
         WHERE mop.manufacturing_order_id = ?
           AND mop.status IN ('not_started','on_hold','in_progress')
         ORDER BY mop.process_sequence",
        [$preOrderId]
    );
}

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-play-circle"></i> 作業開始</h2>

<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header bg-success text-white">作業開始登録</div>
      <div class="card-body">
        <form method="post" id="startForm">
          <?= csrfField() ?>
          <div class="mb-3">
            <label class="form-label fw-bold">作業者 <span class="text-danger">*</span></label>
            <select name="employee_id" class="form-select" required>
              <option value="">― 選択 ―</option>
              <?php foreach ($employees as $emp): ?>
              <option value="<?= $emp['id'] ?>"
                <?= $emp['id'] == $user['employee_id'] ? 'selected' : '' ?>>
                <?= h($emp['employee_code']) ?> <?= h($emp['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">作業指示 <span class="text-danger">*</span></label>
            <select name="manufacturing_order_id" id="orderSelect" class="form-select" required
                    onchange="loadProcesses(this.value)">
              <option value="">― 選択 ―</option>
              <?php foreach ($availableOrders as $o): ?>
              <option value="<?= $o['id'] ?>" <?= $preOrderId == $o['id'] ? 'selected' : '' ?>>
                <?= h($o['order_no']) ?> - <?= h($o['chair_type_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-4">
            <label class="form-label fw-bold">工程 <span class="text-danger">*</span></label>
            <select name="process_id" id="processSelect" class="form-select" required>
              <option value="">― 作業指示を選択してください ―</option>
              <?php foreach ($availableProcesses as $mop): ?>
              <option value="<?= $mop['process_id'] ?>" <?= $preProcessId == $mop['process_id'] ? 'selected' : '' ?>>
                <?= h($mop['process_name']) ?>
                (<?= processStatusLabel($mop['status'])['label'] ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="d-grid">
            <button type="submit" class="btn btn-success btn-lg">
              <i class="bi bi-play-circle-fill"></i> 作業を開始する
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php
$appUrl = APP_URL;
$extraJs = <<<JS
function loadProcesses(orderId) {
  const select = document.getElementById('processSelect');
  if (!orderId) {
    select.innerHTML = '<option value="">― 作業指示を選択してください ―</option>';
    return;
  }
  window.location.href = '?order_id=' + orderId;
}
JS;
require __DIR__ . '/parts/footer.php';
?>
