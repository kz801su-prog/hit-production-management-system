<?php
// =====================================================
// 社員詳細・編集ページ
// タブ: 基本情報 | 職能ランク | 職歴 | 編集履歴
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

$id = getInt('id');
if (!$id) {
    header('Location: ' . APP_URL . '/employees.php');
    exit;
}

$employee = dbFetchOne(
    "SELECT e.*, d.dept_name, sec.section_name, p.position_name
     FROM employees e
     LEFT JOIN departments d   ON e.department_id = d.id
     LEFT JOIN sections    sec ON e.section_id     = sec.id
     LEFT JOIN positions   p   ON e.position_id    = p.id
     WHERE e.id = ?",
    [$id]
);
if (!$employee) {
    setFlash('社員が見つかりません。', 'danger');
    header('Location: ' . APP_URL . '/employees.php');
    exit;
}

$currentUser = getCurrentUser();

// =====================================================
// POST 処理
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    // --- 基本情報更新 ---
    if ($postAction === 'update_basic') {
        $fields = [
            'employee_code'     => postStr('employee_code'),
            'name'              => postStr('name'),
            'name_kana'         => postStr('name_kana'),
            'email'             => postStr('email'),
            'phone'             => postStr('phone'),
            'address'           => postStr('address'),
            'joined_date'       => postStr('joined_date') ?: null,
            'retired_date'      => postStr('retired_date') ?: null,
            'employment_status' => postStr('employment_status', 'active'),
            'department_id'     => postInt('department_id') ?: null,
            'section_id'        => postInt('section_id')    ?: null,
            'position_id'       => postInt('position_id')   ?: null,
        ];

        // 退職日の自動セット
        if ($fields['employment_status'] === 'retired' && !$fields['retired_date']) {
            $fields['retired_date'] = date('Y-m-d');
        }
        // 退職したらユーザーアカウントを無効化
        if ($fields['employment_status'] === 'retired'
            && $employee['employment_status'] !== 'retired') {
            dbExecute("UPDATE users SET is_active = 0 WHERE employee_id = ?", [$id]);
        }
        // 復帰したら再有効化
        if ($fields['employment_status'] === 'active'
            && $employee['employment_status'] === 'retired') {
            $fields['retired_date'] = null;
            dbExecute("UPDATE users SET is_active = 1 WHERE employee_id = ?", [$id]);
        }

        // 差分を編集履歴に記録
        $labelMap = [
            'employee_code' => '社員コード', 'name' => '氏名', 'name_kana' => '氏名カナ',
            'email' => 'メール', 'phone' => '電話', 'address' => '住所',
            'joined_date' => '入社日', 'retired_date' => '退社日',
            'employment_status' => '在籍状態',
            'department_id' => '部署ID', 'section_id' => '課ID', 'position_id' => '役職ID',
        ];
        foreach ($fields as $col => $newVal) {
            $oldVal = $employee[$col] ?? null;
            if ((string)$oldVal !== (string)($newVal ?? '')) {
                dbExecute(
                    "INSERT INTO employee_edit_logs
                        (employee_id, field_name, old_value, new_value, changed_by_user_id)
                     VALUES (?, ?, ?, ?, ?)",
                    [$id, $labelMap[$col] ?? $col, $oldVal, $newVal, $currentUser['id']]
                );
            }
        }

        dbExecute(
            "UPDATE employees SET
                employee_code=?, name=?, name_kana=?, email=?, phone=?, address=?,
                joined_date=?, retired_date=?, employment_status=?,
                department_id=?, section_id=?, position_id=?
             WHERE id=?",
            array_merge(array_values($fields), [$id])
        );
        auditLog('update', 'employees', $id, $employee, $fields);
        setFlash('社員情報を更新しました。');
        header('Location: ' . APP_URL . '/employee_detail.php?id=' . $id . '&tab=basic');
        exit;
    }

    // --- 職能ランク保存 ---
    if ($postAction === 'update_skills') {
        $processIds = $_POST['process_id'] ?? [];
        foreach ($processIds as $pid) {
            $pid  = (int)$pid;
            $rank = (int)($_POST["rank_{$pid}"] ?? 0);
            $memo = trim($_POST["memo_{$pid}"] ?? '');
            if ($rank < 1) continue;
            dbExecute(
                "INSERT INTO employee_skill_ranks
                    (employee_id, process_id, rank_level, memo, updated_by_user_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    rank_level=VALUES(rank_level), memo=VALUES(memo),
                    updated_by_user_id=VALUES(updated_by_user_id), updated_at=NOW()",
                [$id, $pid, $rank, $memo ?: null, $currentUser['id']]
            );
        }
        setFlash('職能ランクを保存しました。');
        header('Location: ' . APP_URL . '/employee_detail.php?id=' . $id . '&tab=skills');
        exit;
    }
}

// =====================================================
// データ取得
// =====================================================
$tab = $_GET['tab'] ?? 'basic';

$departments = dbFetchAll("SELECT * FROM departments WHERE is_active=1 ORDER BY display_order");
$sections    = dbFetchAll("SELECT * FROM sections    WHERE is_active=1 ORDER BY display_order");
$positions   = dbFetchAll("SELECT * FROM positions   WHERE is_active=1 ORDER BY display_order");
$allProcesses = dbFetchAll("SELECT * FROM processes WHERE is_active=1 ORDER BY display_order");

// 職能ランク
$skillMap = [];
$skillRows = dbFetchAll(
    "SELECT * FROM employee_skill_ranks WHERE employee_id = ?", [$id]
);
foreach ($skillRows as $r) {
    $skillMap[$r['process_id']] = $r;
}

// 職歴（work_logs より）
$workHistory = dbFetchAll(
    "SELECT wl.started_at, wl.ended_at,
            ROUND(TIMESTAMPDIFF(MINUTE, wl.started_at, wl.ended_at) / 60.0, 2) AS hours,
            p.process_name, mo.order_no, ct.chair_type_name
     FROM work_logs wl
     JOIN processes p            ON wl.process_id = p.id
     JOIN manufacturing_orders mo ON wl.manufacturing_order_id = mo.id
     JOIN chair_types ct          ON mo.chair_type_id = ct.id
     WHERE wl.employee_id = ? AND wl.ended_at IS NOT NULL
     ORDER BY wl.started_at DESC
     LIMIT 100",
    [$id]
);

// 工程別合計時間
$workSummary = dbFetchAll(
    "SELECT p.process_name,
            COUNT(*)  AS session_count,
            ROUND(SUM(TIMESTAMPDIFF(MINUTE, wl.started_at, wl.ended_at)) / 60.0, 1) AS total_hours
     FROM work_logs wl
     JOIN processes p ON wl.process_id = p.id
     WHERE wl.employee_id = ? AND wl.ended_at IS NOT NULL
     GROUP BY p.id, p.process_name
     ORDER BY total_hours DESC",
    [$id]
);

// 編集履歴
$editLogs = dbFetchAll(
    "SELECT el.*, u.login_id AS editor_login,
            COALESCE(e2.name, u.login_id) AS editor_name
     FROM employee_edit_logs el
     LEFT JOIN users u ON el.changed_by_user_id = u.id
     LEFT JOIN employees e2 ON u.employee_id = e2.id
     WHERE el.employee_id = ?
     ORDER BY el.changed_at DESC
     LIMIT 200",
    [$id]
);

// 在籍日数
$daysSince = null;
if ($employee['joined_date']) {
    $daysSince = (new DateTime())->diff(new DateTime($employee['joined_date']))->days;
}

$pageTitle = '社員詳細：' . $employee['name'];
require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3 align-items-center">
  <div class="col">
    <h2><i class="bi bi-person-lines-fill"></i>
      <?= h($employee['name']) ?>
      <small class="text-muted fs-6"><?= h($employee['employee_code']) ?></small>
      <?php
        $stBadge = match($employee['employment_status']) {
            'active'  => ['success', '在籍中'],
            'leave'   => ['warning', '休職中'],
            'retired' => ['secondary','退職済'],
            default   => ['secondary', $employee['employment_status']],
        };
      ?>
      <span class="badge bg-<?= $stBadge[0] ?>"><?= $stBadge[1] ?></span>
    </h2>
  </div>
  <div class="col-auto">
    <a href="employees.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> 一覧へ
    </a>
  </div>
</div>

<?= getFlashHtml() ?>

<!-- タブ -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'basic'   ? 'active' : '' ?>"
       href="?id=<?= $id ?>&tab=basic">
      <i class="bi bi-person"></i> 基本情報
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'skills'  ? 'active' : '' ?>"
       href="?id=<?= $id ?>&tab=skills">
      <i class="bi bi-award"></i> 職能ランク
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>"
       href="?id=<?= $id ?>&tab=history">
      <i class="bi bi-clock-history"></i> 職歴
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'logs'    ? 'active' : '' ?>"
       href="?id=<?= $id ?>&tab=logs">
      <i class="bi bi-journal-text"></i> 編集履歴
      <?php if ($editLogs): ?>
        <span class="badge bg-secondary"><?= count($editLogs) ?></span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<?php /* ===== 基本情報 ===== */ if ($tab === 'basic'): ?>
<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="update_basic">
  <div class="row g-3">
    <div class="col-md-4">
      <label class="form-label">社員コード <span class="text-danger">*</span></label>
      <input type="text" name="employee_code" class="form-control" required
             value="<?= h($employee['employee_code']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">氏名 <span class="text-danger">*</span></label>
      <input type="text" name="name" class="form-control" required
             value="<?= h($employee['name']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">氏名カナ</label>
      <input type="text" name="name_kana" class="form-control"
             value="<?= h($employee['name_kana'] ?? '') ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">メールアドレス</label>
      <input type="email" name="email" class="form-control"
             value="<?= h($employee['email'] ?? '') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">電話番号</label>
      <input type="text" name="phone" class="form-control"
             value="<?= h($employee['phone'] ?? '') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">在籍状態</label>
      <select name="employment_status" class="form-select" id="statusSelect">
        <?php foreach (['active'=>'在籍','leave'=>'休職','retired'=>'退職'] as $v => $l): ?>
        <option value="<?= $v ?>" <?= $employee['employment_status'] === $v ? 'selected' : '' ?>>
          <?= $l ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12">
      <label class="form-label">住所</label>
      <input type="text" name="address" class="form-control"
             value="<?= h($employee['address'] ?? '') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">入社日</label>
      <input type="date" name="joined_date" class="form-control"
             value="<?= h($employee['joined_date'] ?? '') ?>">
      <?php if ($daysSince !== null): ?>
        <div class="form-text">在籍 <strong><?= number_format($daysSince) ?>日</strong>
        （<?= floor($daysSince / 365) ?>年 <?= floor(($daysSince % 365) / 30) ?>ヶ月）</div>
      <?php endif; ?>
    </div>
    <div class="col-md-3" id="retiredDateWrap"
         <?= $employee['employment_status'] !== 'retired' ? 'style="display:none"' : '' ?>>
      <label class="form-label">退社日</label>
      <input type="date" name="retired_date" id="retiredDateInput" class="form-control"
             value="<?= h($employee['retired_date'] ?? '') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">部署</label>
      <select name="department_id" class="form-select">
        <option value="">―</option>
        <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>"
          <?= $employee['department_id'] == $d['id'] ? 'selected' : '' ?>>
          <?= h($d['dept_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">課</label>
      <select name="section_id" class="form-select">
        <option value="">―</option>
        <?php foreach ($sections as $s): ?>
        <option value="<?= $s['id'] ?>"
          <?= $employee['section_id'] == $s['id'] ? 'selected' : '' ?>>
          <?= h($s['section_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">役職</label>
      <select name="position_id" class="form-select">
        <option value="">―</option>
        <?php foreach ($positions as $p): ?>
        <option value="<?= $p['id'] ?>"
          <?= $employee['position_id'] == $p['id'] ? 'selected' : '' ?>>
          <?= h($p['position_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="mt-3">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> 更新
    </button>
    <?php if ($employee['employment_status'] !== 'retired'
             && isPresidentOrAdmin()): ?>
    <button type="button" class="btn btn-outline-danger ms-2"
            onclick="confirmRetire()">
      <i class="bi bi-door-open"></i> 退職処理
    </button>
    <?php endif; ?>
  </div>
</form>

<?php elseif ($tab === 'skills'): ?>
<!-- ===== 職能ランク ===== -->
<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="update_skills">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="bi bi-award"></i> 職能ランク（工程別）</span>
      <button type="submit" class="btn btn-sm btn-success">
        <i class="bi bi-save"></i> 保存
      </button>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm table-bordered mb-0">
        <thead class="table-dark text-center">
          <tr>
            <th>工程</th>
            <th>ランク</th>
            <th style="width:280px">備考</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $rankLabels = [0=>'未設定',1=>'見習い',2=>'補助',3=>'一般',4=>'熟練',5=>'マスター'];
        foreach ($allProcesses as $p):
            $sk = $skillMap[$p['id']] ?? [];
            $currentRank = (int)($sk['rank_level'] ?? 0);
        ?>
          <input type="hidden" name="process_id[]" value="<?= $p['id'] ?>">
          <tr>
            <td><strong><?= h($p['process_name']) ?></strong>
              <small class="text-muted">(<?= h($p['process_code']) ?>)</small>
            </td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <select name="rank_<?= $p['id'] ?>" class="form-select form-select-sm" style="width:130px">
                  <?php foreach ($rankLabels as $rv => $rl): ?>
                  <option value="<?= $rv ?>" <?= $currentRank === $rv ? 'selected' : '' ?>>
                    <?= $rv > 0 ? "Lv{$rv} " : '' ?><?= $rl ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($currentRank > 0): ?>
                  <?php
                  $starColors = ['','warning','warning','info','primary','danger'];
                  ?>
                  <span class="text-<?= $starColors[$currentRank] ?>">
                    <?= str_repeat('★', $currentRank) ?><?= str_repeat('☆', 5 - $currentRank) ?>
                  </span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <input type="text" name="memo_<?= $p['id'] ?>" class="form-control form-control-sm"
                     value="<?= h($sk['memo'] ?? '') ?>" placeholder="備考">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php elseif ($tab === 'history'): ?>
<!-- ===== 職歴 ===== -->
<div class="row g-3">
  <div class="col-md-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-bar-chart"></i> 工程別合計</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th>工程</th><th class="text-end">回数</th><th class="text-end">合計時間</th></tr>
          </thead>
          <tbody>
          <?php if ($workSummary): ?>
            <?php foreach ($workSummary as $ws): ?>
            <tr>
              <td><?= h($ws['process_name']) ?></td>
              <td class="text-end"><?= $ws['session_count'] ?></td>
              <td class="text-end fw-bold"><?= $ws['total_hours'] ?>h</td>
            </tr>
            <?php endforeach; ?>
            <tr class="table-info fw-bold">
              <td>合計</td>
              <td class="text-end"><?= array_sum(array_column($workSummary, 'session_count')) ?></td>
              <td class="text-end"><?= array_sum(array_column($workSummary, 'total_hours')) ?>h</td>
            </tr>
          <?php else: ?>
            <tr><td colspan="3" class="text-muted text-center py-2">作業実績なし</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card">
      <div class="card-header"><i class="bi bi-list-ul"></i> 作業履歴（直近100件）</div>
      <div class="card-body p-0">
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
              <tr><th>日時</th><th>工程</th><th>作業指示</th><th>椅子タイプ</th><th class="text-end">時間</th></tr>
            </thead>
            <tbody>
            <?php if ($workHistory): ?>
              <?php foreach ($workHistory as $wh): ?>
              <tr>
                <td><small><?= formatDatetime($wh['started_at']) ?></small></td>
                <td><?= h($wh['process_name']) ?></td>
                <td><?= h($wh['order_no']) ?></td>
                <td><small><?= h($wh['chair_type_name']) ?></small></td>
                <td class="text-end"><?= $wh['hours'] ?>h</td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="5" class="text-muted text-center py-3">作業実績なし</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'logs'): ?>
<!-- ===== 編集履歴 ===== -->
<div class="card">
  <div class="card-header"><i class="bi bi-journal-text"></i> 編集履歴</div>
  <div class="card-body p-0">
    <?php if ($editLogs): ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-dark">
          <tr><th>日時</th><th>変更者</th><th>項目</th><th>変更前</th><th>変更後</th></tr>
        </thead>
        <tbody>
        <?php foreach ($editLogs as $log): ?>
          <tr>
            <td><small><?= formatDatetime($log['changed_at']) ?></small></td>
            <td><?= h($log['editor_name'] ?? $log['editor_login'] ?? '?') ?></td>
            <td><strong><?= h($log['field_name']) ?></strong></td>
            <td class="text-danger"><small><?= h($log['old_value'] ?? '(空)') ?></small></td>
            <td class="text-success"><small><?= h($log['new_value'] ?? '(空)') ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="p-3 text-muted text-center">編集履歴はありません</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = <<<JS
// 退職状態切り替えで退社日欄を表示/非表示
document.getElementById('statusSelect')?.addEventListener('change', function() {
    const wrap  = document.getElementById('retiredDateWrap');
    const input = document.getElementById('retiredDateInput');
    if (this.value === 'retired') {
        wrap.style.display = '';
        if (!input.value) {
            const now = new Date();
            input.value = now.toISOString().split('T')[0];
        }
    } else {
        wrap.style.display = 'none';
    }
});
function confirmRetire() {
    if (!confirm('退職処理を行います。ユーザーアカウントが無効化されます。よろしいですか？')) return;
    document.getElementById('statusSelect').value = 'retired';
    document.getElementById('statusSelect').dispatchEvent(new Event('change'));
    document.querySelector('form [type="submit"]').click();
}
JS;
require __DIR__ . '/parts/footer.php'; ?>
