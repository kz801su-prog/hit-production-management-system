<?php
// =====================================================
// 作業終了入力ページ
// 目的: 作業者が作業終了・不良数・メモを登録する
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
$pageTitle = '作業終了';

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $workLogId = postInt('work_log_id');
    $data = [
        'completed_qty' => postInt('completed_qty'),
        'defect_qty'    => postInt('defect_qty'),
        'rework_qty'    => postInt('rework_qty'),
        'break_minutes' => postFloat('break_minutes'),
        'memo'          => postStr('memo'),
    ];

    try {
        $log = dbFetchOne("SELECT * FROM work_logs WHERE id = ?", [$workLogId]);
        finishWork($workLogId, $data);
        setFlash('作業を終了しました。');
        header('Location: ' . APP_URL . '/progress_board.php?order_id=' . $log['manufacturing_order_id']);
        exit;
    } catch (RuntimeException $e) {
        setFlash($e->getMessage(), 'danger');
    }
}

// 現在進行中の作業ログ一覧（自分のもの優先）
$openLogs = dbFetchAll(
    "SELECT wl.*, mo.order_no, p.process_name, ct.chair_type_name, e.name AS worker_name
     FROM work_logs wl
     JOIN manufacturing_orders mo ON wl.manufacturing_order_id = mo.id
     JOIN processes p ON wl.process_id = p.id
     JOIN chair_types ct ON mo.chair_type_id = ct.id
     JOIN employees e ON wl.employee_id = e.id
     WHERE wl.ended_at IS NULL
     ORDER BY wl.employee_id = ? DESC, wl.started_at DESC",
    [$user['employee_id']]
);

$selectedLog = null;
$logId = getInt('log_id');
if ($logId) {
    foreach ($openLogs as $log) {
        if ($log['id'] == $logId) {
            $selectedLog = $log;
            break;
        }
    }
}

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-stop-circle"></i> 作業終了</h2>

<div class="row">
  <!-- 作業中一覧 -->
  <div class="col-md-5">
    <div class="card">
      <div class="card-header">現在進行中の作業</div>
      <div class="list-group list-group-flush">
      <?php foreach ($openLogs as $log): ?>
        <?php
        $elapsed = round((time() - strtotime($log['started_at'])) / 60, 1);
        $isMe    = $log['employee_id'] == $user['employee_id'];
        ?>
        <a href="?log_id=<?= $log['id'] ?>"
           class="list-group-item list-group-item-action <?= $selectedLog && $selectedLog['id'] == $log['id'] ? 'active' : '' ?>">
          <div class="d-flex justify-content-between">
            <strong><?= h($log['order_no']) ?> - <?= h($log['process_name']) ?></strong>
            <?php if ($isMe): ?>
              <span class="badge bg-primary">自分</span>
            <?php endif; ?>
          </div>
          <small>
            <?= h($log['worker_name']) ?> | 開始 <?= formatDatetime($log['started_at']) ?>
            <span class="text-warning"> (<?= $elapsed ?>分経過)</span>
          </small>
        </a>
      <?php endforeach; ?>
      <?php if (empty($openLogs)): ?>
        <div class="list-group-item text-muted text-center py-3">進行中の作業なし</div>
      <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 終了フォーム -->
  <div class="col-md-7">
    <?php if ($selectedLog): ?>
    <div class="card">
      <div class="card-header bg-warning">作業終了登録</div>
      <div class="card-body">
        <div class="alert alert-info py-2 mb-3">
          <strong><?= h($selectedLog['order_no']) ?></strong> -
          <?= h($selectedLog['process_name']) ?><br>
          <small>
            作業者: <?= h($selectedLog['worker_name']) ?> |
            開始: <?= formatDatetime($selectedLog['started_at']) ?>
          </small>
        </div>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="work_log_id" value="<?= $selectedLog['id'] ?>">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">完了数量</label>
              <input type="number" name="completed_qty" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">不良数量</label>
              <input type="number" name="defect_qty" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">手直し数量</label>
              <input type="number" name="rework_qty" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label">中断・休憩時間（分）</label>
              <input type="number" name="break_minutes" class="form-control" min="0" step="0.5" value="0">
            </div>
            <div class="col-12">
              <label class="form-label">作業メモ（問題点など）</label>
              <textarea name="memo" class="form-control" rows="3"
                        placeholder="問題があった場合はここに記録してください"></textarea>
            </div>
          </div>
          <div class="d-grid mt-3">
            <button type="submit" class="btn btn-warning btn-lg"
                    onclick="return confirm('作業を終了しますか？')">
              <i class="bi bi-stop-circle-fill"></i> 作業を終了する
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php else: ?>
      <div class="alert alert-secondary">左側から終了したい作業を選択してください。</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
