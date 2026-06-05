<?php
// =====================================================
// 社長の言葉管理
// 目的: 社長の言葉の一覧・登録・削除・CSV取込
// 接続テーブル: president_words
// 権限: admin以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('admin');
$pageTitle = '社長の言葉管理';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    if ($postAction === 'add') {
        $no   = postInt('display_no');
        $name = postStr('speaker_name', '社長');
        $msg  = postStr('message');
        if (!$msg) {
            setFlash('発言内容は必須です。', 'danger');
        } else {
            // 番号重複時は次の空き番号を使う
            while (dbFetchOne("SELECT id FROM president_words WHERE display_no = ?", [$no])) {
                $no++;
            }
            dbExecute(
                "INSERT INTO president_words (display_no, speaker_name, message) VALUES (?,?,?)",
                [$no, $name, $msg]
            );
            setFlash("言葉を番号 {$no} で登録しました。");
        }
    }

    if ($postAction === 'delete') {
        $delId = postInt('word_id');
        dbExecute("DELETE FROM president_words WHERE id = ?", [$delId]);
        setFlash('削除しました。', 'warning');
    }

    if ($postAction === 'csv_import' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $fp   = fopen($file['tmp_name'], 'r');
            $line = 0;
            $imported = 0;
            while (($row = fgetcsv($fp)) !== false) {
                $line++;
                if ($line === 1 && !is_numeric($row[0])) continue; // ヘッダスキップ
                if (count($row) < 3) continue;
                $no   = (int)$row[0];
                $name = trim($row[1]);
                $msg  = trim($row[2]);
                if (!$msg) continue;
                // 番号重複回避
                while (dbFetchOne("SELECT id FROM president_words WHERE display_no = ?", [$no])) {
                    $no++;
                }
                dbExecute(
                    "INSERT INTO president_words (display_no, speaker_name, message) VALUES (?,?,?)",
                    [$no, $name ?: '社長', $msg]
                );
                $imported++;
            }
            fclose($fp);
            setFlash("{$imported}件の言葉をCSVから取り込みました。");
        } else {
            setFlash('CSVファイルのアップロードに失敗しました。', 'danger');
        }
    }

    header('Location: ' . APP_URL . '/words.php');
    exit;
}

$words = dbFetchAll("SELECT * FROM president_words ORDER BY display_no");
$nextNo = ($words ? max(array_column($words, 'display_no')) : 0) + 1;

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col"><h2><i class="bi bi-chat-quote"></i> 社長の言葉管理</h2></div>
</div>

<div class="row g-3">
  <!-- 一覧 -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header">登録済み（<?= count($words) ?>件）</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-dark">
            <tr><th style="width:60px">番号</th><th>発言者</th><th>内容</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($words as $w): ?>
            <tr>
              <td class="text-center"><?= $w['display_no'] ?></td>
              <td><?= h($w['speaker_name']) ?></td>
              <td><?= h($w['message']) ?></td>
              <td>
                <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="word_id" value="<?= $w['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">削除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <!-- 新規登録 -->
    <div class="card mb-3">
      <div class="card-header bg-primary text-white">言葉を追加</div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add">
          <div class="mb-2">
            <label class="form-label">番号</label>
            <input type="number" name="display_no" class="form-control form-control-sm"
                   value="<?= $nextNo ?>" min="1">
            <div class="form-text">重複時は自動で次の番号を使います</div>
          </div>
          <div class="mb-2">
            <label class="form-label">発言者</label>
            <input type="text" name="speaker_name" class="form-control form-control-sm" value="社長">
          </div>
          <div class="mb-2">
            <label class="form-label">発言内容 <span class="text-danger">*</span></label>
            <textarea name="message" class="form-control form-control-sm" rows="3" required></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm w-100">追加</button>
        </form>
      </div>
    </div>

    <!-- CSV取込 -->
    <div class="card">
      <div class="card-header bg-success text-white">CSV取込</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="csv_import">
          <div class="mb-2">
            <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv" required>
          </div>
          <div class="form-text mb-2">
            形式: <code>番号, 発言者, 発言内容</code><br>
            1行目がヘッダの場合は自動スキップ
          </div>
          <button type="submit" class="btn btn-success btn-sm w-100">CSVを取込</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
