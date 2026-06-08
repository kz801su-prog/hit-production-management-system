<?php
// =====================================================
// 新規アカウント登録申請
// 目的: 新規ユーザーが自身でアカウントを申請する
//       申請後は管理者の承認（is_active=1 への切替）が必要
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

// すでにログイン済みならダッシュボードへ
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$errors  = [];
$success = false;

// 部署一覧（プルダウン用）
$departments = dbFetchAll("SELECT id, dept_name FROM departments WHERE is_active = 1 ORDER BY display_order");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']             ?? '');
    $loginId     = trim($_POST['login_id']         ?? '');
    $password    = $_POST['password']              ?? '';
    $passConfirm = $_POST['password_confirm']      ?? '';
    $deptId      = (int)($_POST['department_id']   ?? 0) ?: null;

    // バリデーション
    if (!$name)                           $errors[] = '氏名を入力してください。';
    if (!$loginId)                        $errors[] = 'ログインIDを入力してください。';
    if (mb_strlen($loginId) > 50)        $errors[] = 'ログインIDは50文字以内で入力してください。';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $loginId)) $errors[] = 'ログインIDは半角英数字・アンダースコアのみ使用できます。';
    if (strlen($password) < 8)            $errors[] = 'パスワードは8文字以上で入力してください。';
    if ($password !== $passConfirm)       $errors[] = 'パスワードが一致しません。';

    // ログインID重複チェック
    if (!$errors) {
        $exist = dbFetchOne("SELECT id FROM users WHERE login_id = ?", [$loginId]);
        if ($exist) $errors[] = 'そのログインIDはすでに使用されています。';
    }

    if (!$errors) {
        // トランザクションで employee + user を一括作成
        $pdo = dbConnection();
        try {
            $pdo->beginTransaction();

            // 社員コード自動採番（REG-YYYYMMDD-XXXX）
            $empCode = 'REG-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

            // employees テーブルへ登録
            $pdo->prepare(
                "INSERT INTO employees (employee_code, name, is_active, employment_status, department_id)
                 VALUES (?, ?, 1, 'active', ?)"
            )->execute([$empCode, $name, $deptId]);
            $empId = (int)$pdo->lastInsertId();

            // users テーブルへ登録（is_active=0: 管理者承認待ち）
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare(
                "INSERT INTO users (employee_id, login_id, password_hash, role, is_active)
                 VALUES (?, ?, ?, 'worker', 0)"
            )->execute([$empId, $loginId, $hash]);

            $pdo->commit();
            $success = true;

        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = '登録処理中にエラーが発生しました。時間をおいて再度お試しください。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>新規アカウント登録 - <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="bg-dark">
<div class="container">
  <div class="row justify-content-center min-vh-100 align-items-center">
    <div class="col-md-6">

      <div class="text-center mb-4">
        <h1 class="text-white fw-bold fs-4">
          <i class="bi bi-tools"></i> オーツーファーニチャー<br>
          <small class="fs-6 text-white-50">椅子製造 工程管理システム</small>
        </h1>
      </div>

      <div class="card shadow">
        <div class="card-header bg-success text-white">
          <h5 class="mb-0"><i class="bi bi-person-plus"></i> 新規アカウント登録申請</h5>
        </div>
        <div class="card-body">

          <?php if ($success): ?>
          <div class="alert alert-success">
            <i class="bi bi-check-circle"></i> <strong>登録申請を受け付けました。</strong><br>
            管理者が承認するまでログインできません。管理者にご連絡ください。
          </div>
          <a href="<?= APP_URL ?>/login.php" class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right"></i> ログインページへ
          </a>

          <?php else: ?>

          <?php if ($errors): ?>
          <div class="alert alert-danger">
            <ul class="mb-0">
              <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <form method="post" action="">
            <div class="mb-3">
              <label class="form-label fw-bold">氏名 <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control"
                     value="<?= h($_POST['name'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold">所属部署</label>
              <select name="department_id" class="form-select">
                <option value="">― 選択してください（任意）―</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>"
                  <?= (($_POST['department_id'] ?? '') == $d['id']) ? 'selected' : '' ?>>
                  <?= h($d['dept_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <hr>
            <div class="mb-3">
              <label class="form-label fw-bold">ログインID <span class="text-danger">*</span></label>
              <input type="text" name="login_id" class="form-control"
                     value="<?= h($_POST['login_id'] ?? '') ?>"
                     pattern="[a-zA-Z0-9_]+" maxlength="50" required>
              <div class="form-text">半角英数字・アンダースコアのみ。例: yamada_taro</div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold">パスワード <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control" minlength="8" required>
              <div class="form-text">8文字以上</div>
            </div>
            <div class="mb-4">
              <label class="form-label fw-bold">パスワード（確認） <span class="text-danger">*</span></label>
              <input type="password" name="password_confirm" class="form-control" minlength="8" required>
            </div>
            <button type="submit" class="btn btn-success btn-lg w-100">
              <i class="bi bi-send"></i> 申請する
            </button>
          </form>

          <hr class="my-3">
          <div class="text-center">
            <a href="<?= APP_URL ?>/login.php" class="text-muted small">
              <i class="bi bi-arrow-left"></i> ログインページへ戻る
            </a>
          </div>
          <?php endif; ?>

        </div>
      </div>

      <p class="text-center text-white-50 small mt-3">
        <?= h(APP_NAME) ?> v<?= APP_VERSION ?>
      </p>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
