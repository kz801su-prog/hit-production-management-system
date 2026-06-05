<?php
// =====================================================
// 椅子タイプ一覧・検索
// 目的: 椅子タイプを写真・バージョン付きで検索・表示
// 接続テーブル: chair_types, chair_type_groups, chair_type_media, chair_type_keywords
// 呼び出し先: chair_type_form.php, standards.php, adjustments.php
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/chair_type_service.php';

requireLogin();
$pageTitle = '椅子タイプ一覧';

// 検索フィルタ
$filters = [
    'keyword'  => $_GET['keyword'] ?? '',
    'group_id' => getInt('group_id'),
    'is_base'  => $_GET['is_base'] ?? '',
];

$chairTypes = getChairTypeList($filters);
$groups     = getChairTypeGroups();

// 各タイプのサムネイル画像を取得
$typeIds = array_column($chairTypes, 'id');
$thumbs  = [];
if ($typeIds) {
    $phs  = implode(',', array_fill(0, count($typeIds), '?'));
    $imgs = dbFetchAll(
        "SELECT chair_type_id, file_path FROM chair_type_media
         WHERE chair_type_id IN ({$phs}) AND media_type IN ('photo','drawing')
         GROUP BY chair_type_id ORDER BY display_order",
        $typeIds
    );
    foreach ($imgs as $img) {
        $thumbs[$img['chair_type_id']] = $img['file_path'];
    }
}

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col"><h2><i class="bi bi-archive"></i> 椅子タイプ一覧</h2></div>
  <?php if (isLeader()): ?>
  <div class="col-auto">
    <a href="<?= APP_URL ?>/chair_type_form.php" class="btn btn-primary">
      <i class="bi bi-plus-circle"></i> 新規登録
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- 検索フォーム -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2">
      <div class="col-md-4">
        <input type="text" name="keyword" class="form-control" placeholder="キーワード（コード・名前・メモ等）"
               value="<?= h($filters['keyword']) ?>">
      </div>
      <div class="col-md-3">
        <select name="group_id" class="form-select">
          <option value="">― 全グループ ―</option>
          <?php foreach ($groups as $g): ?>
          <option value="<?= $g['id'] ?>" <?= $filters['group_id'] == $g['id'] ? 'selected' : '' ?>>
            <?= h($g['group_code']) ?> - <?= h($g['group_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="is_base" class="form-select">
          <option value="">全種別</option>
          <option value="1" <?= $filters['is_base'] === '1' ? 'selected' : '' ?>>基本形のみ</option>
          <option value="0" <?= $filters['is_base'] === '0' ? 'selected' : '' ?>>差分版のみ</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> 検索</button>
        <a href="chair_types.php" class="btn btn-outline-secondary">リセット</a>
      </div>
    </form>
  </div>
</div>

<!-- 結果（グリッド表示） -->
<div class="row row-cols-1 row-cols-md-3 row-cols-lg-4 g-3">
<?php foreach ($chairTypes as $ct): ?>
  <div class="col">
    <div class="card h-100 <?= $ct['is_base_type'] ? 'border-primary' : '' ?>">
      <!-- サムネイル -->
      <?php if (!empty($thumbs[$ct['id']])): ?>
        <img src="<?= APP_URL ?>/uploads/chair_type_media/<?= h($thumbs[$ct['id']]) ?>"
             class="card-img-top chair-thumb" alt="<?= h($ct['chair_type_name']) ?>">
      <?php else: ?>
        <div class="card-img-top bg-light d-flex align-items-center justify-content-center chair-thumb">
          <i class="bi bi-image text-muted" style="font-size:3rem"></i>
        </div>
      <?php endif; ?>
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
          <span class="badge bg-<?= $ct['is_base_type'] ? 'primary' : 'secondary' ?> mb-1">
            <?= $ct['is_base_type'] ? '基本形' : 'v.' . $ct['version_no'] ?>
          </span>
          <small class="text-muted"><?= h($ct['group_code']) ?></small>
        </div>
        <h6 class="card-title mb-1">
          <code><?= h($ct['chair_type_code']) ?></code>
        </h6>
        <p class="card-text small"><?= h($ct['chair_type_name']) ?></p>
        <?php if ($ct['difference_summary']): ?>
          <p class="card-text small text-info">
            <i class="bi bi-arrow-right-circle"></i> <?= h(mb_substr($ct['difference_summary'], 0, 40)) ?>
          </p>
        <?php endif; ?>
      </div>
      <div class="card-footer d-flex gap-1">
        <a href="chair_type_form.php?id=<?= $ct['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill">
          <i class="bi bi-pencil"></i> 詳細
        </a>
        <a href="standards.php?chair_type_id=<?= $ct['id'] ?>" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-clock"></i>
        </a>
        <a href="chair_type_media.php?chair_type_id=<?= $ct['id'] ?>" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-images"></i>
        </a>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($chairTypes)): ?>
  <div class="col-12 text-center py-5 text-muted">
    <i class="bi bi-search fs-1"></i><br>該当する椅子タイプが見つかりません
  </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
