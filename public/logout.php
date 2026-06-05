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

doLogout();
header('Location: ' . APP_URL . '/login.php');
exit;
