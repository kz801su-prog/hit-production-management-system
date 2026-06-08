<?php
// =====================================================
// 社員管理一覧
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

// --- POST: 新規登録 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'employee_code'     => postStr('employee_code'),
        'name'              => postStr('name'),
        'name_kana'         => postStr('name_kana'),
        'email'             => postStr('email'),
        'phone'             => postStr('phone'),
        'joined_date'       => postStr('joined_date') ?: null,
        'employment_status' => 'active',
        'department_id'     => postInt('department_id') ?: null,
        'section_id'        => postInt('section_id')    ?: null,
        'position_id'       => postInt('position_id')   ?: null,
    ];
    try {
        $newId = dbExecute(
            "INSERT INTO employees
                (employee_code,name,name_kana,email,phone,joined_date,
                 employment_status,department_id,section_id,position_id)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            array_values($data)
        );
        auditLog('create','employees',(int)$newId,null,$data);
        setFlash('社員を登録しました。');
        header('Location: ' . APP_URL . '/employee_detail.php?id=' . $newId);
        exit;
    } catch (PDOException $e) {
        setFlash('登録失敗: ' . $e->getMessage(), 'danger');
    }
    header('Location: ' . APP_URL . '/employees.php');
    exit;
}

// --- データ取得 ---
$search         = trim($_GET['q'] ?? '');
$deptFilter     = getInt('dept_id');
$showRetired    = !empty($_GET['retired']);
$departments    = dbFetchAll("SELECT * FROM departments WHERE is_active=1 ORDER BY display_order");
$sections       = dbFetchAll("SELECT * FROM sections    WHERE is_active=1 ORDER BY display_order");
$positions      = dbFetchAll("SELECT * FROM positions   WHERE is_active=1 ORDER BY display_order");

$sql = "SELECT e.*, d.dept_name, sec.section_name, p.position_name,
               DATEDIFF(CURDATE(), e.joined_date) AS days_since_join
        FROM employees e
        LEFT JOIN departments d   ON e.department_id = d.id
        LEFT JOIN sections    sec ON e.section_id     = sec.id
        LEFT JOIN positions   p   ON e.position_id    = p.id
        WHERE e.is_active = 1";
$params = [];

if (!$showRetired) {
    $sql .= " AND e.employment_status != 'retired'";
}
if ($search) {
    $sql .= " AND (e.name LIKE ? OR e.employee_code LIKE ? OR e.name_kana LIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
if ($deptFilter) {
    $sql .= " AND e.department_id = ?";
    $params[] = $deptFilter;
}
$sql .= " ORDER BY e.employment_status = 'retired', e.employee_code";

$employees = dbFetchAll($sql, $params);

// 部門別人数サマリー
$deptSummary = dbFetchAll(
    "SELECT d.dept_name, COUNT(*) AS cnt
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     WHERE e.is_active = 1 AND e.employment_status = 'active'
     GROUP BY d.id, d.dept_name ORDER BY d.display_order"
);
$totalActive = (int)(dbFetchOne(
    "SELECT COUNT(*) AS cnt FROM employees WHERE is_active=1 AND employment_status='active'"
)['cnt'] ?? 0);

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3 align-items-center">
  <div class="col">
    <h2><i class="bi bi-people"></i> 社員管理</h2>
  </div>
  <div class="col-auto d-flex gap-2">
    <a href="employee_import.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-upload"></i> CSV取込
    </a>
    <a href="employee_export.php<?= $showRetired ? '?include_retired=1' : '' ?>"
       class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-download"></i> CSV出力
    </a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newEmpModal">
      <i class="bi bi-plus-circle"></i> 新規登録
    </button>
  </div>
</div>

<?= getFlashHtml() ?>

<!-- 部門サマリー -->
<div class="row g-2 mb-3">
  <div class="col-auto">
    <div class="card border-primary text-center px-3 py-2">
      <div class="fw-bold fs-5 text-primary"><?= $totalActive ?></div>
      <div class="small text-muted">在籍合計</div>
    </div>
  </div>
  <?php foreach ($deptSummary as $ds): ?>
  <div class="col-auto">
    <a href="?dept_id=<?= array_column($departments,'id','dept_name')[$ds['dept_name']] ?? '' ?>"
       class="card text-center px-3 py-2 text-decoration-none text-dark <?= $deptFilter ? '' : '' ?>">
      <div class="fw-bold"><?= $ds['cnt'] ?></div>
      <div class="small text-muted"><?= h($ds['dept_name']) ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- 検索フォーム -->
<form method="get" class="row g-2 mb-3">
  <div class="col-md-3">
    <input type="text" name="q" class="form-control form-control-sm"
           placeholder="氏名・社員コードで検索" value="<?= h($search) ?>">
  </div>
  <div class="col-md-2">
    <select name="dept_id" class="form-select form-select-sm">
      <option value="">全部署</option>
      <?php foreach ($departments as $d): ?>
      <option value="<?= $d['id'] ?>" <?= $deptFilter == $d['id'] ? 'selected' : '' ?>>
        <?= h($d['dept_name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <div class="form-check mt-1">
      <input class="form-check-input" type="checkbox" name="retired" id="showRetired" value="1"
             <?= $showRetired ? 'checked' : '' ?> onchange="this.form.submit()">
      <label class="form-check-label small" for="showRetired">退職者を含める</label>
    </div>
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-search"></i>
    </button>
    <?php if ($search || $deptFilter): ?>
    <a href="?<?= $showRetired ? 'retired=1' : '' ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-x"></i> クリア
    </a>
    <?php endif; ?>
  </div>
</form>

<!-- 社員一覧 -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-dark">
          <tr>
            <th>社員コード</th>
            <th>氏名</th>
            <th>部署 / 課</th>
            <th>役職</th>
            <th>在籍</th>
            <th>入社日</th>
            <th>在籍日数</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
          <?php $isRetired = ($e['employment_status'] === 'retired'); ?>
          <tr class="<?= $isRetired ? 'text-muted' : '' ?>">
            <td><code><?= h($e['employee_code']) ?></code></td>
            <td>
              <strong><?= h($e['name']) ?></strong>
              <?php if ($e['name_kana']): ?>
                <br><small class="text-muted"><?= h($e['name_kana']) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <?= h($e['dept_name'] ?? '―') ?>
              <?php if ($e['section_name']): ?>
                <small class="text-muted">/ <?= h($e['section_name']) ?></small>
              <?php endif; ?>
            </td>
            <td><?= h($e['position_name'] ?? '―') ?></td>
            <td>
              <?php
              $badgeClass = match($e['employment_status']) {
                  'active'  => 'success',
                  'leave'   => 'warning',
                  'retired' => 'secondary',
                  default   => 'secondary',
              };
              $badgeLabel = match($e['employment_status']) {
                  'active'  => '在籍',
                  'leave'   => '休職',
                  'retired' => '退職',
                  default   => $e['employment_status'],
              };
              ?>
              <span class="badge bg-<?= $badgeClass ?>"><?= $badgeLabel ?></span>
              <?php if ($isRetired && $e['retired_date']): ?>
                <br><small><?= formatDate($e['retired_date']) ?></small>
              <?php endif; ?>
            </td>
            <td><small><?= formatDate($e['joined_date']) ?></small></td>
            <td class="text-end">
              <?php if ($e['joined_date'] && !$isRetired): ?>
                <small><?= number_format((int)$e['days_since_join']) ?>日</small>
              <?php endif; ?>
            </td>
            <td>
              <a href="employee_detail.php?id=<?= $e['id'] ?>"
                 class="btn btn-sm btn-outline-primary">
                <i class="bi bi-pencil"></i> 詳細/編集
              </a>
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

<!-- 新規登録モーダル -->
<div class="modal fade" id="newEmpModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-person-plus"></i> 社員を新規登録</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">社員コード <span class="text-danger">*</span></label>
              <input type="text" name="employee_code" class="form-control" required
                     placeholder="EMP-001">
            </div>
            <div class="col-md-4">
              <label class="form-label">氏名 <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">氏名カナ</label>
              <input type="text" name="name_kana" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">メール</label>
              <input type="email" name="email" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">電話</label>
              <input type="text" name="phone" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">入社日</label>
              <input type="date" name="joined_date" class="form-control"
                     value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">部署</label>
              <select name="department_id" class="form-select">
                <option value="">―</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>"><?= h($d['dept_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">課</label>
              <select name="section_id" class="form-select">
                <option value="">―</option>
                <?php foreach ($sections as $s): ?>
                <option value="<?= $s['id'] ?>"><?= h($s['section_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">役職</label>
              <select name="position_id" class="form-select">
                <option value="">―</option>
                <?php foreach ($positions as $p): ?>
                <option value="<?= $p['id'] ?>"><?= h($p['position_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save"></i> 登録（詳細ページへ）
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
