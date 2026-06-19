<?php
// =====================================================
// 製品タイプ登録・編集フォーム
// 目的: 製品タイプの基本情報を登録・更新する
// 接続テーブル: chair_types, chair_type_groups
// 権限: process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/chair_type_service.php';

requireLogin();
requireRole('process_leader');

$id        = getInt('id');
$isEdit    = $id > 0;
$pageTitle = $isEdit ? '製品タイプ編集' : '製品タイプ登録';

$chairType = null;
if ($isEdit) {
    $chairType = dbFetchOne("SELECT * FROM chair_types WHERE id = ? AND is_active = 1", [$id]);
    if (!$chairType) {
        setFlash('製品タイプが見つかりません。', 'danger');
        header('Location: ' . APP_URL . '/chair_types.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'chair_type_group_id' => postInt('chair_type_group_id'),
        'chair_type_code'     => postStr('chair_type_code'),
        'chair_type_name'     => postStr('chair_type_name'),
        'version_no'          => postInt('version_no', 0),
        'is_base_type'        => postInt('is_base_type', 0),
        'base_chair_type_id'  => postInt('base_chair_type_id') ?: null,
        'base_quantity'       => postInt('base_quantity', 1),
        'shape_summary'       => postStr('shape_summary'),
        'difference_summary'  => postStr('difference_summary'),
        'search_text'         => postStr('search_text'),
    ];

    if (!$data['chair_type_group_id'] || !$data['chair_type_code'] || !$data['chair_type_name']) {
        setFlash('グループ・コード・名前は必須です。', 'danger');
    } else {
        try {
            if ($isEdit) {
                $before = dbFetchOne("SELECT * FROM chair_types WHERE id = ?", [$id]);
                dbExecute(
                    "UPDATE chair_types SET
                        chair_type_group_id=?, chair_type_code=?, chair_type_name=?,
                        version_no=?, is_base_type=?, base_chair_type_id=?,
                        base_quantity=?, shape_summary=?, difference_summary=?, search_text=?
                     WHERE id=?",
                    array_merge(array_values($data), [$id])
                );
                auditLog('update', 'chair_types', $id, $before, $data);
                setFlash('製品タイプを更新しました。');
            } else {
                $newId = dbExecute(
                    "INSERT INTO chair_types
                        (chair_type_group_id,chair_type_code,chair_type_name,version_no,
                         is_base_type,base_chair_type_id,base_quantity,shape_summary,
                         difference_summary,search_text)
                     VALUES (?,?,?,?,?,?,?,?,?,?)",
                    array_values($data)
                );
                auditLog('create', 'chair_types', (int)$newId, null, $data);
                setFlash('製品タイプを登録しました。');
                header('Location: ' . APP_URL . '/chair_type_form.php?id=' . $newId);
                exit;
            }
        } catch (PDOException $e) {
            setFlash('保存失敗（コードが重複している可能性があります）: ' . $e->getMessage(), 'danger');
        }
    }
    header('Location: ' . APP_URL . '/chair_type_form.php' . ($isEdit ? "?id={$id}" : ''));
    exit;
}

$groups   = getChairTypeGroups();
// 基本形一覧（派生元選択用）
$baseTypes = dbFetchAll(
    "SELECT id, chair_type_code, chair_type_name FROM chair_types
     WHERE is_base_type = 1 AND is_active = 1 ORDER BY chair_type_code"
);

// 工程フローチャート用データ（編集時のみ）
$flowProcesses = [];
if ($isEdit) {
    $flowProcesses = dbFetchAll(
        "SELECT ctps.display_order, ctps.setup_minutes, ctps.base_work_minutes,
                ctps.standard_workers, ctps.difficulty_level, ctps.can_start_parallel,
                ctps.is_outsourced, ctps.outsource_vendor, ctps.is_temporarily_excluded,
                ctps.is_active,
                p.id AS process_id, p.process_name, p.process_code
         FROM chair_type_process_standards ctps
         JOIN processes p ON ctps.process_id = p.id
         WHERE ctps.chair_type_id = ? AND ctps.is_active = 1
         ORDER BY ctps.display_order, p.display_order",
        [$id]
    );
}

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="chair_types.php">製品タイプ一覧</a></li>
        <li class="breadcrumb-item active"><?= $pageTitle ?></li>
      </ol>
    </nav>
    <h2><i class="bi bi-archive"></i> <?= $pageTitle ?></h2>
  </div>
</div>

<div class="row">
  <div class="col-md-8">
    <div class="card">
      <div class="card-header bg-primary text-white">基本情報</div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">グループ <span class="text-danger">*</span></label>
              <select name="chair_type_group_id" class="form-select" required>
                <option value="">― 選択 ―</option>
                <?php foreach ($groups as $g): ?>
                <option value="<?= $g['id'] ?>"
                  <?= ($chairType['chair_type_group_id'] ?? '') == $g['id'] ? 'selected' : '' ?>>
                  <?= h($g['group_code']) ?> - <?= h($g['group_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">タイプコード <span class="text-danger">*</span></label>
              <input type="text" name="chair_type_code" class="form-control" required
                     placeholder="例: CHAIR-A-01"
                     value="<?= h($chairType['chair_type_code'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-bold">タイプ名 <span class="text-danger">*</span></label>
              <input type="text" name="chair_type_name" class="form-control" required
                     value="<?= h($chairType['chair_type_name'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">バージョン番号</label>
              <input type="number" name="version_no" class="form-control" min="0"
                     value="<?= h($chairType['version_no'] ?? 0) ?>">
              <div class="form-text">基本形=0、差分版=1,2,3...</div>
            </div>
            <div class="col-md-3">
              <label class="form-label">基本本数</label>
              <input type="number" name="base_quantity" class="form-control" min="1" required
                     value="<?= h($chairType['base_quantity'] ?? 1) ?>">
              <div class="form-text">標準時間の基準数量</div>
            </div>
            <div class="col-md-3">
              <label class="form-label">基本形フラグ</label>
              <select name="is_base_type" class="form-select">
                <option value="0" <?= !($chairType['is_base_type'] ?? 1) ? 'selected' : '' ?>>差分版</option>
                <option value="1" <?= ($chairType['is_base_type'] ?? 0) ? 'selected' : '' ?>>基本形</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">派生元（基本形）</label>
              <select name="base_chair_type_id" class="form-select">
                <option value="">― なし ―</option>
                <?php foreach ($baseTypes as $bt): ?>
                <option value="<?= $bt['id'] ?>"
                  <?= ($chairType['base_chair_type_id'] ?? '') == $bt['id'] ? 'selected' : '' ?>>
                  <?= h($bt['chair_type_code']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">形状・仕様説明</label>
              <textarea name="shape_summary" class="form-control" rows="3"
              ><?= h($chairType['shape_summary'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">基本形との違い（差分説明）</label>
              <textarea name="difference_summary" class="form-control" rows="2"
              ><?= h($chairType['difference_summary'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">検索キーワード（スペース区切り）</label>
              <input type="text" name="search_text" class="form-control"
                     placeholder="顧客名 生地名 用途 図面番号..."
                     value="<?= h($chairType['search_text'] ?? '') ?>">
            </div>
          </div>
          <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save"></i> <?= $isEdit ? '更新' : '登録' ?>
            </button>
            <a href="chair_types.php" class="btn btn-secondary">キャンセル</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($isEdit): ?>
  <!-- 関連ページへのリンク -->
  <div class="col-md-4">
    <div class="card mb-3">
      <div class="card-header">関連管理</div>
      <div class="list-group list-group-flush">
        <a href="standards.php?chair_type_id=<?= $id ?>" class="list-group-item list-group-item-action">
          <i class="bi bi-clock"></i> 工程標準時間を管理
        </a>
        <a href="adjustments.php?chair_type_id=<?= $id ?>" class="list-group-item list-group-item-action">
          <i class="bi bi-plus-minus"></i> 差分工程を管理
        </a>
        <a href="chair_type_media.php?chair_type_id=<?= $id ?>" class="list-group-item list-group-item-action">
          <i class="bi bi-images"></i> 画像・図面を管理
        </a>
        <a href="chair_type_keywords.php?chair_type_id=<?= $id ?>" class="list-group-item list-group-item-action">
          <i class="bi bi-tags"></i> 検索キーワードを管理
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($isEdit && !empty($flowProcesses)): ?>
<!-- =====================================================
     工程フローチャート
     ===================================================== -->
<div class="row mt-4">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <span><i class="bi bi-diagram-3"></i> 工程フロー</span>
        <span class="badge bg-secondary ms-1"><?= count($flowProcesses) ?>工程</span>
        <a href="standards.php?chair_type_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary ms-auto">
          <i class="bi bi-pencil"></i> 工程を編集
        </a>
      </div>
      <div class="card-body overflow-auto">
        <div class="flowchart-wrap d-flex align-items-stretch gap-0 pb-2">
          <?php foreach ($flowProcesses as $i => $fp):
            $isOut   = !empty($fp['is_outsourced']);
            $isExcl  = !empty($fp['is_temporarily_excluded']);
            $isPara  = !empty($fp['can_start_parallel']);
            $stdMin  = ($fp['setup_minutes'] ?? 0) + ($fp['base_work_minutes'] ?? 0);
            $diff    = (int)($fp['difficulty_level'] ?? 1);

            if ($isExcl)  $nodeClass = 'fc-node-excluded';
            elseif ($isOut) $nodeClass = 'fc-node-outsourced';
            else          $nodeClass = 'fc-node-normal';
          ?>
            <?php if ($i > 0): ?>
            <div class="fc-arrow d-flex align-items-center">
              <i class="bi bi-arrow-right-short fs-3 text-muted"></i>
            </div>
            <?php endif; ?>
            <div class="fc-node <?= $nodeClass ?> <?= $isPara ? 'fc-parallel' : '' ?>
                         <?= $isExcl ? 'fc-excluded' : '' ?>">
              <div class="fc-seq text-muted">
                <?= sprintf('%02d', $i + 1) ?>
                <?php if ($isPara): ?><i class="bi bi-layers-fill" title="並行可"></i><?php endif; ?>
              </div>
              <div class="fc-name fw-bold"><?= h($fp['process_name']) ?></div>
              <div class="fc-code font-monospace text-muted"><?= h($fp['process_code']) ?></div>
              <?php if ($stdMin > 0): ?>
              <div class="fc-time mt-1">
                <i class="bi bi-clock text-muted"></i>
                <?= formatMinutes((float)$stdMin) ?>
              </div>
              <?php endif; ?>
              <div class="fc-badges mt-1 d-flex flex-wrap gap-1 justify-content-center">
                <?php if ($fp['standard_workers'] > 1): ?>
                  <span class="badge bg-secondary" title="標準人数">
                    <i class="bi bi-people-fill"></i><?= $fp['standard_workers'] ?>人
                  </span>
                <?php endif; ?>
                <?php if ($diff >= 4): ?>
                  <span class="badge bg-<?= $diff === 5 ? 'danger' : 'warning text-dark' ?>" title="難易度">
                    難<?= $diff ?>
                  </span>
                <?php endif; ?>
                <?php if ($isOut): ?>
                  <span class="badge bg-warning text-dark" title="外注">外注</span>
                <?php endif; ?>
                <?php if ($isExcl): ?>
                  <span class="badge bg-danger" title="一時除外">除外</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- 凡例 -->
        <div class="d-flex gap-3 mt-3 flex-wrap small text-muted">
          <span><span class="fc-legend fc-node-normal d-inline-block"></span> 通常工程</span>
          <span><span class="fc-legend fc-node-outsourced d-inline-block"></span> 外注工程</span>
          <span><span class="fc-legend fc-node-excluded d-inline-block"></span> 一時除外</span>
          <span><i class="bi bi-layers-fill text-primary"></i> 前工程と並行可</span>
        </div>
      </div>
    </div>
  </div>
</div>
<?php elseif ($isEdit): ?>
<div class="row mt-3">
  <div class="col-12">
    <div class="alert alert-light border">
      <i class="bi bi-diagram-3 text-muted"></i>
      まだ工程が登録されていません。
      <a href="standards.php?chair_type_id=<?= $id ?>">工程標準時間管理</a>から工程を追加してください。
    </div>
  </div>
</div>
<?php endif; ?>

<style>
/* 工程フローチャート */
.flowchart-wrap     { min-width: max-content; }
.fc-node            { border: 2px solid #0d6efd; border-radius: 10px; background: #f0f7ff;
                      min-width: 110px; max-width: 130px; padding: 8px 10px; text-align: center; }
.fc-node-outsourced { border-color: #ffc107; background: #fffbf0; }
.fc-node-excluded   { border-color: #dc3545; background: #fff0f0; opacity: .7; }
.fc-parallel        { border-style: dashed; }
.fc-seq             { font-size: .7rem; color: #999; margin-bottom: 2px; }
.fc-name            { font-size: .85rem; line-height: 1.2; }
.fc-code            { font-size: .7rem; color: #888; margin-top: 2px; }
.fc-time            { font-size: .75rem; color: #555; }
.fc-badges          { font-size: .65rem; }
.fc-arrow           { padding: 0 2px; }
.fc-legend          { width: 14px; height: 14px; border: 2px solid #0d6efd;
                      border-radius: 3px; background: #f0f7ff; vertical-align: middle; margin-right: 3px; }
.fc-legend.fc-node-outsourced { border-color: #ffc107; background: #fffbf0; }
.fc-legend.fc-node-excluded   { border-color: #dc3545; background: #fff0f0; }
</style>

<?php require __DIR__ . '/parts/footer.php'; ?>
