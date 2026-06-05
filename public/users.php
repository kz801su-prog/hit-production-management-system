<?php
// =====================================================
// ユーザー（ログインアカウント）管理
// 目的: ログインID・パスワード・権限の管理
// 接続テーブル: users, employees
// 権限: admin以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('admin');
$pageTitle = 'ユーザー管理';

$action = $_GET['action'] ?? 'list';
$id     = getInt('id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    if ($postAction === 'create') {
        $loginId  = postStr('login_id');
        $password = postStr('password');
        $empId    = postInt('employee_id');
        $role     = postStr('role', 'worker');

        if (!$loginId || !$password || !$empId) {
            setFlash('必須項目を入力してください。', 'danger');
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $newId = dbExecute(
                    "INSERT INTO users (employee_id, login_id, password_hash, role) VALUES (?,?,?,?)",
                    [$empId, $loginId, $hash, $role]
                );
                auditLog('create', 'users', (int)$newId);
                setFlash('ユーザーを作成しました。');
            } catch (PDOException $e) {
                setFlash('作成失敗（ログインIDが重複している可能性があります）', 'danger');
            }
        }
        header('Location: ' . APP_URL . '/users.php');
        exit;
    }

    if ($postAction === 'update_role' && $id) {
        $role = postStr('role', 'worker');
        dbExecute("UPDATE users SET role = ? WHERE id = ?", [$role, $id]);
        auditLog('update_role', 'users', $id, null, ['role' => $role]);
        setFlash('権限を更新しました。');
        header('Location: ' . APP_URL . '/users.php');
        exit;
    }

    if ($postAction === 'reset_password' && $id) {
        $newPass = postStr('new_password');
        if (strlen($newPass) < 8) {
            setFlash('パスワードは8文字以上で入力してください。', 'danger');
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            dbExecute("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $id]);
            auditLog('reset_password', 'users', $id);
            setFlash('パスワードをリセットしました。');
        }
        header('Location: ' . APP_URL . '/users.php');
        exit;
    }

    if ($postAction === 'toggle_active' && $id) {
        $row = dbFetchOne("SELECT is_active FROM users WHERE id = ?", [$id]);
        $newActive = $row ? ($row['is_active'] ? 0 : 1) : 1;
        dbExecute("UPDATE users SET is_active = ? WHERE id = ?", [$newActive, $id]);
        auditLog('toggle_active', 'users', $id, ['is_active' => $row['is_active']], ['is_active' => $newActive]);
        setFlash('ユーザーの有効/無効を切り替えました。');
        header('Location: ' . APP_URL . '/users.php');
        exit;
    }
}

// ユーザー一覧
$users = dbFetchAll(
    "SELECT u.*, e.name AS emp_name, e.employee_code, d.dept_name
     FROM users u
     JOIN employees e ON u.employee_id = e.id
     LEFT JOIN departments d ON e.department_id = d.id
     ORDER BY u.id"
);

// 社員一覧（未ユーザー化の人のみ）
$freeEmployees = dbFetchAll(
    "SELECT e.id, e.employee_code, e.name FROM employees e
     WHERE e.is_active = 1 AND e.employment_status = 'active'
       AND NOT EXISTS (SELECT 1 FROM users u WHERE u.employee_id = e.id)
     ORDER BY e.employee_code"
);

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col"><h2><i class="bi bi-person-gear"></i> ユーザー管理</h2></div>
  <div class="col-auto">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
      <i class="bi bi-plus-circle"></i> ユーザー作成
    </button>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-dark">
          <tr>
            <th>ログインID</th><th>社員</th><th>部署</th><th>権限</th>
            <th>最終ログイン</th><th>状態</th><th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr class="<?= !$u['is_active'] ? 'table-secondary text-muted' : '' ?>">
            <td><strong><?= h($u['login_id']) ?></strong></td>
            <td><?= h($u['emp_name']) ?><br><small class="text-muted"><?= h($u['employee_code']) ?></small></td>
            <td><?= h($u['dept_name'] ?? '―') ?></td>
            <td>
              <span class="badge bg-<?= $u['role'] === 'admin' || $u['role'] === 'president' ? 'danger' : 'primary' ?>">
                <?= h(roleLabel($u['role'])) ?>
              </span>
            </td>
            <td><small><?= formatDatetime($u['last_login_at']) ?></small></td>
            <td>
              <span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>">
                <?= $u['is_active'] ? '有効' : '無効' ?>
              </span>
            </td>
            <td>
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                      data-bs-target="#editModal<?= $u['id'] ?>">操作</button>
            </td>
          </tr>

          <!-- 操作モーダル -->
          <div class="modal fade" id="editModal<?= $u['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><?= h($u['login_id']) ?> の操作</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <!-- 権限変更 -->
                  <form method="post" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_role">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <label class="form-label">権限変更</label>
                    <div class="input-group">
                      <select name="role" class="form-select">
                        <?php foreach (['president'=>'社長','admin'=>'管理者','factory_manager'=>'工場長','process_leader'=>'工程リーダー','worker'=>'作業員'] as $rv => $rl): ?>
                        <option value="<?= $rv ?>" <?= $u['role'] === $rv ? 'selected' : '' ?>><?= $rl ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="btn btn-primary">変更</button>
                    </div>
                  </form>
                  <!-- パスワードリセット -->
                  <form method="post" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <label class="form-label">パスワードリセット（8文字以上）</label>
                    <div class="input-group">
                      <input type="password" name="new_password" class="form-control" minlength="8" required>
                      <button type="submit" class="btn btn-warning">リセット</button>
                    </div>
                  </form>
                  <!-- 有効/無効切り替え -->
                  <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-<?= $u['is_active'] ? 'danger' : 'success' ?> w-100"
                            onclick="return confirm('本当に切り替えますか？')">
                      <?= $u['is_active'] ? '無効化（ログイン停止）' : '有効化（ログイン再開）' ?>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- 新規ユーザー作成モーダル -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">ユーザーを作成</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">社員 <span class="text-danger">*</span></label>
            <select name="employee_id" class="form-select" required>
              <option value="">― 選択 ―</option>
              <?php foreach ($freeEmployees as $emp): ?>
              <option value="<?= $emp['id'] ?>"><?= h($emp['employee_code']) ?> <?= h($emp['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">ログインID <span class="text-danger">*</span></label>
            <input type="text" name="login_id" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">パスワード（8文字以上）<span class="text-danger">*</span></label>
            <input type="password" name="password" class="form-control" minlength="8" required>
          </div>
          <div class="mb-3">
            <label class="form-label">権限</label>
            <select name="role" class="form-select">
              <?php foreach (['worker'=>'作業員','process_leader'=>'工程リーダー','factory_manager'=>'工場長','admin'=>'管理者'] as $rv => $rl): ?>
              <option value="<?= $rv ?>"><?= $rl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
          <button type="submit" class="btn btn-primary">作成</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
