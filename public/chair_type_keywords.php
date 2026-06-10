<?php
// =====================================================
// 製品タイプ検索キーワード管理
// 目的: 製品タイプへの検索補助キーワードを登録・削除
// 接続テーブル: chair_type_keywords
// 権限: process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('process_leader');
$pageTitle = '検索キーワード管理';

$chairTypeId = getInt('chair_type_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    if ($postAction === 'add') {
        $keyword = postStr('keyword');
        if ($keyword) {
            try {
                dbExecute(
                    "INSERT IGNORE INTO chair_type_keywords (chair_type_id, keyword) VALUES (?, ?)",
                    [$chairTypeId, $keyword]
                );
                setFlash('キーワードを追加しました。');
            } catch (PDOException $e) {
                setFlash('追加失敗（重複の可能性）', 'warning');
            }
        }
    }

    if ($postAction === 'delete') {
        $kwId = postInt('kw_id');
        dbExecute("DELETE FROM chair_type_keywords WHERE id = ?", [$kwId]);
        setFlash('キーワードを削除しました。', 'warning');
    }

    header('Location: ' . APP_URL . "/chair_type_keywords.php?chair_type_id={$chairTypeId}");
    exit;
}

$chairType = $chairTypeId
    ? dbFetchOne("SELECT * FROM chair_types WHERE id = ?", [$chairTypeId])
    : null;
$keywords = $chairTypeId
    ? dbFetchAll("SELECT * FROM chair_type_keywords WHERE chair_type_id = ? ORDER BY keyword", [$chairTypeId])
    : [];

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-tags"></i> 検索キーワード管理</h2>
<?php if ($chairType): ?>
  <p class="text-muted"><?= h($chairType['chair_type_code']) ?> - <?= h($chairType['chair_type_name']) ?></p>

  <div class="row">
    <div class="col-md-6">
      <!-- キーワード一覧 -->
      <div class="card mb-3">
        <div class="card-header">登録キーワード（<?= count($keywords) ?>件）</div>
        <div class="card-body">
          <?php foreach ($keywords as $kw): ?>
          <form method="post" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="kw_id" value="<?= $kw['id'] ?>">
            <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
            <span class="badge bg-secondary me-1 mb-1" style="font-size:0.9em">
              <?= h($kw['keyword']) ?>
              <button type="submit" class="btn-close btn-close-white ms-1" style="font-size:0.6em"
                      onclick="return confirm('削除しますか？')" title="削除"></button>
            </span>
          </form>
          <?php endforeach; ?>
          <?php if (empty($keywords)): ?>
            <span class="text-muted">キーワード未登録</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- 追加フォーム -->
      <div class="card">
        <div class="card-header bg-success text-white">キーワードを追加</div>
        <div class="card-body">
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
            <div class="input-group">
              <input type="text" name="keyword" class="form-control"
                     placeholder="例: 大阪製 / 柄物 / 社長室用" required>
              <button type="submit" class="btn btn-success"><i class="bi bi-plus"></i> 追加</button>
            </div>
          </form>
          <div class="form-text mt-2">
            登録できるキーワード例: 顧客名、生地名、用途、図面番号、仕様名
          </div>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="alert alert-warning">URLに chair_type_id を指定してください。</div>
<?php endif; ?>

<?php require __DIR__ . '/parts/footer.php'; ?>
