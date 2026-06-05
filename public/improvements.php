<?php
// =====================================================
// 改善管理ページ
// 目的: 問題点の記録・改善アクションの登録・進捗管理
// 接続テーブル: issue_logs, improvement_actions, employees, processes
// 権限: 問題登録=全員可、改善アクション登録=process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
$pageTitle = '改善管理';

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    if ($postAction === 'add_issue') {
        dbExecute(
            "INSERT INTO issue_logs (manufacturing_order_id, process_id, employee_id, issue_type, issue_detail, impact_minutes)
             VALUES (?,?,?,?,?,?)",
            [
                postInt('manufacturing_order_id') ?: null,
                postInt('process_id') ?: null,
                $user['employee_id'],
                postStr('issue_type'),
                postStr('issue_detail'),
                postFloat('impact_minutes'),
            ]
        );
        setFlash('問題点を登録しました。');
    }

    if ($postAction === 'add_improvement' && isLeader()) {
        dbExecute(
            "INSERT INTO improvement_actions
                (issue_id, improvement_title, improvement_detail, responsible_employee_id,
                 expected_effect_minutes, due_date, status)
             VALUES (?,?,?,?,?,?,?)",
            [
                postInt('issue_id') ?: null,
                postStr('improvement_title'),
                postStr('improvement_detail'),
                postInt('responsible_employee_id') ?: null,
                postFloat('expected_effect_minutes'),
                postStr('due_date') ?: null,
                'planned',
            ]
        );
        setFlash('改善アクションを登録しました。');
    }

    if ($postAction === 'update_improvement_status' && isLeader()) {
        $impId  = postInt('improvement_id');
        $status = postStr('status');
        $actualEffect = postFloat('actual_effect_minutes');
        dbExecute(
            "UPDATE improvement_actions SET status = ?, actual_effect_minutes = ?,
             completed_at = IF(? = 'done', NOW(), NULL)
             WHERE id = ?",
            [$status, $actualEffect, $status, $impId]
        );
        setFlash('改善アクションを更新しました。');
    }

    header('Location: ' . APP_URL . '/improvements.php');
    exit;
}

// データ取得
$issues = dbFetchAll(
    "SELECT il.*, mo.order_no, p.process_name, e.name AS reporter_name
     FROM issue_logs il
     LEFT JOIN manufacturing_orders mo ON il.manufacturing_order_id = mo.id
     LEFT JOIN processes p ON il.process_id = p.id
     LEFT JOIN employees e ON il.employee_id = e.id
     ORDER BY il.created_at DESC
     LIMIT 50"
);

$improvements = dbFetchAll(
    "SELECT ia.*, e.name AS responsible_name,
            il.issue_detail AS issue_summary
     FROM improvement_actions ia
     LEFT JOIN employees e ON ia.responsible_employee_id = e.id
     LEFT JOIN issue_logs il ON ia.issue_id = il.id
     ORDER BY FIELD(ia.status,'doing','planned','done','cancelled'), ia.due_date
     LIMIT 50"
);

$orders = dbFetchAll(
    "SELECT id, order_no FROM manufacturing_orders WHERE status != 'cancelled' ORDER BY id DESC LIMIT 50"
);
$processes   = dbFetchAll("SELECT * FROM processes WHERE is_active=1 ORDER BY display_order");
$employees   = dbFetchAll(
    "SELECT e.id, e.name FROM employees e WHERE e.is_active=1 ORDER BY e.employee_code"
);

$issueTypeLabels = [
    'material'        => '資材',
    'previous_process'=> '前工程',
    'skill'           => '技能',
    'machine'         => '設備',
    'instruction'     => '指示',
    'quality'         => '品質',
    'other'           => 'その他',
];
$improvStatusLabels = [
    'planned'   => ['label' => '計画中', 'class' => 'secondary'],
    'doing'     => ['label' => '対応中', 'class' => 'primary'],
    'done'      => ['label' => '完了',   'class' => 'success'],
    'cancelled' => ['label' => 'キャンセル', 'class' => 'secondary'],
];

require __DIR__ . '/parts/header.php';
?>

<div class="row">
  <div class="col-md-6">
    <h2><i class="bi bi-exclamation-triangle"></i> 問題点一覧</h2>
  </div>
  <div class="col-md-6">
    <h2><i class="bi bi-lightbulb"></i> 改善アクション</h2>
  </div>
</div>

<div class="row g-3">
  <!-- 問題点 -->
  <div class="col-md-6">
    <!-- 問題点登録フォーム -->
    <div class="card mb-3">
      <div class="card-header bg-danger text-white">問題点を報告</div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_issue">
          <div class="row g-2">
            <div class="col-6">
              <select name="manufacturing_order_id" class="form-select form-select-sm">
                <option value="">作業指示（任意）</option>
                <?php foreach ($orders as $o): ?>
                <option value="<?= $o['id'] ?>"><?= h($o['order_no']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <select name="process_id" class="form-select form-select-sm">
                <option value="">工程（任意）</option>
                <?php foreach ($processes as $p): ?>
                <option value="<?= $p['id'] ?>"><?= h($p['process_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <select name="issue_type" class="form-select form-select-sm" required>
                <?php foreach ($issueTypeLabels as $v => $l): ?>
                <option value="<?= $v ?>"><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <input type="number" name="impact_minutes" class="form-control form-control-sm"
                     placeholder="影響時間（分）" min="0" step="0.5" value="0">
            </div>
            <div class="col-12">
              <textarea name="issue_detail" class="form-control form-control-sm" rows="2"
                        placeholder="問題の内容を詳しく記入してください" required></textarea>
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-sm btn-danger">報告する</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- 問題点一覧 -->
    <div class="list-group">
    <?php foreach ($issues as $issue): ?>
      <div class="list-group-item">
        <div class="d-flex justify-content-between">
          <span class="badge bg-danger"><?= $issueTypeLabels[$issue['issue_type']] ?? $issue['issue_type'] ?></span>
          <small class="text-muted"><?= formatDatetime($issue['created_at']) ?></small>
        </div>
        <p class="mb-1 mt-1"><?= h($issue['issue_detail']) ?></p>
        <small class="text-muted">
          <?= $issue['order_no'] ? '作業指示: ' . h($issue['order_no']) : '' ?>
          <?= $issue['process_name'] ? '| 工程: ' . h($issue['process_name']) : '' ?>
          <?= $issue['impact_minutes'] ? '| 影響: ' . formatMinutes($issue['impact_minutes']) : '' ?>
          | 報告者: <?= h($issue['reporter_name'] ?? '―') ?>
        </small>
        <?php if (isLeader()): ?>
        <div class="mt-1">
          <button class="btn btn-xs btn-outline-primary" data-bs-toggle="modal"
                  data-bs-target="#impModal" onclick="setIssueId(<?= $issue['id'] ?>)">
            改善策を追加
          </button>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- 改善アクション -->
  <div class="col-md-6">
    <div class="list-group">
    <?php foreach ($improvements as $imp): ?>
      <?php $si = $improvStatusLabels[$imp['status']] ?? ['label'=>$imp['status'],'class'=>'secondary']; ?>
      <div class="list-group-item">
        <div class="d-flex justify-content-between align-items-start">
          <strong><?= h($imp['improvement_title']) ?></strong>
          <span class="badge bg-<?= $si['class'] ?>"><?= $si['label'] ?></span>
        </div>
        <p class="mb-1 small"><?= h($imp['improvement_detail']) ?></p>
        <small class="text-muted">
          担当: <?= h($imp['responsible_name'] ?? '―') ?>
          | 期限: <?= formatDate($imp['due_date']) ?>
          | 期待効果: <?= formatMinutes($imp['expected_effect_minutes'] ?? 0) ?>
        </small>
        <?php if (isLeader() && $imp['status'] !== 'done'): ?>
        <form method="post" class="d-flex gap-1 mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_improvement_status">
          <input type="hidden" name="improvement_id" value="<?= $imp['id'] ?>">
          <select name="status" class="form-select form-select-sm" style="width:100px">
            <?php foreach ($improvStatusLabels as $sv => $sl): ?>
            <option value="<?= $sv ?>" <?= $imp['status'] === $sv ? 'selected' : '' ?>><?= $sl['label'] ?></option>
            <?php endforeach; ?>
          </select>
          <input type="number" name="actual_effect_minutes" class="form-control form-control-sm"
                 style="width:80px" placeholder="効果分" min="0" step="0.5"
                 value="<?= h($imp['actual_effect_minutes'] ?? 0) ?>">
          <button type="submit" class="btn btn-sm btn-outline-primary">更新</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- 改善アクション追加モーダル -->
<?php if (isLeader()): ?>
<div class="modal fade" id="impModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title">改善アクションを追加</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_improvement">
        <input type="hidden" name="issue_id" id="issueIdInput" value="">
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">タイトル <span class="text-danger">*</span></label>
            <input type="text" name="improvement_title" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">内容</label>
            <textarea name="improvement_detail" class="form-control" rows="3"></textarea>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">責任者</label>
              <select name="responsible_employee_id" class="form-select">
                <option value="">― 選択 ―</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>"><?= h($emp['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">期限</label>
              <input type="date" name="due_date" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label">期待効果（分）</label>
              <input type="number" name="expected_effect_minutes" class="form-control" min="0" step="0.5" value="0">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
          <button type="submit" class="btn btn-success">登録</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php $extraJs = "function setIssueId(id) { document.getElementById('issueIdInput').value = id; }"; ?>
<?php endif; ?>

<?php require __DIR__ . '/parts/footer.php'; ?>
