<?php
// =====================================================
// ユーザー設定（パスワード変更 / Authenticator設定）
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
$pageTitle   = 'マイ設定';
$currentUser = getCurrentUser();
$activeTab   = $_GET['tab'] ?? 'password';

// TOTP 設定必須メッセージ
$setupRequired = !empty($_SESSION['totp_setup_required']);

// =====================================================
// POST 処理
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    // --- パスワード変更 ---
    if ($postAction === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $row = dbFetchOne("SELECT password_hash FROM users WHERE id = ?", [$currentUser['id']]);
        if (!password_verify($current, $row['password_hash'] ?? '')) {
            setFlash('現在のパスワードが正しくありません。', 'danger');
        } elseif (strlen($new) < 8) {
            setFlash('新しいパスワードは8文字以上で入力してください。', 'danger');
        } elseif ($new !== $confirm) {
            setFlash('新しいパスワードが一致しません。', 'danger');
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            dbExecute("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $currentUser['id']]);
            auditLog('change_password', 'users', $currentUser['id']);
            setFlash('パスワードを変更しました。');
        }
        header('Location: ' . APP_URL . '/settings.php?tab=password');
        exit;
    }

    // --- TOTP 設定開始: シークレット生成 ---
    if ($postAction === 'totp_start_setup') {
        $secret = totpGenerateSecret();
        $_SESSION['totp_setup_secret'] = $secret;
        header('Location: ' . APP_URL . '/settings.php?tab=totp&totp_step=verify');
        exit;
    }

    // --- TOTP 設定完了: コード検証して保存 ---
    if ($postAction === 'totp_save') {
        $code   = preg_replace('/\s+/', '', $_POST['totp_code'] ?? '');
        $secret = $_SESSION['totp_setup_secret'] ?? '';
        if (!$secret) {
            setFlash('セッションが切れました。もう一度やり直してください。', 'danger');
            header('Location: ' . APP_URL . '/settings.php?tab=totp');
            exit;
        }
        if (!totpVerify($secret, $code)) {
            setFlash('認証コードが正しくありません。Authenticator の現在のコードを入力してください。', 'danger');
            header('Location: ' . APP_URL . '/settings.php?tab=totp&totp_step=verify');
            exit;
        }
        // 検証成功 → 保存
        $encrypted = totpEncrypt($secret);
        dbExecute("UPDATE users SET mfa_secret_encrypted = ? WHERE id = ?", [$encrypted, $currentUser['id']]);
        unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_required']);
        $_SESSION['mfa_enabled'] = true;
        auditLog('totp_enable', 'users', $currentUser['id']);
        setFlash('Authenticator の設定が完了しました。');
        header('Location: ' . APP_URL . '/settings.php?tab=totp');
        exit;
    }

    // --- TOTP 無効化 ---
    if ($postAction === 'totp_disable') {
        $code = preg_replace('/\s+/', '', $_POST['totp_code'] ?? '');
        $sec  = getTotpSecret($currentUser['id']);
        if (!$sec || !totpVerify($sec, $code)) {
            setFlash('認証コードが正しくありません。', 'danger');
            header('Location: ' . APP_URL . '/settings.php?tab=totp');
            exit;
        }
        dbExecute("UPDATE users SET mfa_secret_encrypted = NULL WHERE id = ?", [$currentUser['id']]);
        $_SESSION['mfa_enabled']   = false;
        $_SESSION['totp_verified'] = false;
        auditLog('totp_disable', 'users', $currentUser['id']);
        setFlash('Authenticator 設定を解除しました。');
        header('Location: ' . APP_URL . '/settings.php?tab=totp');
        exit;
    }
}

// 現在のTOTP設定状態
$totpEnabled  = isTotpEnabled($currentUser['id']);
$totpRequired = isTotpRequired();
$totpStep     = $_GET['totp_step'] ?? '';  // 'verify' = QRコード表示・検証ステップ
$setupSecret  = $_SESSION['totp_setup_secret'] ?? null;

require __DIR__ . '/parts/header.php';
?>

<?php if ($setupRequired): ?>
<div class="alert alert-warning border-warning">
  <i class="bi bi-shield-exclamation"></i>
  <strong>Authenticator の設定が必要です。</strong>
  システムポリシーにより二段階認証が必須となっています。下の「Authenticator タブ」から設定してください。
</div>
<?php endif; ?>

<h2 class="mb-4"><i class="bi bi-gear-fill"></i> マイ設定</h2>

<!-- タブ -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'password' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/settings.php?tab=password">
      <i class="bi bi-key"></i> パスワード変更
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'totp' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/settings.php?tab=totp">
      <i class="bi bi-shield-lock"></i> Authenticator設定
      <?php if ($totpEnabled): ?>
        <span class="badge bg-success ms-1">設定済</span>
      <?php elseif ($totpRequired): ?>
        <span class="badge bg-danger ms-1">要設定</span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<!-- ===== パスワード変更タブ ===== -->
<?php if ($activeTab === 'password'): ?>
<div class="row">
  <div class="col-md-5">
    <div class="card">
      <div class="card-header fw-bold"><i class="bi bi-key"></i> パスワード変更</div>
      <div class="card-body">
        <?= getFlashHtml() ?>
        <form method="post" action="">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="mb-3">
            <label class="form-label">現在のパスワード</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">新しいパスワード（8文字以上）</label>
            <input type="password" name="new_password" class="form-control" minlength="8" required>
          </div>
          <div class="mb-4">
            <label class="form-label">新しいパスワード（確認）</label>
            <input type="password" name="confirm_password" class="form-control" minlength="8" required>
          </div>
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-check-circle"></i> パスワードを変更
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===== Authenticator 設定タブ ===== -->
<?php if ($activeTab === 'totp'): ?>
<?= getFlashHtml() ?>

<div class="row">
  <div class="col-md-6">

    <?php if ($totpStep === 'verify' && $setupSecret): ?>
    <!-- TOTP 設定ステップ: QRコード表示 & 検証 -->
    <div class="card border-warning">
      <div class="card-header bg-warning text-dark fw-bold">
        <i class="bi bi-qr-code"></i> Authenticator の登録
      </div>
      <div class="card-body">
        <p>以下の手順で Authenticator アプリに登録してください。</p>
        <ol class="mb-3">
          <li>スマートフォンで <strong>Google Authenticator</strong> または <strong>Microsoft Authenticator</strong> を開く</li>
          <li>「+」ボタンで「QRコードをスキャン」を選択</li>
          <li>下のQRコードをスキャン（またはキーを手動入力）</li>
          <li>表示された6桁のコードを下に入力して確認</li>
        </ol>

        <!-- QRコード表示エリア -->
        <div class="text-center mb-3">
          <div id="qrcode" class="d-inline-block p-2 bg-white rounded"></div>
        </div>

        <div class="alert alert-secondary small">
          <strong>手動入力キー:</strong><br>
          <code class="user-select-all fs-6"><?= h($setupSecret) ?></code>
        </div>

        <form method="post" action="">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="totp_save">
          <div class="mb-3">
            <label class="form-label fw-bold">認証コード（6桁）</label>
            <input type="text" name="totp_code" class="form-control form-control-lg text-center"
                   maxlength="6" pattern="\d{6}" inputmode="numeric"
                   autocomplete="one-time-code" required placeholder="000000" autofocus>
          </div>
          <button type="submit" class="btn btn-success w-100">
            <i class="bi bi-check-circle"></i> 設定を完了する
          </button>
        </form>
        <div class="mt-2">
          <a href="<?= APP_URL ?>/settings.php?tab=totp" class="btn btn-outline-secondary btn-sm w-100">
            キャンセル
          </a>
        </div>
      </div>
    </div>

    <?php elseif ($totpEnabled): ?>
    <!-- TOTP 設定済み -->
    <div class="card border-success">
      <div class="card-header bg-success text-white fw-bold">
        <i class="bi bi-shield-check"></i> Authenticator 設定済み
      </div>
      <div class="card-body">
        <p class="text-success"><i class="bi bi-check-circle-fill"></i> 二段階認証が有効です。</p>
        <hr>
        <p class="small text-muted">設定を解除する場合は、現在の認証コードを入力してください。</p>
        <form method="post" action=""
              onsubmit="return confirm('二段階認証を無効にします。本当によろしいですか？');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="totp_disable">
          <div class="mb-3">
            <label class="form-label">認証コード（6桁）</label>
            <input type="text" name="totp_code" class="form-control text-center"
                   maxlength="6" pattern="\d{6}" inputmode="numeric"
                   required placeholder="000000">
          </div>
          <button type="submit" class="btn btn-outline-danger w-100">
            <i class="bi bi-shield-x"></i> Authenticator 設定を解除する
          </button>
        </form>
      </div>
    </div>

    <?php else: ?>
    <!-- TOTP 未設定 -->
    <div class="card <?= $totpRequired ? 'border-danger' : '' ?>">
      <div class="card-header <?= $totpRequired ? 'bg-danger text-white' : '' ?> fw-bold">
        <i class="bi bi-shield"></i> Authenticator 未設定
        <?php if ($totpRequired): ?><span class="ms-2 badge bg-light text-danger">必須</span><?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($totpRequired): ?>
        <div class="alert alert-danger">
          <i class="bi bi-exclamation-triangle"></i>
          システムポリシーにより <strong>Authenticator の設定が必須</strong> です。
        </div>
        <?php else: ?>
        <p class="text-muted">
          Authenticator アプリと連携することで、パスワード漏洩時のリスクを大幅に軽減できます。
        </p>
        <?php endif; ?>
        <form method="post" action="">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="totp_start_setup">
          <button type="submit" class="btn btn-<?= $totpRequired ? 'danger' : 'primary' ?> w-100">
            <i class="bi bi-qr-code-scan"></i> Authenticator を設定する
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>
  <div class="col-md-6">
    <div class="card bg-light border-0">
      <div class="card-body">
        <h6><i class="bi bi-info-circle"></i> 対応アプリ</h6>
        <ul class="small">
          <li>Google Authenticator（iOS / Android）</li>
          <li>Microsoft Authenticator（iOS / Android）</li>
          <li>Authy（iOS / Android / PC）</li>
        </ul>
        <h6 class="mt-3"><i class="bi bi-exclamation-circle"></i> 注意事項</h6>
        <ul class="small text-muted">
          <li>スマートフォンを機種変更・紛失した場合は管理者にご連絡ください</li>
          <li>設定を解除するには現在の認証コードが必要です</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php if ($totpStep === 'verify' && $setupSecret): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById('qrcode'), {
    text: <?= json_encode(totpOtpauthUrl($setupSecret, $currentUser['login_id'], APP_NAME)) ?>,
    width: 200,
    height: 200,
    correctLevel: QRCode.CorrectLevel.M
});
</script>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/parts/footer.php'; ?>
