<?php
// =====================================================
// DB接続モジュール
// 目的: PDOシングルトン接続を全ページに提供する
// 接続先: config/config.php のDB設定
// 呼び出し元: すべてのPHPファイル
// 将来改良: 将来複数DBが必要になった場合は引数でDB名を切り替えられるよう拡張する
// =====================================================

require_once __DIR__ . '/../config/config.php';

/**
 * PDOシングルトン接続を返す
 * 同一リクエスト内では同じ接続オブジェクトを使い回す
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[DB接続エラー] ' . $e->getMessage());
        http_response_code(503);
        die('<h1>データベース接続に失敗しました。管理者に連絡してください。</h1>');
    }

    return $pdo;
}

/**
 * SELECT系のユーティリティ: 複数行を取得
 * @param string $sql   プレースホルダ付きSQL
 * @param array  $params バインドパラメータ
 */
function dbFetchAll(string $sql, array $params = []): array {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * SELECT系のユーティリティ: 1行を取得
 */
function dbFetchOne(string $sql, array $params = []): array|false {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

/**
 * INSERT/UPDATE/DELETE ユーティリティ
 * @return string 最後に挿入されたID（SELECTには使わない）
 */
function dbExecute(string $sql, array $params = []): string {
    $pdo = getDB();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $pdo->lastInsertId();
}
