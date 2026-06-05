<?php
// =====================================================
// 椅子タイプ画像・図面管理
// 目的: 椅子タイプに写真・図面・作業指示図をアップロード・管理
// 接続テーブル: chair_type_media
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
$pageTitle = '画像・図面管理';

$chairTypeId = getInt('chair_type_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    if ($postAction === 'upload' && isset($_FILES['media_file'])) {
        $file     = $_FILES['media_file'];
        $maxBytes = MAX_UPLOAD_MB * 1024 * 1024;

        if ($file['error'] !== UPLOAD_ERR_OK) {
            setFlash('アップロードエラーが発生しました。', 'danger');
        } elseif ($file['size'] > $maxBytes) {
            setFlash('ファイルサイズが上限（' . MAX_UPLOAD_MB . 'MB）を超えています。', 'danger');
        } elseif (!in_array($file['type'], ALLOWED_IMG_TYPES)) {
            setFlash('JPEG・PNG・GIF・WebP形式のみアップロード可能です。', 'danger');
        } else {
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $saveName = 'ct_' . $chairTypeId . '_' . uniqid() . '.' . $ext;
            $savePath = UPLOAD_MEDIA_DIR . $saveName;

            if (!is_dir(UPLOAD_MEDIA_DIR)) {
                mkdir(UPLOAD_MEDIA_DIR, 0755, true);
            }

            if (move_uploaded_file($file['tmp_name'], $savePath)) {
                dbExecute(
                    "INSERT INTO chair_type_media
                        (chair_type_id, media_type, file_path, original_file_name, caption, display_order)
                     VALUES (?,?,?,?,?,?)",
                    [
                        $chairTypeId,
                        postStr('media_type', 'photo'),
                        $saveName,
                        $file['name'],
                        postStr('caption'),
                        postInt('display_order', 0),
                    ]
                );
                auditLog('upload', 'chair_type_media', null, null, ['file' => $saveName]);
                setFlash('画像をアップロードしました。');
            } else {
                setFlash('ファイル保存に失敗しました。', 'danger');
            }
        }
    }

    if ($postAction === 'delete') {
        $mediaId = postInt('media_id');
        $media   = dbFetchOne("SELECT * FROM chair_type_media WHERE id = ?", [$mediaId]);
        if ($media) {
            $filepath = UPLOAD_MEDIA_DIR . $media['file_path'];
            if (file_exists($filepath)) unlink($filepath);
            dbExecute("DELETE FROM chair_type_media WHERE id = ?", [$mediaId]);
            auditLog('delete', 'chair_type_media', $mediaId, $media, null);
            setFlash('画像を削除しました。', 'warning');
        }
    }

    header('Location: ' . APP_URL . "/chair_type_media.php?chair_type_id={$chairTypeId}");
    exit;
}

// データ取得
$chairType = $chairTypeId
    ? dbFetchOne("SELECT * FROM chair_types WHERE id = ?", [$chairTypeId])
    : null;

$mediaList = $chairTypeId
    ? dbFetchAll("SELECT * FROM chair_type_media WHERE chair_type_id = ? ORDER BY display_order, id", [$chairTypeId])
    : [];

$mediaTypeLabels = [
    'photo'            => '完成写真',
    'drawing'          => '図面',
    'instruction'      => '作業指示図',
    'sewing_line'      => '縫製ライン図',
    'upholstery_point' => '張り込みポイント',
    'difference'       => '差分説明',
    'other'            => 'その他',
];

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col">
    <h2><i class="bi bi-images"></i> 画像・図面管理</h2>
    <?php if ($chairType): ?>
      <p class="text-muted"><?= h($chairType['chair_type_code']) ?> - <?= h($chairType['chair_type_name']) ?></p>
    <?php endif; ?>
  </div>
  <div class="col-auto">
    <a href="chair_type_form.php?id=<?= $chairTypeId ?>" class="btn btn-outline-secondary">タイプ詳細へ戻る</a>
  </div>
</div>

<?php if ($chairType): ?>
<!-- アップロードフォーム -->
<div class="card mb-3">
  <div class="card-header bg-primary text-white">画像をアップロード</div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
      <div class="col-md-3">
        <select name="media_type" class="form-select">
          <?php foreach ($mediaTypeLabels as $v => $l): ?>
          <option value="<?= $v ?>"><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <input type="file" name="media_file" class="form-control" accept="image/*" required>
      </div>
      <div class="col-md-3">
        <input type="text" name="caption" class="form-control" placeholder="説明文（任意）">
      </div>
      <div class="col-md-1">
        <input type="number" name="display_order" class="form-control" placeholder="順" value="0">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> UP</button>
      </div>
    </form>
  </div>
</div>

<!-- 画像一覧 -->
<div class="row row-cols-2 row-cols-md-4 g-3">
<?php foreach ($mediaList as $m): ?>
  <div class="col">
    <div class="card h-100">
      <img src="<?= APP_URL ?>/uploads/chair_type_media/<?= h($m['file_path']) ?>"
           class="card-img-top" style="height:180px;object-fit:contain;background:#f5f5f5"
           alt="<?= h($m['caption'] ?? '') ?>">
      <div class="card-body p-2">
        <div class="badge bg-info mb-1"><?= $mediaTypeLabels[$m['media_type']] ?? $m['media_type'] ?></div>
        <?php if ($m['caption']): ?>
          <p class="card-text small"><?= h($m['caption']) ?></p>
        <?php endif; ?>
        <small class="text-muted"><?= h($m['original_file_name']) ?></small>
      </div>
      <div class="card-footer p-1">
        <form method="post" onsubmit="return confirm('削除しますか？')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="media_id" value="<?= $m['id'] ?>">
          <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger w-100">削除</button>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($mediaList)): ?>
  <div class="col-12 text-center text-muted py-4"><i class="bi bi-image fs-1"></i><br>画像未登録</div>
<?php endif; ?>
</div>
<?php else: ?>
  <div class="alert alert-warning">椅子タイプを選択してください。</div>
<?php endif; ?>

<?php require __DIR__ . '/parts/footer.php'; ?>
