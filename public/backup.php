<?php
// =====================================================
// バックアップ管理ページ
// 目的: 手動バックアップの実行・ログ確認・ダウンロード
// 接続テーブル: backup_logs
// 権限: admin以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/backup_service.php';

requireLogin();
requireRole('admin');
$pageTitle = 'バックアップ管理';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    if ($postAction === 'run_backup') {
        $result = runBackup();
        if ($result['success']) {
            setFlash($result['message']);
        } else {
            setFlash($result['message'], 'danger');
        }
        header('Location: ' . APP_URL . '/backup.php');
        exit;
    }

    if ($postAction === 'download') {
        $filename = basename(postStr('filename'));
        $filepath = BACKUP_DIR . $filename;
        if (file_exists($filepath) && preg_match('/^backup_\d{14}\.sql$/', $filename)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } else {
            setFlash('ファイルが見つかりません。', 'danger');
        }
    }
}

$logs        = getBackupLogs(30);
$backupFiles = glob(BACKUP_DIR . 'backup_*.sql') ?: [];
rsort($backupFiles);

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-database"></i> バックアップ管理</h2>

<div class="row g-3">
  <!-- バックアップ実行 -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-header bg-primary text-white">バックアップ実行</div>
      <div class="card-body">
        <p class="text-muted small">
          全テーブルのSQLダンプを生成してサーバーに保存します。<br>
          直近<?= BACKUP_RETENTION_DAYS ?>日分のみ保持されます。
        </p>
        <form method="post" onsubmit="return confirm('バックアップを実行しますか？')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="run_backup">
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-download"></i> バックアップを実行
          </button>
        </form>
      </div>
    </div>

    <!-- バックアップファイル一覧 -->
    <div class="card mt-3">
      <div class="card-header">保存済みファイル（<?= count($backupFiles) ?>件）</div>
      <div class="list-group list-group-flush">
      <?php foreach (array_slice($backupFiles, 0, 10) as $fp): ?>
        <?php $fname = basename($fp); ?>
        <div class="list-group-item d-flex justify-content-between align-items-center">
          <div>
            <small><?= h($fname) ?></small><br>
            <small class="text-muted"><?= round(filesize($fp) / 1024, 1) ?> KB</small>
          </div>
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="download">
            <input type="hidden" name="filename" value="<?= h($fname) ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">DL</button>
          </form>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- バックアップログ -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header">バックアップ履歴（直近30件）</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-dark">
            <tr><th>日時</th><th>ファイル名</th><th>結果</th><th>エラー</th></tr>
          </thead>
          <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td><?= formatDatetime($log['created_at']) ?></td>
              <td><code><?= h($log['backup_file']) ?></code></td>
              <td>
                <span class="badge bg-<?= $log['status'] === 'success' ? 'success' : 'danger' ?>">
                  <?= $log['status'] === 'success' ? '成功' : '失敗' ?>
                </span>
              </td>
              <td><small class="text-danger"><?= h($log['error_message'] ?? '') ?></small></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($logs)): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">バックアップ履歴なし</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
