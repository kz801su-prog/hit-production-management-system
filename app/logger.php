<?php
// =====================================================
// 操作ログ（監査ログ）モジュール
// 目的: 誰がいつ何を変更したかをaudit_logsに記録
// 接続テーブル: audit_logs
// 呼び出し元: CRUD処理を持つすべてのサービス・ページ
// =====================================================

/**
 * 操作ログを audit_logs テーブルへ記録
 * @param string   $action      操作内容（login/create/update/delete等）
 * @param string   $targetTable 対象テーブル名
 * @param int|null $targetId    対象レコードID
 * @param mixed    $before      変更前データ（配列またはnull）
 * @param mixed    $after       変更後データ（配列またはnull）
 */
function auditLog(
    string $action,
    string $targetTable = '',
    ?int   $targetId    = null,
    mixed  $before      = null,
    mixed  $after       = null
): void {
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';

        dbExecute(
            "INSERT INTO audit_logs
                (user_id, action, target_table, target_id, before_data, after_data, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $userId,
                $action,
                $targetTable ?: null,
                $targetId,
                $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                $after  !== null ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null,
                $ip,
                mb_substr($ua, 0, 500),
            ]
        );
    } catch (Throwable $e) {
        // ログ書き込み失敗はエラーログに記録するだけで処理を継続する
        error_log('[auditLog失敗] ' . $e->getMessage());
    }
}
