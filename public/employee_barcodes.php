<?php
// =====================================================
// 社員コードバーコード印刷
// 目的: 全社員のemployee_codeをCode128バーコードで印刷
// バーコードスキャンステーションで作業者特定に使用
// 接続テーブル: employees, departments
// 権限: admin以上（個人情報のため）
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';

requireLogin();
requireRole('admin');

$deptFilter = (int)($_GET['dept_id'] ?? 0);

$employees = dbFetchAll(
    "SELECT e.*, d.dept_name, p.position_name
     FROM employees e
     LEFT JOIN departments d ON e.department_id = d.id
     LEFT JOIN positions p ON e.position_id = p.id
     WHERE e.is_active = 1 AND e.employment_status = 'active'"
    . ($deptFilter ? " AND e.department_id = {$deptFilter}" : '')
    . " ORDER BY d.dept_name, e.employee_code"
);

$departments = dbFetchAll("SELECT * FROM departments WHERE is_active=1 ORDER BY display_order");

$pageTitle = '社員コードバーコード印刷';
require __DIR__ . '/parts/header.php';
?>

<div class="d-print-none mb-3 d-flex gap-2 align-items-center flex-wrap">
  <h2 class="mb-0"><i class="bi bi-person-badge"></i> 社員コードバーコード印刷</h2>
  <div class="ms-auto d-flex gap-2">
    <button class="btn btn-primary" onclick="window.print()">
      <i class="bi bi-printer"></i> 印刷
    </button>
    <a href="process_barcodes.php" class="btn btn-outline-secondary">
      <i class="bi bi-upc"></i> 工程バーコードへ
    </a>
    <a href="barcode_station.php" class="btn btn-outline-dark">
      <i class="bi bi-upc-scan"></i> スキャンステーション
    </a>
  </div>
</div>

<div class="d-print-none mb-3">
  <div class="alert alert-info py-2">
    <i class="bi bi-info-circle"></i>
    <strong>使い方：</strong>
    バーコードスキャンステーションで <strong>作業者バーコード</strong> として読み込みます。
    各社員に社員証として配付してください。
  </div>
  <form method="get" class="row g-2 align-items-end">
    <div class="col-auto">
      <label class="form-label mb-0 small">部署絞り込み</label>
      <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">全部署</option>
        <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $deptFilter == $d['id'] ? 'selected' : '' ?>>
          <?= h($d['dept_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <span class="badge bg-secondary"><?= count($employees) ?>名</span>
    </div>
  </form>
</div>

<?php if (empty($employees)): ?>
  <div class="alert alert-warning">対象の社員が見つかりません。</div>
<?php endif; ?>

<div class="row g-3" id="barcodeContainer">
<?php
$lastDept = null;
foreach ($employees as $emp):
    $dept = $emp['dept_name'] ?? '未所属';
    if ($dept !== $lastDept):
        $lastDept = $dept;
?>
  <div class="col-12 d-print-none">
    <h5 class="text-muted border-bottom pb-1 mt-2"><?= h($dept) ?></h5>
  </div>
<?php endif; ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card border-dark barcode-card h-100">
      <div class="card-body p-2 text-center">
        <div class="fw-bold fs-6 mb-0"><?= h($emp['name']) ?></div>
        <div class="small text-muted mb-1"><?= h($emp['position_name'] ?? '') ?></div>
        <div class="barcode-svg-wrap">
          <svg class="barcode w-100" id="ebc-<?= $emp['id'] ?>"></svg>
        </div>
        <div class="small font-monospace mt-1"><?= h($emp['employee_code']) ?></div>
        <div class="very-small text-muted"><?= h($emp['dept_name'] ?? '') ?></div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
<?php foreach ($employees as $emp): ?>
    JsBarcode('#ebc-<?= $emp['id'] ?>', <?= json_encode($emp['employee_code']) ?>, {
        format: 'CODE128', lineColor: '#000', width: 2, height: 55,
        displayValue: false, margin: 0
    });
<?php endforeach; ?>
});
</script>

<style>
@media print {
  .d-print-none { display: none !important; }
  .barcode-card { break-inside: avoid; page-break-inside: avoid; }
  body, .container-fluid { padding: 0 !important; margin: 0 !important; }
  nav, footer { display: none !important; }
}
.barcode-svg-wrap svg { height: 55px; }
.very-small { font-size: 0.7rem; }
</style>

<?php require __DIR__ . '/parts/footer.php'; ?>
