<?php
// =====================================================
// 工程標準時間管理
// 目的: 製品タイプごとの段取り・作業時間・アローアンスを設定
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction      = postStr('action', 'save_all');
    $postChairTypeId = postInt('chair_type_id');

    // --- 工程をこの製品タイプに追加 ---
    if ($postAction === 'add_process') {
        $pid = postInt('new_process_id');
        if ($pid && $postChairTypeId) {
            dbExecute(
                "INSERT IGNORE INTO chair_type_process_standards
                    (chair_type_id, process_id, base_quantity, setup_minutes, base_work_minutes,
                     allowance_rate, allowance_minutes, standard_workers, difficulty_level,
                     can_start_parallel, display_order, is_active)
                 VALUES (?,?,1,0,0,0,0,1,1,1,0,1)",
                [$postChairTypeId, $pid]
            );
            setFlash('工程を追加しました。');
        }
        header('Location: ' . APP_URL . '/standards.php?chair_type_id=' . $postChairTypeId);
        exit;
    }

    // --- 工程をこの製品タイプから削除 ---
    if ($postAction === 'delete_process') {
        $pid = postInt('del_process_id');
        if ($pid && $postChairTypeId) {
            dbExecute(
                "DELETE FROM chair_type_process_standards WHERE chair_type_id=? AND process_id=?",
                [$postChairTypeId, $pid]
            );
            setFlash('工程を削除しました。', 'warning');
        }
        header('Location: ' . APP_URL . '/standards.php?chair_type_id=' . $postChairTypeId);
        exit;
    }

    // --- 一括保存 ---
    $processIds = $_POST['process_id'] ?? [];
    foreach ($processIds as $pid) {
        $pid = (int)$pid;
        $data = [
            'dept_id'                 => postInt("dept_id_{$pid}") ?: null,
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
                (chair_type_id, process_id, dept_id, base_quantity, setup_minutes, base_work_minutes,
                 allowance_rate, allowance_minutes, allowance_reason, standard_workers,
                 difficulty_level, can_start_parallel, display_order, is_active,
                 is_outsourced, outsource_vendor, outsource_lead_days,
                 is_temporarily_excluded, excluded_reason)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                dept_id=VALUES(dept_id),
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
                $postChairTypeId, $pid, $data['dept_id'],
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

// この製品タイプに登録済みの工程（存在する標準レコード）
$existingStandards  = [];
$existingProcessIds = [];
if ($chairTypeId) {
    $rows = dbFetchAll(
        "SELECT s.*, p.process_name, p.process_code, d.dept_name
         FROM chair_type_process_standards s
         JOIN processes p ON s.process_id = p.id
         LEFT JOIN departments d ON s.dept_id = d.id
         WHERE s.chair_type_id = ?
         ORDER BY s.display_order, p.display_order",
        [$chairTypeId]
    );
    foreach ($rows as $r) {
        $existingStandards[$r['process_id']] = $r;
        $existingProcessIds[] = $r['process_id'];
    }
}

// 追加可能な工程（まだ登録されていない）
$addableProcesses = dbFetchAll(
    empty($existingProcessIds)
        ? "SELECT * FROM processes WHERE is_active=1 ORDER BY display_order"
        : "SELECT * FROM processes WHERE is_active=1 AND id NOT IN (" . implode(',', $existingProcessIds) . ") ORDER BY display_order"
);

// 部署一覧
$departments = dbFetchAll("SELECT id, dept_name FROM departments WHERE is_active=1 ORDER BY display_order");

// 製品タイプ一覧（選択用）
$chairTypes = dbFetchAll(
    "SELECT ct.id, ct.chair_type_code, ct.chair_type_name, g.group_name
     FROM chair_types ct JOIN chair_type_groups g ON ct.chair_type_group_id = g.id
     WHERE ct.is_active = 1 ORDER BY ct.chair_type_code"
);

require __DIR__ . '/parts/header.php';
?>

<h2><i class="bi bi-clock"></i> 工程標準時間管理</h2>

<!-- 製品タイプ選択 -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">製品タイプを選択</label>
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
<div class="alert alert-info d-flex justify-content-between align-items-center">
  <span><strong><?= h($chairType['chair_type_code']) ?></strong> - <?= h($chairType['chair_type_name']) ?>
  | 基本本数: <?= h($chairType['base_quantity']) ?>本
  | 登録工程: <strong><?= count($existingStandards) ?></strong>件</span>
  <a href="chair_type_form.php?id=<?= $chairTypeId ?>" class="btn btn-sm btn-outline-secondary">タイプ詳細へ戻る</a>
</div>

<!-- 工程追加 -->
<?php if (!empty($addableProcesses)): ?>
<div class="card mb-3 border-success">
  <div class="card-header bg-success text-white"><i class="bi bi-plus-circle"></i> この製品タイプに工程を追加</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-center">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_process">
      <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
      <div class="col-md-5">
        <select name="new_process_id" class="form-select" required>
          <option value="">― 追加する工程を選択 ―</option>
          <?php foreach ($addableProcesses as $p): ?>
          <option value="<?= $p['id'] ?>"><?= h($p['process_code']) ?> - <?= h($p['process_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-success"><i class="bi bi-plus"></i> 追加</button>
      </div>
      <div class="col">
        <small class="text-muted">追加後に標準時間・責任部署を設定して保存してください。</small>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($existingStandards)): ?>
<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save_all">
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
        <table class="table table-bordered table-sm mb-0" style="min-width:1500px">
          <thead class="text-center">
            <tr>
              <th class="table-dark" colspan="2">工程</th>
              <th class="table-info">責任部署</th>
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
              <th class="table-secondary">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($existingStandards as $pid => $s): ?>
            <?php $isOut = !empty($s['is_outsourced']); $isExcl = !empty($s['is_temporarily_excluded']); ?>
            <input type="hidden" name="process_id[]" value="<?= $pid ?>">
            <tr class="<?= $isExcl ? 'table-danger' : ($isOut ? 'table-warning' : '') ?>">
              <td class="text-center text-muted"><small><?= h($s['process_code']) ?></small></td>
              <td><strong><?= h($s['process_name']) ?></strong></td>
              <td>
                <select name="dept_id_<?= $pid ?>" class="form-select form-select-sm" style="width:110px">
                  <option value="">―</option>
                  <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= ($s['dept_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['dept_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" name="base_quantity_<?= $pid ?>" class="form-control form-control-sm"
                         value="<?= h($s['base_quantity'] ?? 1) ?>" min="1" style="width:65px"></td>
              <td><input type="number" name="setup_minutes_<?= $pid ?>" class="form-control form-control-sm"
                         value="<?= h($s['setup_minutes'] ?? 0) ?>" min="0" step="0.5" style="width:70px"></td>
              <td><input type="number" name="base_work_minutes_<?= $pid ?>" class="form-control form-control-sm"
                         value="<?= h($s['base_work_minutes'] ?? 0) ?>" min="0" step="0.5" style="width:75px"></td>
              <td><input type="number" name="allowance_rate_<?= $pid ?>" class="form-control form-control-sm"
                         value="<?= h($s['allowance_rate'] ?? 0) ?>" min="0" step="0.1" style="width:65px"></td>
              <td><input type="number" name="allowance_minutes_<?= $pid ?>" class="form-control form-control-sm"
                         value="<?= h($s['allowance_minutes'] ?? 0) ?>" min="0" step="0.5" style="width:65px"></td>
              <td><input type="text" name="allowance_reason_<?= $pid ?>" class="form-control form-control-sm"
                         value="<?= h($s['allowance_reason'] ?? '') ?>" style="width:110px"></td>
              <td><input type="number" name="standard_workers_<?= $pid ?>" class="form-control form-control-sm"
                         value="<?= h($s['standard_workers'] ?? 1) ?>" min="1" style="width:55px"></td>
              <td>
                <select name="difficulty_level_<?= $pid ?>" class="form-select form-select-sm" style="width:65px">
                  <?php for ($lv=1; $lv<=5; $lv++): ?>
                  <option value="<?= $lv ?>" <?= ($s['difficulty_level'] ?? 1) == $lv ? 'selected' : '' ?>><?= $lv ?></option>
                  <?php endfor; ?>
                </select>
              </td>
              <td class="text-center">
                <input type="checkbox" name="can_start_parallel_<?= $pid ?>"
                       <?= ($s['can_start_parallel'] ?? 1) ? 'checked' : '' ?>>
              </td>
              <td><input type="number" name="display_order_<?= $pid ?>" class="form-control form-control-sm"
                         value="<?= h($s['display_order'] ?? 0) ?>" style="width:55px"></td>
              <td class="text-center">
                <input type="checkbox" name="is_active_<?= $pid ?>"
                       <?= ($s['is_active'] ?? 1) ? 'checked' : '' ?>>
              </td>
              <td class="text-center">
                <input type="checkbox" name="is_outsourced_<?= $pid ?>"
                       class="outsource-toggle" data-pid="<?= $pid ?>"
                       <?= $isOut ? 'checked' : '' ?>>
              </td>
              <td>
                <input type="text" name="outsource_vendor_<?= $pid ?>" class="form-control form-control-sm"
                       value="<?= h($s['outsource_vendor'] ?? '') ?>" placeholder="外注先名"
                       style="width:130px" <?= !$isOut ? 'disabled' : '' ?>>
              </td>
              <td>
                <input type="number" name="outsource_lead_days_<?= $pid ?>" class="form-control form-control-sm"
                       value="<?= h($s['outsource_lead_days'] ?? 0) ?>" min="0"
                       style="width:65px" <?= !$isOut ? 'disabled' : '' ?>>
              </td>
              <td class="text-center">
                <input type="checkbox" name="is_temporarily_excluded_<?= $pid ?>"
                       class="exclude-toggle" data-pid="<?= $pid ?>"
                       <?= $isExcl ? 'checked' : '' ?>>
              </td>
              <td>
                <input type="text" name="excluded_reason_<?= $pid ?>" class="form-control form-control-sm"
                       value="<?= h($s['excluded_reason'] ?? '') ?>" placeholder="除外理由"
                       style="width:140px" <?= !$isExcl ? 'disabled' : '' ?>>
              </td>
              <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger"
                        onclick="delProcess(<?= $pid ?>, '<?= h($s['process_name']) ?>')">
                  <i class="bi bi-trash"></i>
                </button>
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
<?php else: ?>
  <div class="alert alert-warning"><i class="bi bi-info-circle"></i> この製品タイプにはまだ工程が登録されていません。上の「工程を追加」から追加してください。</div>
<?php endif; ?>

<!-- 工程削除用の隠しフォーム -->
<form id="delProcForm" method="post" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete_process">
  <input type="hidden" name="chair_type_id" value="<?= $chairTypeId ?>">
  <input type="hidden" name="del_process_id" id="delProcId">
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
function delProcess(pid, name) {
    if (!confirm('「' + name + '」をこの製品タイプから削除しますか？\\n既存の作業指示には影響しません。')) return;
    document.getElementById('delProcId').value = pid;
    document.getElementById('delProcForm').submit();
}
JS;
require __DIR__ . '/parts/footer.php'; ?>
