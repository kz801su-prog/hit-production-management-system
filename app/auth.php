<?php
// =====================================================
// 認証・セッション管理モジュール
// 目的: ログイン・ログアウト・TOTP二段階認証・CSRF保護
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/totp.php';

// =====================================================
// ログイン必須チェック
// セッション状態に応じて適切なページへリダイレクト
// =====================================================
function requireLogin(): void {
    // TOTP 入力待ち状態（パスワードは通過済み、TOTP未検証）
    if (!empty($_SESSION['totp_pending_user_id']) && empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    // 未ログイン
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    // セッションタイムアウト
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            doLogout();
            header('Location: ' . APP_URL . '/login.php?msg=timeout');
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
    // TOTP 未設定 → 設定ページへ誘導（settings.php と logout.php は除外）
    if (!empty($_SESSION['totp_setup_required'])) {
        $page = basename($_SERVER['PHP_SELF'] ?? '');
        if ($page !== 'settings.php' && $page !== 'logout.php') {
            header('Location: ' . APP_URL . '/settings.php?tab=totp&msg=setup_required');
            exit;
        }
    }
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['employee_id']);
}

// =====================================================
// ログイン処理（Step 1: パスワード認証）
// 戻り値:
//   false                         → 認証失敗
//   ['status'=>'ok', ...]         → ログイン完了
//   ['status'=>'totp_required']   → TOTP コード入力待ち
//   ['status'=>'totp_setup']      → TOTP 未設定（設定必須）
// =====================================================
function doLogin(string $loginId, string $password): array|false {
    $user = dbFetchOne(
        "SELECT u.*, e.name AS employee_name, e.employee_code
         FROM users u
         JOIN employees e ON u.employee_id = e.id
         WHERE u.login_id = ?
           AND u.is_active = 1
           AND e.is_active = 1
           AND e.employment_status = 'active'",
        [$loginId]
    );

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $totpRequired = isTotpRequired();
    $totpEnabled  = !empty($user['mfa_secret_encrypted']);

    // TOTP 必須 & ユーザーが TOTP 設定済み → Step 2 へ
    if ($totpRequired && $totpEnabled) {
        session_regenerate_id(true);
        $_SESSION['totp_pending_user_id'] = (int)$user['id'];
        return ['status' => 'totp_required'];
    }

    // TOTP 必須 & ユーザーが TOTP 未設定 → ログインさせて設定を強制
    $setupRequired = $totpRequired && !$totpEnabled;

    _setFullSession($user);
    if ($setupRequired) {
        $_SESSION['totp_setup_required'] = true;
        return ['status' => 'totp_setup'];
    }
    return ['status' => 'ok'] + $user;
}

// =====================================================
// ログイン処理（Step 2: TOTP 検証）
// =====================================================
function doTotpVerify(string $code): bool {
    $pendingId = $_SESSION['totp_pending_user_id'] ?? 0;
    if (!$pendingId) return false;

    $user = dbFetchOne(
        "SELECT u.*, e.name AS employee_name, e.employee_code
         FROM users u JOIN employees e ON u.employee_id = e.id
         WHERE u.id = ? AND u.is_active = 1",
        [$pendingId]
    );
    if (!$user) return false;

    $secret = totpDecrypt($user['mfa_secret_encrypted'] ?? '');
    if (!$secret || !totpVerify($secret, $code)) return false;

    unset($_SESSION['totp_pending_user_id']);
    _setFullSession($user);
    $_SESSION['totp_verified'] = true;
    return true;
}

// セッションに全ユーザー情報をセット（内部用）
function _setFullSession(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['employee_id']   = (int)$user['employee_id'];
    $_SESSION['employee_name'] = $user['employee_name'];
    $_SESSION['login_id']      = $user['login_id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['mfa_enabled']   = !empty($user['mfa_secret_encrypted']);
    $_SESSION['last_activity'] = time();
    dbExecute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    if (function_exists('auditLog')) {
        auditLog('login', 'users', (int)$user['id']);
    }
}

// =====================================================
// ログアウト処理
// =====================================================
function doLogout(): void {
    if (isLoggedIn() && function_exists('auditLog')) {
        auditLog('logout', 'users', $_SESSION['user_id'] ?? null);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// =====================================================
// 現在のログインユーザー情報
// =====================================================
function getCurrentUser(): array {
    return [
        'id'          => $_SESSION['user_id']       ?? 0,
        'employee_id' => $_SESSION['employee_id']   ?? 0,
        'name'        => $_SESSION['employee_name'] ?? '',
        'login_id'    => $_SESSION['login_id']      ?? '',
        'role'        => $_SESSION['role']           ?? 'worker',
        'mfa_enabled' => $_SESSION['mfa_enabled']   ?? false,
    ];
}

// =====================================================
// CSRF 保護
// =====================================================
function csrfField(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $token = htmlspecialchars($_SESSION['csrf_token']);
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function verifyCsrf(): void {
    $posted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $posted)) {
        http_response_code(403);
        die('<h1>CSRF検証エラー。操作をやり直してください。</h1>');
    }
}

// =====================================================
// 権限名の日本語変換
// =====================================================
function roleLabel(string $role): string {
    return match($role) {
        'president'       => '社長',
        'admin'           => '管理者',
        'factory_manager' => '工場長',
        'process_leader'  => '工程リーダー',
        'worker'          => '作業員',
        default           => $role,
    };
}
