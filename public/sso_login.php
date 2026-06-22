<?php
// W-Central SSO Portal からのトークン検証→セッション確立
// SSO secret は config/sso.php から読み込む（なければデフォルト値を使用）

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';

$ssoConfigPath = __DIR__ . '/../config/sso.php';
if (is_file($ssoConfigPath)) {
    require_once $ssoConfigPath;
}
if (!defined('OTSU_SSO_SECRET')) {
    define('OTSU_SSO_SECRET', 'kz801xs_sso_2026_06_SincolN_5f7b9a2c41d84e63b7a94a6c1e2fdd10');
}

function _otsu_sso_verify(string $token): ?array
{
    if (!str_contains($token, '.')) return null;
    [$body, $sig] = explode('.', $token, 2);
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, OTSU_SSO_SECRET, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $sig)) return null;
    $pad = strlen($body) % 4;
    if ($pad) $body .= str_repeat('=', 4 - $pad);
    $json = base64_decode(strtr($body, '-_', '+/'), true);
    if ($json === false) return null;
    $payload = json_decode($json, true);
    if (!is_array($payload)) return null;
    $now = time();
    if (!empty($payload['exp']) && $payload['exp'] < $now) return null;
    if (!empty($payload['iat']) && $payload['iat'] > $now + 60) return null;
    return $payload;
}

$token   = trim($_GET['sso_token'] ?? '');
$payload = $token !== '' ? _otsu_sso_verify($token) : null;

if (!$payload) {
    header('Location: ' . APP_URL . '/login.php?msg=sso_failed&local=1');
    exit;
}

$userPayload = is_array($payload['user'] ?? null) ? $payload['user'] : [];
$candidates  = array_values(array_filter(array_unique([
    trim($userPayload['employee_code'] ?? ''),
    trim($userPayload['login_id'] ?? ''),
    trim($userPayload['employee_no'] ?? ''),
])));

// sso_id カラムで検索（最優先）
$user = null;
foreach ($candidates as $c) {
    $found = dbFetchOne(
        "SELECT u.*, e.name AS employee_name, e.employee_code
           FROM users u
           JOIN employees e ON u.employee_id = e.id
          WHERE u.sso_id = ?
            AND u.is_active = 1
            AND e.is_active = 1
            AND e.employment_status = 'active'",
        [$c]
    );
    if ($found) { $user = $found; break; }
}

// login_id でフォールバック検索
if (!$user) {
    foreach ($candidates as $c) {
        $found = dbFetchOne(
            "SELECT u.*, e.name AS employee_name, e.employee_code
               FROM users u
               JOIN employees e ON u.employee_id = e.id
              WHERE u.login_id = ?
                AND u.is_active = 1
                AND e.is_active = 1
                AND e.employment_status = 'active'",
            [$c]
        );
        if ($found) { $user = $found; break; }
    }
}

if (!$user) {
    header('Location: ' . APP_URL . '/login.php?msg=sso_no_user&local=1');
    exit;
}

// セッション確立（_setFullSession 相当、TOTP 強制をスキップ）
session_regenerate_id(true);
$_SESSION['user_id']       = (int)$user['id'];
$_SESSION['employee_id']   = (int)$user['employee_id'];
$_SESSION['employee_name'] = $user['employee_name'];
$_SESSION['login_id']      = $user['login_id'];
$_SESSION['role']          = $user['role'];
$_SESSION['mfa_enabled']   = !empty($user['mfa_secret_encrypted']);
$_SESSION['last_activity'] = time();
$_SESSION['sso_login']     = true;
unset($_SESSION['totp_setup_required'], $_SESSION['totp_pending_user_id']);

dbExecute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);

header('Location: ' . APP_URL . '/dashboard.php');
exit;
