<?php
// =====================================================
// バックアップサービス
// 目的: DBのSQLダンプを生成してファイル保存、古いバックアップを削除
// 接続テーブル: backup_logs
// 呼び出し元: backup.php（手動）、CLI cron（自動）
// 注意: Xserver本番環境ではmysqldumpが使えない場合がある。
//       その場合は SELECT INTO OUTFILE 方式か phpMyAdmin の定期バックアップを使う。
// =====================================================

/**
 * DBバックアップを実行する
 * @return array ['success' => bool, 'filename' => string, 'message' => string]
 */
function runBackup(): array {
    $filename   = 'backup_' . date('YmdHis') . '.sql';
    $filepath   = BACKUP_DIR . $filename;
    $errorMsg   = '';
    $success    = false;

    try {
        // まず BACKUP_DIR の存在確認
        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0755, true);
        }

        // PDOでテーブル一覧を取得してSQLダンプ生成
        $sql = generateSqlDump();
        $written = file_put_contents($filepath, $sql);

        if ($written === false) {
            throw new RuntimeException('バックアップファイルの書き込みに失敗しました。');
        }

        $success = true;

        // 古いバックアップを削除（30日超）
        cleanOldBackups();

    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        error_log('[backup失敗] ' . $errorMsg);
        $filename = '';
    }

    // ログ記録
    dbExecute(
        "INSERT INTO backup_logs (backup_file, status, error_message) VALUES (?, ?, ?)",
        [$filename ?: 'N/A', $success ? 'success' : 'failed', $errorMsg ?: null]
    );

    if (!$success) {
        sendBackupFailureAlert($errorMsg);
    }

    return [
        'success'  => $success,
        'filename' => $filename,
        'message'  => $success ? "バックアップ完了: {$filename}" : "バックアップ失敗: {$errorMsg}",
    ];
}

/**
 * PDOでSQLダンプを生成する（INSERT文形式）
 */
function generateSqlDump(): string {
    $pdo    = getDB();
    $lines  = ["-- Backup generated at " . date('Y-m-d H:i:s') . "\n"];
    $lines[] = "SET FOREIGN_KEY_CHECKS = 0;\n";
    $lines[] = "SET NAMES utf8mb4;\n\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $lines[] = "-- Table: {$table}\n";
        $lines[] = "DROP TABLE IF EXISTS `{$table}`;\n";
        $lines[] = $createStmt['Create Table'] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                $lines[] = "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $lines[] = "\n";
        }
    }

    $lines[] = "SET FOREIGN_KEY_CHECKS = 1;\n";
    return implode('', $lines);
}

/**
 * 保存期間を超えた古いバックアップを削除する
 */
function cleanOldBackups(): void {
    $files = glob(BACKUP_DIR . 'backup_*.sql');
    if (!$files) return;

    $retentionDays = BACKUP_RETENTION_DAYS;
    $cutoff        = time() - $retentionDays * 86400;

    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
        }
    }
}

/**
 * バックアップ失敗メールを送信する
 */
function sendBackupFailureAlert(string $errorMsg): void {
    $subject = '[椅子製造システム] バックアップ失敗通知';
    $body    = "バックアップに失敗しました。\n\n"
             . "日時: " . date('Y-m-d H:i:s') . "\n"
             . "エラー: {$errorMsg}\n\n"
             . "至急ご確認ください。";

    @mail(MAIL_ADMIN, $subject, $body, "From: " . MAIL_FROM);
}

/**
 * バックアップログ一覧を取得
 */
function getBackupLogs(int $limit = 30): array {
    return dbFetchAll(
        "SELECT * FROM backup_logs ORDER BY created_at DESC LIMIT ?",
        [$limit]
    );
}
