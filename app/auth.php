<?php
// =====================================================
// 認証・セッション管理モジュール
// 目的: ログイン・ログアウト・セッション管理・CSRF保護
// 接続テーブル: users, employees, audit_logs
// 呼び出し元: すべてのpublicページ（requireLogin経由）
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

/**
 * ログイン必須チェック
 * 未ログインまたはタイムアウトの場合はログインページへリダイレクト
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    // セッションタイムアウトチェック
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            doLogout();
            header('Location: ' . APP_URL . '/login.php?msg=timeout');
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
}

/**
 * ログイン状態確認
 */
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['employee_id']);
}

/**
 * ログイン処理
 * @param string $loginId ログインID
 * @param string $password 平文パスワード
 * @return array|false 成功時はユーザー情報配列、失敗時はfalse
 */
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

    // セッションIDを再生成してセッション固定攻撃を防ぐ
    session_regenerate_id(true);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['employee_id']   = $user['employee_id'];
    $_SESSION['employee_name'] = $user['employee_name'];
    $_SESSION['login_id']      = $user['login_id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['last_activity'] = time();

    // 最終ログイン日時を更新
    dbExecute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);

    // 操作ログ記録（auth.phpからlogger.phpへ依存するため後で読み込む）
    if (function_exists('auditLog')) {
        auditLog('login', 'users', $user['id']);
    }

    return $user;
}

/**
 * ログアウト処理
 */
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

/**
 * 現在ログイン中のユーザー情報を返す
 */
function getCurrentUser(): array {
    return [
        'id'            => $_SESSION['user_id']       ?? 0,
        'employee_id'   => $_SESSION['employee_id']   ?? 0,
        'name'          => $_SESSION['employee_name'] ?? '',
        'login_id'      => $_SESSION['login_id']      ?? '',
        'role'          => $_SESSION['role']           ?? 'worker',
    ];
}

/**
 * CSRFトークンを生成してhiddenフィールドHTMLを返す
 */
function csrfField(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $token = htmlspecialchars($_SESSION['csrf_token']);
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * CSRFトークンを検証する（POSTハンドラ先頭で必ず呼ぶ）
 */
function verifyCsrf(): void {
    $posted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $posted)) {
        http_response_code(403);
        die('<h1>CSRF検証エラー。操作をやり直してください。</h1>');
    }
}

/**
 * 権限名を日本語に変換
 */
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
