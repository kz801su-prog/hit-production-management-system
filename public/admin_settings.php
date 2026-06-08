<?php
// =====================================================
// システム設定（admin以上のみ）
// - Authenticator必須化の ON/OFF
// - 登録申請待ちユーザーの承認
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('admin');
$pageTitle = 'システム設定';

// =====================================================
// POST 処理
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    // TOTP 必須設定の更新
    if ($postAction === 'update_totp_required') {
        $value = postStr('totp_required') === '1' ? '1' : '0';
        dbExecute(
            "INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
             VALUES ('totp_required', ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     updated_by_user_id = VALUES(updated_by_user_id),
                                     updated_at = NOW()",
            [$value, getCurrentUser()['id']]
        );
        auditLog('update_setting', 'system_settings', null, null, ['totp_required' => $value]);
        setFlash('Authenticator設定を' . ($value === '1' ? '必須' : '任意') . 'に変更しました。');
        header('Location: ' . APP_URL . '/admin_settings.php');
        exit;
    }

    // 申請待ちユーザーを承認（有効化）
    if ($postAction === 'approve_user') {
        $userId = postInt('user_id');
        if ($userId) {
            dbExecute("UPDATE users SET is_active = 1 WHERE id = ? AND is_active = 0", [$userId]);
            auditLog('approve_user', 'users', $userId, ['is_active' => 0], ['is_active' => 1]);
            setFlash('ユーザーを承認しました。');
        }
        header('Location: ' . APP_URL . '/admin_settings.php');
        exit;
    }

    // 申請を却下（ユーザー・社員レコードを削除）
    if ($postAction === 'reject_user') {
        $userId = postInt('user_id');
        if ($userId) {
            $user = dbFetchOne("SELECT employee_id FROM users WHERE id = ? AND is_active = 0", [$userId]);
            if ($user) {
                $pdo = dbConnection();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM users     WHERE id = ?")->execute([$userId]);
                    $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$user['employee_id']]);
                    $pdo->commit();
                    auditLog('reject_user', 'users', $userId);
                    setFlash('登録申請を却下しました。', 'warning');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    setFlash('削除処理中にエラーが発生しました。', 'danger');
                }
            }
        }
        header('Location: ' . APP_URL . '/admin_settings.php');
        exit;
    }
}

// 現在の設定値
$totpRequired = isTotpRequired();

// 承認待ちユーザー一覧（is_active=0）
$pendingUsers = dbFetchAll(
    "SELECT u.id, u.login_id, u.role, u.created_at,
            e.name AS emp_name, d.dept_name
     FROM users u
     JOIN employees e ON u.employee_id = e.id
     LEFT JOIN departments d ON e.department_id = d.id
     WHERE u.is_active = 0
     ORDER BY u.created_at DESC"
);

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col"><h2><i class="bi bi-sliders"></i> システム設定</h2></div>
</div>

<?= getFlashHtml() ?>

<!-- ===== Authenticator 必須設定 ===== -->
<div class="card mb-4">
  <div class="card-header fw-bold">
    <i class="bi bi-shield-lock"></i> Authenticator（二段階認証）設定
  </div>
  <div class="card-body">
    <div class="row align-items-center">
      <div class="col-md-8">
        <p class="mb-1">
          <strong>現在の設定:</strong>
          <?php if ($totpRequired): ?>
            <span class="badge bg-danger fs-6"><i class="bi bi-shield-check"></i> Authenticator 必須</span>
          <?php else: ?>
            <span class="badge bg-secondary fs-6"><i class="bi bi-shield"></i> Authenticator 任意</span>
          <?php endif; ?>
        </p>
        <p class="text-muted small mb-0">
          「必須」にすると、すべてのユーザーはログイン時に Authenticator の認証コードを入力する必要があります。<br>
          未設定のユーザーはログイン後すぐに設定ページへ誘導されます。
        </p>
      </div>
      <div class="col-md-4 text-end">
        <form method="post" action=""
              onsubmit="return confirm('Authenticator 設定を変更します。よろしいですか？');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_totp_required">
          <input type="hidden" name="totp_required" value="<?= $totpRequired ? '0' : '1' ?>">
          <button type="submit"
                  class="btn <?= $totpRequired ? 'btn-outline-secondary' : 'btn-danger' ?> btn-lg">
            <?php if ($totpRequired): ?>
              <i class="bi bi-shield-slash"></i> 任意に変更
            <?php else: ?>
              <i class="bi bi-shield-lock"></i> 必須に設定
            <?php endif; ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ===== 登録申請待ちユーザー ===== -->
<div class="card">
  <div class="card-header fw-bold">
    <i class="bi bi-person-check"></i> 登録申請待ちユーザー
    <?php if ($pendingUsers): ?>
      <span class="badge bg-warning text-dark ms-2"><?= count($pendingUsers) ?></span>
    <?php endif; ?>
  </div>
  <div class="card-body <?= !$pendingUsers ? 'p-3' : 'p-0' ?>">
    <?php if (!$pendingUsers): ?>
      <p class="text-muted mb-0"><i class="bi bi-check-circle"></i> 承認待ちのユーザーはいません。</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
          <thead class="table-warning">
            <tr>
              <th>ログインID</th>
              <th>氏名</th>
              <th>所属</th>
              <th>申請日時</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($pendingUsers as $u): ?>
            <tr>
              <td><strong><?= h($u['login_id']) ?></strong></td>
              <td><?= h($u['emp_name']) ?></td>
              <td><?= h($u['dept_name'] ?? '―') ?></td>
              <td><small><?= formatDatetime($u['created_at']) ?></small></td>
              <td>
                <!-- 承認 -->
                <form method="post" action="" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="approve_user">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-check-lg"></i> 承認
                  </button>
                </form>
                <!-- 却下 -->
                <form method="post" action="" class="d-inline ms-1"
                      onsubmit="return confirm('この申請を却下（削除）します。よろしいですか？');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="reject_user">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-x-lg"></i> 却下
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
