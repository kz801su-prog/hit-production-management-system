<?php
// =====================================================
// 社員管理ページ
// 目的: 社員の一覧・登録・編集・論理削除
// 接続テーブル: employees, departments, sections, positions
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
$pageTitle = '社員管理';

$action = $_GET['action'] ?? 'list';
$id     = getInt('id');

// --- POST処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    $data = [
        'employee_code'     => postStr('employee_code'),
        'name'              => postStr('name'),
        'name_kana'         => postStr('name_kana'),
        'email'             => postStr('email'),
        'phone'             => postStr('phone'),
        'joined_date'       => postStr('joined_date') ?: null,
        'employment_status' => postStr('employment_status', 'active'),
        'department_id'     => postInt('department_id') ?: null,
        'section_id'        => postInt('section_id')    ?: null,
        'position_id'       => postInt('position_id')   ?: null,
    ];

    if ($postAction === 'create') {
        try {
            $newId = dbExecute(
                "INSERT INTO employees (employee_code,name,name_kana,email,phone,joined_date,
                    employment_status,department_id,section_id,position_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                array_values($data)
            );
            auditLog('create', 'employees', (int)$newId, null, $data);
            setFlash('社員を登録しました。');
        } catch (PDOException $e) {
            setFlash('登録失敗: ' . $e->getMessage(), 'danger');
        }
        header('Location: ' . APP_URL . '/employees.php');
        exit;
    }

    if ($postAction === 'update' && $id) {
        $before = dbFetchOne("SELECT * FROM employees WHERE id = ?", [$id]);
        dbExecute(
            "UPDATE employees SET employee_code=?,name=?,name_kana=?,email=?,phone=?,joined_date=?,
                employment_status=?,department_id=?,section_id=?,position_id=?
             WHERE id=?",
            array_merge(array_values($data), [$id])
        );
        auditLog('update', 'employees', $id, $before, $data);
        setFlash('社員情報を更新しました。');
        header('Location: ' . APP_URL . '/employees.php');
        exit;
    }

    if ($postAction === 'delete' && $id) {
        $before = dbFetchOne("SELECT * FROM employees WHERE id = ?", [$id]);
        dbExecute("UPDATE employees SET is_active = 0 WHERE id = ?", [$id]);
        auditLog('delete', 'employees', $id, $before, null);
        setFlash('社員を削除しました（論理削除）。', 'warning');
        header('Location: ' . APP_URL . '/employees.php');
        exit;
    }
}

// --- データ取得 ---
$departments = dbFetchAll("SELECT * FROM departments WHERE is_active=1 ORDER BY display_order");
$sections    = dbFetchAll("SELECT * FROM sections WHERE is_active=1 ORDER BY display_order");
$positions   = dbFetchAll("SELECT * FROM positions WHERE is_active=1 ORDER BY display_order");

$editEmployee = null;
if ($action === 'edit' && $id) {
    $editEmployee = dbFetchOne("SELECT * FROM employees WHERE id = ? AND is_active = 1", [$id]);
}

// 社員一覧（キーワード検索）
$search = $_GET['q'] ?? '';
$employees = dbFetchAll(
    "SELECT e.*, d.dept_name, s.section_name, p.position_name
     FROM employees e
     LEFT JOIN departments d ON e.department_id = d.id
     LEFT JOIN sections s ON e.section_id = s.id
     LEFT JOIN positions p ON e.position_id = p.id
     WHERE e.is_active = 1
       AND (? = '' OR e.name LIKE ? OR e.employee_code LIKE ? OR e.name_kana LIKE ?)
     ORDER BY e.employee_code",
    [$search, "%$search%", "%$search%", "%$search%"]
);

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col">
    <h2><i class="bi bi-people"></i> 社員管理</h2>
  </div>
  <div class="col-auto">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#empModal">
      <i class="bi bi-plus-circle"></i> 新規登録
    </button>
  </div>
</div>

<!-- 検索フォーム -->
<form method="get" class="row g-2 mb-3">
  <div class="col-md-4">
    <input type="text" name="q" class="form-control" placeholder="氏名・社員コードで検索"
           value="<?= h($search) ?>">
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </div>
</form>

<!-- 社員一覧テーブル -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-dark">
          <tr>
            <th>社員コード</th><th>氏名</th><th>部署</th><th>課</th>
            <th>役職</th><th>在籍状態</th><th>入社日</th><th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
          <tr>
            <td><?= h($e['employee_code']) ?></td>
            <td><strong><?= h($e['name']) ?></strong><br><small class="text-muted"><?= h($e['name_kana']) ?></small></td>
            <td><?= h($e['dept_name'] ?? '―') ?></td>
            <td><?= h($e['section_name'] ?? '―') ?></td>
            <td><?= h($e['position_name'] ?? '―') ?></td>
            <td>
              <span class="badge bg-<?= $e['employment_status'] === 'active' ? 'success' : 'secondary' ?>">
                <?= match($e['employment_status']) { 'active' => '在籍', 'leave' => '休職', default => '退職' } ?>
              </span>
            </td>
            <td><?= formatDate($e['joined_date']) ?></td>
            <td>
              <a href="?action=edit&id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary">編集</a>
              <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">削除</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($employees)): ?>
          <tr><td colspan="8" class="text-center text-muted py-3">該当する社員がいません</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- 新規登録モーダル / 編集フォーム -->
<?php
$modalData = $editEmployee ?? null;
$modalTitle = $editEmployee ? '社員情報を編集' : '社員を新規登録';
$modalAction = $editEmployee ? 'update' : 'create';
?>
<div class="modal fade <?= $editEmployee ? 'show' : '' ?>" id="empModal" tabindex="-1"
     <?= $editEmployee ? 'style="display:block"' : '' ?>>
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><?= $modalTitle ?></h5>
        <a href="<?= APP_URL ?>/employees.php" class="btn-close btn-close-white"></a>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $modalAction ?>">
        <?php if ($editEmployee): ?>
        <input type="hidden" name="id" value="<?= $editEmployee['id'] ?>">
        <?php endif; ?>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">社員コード <span class="text-danger">*</span></label>
              <input type="text" name="employee_code" class="form-control" required
                     value="<?= h($modalData['employee_code'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">氏名 <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required
                     value="<?= h($modalData['name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">氏名カナ</label>
              <input type="text" name="name_kana" class="form-control"
                     value="<?= h($modalData['name_kana'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">メールアドレス</label>
              <input type="email" name="email" class="form-control"
                     value="<?= h($modalData['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">電話番号</label>
              <input type="text" name="phone" class="form-control"
                     value="<?= h($modalData['phone'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">入社日</label>
              <input type="date" name="joined_date" class="form-control"
                     value="<?= h($modalData['joined_date'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">在籍状態</label>
              <select name="employment_status" class="form-select">
                <?php foreach (['active'=>'在籍','leave'=>'休職','retired'=>'退職'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= ($modalData['employment_status'] ?? 'active') === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">部署</label>
              <select name="department_id" class="form-select">
                <option value="">― 選択 ―</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>" <?= ($modalData['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                  <?= h($d['dept_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">課</label>
              <select name="section_id" class="form-select">
                <option value="">― 選択 ―</option>
                <?php foreach ($sections as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ($modalData['section_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                  <?= h($s['section_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">役職</label>
              <select name="position_id" class="form-select">
                <option value="">― 選択 ―</option>
                <?php foreach ($positions as $p): ?>
                <option value="<?= $p['id'] ?>" <?= ($modalData['position_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                  <?= h($p['position_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="<?= APP_URL ?>/employees.php" class="btn btn-secondary">キャンセル</a>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save"></i> <?= $editEmployee ? '更新' : '登録' ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if ($editEmployee): ?>
<div class="modal-backdrop fade show"></div>
<?php endif; ?>

<?php require __DIR__ . '/parts/footer.php'; ?>
