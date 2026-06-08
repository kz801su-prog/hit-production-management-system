<?php
// =====================================================
// ログアウト処理
// セッションを破棄してログインページへリダイレクト
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/functions.php';

// TOTP 認証待ち状態でもセッション全クリア
if (!empty($_SESSION['totp_pending_user_id'])) {
    $_SESSION = [];
    session_destroy();
} else {
    doLogout();
}
header('Location: ' . APP_URL . '/login.php');
exit;
