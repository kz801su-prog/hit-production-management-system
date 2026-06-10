<?php
// =====================================================
// 差分工程管理
// 目的: 基本形との差分（加算・減算・工程追加・削除）を管理
// 接続テーブル: chair_type_process_adjustments, processes
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
$pageTitle = '差分工程管理';

$chairTypeId = getInt('chair_type_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    if ($postAction === 'create') {
        $data = [
            'chair_type_id'      => postInt('chair_type_id'),
            'process_id'         => postInt('process_id') ?: null,
            'adjustment_type'    => postStr('adjustment_type'),
            'adjustment_name'    => postStr('adjustment_name'),
            'adjustment_minutes' => postFloat('adjustment_minutes'),
            'adjustment_rate'    => postFloat('adjustment_rate'),
            'applies_per'        => postStr('applies_per', 'order'),
            'reason'             => postStr('reason'),
        ];
        $newId = dbExecute(
            "INSERT INTO chair_type_process_adjustments
                (chair_type_id,process_id,adjustment_type,adjustment_name,
                 adjustment_minutes,adjustment_rate,applies_per,reason)
             VALUES (?,?,?,?,?,?,?,?)",
            array_values($data)
        );
        auditLog('create', 'chair_type_process_adjustments', (int)$newId, null, $data);
        setFlash('差分工程を登録しました。');
    }

    if ($postAction === 'delete') {
        $delId = postInt('adj_id');
        dbExecute("UPDATE chair_type_process_adjustments SET is_active = 0 WHERE id = ?", [$delId]);
        auditLog('delete', 'chair_type_process_adjustments', $delId);
        setFlash('差分工程を削除しました。', 'warning');
    }

    $redir = $chairTypeId ? "?chair_type_id={$chairTypeId}" : '';
    header('Location: ' . APP_URL . '/adjustments.php' . $redir);
    exit;
}

// データ取得
$chairTypes = dbFetchAll(
    "SELECT ct.id, ct.chair_type_code, ct.chair_type_name FROM chair_types ct
     WHERE ct.is_active = 1 AND ct.is_base_type = 0 ORDER BY ct.chair_type_code"
);
$allProcesses = dbFetchAll("SELECT * FROM processes WHERE is_active=1 ORDER BY display_order");

$chairType   = null;
$adjustments = [];
if ($chairTypeId) {
    $chairType   = dbFetchOne("SELECT * FROM chair_types WHERE id = ? AND is_active = 1", [$chairTypeId]);
    $adjustments = dbFetchAll(
        "SELECT a.*, p.process_name FROM chair_type_process_adjustments a
         LEFT JOIN processes p ON a.process_id = p.id
         WHERE a.chair_type_id = ? AND a.is_active = 1 ORDER BY a.id",
        [$chairTypeId]
    );
}

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-plus-minus"></i> 差分工程管理</h2>

<!-- 製品タイプ選択 -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">差分版の製品タイプを選択</label>
        <select name="chair_type_id" class="form-select" onchange="this.form.submit()">
          <option value="">― 選択してください ―</option>
          <?php foreach ($chairTypes as $ct): ?>
          <option value="<?= $ct['id'] ?>" <?= $chairTypeId == $ct['id'] ? 'selected' : '' ?>>
            <?= h($ct['chair_type_code']) ?> - <?= h($ct['chair_type_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<?php if ($chairType): ?>
<div class="alert alert-info">
  <strong><?= h($chairType['chair_type_code']) ?></strong> - <?= h($chairType['chair_type_name']) ?>
</div>

<div class="row">
  <!-- 差分一覧 -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header">登録済み差分</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-dark">
            <tr><th>工程</th><th>差分名</th><th>種別</th><th>時間</th><th>単位</th><th>操作</th></tr>
          </thead>
          <tbody>
          <?php foreach ($adjustments as $adj): ?>
            <tr>
              <td><?= h($adj['process_name'] ?? '―') ?></td>
              <td><?= h($adj['adjustment_name']) ?></td>
              <td><span class="badge bg-<?= $adj['adjustment_type'] === 'add' ? 'success' : ($adj['adjustment_type'] === 'subtract' ? 'danger' : 'info') ?>">
                <?= h(adjustmentTypeLabel($adj['adjustment_type'])) ?>
              </span></td>
              <td>
                <?= $adj['adjustment_type'] === 'add' ? '+' : ($adj['adjustment_type'] === 'subtract' ? '-' : '') ?>
                <?= h($adj['adjustment_minutes']) ?>分
                <?= $adj['adjustment_rate'] ? '(' . h($adj['adjustment_rate']) . '%)' : '' ?>
              </td>
              <td><?= h(appliesPerLabel($adj['applies_per'])) ?></td>
              <td>
                <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="adj_id" value="<?= $adj['id'] ?>">
                  <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">削除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($adjustments)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">差分なし</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 新規追加フォーム -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-header bg-success text-white">差分を追加</div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
          <div class="mb-2">
            <label class="form-label">対象工程</label>
            <select name="process_id" class="form-select form-select-sm">
              <option value="">― 全工程 ―</option>
              <?php foreach ($allProcesses as $p): ?>
              <option value="<?= $p['id'] ?>"><?= h($p['process_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">種別 <span class="text-danger">*</span></label>
            <select name="adjustment_type" class="form-select form-select-sm" required>
              <option value="add">加算</option>
              <option value="subtract">減算</option>
              <option value="replace">置換</option>
              <option value="add_process">工程追加</option>
              <option value="remove_process">工程削除</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">差分名 <span class="text-danger">*</span></label>
            <input type="text" name="adjustment_name" class="form-control form-control-sm" required
                   placeholder="例: 肘付き加算">
          </div>
          <div class="mb-2">
            <label class="form-label">時間（分）</label>
            <input type="number" name="adjustment_minutes" class="form-control form-control-sm"
                   value="0" min="0" step="0.5">
          </div>
          <div class="mb-2">
            <label class="form-label">適用単位</label>
            <select name="applies_per" class="form-select form-select-sm">
              <option value="order">指示全体</option>
              <option value="unit">本数単位</option>
              <option value="part">パーツ単位</option>
              <option value="meter">ｍ単位</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">理由</label>
            <textarea name="reason" class="form-control form-control-sm" rows="2"></textarea>
          </div>
          <button type="submit" class="btn btn-success btn-sm w-100">
            <i class="bi bi-plus"></i> 追加
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/parts/footer.php'; ?>
