<?php
// =====================================================
// 一時診断ページ ★確認後すぐ削除してください★
// =====================================================
require_once __DIR__ . '/../config/config.php';

$results = [];

// 1. DB接続テスト
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $results['DB接続'] = '✅ 成功';
} catch (PDOException $e) {
    $results['DB接続'] = '❌ 失敗: ' . $e->getMessage();
    $pdo = null;
}

// 2. usersテーブルの admin ユーザー確認
if ($pdo) {
    try {
        $row = $pdo->prepare("SELECT id, login_id, LEFT(password_hash,20) as hash_head, is_active, role FROM users WHERE login_id='admin'");
        $row->execute();
        $u = $row->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $results['adminユーザー'] = '✅ 存在 (is_active=' . $u['is_active'] . ', role=' . $u['role'] . ')';
            $results['ハッシュ先頭'] = $u['hash_head'] . '...';
        } else {
            $results['adminユーザー'] = '❌ 見つからない（DBにユーザーが未登録）';
        }
    } catch (PDOException $e) {
        $results['adminユーザー'] = '❌ クエリ失敗: ' . $e->getMessage();
        $u = null;
    }

    // 3. password_verify テスト
    try {
        $row2 = $pdo->prepare("SELECT password_hash FROM users WHERE login_id='admin'");
        $row2->execute();
        $h = $row2->fetchColumn();
        if ($h) {
            $results['password123 で検証'] = password_verify('password123', $h) ? '✅ 一致（ログインできるはず）' : '❌ 不一致（fix_passwords.sql の実行が必要）';
        }
    } catch (PDOException $e) {
        $results['password検証'] = '❌ 失敗: ' . $e->getMessage();
    }

    // 4. system_settingsテーブル確認
    try {
        $pdo->query("SELECT 1 FROM system_settings LIMIT 1");
        $results['system_settingsテーブル'] = '✅ 存在';
    } catch (PDOException $e) {
        $results['system_settingsテーブル'] = '❌ 存在しない → fix_passwords.sql を実行してください';
    }
}

// 5. APP_URL 確認
$results['APP_URL'] = APP_URL;
$results['PHPバージョン'] = PHP_VERSION;
?>
<!DOCTYPE html>
<html lang="ja">
<head><meta charset="UTF-8"><title>診断</title>
<style>body{font-family:monospace;padding:20px;background:#1a1a1a;color:#eee;}
table{border-collapse:collapse;width:100%;}td,th{padding:8px 12px;border:1px solid #444;}
th{background:#333;text-align:left;}</style>
</head>
<body>
<h2>⚠️ 診断ページ（確認後すぐ削除してください）</h2>
<table>
<tr><th>項目</th><th>結果</th></tr>
<?php foreach ($results as $k => $v): ?>
<tr><td><?= htmlspecialchars($k) ?></td><td><?= htmlspecialchars($v) ?></td></tr>
<?php endforeach; ?>
</table>
<hr>
<h3>修正SQLコピー用</h3>
<pre style="background:#333;padding:12px;">UPDATE users
SET password_hash = '$2y$12$.btR48KIN3s.s.MkqcsGTuJSTKfv1aR79N0nySycKdRizLQbdttkO'
WHERE login_id IN ('president', 'admin', 'yamada', 'sato', 'suzuki', 'ito');</pre>
</body>
</html>
