<?php
// =====================================================
// 製品タイプ画像・図面管理
// 目的: 製品タイプに写真・図面・作業指示図をアップロード・管理
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
        $file          = $_FILES['media_file'];
        $maxBytes      = MAX_UPLOAD_MB * 1024 * 1024;
        $allowedTypes  = array_merge(ALLOWED_IMG_TYPES, ['application/pdf']);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            setFlash('アップロードエラーが発生しました。', 'danger');
        } elseif ($file['size'] > $maxBytes) {
            setFlash('ファイルサイズが上限（' . MAX_UPLOAD_MB . 'MB）を超えています。', 'danger');
        } elseif (!in_array($file['type'], $allowedTypes)) {
            setFlash('JPEG・PNG・GIF・WebP・PDF形式のみアップロード可能です。', 'danger');
        } else {
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
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
                setFlash('ファイルをアップロードしました。');
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
$searchMode    = $_GET['search'] ?? '';   // 'drawing' で図面専用検索
$searchKeyword = trim($_GET['kw'] ?? '');

// 図面専用検索: 全製品タイプの図面を横断検索
if ($searchMode === 'drawing') {
    $chairType = null;
    $sql  = "SELECT m.*, ct.chair_type_code, ct.chair_type_name
             FROM chair_type_media m
             JOIN chair_types ct ON ct.id = m.chair_type_id
             WHERE m.media_type = 'drawing'";
    $params = [];
    if ($searchKeyword !== '') {
        $sql .= " AND (ct.chair_type_code LIKE ? OR ct.chair_type_name LIKE ? OR m.caption LIKE ? OR m.original_file_name LIKE ?)";
        $like = "%{$searchKeyword}%";
        $params = [$like, $like, $like, $like];
    }
    $sql .= " ORDER BY ct.chair_type_code, m.display_order, m.id";
    $mediaList = dbFetchAll($sql, $params);
} else {
    $chairType = $chairTypeId
        ? dbFetchOne("SELECT * FROM chair_types WHERE id = ?", [$chairTypeId])
        : null;

    $mediaList = $chairTypeId
        ? dbFetchAll("SELECT * FROM chair_type_media WHERE chair_type_id = ? ORDER BY display_order, id", [$chairTypeId])
        : [];
}

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

<div class="row mb-3 align-items-center">
  <div class="col">
    <?php if ($searchMode === 'drawing'): ?>
      <h2><i class="bi bi-search"></i> 図面専用検索</h2>
    <?php else: ?>
      <h2><i class="bi bi-images"></i> 画像・図面管理</h2>
      <?php if ($chairType): ?>
        <p class="text-muted mb-0"><?= h($chairType['chair_type_code']) ?> - <?= h($chairType['chair_type_name']) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="col-auto d-flex gap-2">
    <?php if ($searchMode === 'drawing'): ?>
      <form class="d-flex gap-2" method="get">
        <input type="hidden" name="search" value="drawing">
        <input type="search" name="kw" class="form-control form-control-sm" placeholder="品番・品名・キャプション" value="<?= h($searchKeyword) ?>" style="width:200px">
        <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
      </form>
      <a href="chair_type_media.php<?= $chairTypeId ? "?chair_type_id={$chairTypeId}" : '' ?>" class="btn btn-outline-secondary btn-sm">図面検索を閉じる</a>
    <?php else: ?>
      <a href="chair_type_media.php?search=drawing" class="btn btn-outline-info btn-sm"><i class="bi bi-file-earmark-pdf"></i> 図面専用検索</a>
      <?php if ($chairTypeId): ?>
        <a href="chair_type_form.php?id=<?= $chairTypeId ?>" class="btn btn-outline-secondary btn-sm">タイプ詳細へ戻る</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($searchMode !== 'drawing' && $chairType): ?>
<!-- アップロードフォーム -->
<div class="card mb-3">
  <div class="card-header bg-primary text-white">ファイルをアップロード</div>
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
        <input type="file" name="media_file" class="form-control" accept="image/*,application/pdf" required>
        <small class="text-muted">JPEG / PNG / GIF / WebP / PDF</small>
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
<?php endif; ?>

<!-- メディア一覧 -->
<?php if ($searchMode === 'drawing' && !empty($mediaList)): ?>
  <p class="text-muted mb-2"><?= count($mediaList) ?>件の図面が見つかりました。</p>
<?php endif; ?>
<div class="row row-cols-2 row-cols-md-4 g-3">
<?php foreach ($mediaList as $m):
    $isPdf   = strtolower(pathinfo($m['file_path'], PATHINFO_EXTENSION)) === 'pdf';
    $fileUrl = APP_URL . '/uploads/chair_type_media/' . h($m['file_path']);
?>
  <div class="col">
    <div class="card h-100">
      <?php if ($isPdf): ?>
        <a href="<?= $fileUrl ?>" target="_blank" class="d-flex align-items-center justify-content-center text-decoration-none"
           style="height:180px;background:#f8f9fa;border-bottom:1px solid #dee2e6">
          <div class="text-center text-danger">
            <i class="bi bi-file-earmark-pdf" style="font-size:3.5rem"></i>
            <div class="small mt-1 text-muted">PDFを開く</div>
          </div>
        </a>
      <?php else: ?>
        <a href="<?= $fileUrl ?>" target="_blank">
          <img src="<?= $fileUrl ?>" class="card-img-top"
               style="height:180px;object-fit:contain;background:#f5f5f5"
               alt="<?= h($m['caption'] ?? '') ?>">
        </a>
      <?php endif; ?>
      <div class="card-body p-2">
        <?php if ($searchMode === 'drawing' && isset($m['chair_type_code'])): ?>
          <div class="fw-bold small mb-1"><?= h($m['chair_type_code']) ?> <?= h($m['chair_type_name']) ?></div>
        <?php endif; ?>
        <div class="badge bg-info mb-1"><?= $mediaTypeLabels[$m['media_type']] ?? $m['media_type'] ?></div>
        <?php if ($m['caption']): ?>
          <p class="card-text small"><?= h($m['caption']) ?></p>
        <?php endif; ?>
        <small class="text-muted"><?= h($m['original_file_name']) ?></small>
      </div>
      <?php if ($searchMode !== 'drawing'): ?>
      <div class="card-footer p-1">
        <form method="post" onsubmit="return confirm('削除しますか？')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="media_id" value="<?= $m['id'] ?>">
          <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger w-100">削除</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($mediaList)): ?>
  <div class="col-12 text-center text-muted py-4">
    <i class="bi bi-image fs-1"></i><br>
    <?= $searchMode === 'drawing' ? '該当する図面が見つかりません' : 'ファイル未登録' ?>
  </div>
<?php endif; ?>
</div>
<?php if ($searchMode !== 'drawing' && !$chairType): ?>
  <div class="alert alert-warning">製品タイプを選択してください。</div>
<?php endif; ?>

<?php require __DIR__ . '/parts/footer.php'; ?>
