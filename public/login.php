<?php
// =====================================================
// ログインページ
// 目的: ユーザー認証を行い、セッションを開始する
// 接続テーブル: users, employees
// 呼び出し先: dashboard.php
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

$error = '';
$msg   = '';

if ($_GET['msg'] ?? '' === 'timeout') {
    $msg = 'セッションがタイムアウトしました。再度ログインしてください。';
}

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId  = postStr('login_id');
    $password = postStr('password');

    if (!$loginId || !$password) {
        $error = 'ログインIDとパスワードを入力してください。';
    } else {
        $user = doLogin($loginId, $password);
        if ($user) {
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        } else {
            $error = 'ログインIDまたはパスワードが正しくありません。';
        }
    }
}

// 社長の言葉をランダムに1件取得（ログイン画面に表示）
$word = dbFetchOne("SELECT * FROM president_words WHERE is_active = 1 ORDER BY RAND() LIMIT 1");
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ログイン - <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="bg-dark">
<div class="container">
  <div class="row justify-content-center min-vh-100 align-items-center">
    <div class="col-md-5">

      <!-- アプリタイトル -->
      <div class="text-center mb-4">
        <h1 class="text-white fw-bold fs-4">
          <i class="bi bi-tools"></i> オーツーファーニチャー<br>
          <small class="fs-6 text-white-50">椅子製造 工程管理システム</small>
        </h1>
      </div>

      <!-- 社長の言葉 -->
      <?php if ($word): ?>
      <div class="card bg-secondary text-white mb-4 border-0">
        <div class="card-body text-center py-2">
          <small class="text-white-50"><?= h($word['speaker_name']) ?>の言葉</small>
          <p class="mb-0 fst-italic">"<?= h($word['message']) ?>"</p>
        </div>
      </div>
      <?php endif; ?>

      <!-- ログインフォーム -->
      <div class="card shadow">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0"><i class="bi bi-person-lock"></i> ログイン</h5>
        </div>
        <div class="card-body">
          <?php if ($error): ?>
          <div class="alert alert-danger"><?= h($error) ?></div>
          <?php endif; ?>
          <?php if ($msg): ?>
          <div class="alert alert-warning"><?= h($msg) ?></div>
          <?php endif; ?>

          <form method="post" action="">
            <div class="mb-3">
              <label class="form-label fw-bold">ログインID</label>
              <input type="text" name="login_id" class="form-control form-control-lg"
                     value="<?= h(postStr('login_id')) ?>"
                     autocomplete="username" required autofocus>
            </div>
            <div class="mb-4">
              <label class="form-label fw-bold">パスワード</label>
              <input type="password" name="password" class="form-control form-control-lg"
                     autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100">
              <i class="bi bi-box-arrow-in-right"></i> ログイン
            </button>
          </form>
        </div>
      </div>

      <!-- バージョン情報 -->
      <p class="text-center text-white-50 small mt-3">
        <?= h(APP_NAME) ?> v<?= APP_VERSION ?>
      </p>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
