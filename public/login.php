<?php
// =====================================================
// ログインページ（二段階認証対応）
// Step 1: ログインID + パスワード
// Step 2: Authenticator 6桁コード（TOTP必須時）
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$error   = '';
$msg     = '';
$step    = 1; // 1=パスワード入力, 2=TOTP入力

if (($_GET['msg'] ?? '') === 'timeout') {
    $msg = 'セッションがタイムアウトしました。再度ログインしてください。';
}

// TOTP 入力待ち状態なら Step 2 を表示
if (!empty($_SESSION['totp_pending_user_id'])) {
    $step = 2;
}

// =====================================================
// POST 処理
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postStep = (int)($_POST['step'] ?? 1);

    // --- Step 1: パスワード認証 ---
    if ($postStep === 1) {
        $loginId  = trim($_POST['login_id']  ?? '');
        $password = trim($_POST['password']  ?? '');

        if (!$loginId || !$password) {
            $error = 'ログインIDとパスワードを入力してください。';
        } else {
            $result = doLogin($loginId, $password);
            if ($result === false) {
                $error = 'ログインIDまたはパスワードが正しくありません。';
            } elseif ($result['status'] === 'totp_required') {
                $step = 2;
            } elseif ($result['status'] === 'totp_setup') {
                // ログイン完了（TOTP設定が必要だが強制リダイレクトは requireLogin が行う）
                header('Location: ' . APP_URL . '/settings.php?tab=totp&msg=setup_required');
                exit;
            } else {
                header('Location: ' . APP_URL . '/dashboard.php');
                exit;
            }
        }
    }

    // --- Step 2: TOTP 認証 ---
    if ($postStep === 2) {
        $code = preg_replace('/\s+/', '', $_POST['totp_code'] ?? '');
        if (doTotpVerify($code)) {
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        } else {
            $step  = 2;
            $error = '認証コードが正しくありません。Authenticator アプリの現在のコードを入力してください。';
        }
    }
}

// 社長の言葉をランダムに1件取得
try {
    $word = dbFetchOne("SELECT * FROM president_words WHERE is_active = 1 ORDER BY RAND() LIMIT 1");
} catch (Exception $e) {
    $word = null;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ログイン - <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="manifest" href="<?= APP_URL ?>/manifest.webmanifest">
<link rel="icon" href="<?= APP_URL ?>/assets/icons/pwa-icon.svg" type="image/svg+xml">
<meta name="theme-color" content="#0d6efd">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(APP_NAME) ?>">
</head>
<body class="bg-dark">
<div class="container">
  <div class="row justify-content-center min-vh-100 align-items-center">
    <div class="col-md-5">

      <!-- タイトル -->
      <div class="text-center mb-4">
        <img src="<?= APP_URL ?>/assets/images/logo.png" alt="OTU-FURNITURE" style="max-width:260px;width:100%;height:auto;" class="mb-2">
        <p class="text-white-50 small mb-0">椅子製造 工程管理システム</p>
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

      <!-- ログインカード -->
      <div class="card shadow">

        <?php if ($step === 1): ?>
        <!-- Step 1: パスワード認証 -->
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0"><i class="bi bi-person-lock"></i> ログイン</h5>
        </div>
        <div class="card-body">
          <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
          <?php if ($msg):   ?><div class="alert alert-warning"><?= h($msg) ?></div><?php endif; ?>
          <form method="post" action="">
            <input type="hidden" name="step" value="1">
            <div class="mb-3">
              <label class="form-label fw-bold">ログインID</label>
              <input type="text" name="login_id" class="form-control form-control-lg"
                     value="<?= h($_POST['login_id'] ?? '') ?>"
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
          <hr class="my-3">
          <div class="text-center">
            <a href="<?= APP_URL ?>/register.php" class="text-muted small">
              <i class="bi bi-person-plus"></i> 新規アカウント登録申請
            </a>
          </div>
        </div>

        <?php else: ?>
        <!-- Step 2: TOTP 認証 -->
        <div class="card-header bg-warning text-dark">
          <h5 class="mb-0"><i class="bi bi-shield-lock"></i> 二段階認証</h5>
        </div>
        <div class="card-body">
          <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
          <p class="text-muted small mb-3">
            Authenticator アプリ（Google Authenticator / Microsoft Authenticator 等）に表示されている
            <strong>6桁のコード</strong>を入力してください。
          </p>
          <form method="post" action="">
            <input type="hidden" name="step" value="2">
            <div class="mb-4">
              <label class="form-label fw-bold">認証コード（6桁）</label>
              <input type="text" name="totp_code" class="form-control form-control-lg text-center"
                     maxlength="6" pattern="\d{6}" inputmode="numeric"
                     autocomplete="one-time-code" required autofocus
                     placeholder="000000">
            </div>
            <button type="submit" class="btn btn-warning btn-lg w-100">
              <i class="bi bi-check-circle"></i> 認証
            </button>
          </form>
          <hr class="my-3">
          <div class="text-center">
            <a href="<?= APP_URL ?>/login.php" onclick="
              fetch('<?= APP_URL ?>/logout.php', {method:'POST',body:new URLSearchParams({csrf_token:'', cancel:'1'})});
              " class="text-muted small">
              <i class="bi bi-arrow-left"></i> ログインに戻る
            </a>
          </div>
        </div>
        <?php endif; ?>

      </div>

      <p class="text-center text-white-50 small mt-3">
        <?= h(APP_NAME) ?> v<?= APP_VERSION ?>
      </p>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?= APP_URL ?>/sw.js');
  });
}
</script>
<script>
// TOTP コード入力: 6桁入力完了で自動送信
document.addEventListener('DOMContentLoaded', () => {
    const totpInput = document.querySelector('[name="totp_code"]');
    if (totpInput) {
        totpInput.addEventListener('input', function() {
            if (this.value.length === 6 && /^\d{6}$/.test(this.value)) {
                this.closest('form').submit();
            }
        });
    }
});
</script>
</body>
</html>
