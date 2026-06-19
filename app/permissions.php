<?php
// =====================================================
// 権限管理モジュール
// 目的: ロールベースのアクセス制御（RBAC）
// 接続テーブル: users（roleカラム）
// 呼び出し元: 各publicページ・サービス
// 権限レベル: president > admin > factory_manager > process_leader > worker
// =====================================================

// 権限レベル定義（数値が高いほど上位）
const ROLE_LEVELS = [
    'president'       => 50,
    'admin'           => 40,
    'factory_manager' => 30,
    'process_leader'  => 20,
    'worker'          => 10,
];

/**
 * 現在ユーザーが指定ロール以上の権限を持つか確認
 * 不足している場合はエラーページを表示して終了
 */
function requireRole(string $minRole): void {
    $user = getCurrentUser();
    if (!hasRole($minRole, $user['role'])) {
        http_response_code(403);
        require __DIR__ . '/../public/parts/403.php';
        exit;
    }
}

/**
 * ロール比較：$userRole が $minRole 以上かどうか
 */
function hasRole(string $minRole, string $userRole = ''): bool {
    if (empty($userRole)) {
        $user = getCurrentUser();
        $userRole = $user['role'];
    }
    $minLevel  = ROLE_LEVELS[$minRole]  ?? 0;
    $userLevel = ROLE_LEVELS[$userRole] ?? 0;
    return $userLevel >= $minLevel;
}

/**
 * 管理者以上かどうか
 */
function isAdmin(): bool {
    return hasRole('admin');
}

/**
 * 社長のみかどうか
 */
function isPresident(): bool {
    $user = getCurrentUser();
    return $user['role'] === 'president';
}

/**
 * 社長または管理者かどうか（コスト設定など機密情報の閲覧に使用）
 */
function isPresidentOrAdmin(): bool {
    $user = getCurrentUser();
    return in_array($user['role'], ['president', 'admin'], true);
}

/**
 * 工場長以上かどうか
 */
function isManager(): bool {
    return hasRole('factory_manager');
}

/**
 * 工程リーダー以上かどうか
 */
function isLeader(): bool {
    return hasRole('process_leader');
}

/**
 * 特定のユーザーID自身か管理者以上かどうか
 * （自分のデータのみ編集可能などに使う）
 */
function isSelfOrAdmin(int $targetUserId): bool {
    $user = getCurrentUser();
    return $user['id'] === $targetUserId || isAdmin();
}

/**
 * 操作ボタンのHTMLを権限に応じて返す（権限不足なら空文字）
 * @param string $minRole 最低必要権限
 * @param string $html    表示するHTML
 */
function showIfRole(string $minRole, string $html): string {
    return hasRole($minRole) ? $html : '';
}
