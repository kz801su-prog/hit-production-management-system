<?php
// =====================================================
// 工程標準時間管理
// 目的: 椅子タイプごとの段取り・作業時間・アローアンスを設定
// 接続テーブル: chair_type_process_standards, processes, chair_types
// 権限: process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/chair_type_service.php';
require_once __DIR__ . '/../app/standard_time_service.php';

requireLogin();
requireRole('process_leader');

$chairTypeId = getInt('chair_type_id');
$pageTitle   = '工程標準時間管理';

$chairType = null;
if ($chairTypeId) {
    $chairType = dbFetchOne(
        "SELECT ct.*, g.group_code FROM chair_types ct
         JOIN chair_type_groups g ON ct.chair_type_group_id = g.id
         WHERE ct.id = ? AND ct.is_active = 1",
        [$chairTypeId]
    );
}

// POST: 標準時間の一括保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postChairTypeId = postInt('chair_type_id');

    // process_id ごとにデータを収集
    $processIds = $_POST['process_id'] ?? [];
    foreach ($processIds as $pid) {
        $pid = (int)$pid;
        $data = [
            'base_quantity'           => (int)($_POST["base_quantity_{$pid}"] ?? 1),
            'setup_minutes'           => (float)($_POST["setup_minutes_{$pid}"] ?? 0),
            'base_work_minutes'       => (float)($_POST["base_work_minutes_{$pid}"] ?? 0),
            'allowance_rate'          => (float)($_POST["allowance_rate_{$pid}"] ?? 0),
            'allowance_minutes'       => (float)($_POST["allowance_minutes_{$pid}"] ?? 0),
            'allowance_reason'        => trim($_POST["allowance_reason_{$pid}"] ?? ''),
            'standard_workers'        => (int)($_POST["standard_workers_{$pid}"] ?? 1),
            'difficulty_level'        => (int)($_POST["difficulty_level_{$pid}"] ?? 1),
            'can_start_parallel'      => isset($_POST["can_start_parallel_{$pid}"]) ? 1 : 0,
            'display_order'           => (int)($_POST["display_order_{$pid}"] ?? 0),
            'is_active'               => isset($_POST["is_active_{$pid}"]) ? 1 : 0,
            'is_outsourced'           => isset($_POST["is_outsourced_{$pid}"]) ? 1 : 0,
            'outsource_vendor'        => trim($_POST["outsource_vendor_{$pid}"] ?? ''),
            'outsource_lead_days'     => (int)($_POST["outsource_lead_days_{$pid}"] ?? 0),
            'is_temporarily_excluded' => isset($_POST["is_temporarily_excluded_{$pid}"]) ? 1 : 0,
            'excluded_reason'         => trim($_POST["excluded_reason_{$pid}"] ?? ''),
        ];

        dbExecute(
            "INSERT INTO chair_type_process_standards
                (chair_type_id, process_id, base_quantity, setup_minutes, base_work_minutes,
                 allowance_rate, allowance_minutes, allowance_reason, standard_workers,
                 difficulty_level, can_start_parallel, display_order, is_active,
                 is_outsourced, outsource_vendor, outsource_lead_days,
                 is_temporarily_excluded, excluded_reason)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                base_quantity=VALUES(base_quantity), setup_minutes=VALUES(setup_minutes),
                base_work_minutes=VALUES(base_work_minutes), allowance_rate=VALUES(allowance_rate),
                allowance_minutes=VALUES(allowance_minutes), allowance_reason=VALUES(allowance_reason),
                standard_workers=VALUES(standard_workers), difficulty_level=VALUES(difficulty_level),
                can_start_parallel=VALUES(can_start_parallel), display_order=VALUES(display_order),
                is_active=VALUES(is_active),
                is_outsourced=VALUES(is_outsourced), outsource_vendor=VALUES(outsource_vendor),
                outsource_lead_days=VALUES(outsource_lead_days),
                is_temporarily_excluded=VALUES(is_temporarily_excluded),
                excluded_reason=VALUES(excluded_reason)",
            [
                $postChairTypeId, $pid,
                $data['base_quantity'], $data['setup_minutes'], $data['base_work_minutes'],
                $data['allowance_rate'], $data['allowance_minutes'], $data['allowance_reason'],
                $data['standard_workers'], $data['difficulty_level'],
                $data['can_start_parallel'], $data['display_order'], $data['is_active'],
                $data['is_outsourced'], $data['outsource_vendor'] ?: null, $data['outsource_lead_days'],
                $data['is_temporarily_excluded'], $data['excluded_reason'] ?: null,
            ]
        );
    }
    auditLog('update', 'chair_type_process_standards', $postChairTypeId);
    setFlash('標準時間を保存しました。');
    header('Location: ' . APP_URL . '/standards.php?chair_type_id=' . $postChairTypeId);
    exit;
}

// 全工程一覧
$allProcesses = dbFetchAll("SELECT * FROM processes WHERE is_active=1 ORDER BY display_order");

// 現在の標準設定（椅子タイプ選択時）
$existingStandards = [];
if ($chairTypeId) {
    $rows = dbFetchAll(
        "SELECT * FROM chair_type_process_standards WHERE chair_type_id = ?",
        [$chairTypeId]
    );
    foreach ($rows as $r) {
        $existingStandards[$r['process_id']] = $r;
    }
}

// 椅子タイプ一覧（選択用）
$chairTypes = dbFetchAll(
    "SELECT ct.id, ct.chair_type_code, ct.chair_type_name, g.group_name
     FROM chair_types ct JOIN chair_type_groups g ON ct.chair_type_group_id = g.id
     WHERE ct.is_active = 1 ORDER BY ct.chair_type_code"
);

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-clock"></i> 工程標準時間管理</h2>

<!-- 椅子タイプ選択 -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">椅子タイプを選択</label>
        <select name="chair_type_id" class="form-select" required onchange="this.form.submit()">
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
  | 基本本数: <?= h($chairType['base_quantity']) ?>本
</div>

<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>工程別標準時間設定</span>
      <button type="submit" class="btn btn-sm btn-success">
        <i class="bi bi-save"></i> 一括保存
      </button>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-bordered table-sm mb-0" style="min-width:1400px">
          <thead class="text-center">
            <tr>
              <th class="table-dark" colspan="2">工程</th>
              <th class="table-dark">基準本数</th>
              <th class="table-dark">段取り(分)</th>
              <th class="table-dark">正味作業(分)<br><small>基準本数分</small></th>
              <th class="table-dark">アロー率(%)</th>
              <th class="table-dark">固定アロー(分)</th>
              <th class="table-dark">理由</th>
              <th class="table-dark">人数</th>
              <th class="table-dark">難易度</th>
              <th class="table-dark">並行</th>
              <th class="table-dark">順番</th>
              <th class="table-dark">有効</th>
              <th class="table-warning">外注</th>
              <th class="table-warning">外注先</th>
              <th class="table-warning">外注LT(日)</th>
              <th class="table-danger">一時除外</th>
              <th class="table-danger">除外理由</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($allProcesses as $p): ?>
            <?php $s = $existingStandards[$p['id']] ?? []; ?>
            <?php $isOut = !empty($s['is_outsourced']); $isExcl = !empty($s['is_temporarily_excluded']); ?>
            <input type="hidden" name="process_id[]" value="<?= $p['id'] ?>">
            <tr class="<?= $isExcl ? 'table-danger' : ($isOut ? 'table-warning' : '') ?>">
              <td class="text-center text-muted"><small><?= h($p['process_code']) ?></small></td>
              <td><strong><?= h($p['process_name']) ?></strong></td>
              <td><input type="number" name="base_quantity_<?= $p['id'] ?>" class="form-control form-control-sm"
                         value="<?= h($s['base_quantity'] ?? 1) ?>" min="1" style="width:65px"></td>
              <td><input type="number" name="setup_minutes_<?= $p['id'] ?>" class="form-control form-control-sm"
                         value="<?= h($s['setup_minutes'] ?? 0) ?>" min="0" step="0.5" style="width:70px"></td>
              <td><input type="number" name="base_work_minutes_<?= $p['id'] ?>" class="form-control form-control-sm"
                         value="<?= h($s['base_work_minutes'] ?? 0) ?>" min="0" step="0.5" style="width:75px"></td>
              <td><input type="number" name="allowance_rate_<?= $p['id'] ?>" class="form-control form-control-sm"
                         value="<?= h($s['allowance_rate'] ?? 0) ?>" min="0" step="0.1" style="width:65px"></td>
              <td><input type="number" name="allowance_minutes_<?= $p['id'] ?>" class="form-control form-control-sm"
                         value="<?= h($s['allowance_minutes'] ?? 0) ?>" min="0" step="0.5" style="width:65px"></td>
              <td><input type="text" name="allowance_reason_<?= $p['id'] ?>" class="form-control form-control-sm"
                         value="<?= h($s['allowance_reason'] ?? '') ?>" style="width:110px"></td>
              <td><input type="number" name="standard_workers_<?= $p['id'] ?>" class="form-control form-control-sm"
                         value="<?= h($s['standard_workers'] ?? 1) ?>" min="1" style="width:55px"></td>
              <td>
                <select name="difficulty_level_<?= $p['id'] ?>" class="form-select form-select-sm" style="width:65px">
                  <?php for ($lv=1; $lv<=5; $lv++): ?>
                  <option value="<?= $lv ?>" <?= ($s['difficulty_level'] ?? 1) == $lv ? 'selected' : '' ?>><?= $lv ?></option>
                  <?php endfor; ?>
                </select>
              </td>
              <td class="text-center">
                <input type="checkbox" name="can_start_parallel_<?= $p['id'] ?>"
                       <?= ($s['can_start_parallel'] ?? 1) ? 'checked' : '' ?>>
              </td>
              <td><input type="number" name="display_order_<?= $p['id'] ?>" class="form-control form-control-sm"
                         value="<?= h($s['display_order'] ?? 0) ?>" style="width:55px"></td>
              <td class="text-center">
                <input type="checkbox" name="is_active_<?= $p['id'] ?>"
                       <?= ($s['is_active'] ?? 1) ? 'checked' : '' ?>>
              </td>
              <td class="text-center">
                <input type="checkbox" name="is_outsourced_<?= $p['id'] ?>"
                       class="outsource-toggle" data-pid="<?= $p['id'] ?>"
                       <?= $isOut ? 'checked' : '' ?>>
              </td>
              <td>
                <input type="text" name="outsource_vendor_<?= $p['id'] ?>" class="form-control form-control-sm"
                       value="<?= h($s['outsource_vendor'] ?? '') ?>" placeholder="外注先名"
                       style="width:130px" <?= !$isOut ? 'disabled' : '' ?>>
              </td>
              <td>
                <input type="number" name="outsource_lead_days_<?= $p['id'] ?>" class="form-control form-control-sm"
                       value="<?= h($s['outsource_lead_days'] ?? 0) ?>" min="0"
                       style="width:65px" <?= !$isOut ? 'disabled' : '' ?>>
              </td>
              <td class="text-center">
                <input type="checkbox" name="is_temporarily_excluded_<?= $p['id'] ?>"
                       class="exclude-toggle" data-pid="<?= $p['id'] ?>"
                       <?= $isExcl ? 'checked' : '' ?>>
              </td>
              <td>
                <input type="text" name="excluded_reason_<?= $p['id'] ?>" class="form-control form-control-sm"
                       value="<?= h($s['excluded_reason'] ?? '') ?>" placeholder="除外理由"
                       style="width:140px" <?= !$isExcl ? 'disabled' : '' ?>>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="mt-2">
    <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> 一括保存</button>
    <a href="chair_type_form.php?id=<?= $chairTypeId ?>" class="btn btn-outline-secondary ms-2">タイプ詳細へ戻る</a>
  </div>
</form>
<?php endif; ?>

<?php
$extraJs = <<<JS
document.querySelectorAll('.outsource-toggle').forEach(cb => {
    cb.addEventListener('change', function() {
        const pid = this.dataset.pid;
        const dis = !this.checked;
        document.querySelector('[name="outsource_vendor_'+pid+'"]').disabled = dis;
        document.querySelector('[name="outsource_lead_days_'+pid+'"]').disabled = dis;
    });
});
document.querySelectorAll('.exclude-toggle').forEach(cb => {
    cb.addEventListener('change', function() {
        const pid = this.dataset.pid;
        document.querySelector('[name="excluded_reason_'+pid+'"]').disabled = !this.checked;
    });
});
JS;
require __DIR__ . '/parts/footer.php'; ?>
