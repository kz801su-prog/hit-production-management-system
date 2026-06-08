<?php
// =====================================================
// 経営者ダッシュボード（社長・部長・admin）
// 製造管理の経営指標をモバイル対応で表示
// =====================================================
// このファイルは dashboard.php からインクルードされる
// =====================================================
if (!defined('APP_URL')) {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../app/db.php';
    require_once __DIR__ . '/../app/auth.php';
    require_once __DIR__ . '/../app/permissions.php';
    require_once __DIR__ . '/../app/functions.php';
    requireLogin();
    requireRole('factory_manager');
}

$pageTitle  = 'マネジメントダッシュボード';
$today      = date('Y-m-d');
$thisMonth  = date('Y-m');
$monthFrom  = date('Y-m-01');
$monthTo    = date('Y-m-t');
$currentUser = getCurrentUser();

// =====================================================
// コスト設定読み込み
// =====================================================
$costConf = [];
try {
    $rows = dbFetchAll(
        "SELECT setting_key, setting_value FROM system_settings
         WHERE setting_key IN (
             'cost_target_month','monthly_salary_total','monthly_overhead_cost',
             'monthly_production_target'
         )"
    );
    $costConf = array_column($rows, 'setting_value', 'setting_key');
} catch (Exception $e) {}

$costMonth      = $costConf['cost_target_month'] ?: $thisMonth;
$salaryTotal    = (int)($costConf['monthly_salary_total']      ?? 0);
$overheadCost   = (int)($costConf['monthly_overhead_cost']     ?? 0);
$productionTarget = (int)($costConf['monthly_production_target'] ?? 0);

// =====================================================
// KPI集計
// =====================================================

// 仕掛中（WIP）
$wipQty = (int)(dbFetchOne(
    "SELECT COALESCE(SUM(quantity),0) AS q FROM manufacturing_orders WHERE status='in_progress'"
)['q'] ?? 0);
$wipCount = (int)(dbFetchOne(
    "SELECT COUNT(*) AS c FROM manufacturing_orders WHERE status='in_progress'"
)['c'] ?? 0);

// 今月完成
$completedQty = (int)(dbFetchOne(
    "SELECT COALESCE(SUM(quantity),0) AS q FROM manufacturing_orders
     WHERE status='completed' AND DATE(updated_at) BETWEEN ? AND ?",
    [$monthFrom, $monthTo]
)['q'] ?? 0);
$completedCount = (int)(dbFetchOne(
    "SELECT COUNT(*) AS c FROM manufacturing_orders
     WHERE status='completed' AND DATE(updated_at) BETWEEN ? AND ?",
    [$monthFrom, $monthTo]
)['c'] ?? 0);

// 今月受注・計画本数（完成 + 仕掛中 + 計画中）
$plannedQty = (int)(dbFetchOne(
    "SELECT COALESCE(SUM(quantity),0) AS q FROM manufacturing_orders
     WHERE status NOT IN ('cancelled')
       AND (DATE(created_at) BETWEEN ? AND ? OR status IN ('in_progress','planned'))",
    [$monthFrom, $monthTo]
)['q'] ?? 0);
$targetQty = $productionTarget > 0 ? $productionTarget : max($plannedQty, $completedQty);
$achieveRate = $targetQty > 0 ? min(100, round($completedQty / $targetQty * 100, 1)) : 0;

// 納期遵守率 OTD（今月完成分）
$otdRow = dbFetchOne(
    "SELECT
         COUNT(*) AS total,
         SUM(CASE WHEN due_date IS NULL OR DATE(updated_at) <= due_date THEN 1 ELSE 0 END) AS ontime
     FROM manufacturing_orders
     WHERE status='completed' AND DATE(updated_at) BETWEEN ? AND ?",
    [$monthFrom, $monthTo]
);
$otdRate = ($otdRow && $otdRow['total'] > 0)
    ? round($otdRow['ontime'] / $otdRow['total'] * 100, 1) : null;

// 遅延件数
$delayedCount = (int)(dbFetchOne(
    "SELECT COUNT(*) AS c FROM manufacturing_order_processes
     WHERE delay_status IN ('delayed','critical')"
)['c'] ?? 0);
$criticalCount = (int)(dbFetchOne(
    "SELECT COUNT(*) AS c FROM manufacturing_order_processes WHERE delay_status='critical'"
)['c'] ?? 0);

// 今月稼働人数
$activeWorkers = (int)(dbFetchOne(
    "SELECT COUNT(DISTINCT employee_id) AS c FROM work_logs
     WHERE DATE(started_at) BETWEEN ? AND ?",
    [$monthFrom, $monthTo]
)['c'] ?? 0);

// 在籍社員数
$totalEmployees = (int)(dbFetchOne(
    "SELECT COUNT(*) AS c FROM employees WHERE is_active=1 AND employment_status='active'"
)['c'] ?? 0);

// コスト計算
$totalCost    = $salaryTotal + $overheadCost;
$costPerUnit  = $completedQty > 0 ? (int)($totalCost / $completedQty) : null;
$perPersonQty = $activeWorkers > 0 ? round($completedQty / $activeWorkers, 1) : null;

// 遅延アラート上位
$delayedList = dbFetchAll(
    "SELECT mop.delay_status, mop.delay_minutes, mo.order_no, mo.due_date, mo.priority,
            ct.chair_type_name, p.process_name, mo.id AS order_id,
            mo.customer_name, mo.project_name
     FROM manufacturing_order_processes mop
     JOIN manufacturing_orders mo ON mop.manufacturing_order_id = mo.id
     JOIN chair_types ct ON mo.chair_type_id = ct.id
     JOIN processes p    ON mop.process_id = p.id
     WHERE mop.delay_status IN ('delayed','critical')
       AND mo.status NOT IN ('completed','cancelled')
     ORDER BY FIELD(mop.delay_status,'critical','delayed'), mop.delay_minutes DESC
     LIMIT 8"
);

// 今後7日の納期迫る案件
$upcomingDue = dbFetchAll(
    "SELECT mo.id, mo.order_no, mo.due_date, mo.priority, mo.quantity, mo.status,
            mo.customer_name, mo.project_name,
            ct.chair_type_name,
            DATEDIFF(mo.due_date, CURDATE()) AS days_left
     FROM manufacturing_orders mo
     JOIN chair_types ct ON mo.chair_type_id = ct.id
     WHERE mo.status NOT IN ('completed','cancelled')
       AND mo.due_date IS NOT NULL
       AND mo.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY mo.due_date, FIELD(mo.priority,'urgent','high','normal')
     LIMIT 10"
);

// 部門別稼働
$deptStatus = dbFetchAll(
    "SELECT d.dept_name,
            COUNT(DISTINCT e.id) AS emp_cnt,
            COUNT(DISTINCT CASE WHEN wl.started_at IS NOT NULL THEN e.id END) AS working_cnt,
            ROUND(COALESCE(SUM(
                CASE WHEN wl.ended_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, wl.started_at, wl.ended_at) END
            ), 0) / 60.0, 1) AS today_hours
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     LEFT JOIN work_logs wl ON wl.employee_id = e.id
         AND DATE(wl.started_at) = ?
     WHERE e.is_active=1 AND e.employment_status='active'
     GROUP BY d.id, d.dept_name
     ORDER BY d.display_order",
    [$today]
);

// 日別生産数（今月）
$dailyProduction = dbFetchAll(
    "SELECT DATE(updated_at) AS prod_date,
            SUM(quantity) AS qty,
            COUNT(*) AS order_count
     FROM manufacturing_orders
     WHERE status='completed' AND DATE(updated_at) BETWEEN ? AND ?
     GROUP BY DATE(updated_at)
     ORDER BY prod_date",
    [$monthFrom, $monthTo]
);

// 月別完成本数推移（過去6ヶ月）
$monthlyTrend = dbFetchAll(
    "SELECT DATE_FORMAT(updated_at, '%Y-%m') AS ym,
            SUM(quantity) AS qty
     FROM manufacturing_orders
     WHERE status='completed'
       AND updated_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY ym ORDER BY ym"
);

// 社長の言葉
$word = null;
try {
    $word = dbFetchOne("SELECT * FROM president_words WHERE is_active=1 ORDER BY RAND() LIMIT 1");
} catch (Exception $e) {}

// ガントチャート期間
$ganttPeriod = $_GET['gantt_period'] ?? 'week';
switch ($ganttPeriod) {
    case 'tomorrow': $ganttFrom = date('Y-m-d', strtotime('+1 day')); $ganttTo = $ganttFrom; break;
    case 'month':    $ganttFrom = $monthFrom; $ganttTo = $monthTo; break;
    default:         $ganttFrom = $today; $ganttTo = date('Y-m-d', strtotime('+6 days')); break;
}

// JS用データ
$jsDaily   = json_encode(array_values($dailyProduction), JSON_UNESCAPED_UNICODE);
$jsMonthly = json_encode(array_values($monthlyTrend),    JSON_UNESCAPED_UNICODE);

// =====================================================
// 部門タブ用データ
// =====================================================
$deptCards = dbFetchAll(
    "SELECT d.id AS dept_id, d.dept_name, d.display_order,
            COUNT(DISTINCT e.id) AS emp_cnt,
            COUNT(DISTINCT CASE WHEN aw.id IS NOT NULL THEN e.id END) AS active_now,
            COUNT(DISTINCT CASE WHEN DATE(wl.started_at)=? THEN e.id END) AS working_today,
            ROUND(COALESCE(SUM(
                CASE WHEN DATE(wl.started_at)=? AND wl.ended_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE,wl.started_at,wl.ended_at) END
            ),0)/60,1) AS today_hours,
            ROUND(COALESCE(SUM(
                CASE WHEN DATE(wl.started_at) BETWEEN ? AND ? AND wl.ended_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE,wl.started_at,wl.ended_at) END
            ),0)/60,1) AS month_hours,
            COUNT(DISTINCT CASE WHEN DATE(wl.started_at) BETWEEN ? AND ? AND wl.ended_at IS NOT NULL
                THEN wl.manufacturing_order_id END) AS month_orders
     FROM departments d
     LEFT JOIN employees e  ON e.department_id=d.id
         AND e.employment_status='active' AND e.is_active=1
     LEFT JOIN work_logs wl ON wl.employee_id=e.id
     LEFT JOIN work_logs aw ON aw.employee_id=e.id AND aw.ended_at IS NULL
     GROUP BY d.id, d.dept_name, d.display_order
     ORDER BY d.display_order",
    [$today, $today, $monthFrom, $monthTo, $monthFrom, $monthTo]
);

// 全社員稼働データ
$allEmpData = dbFetchAll(
    "SELECT e.id AS emp_id, e.name, e.employee_code,
            d.dept_name, d.id AS dept_id,
            pos.position_name,
            MAX(CASE WHEN wl.ended_at IS NULL THEN 1 ELSE 0 END) AS is_active,
            ROUND(COALESCE(SUM(
                CASE WHEN DATE(wl.started_at)=? AND wl.ended_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE,wl.started_at,wl.ended_at) END
            ),0)/60,1) AS today_hours,
            ROUND(COALESCE(SUM(
                CASE WHEN DATE(wl.started_at) BETWEEN ? AND ? AND wl.ended_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE,wl.started_at,wl.ended_at) END
            ),0)/60,1) AS month_hours,
            COUNT(DISTINCT CASE WHEN DATE(wl.started_at) BETWEEN ? AND ? AND wl.ended_at IS NOT NULL
                THEN wl.manufacturing_order_id END) AS month_orders,
            MAX(CASE WHEN DATE(wl.started_at)=? THEN 1 ELSE 0 END) AS worked_today
     FROM employees e
     LEFT JOIN departments d   ON e.department_id=d.id
     LEFT JOIN positions pos   ON e.position_id=pos.id
     LEFT JOIN work_logs wl    ON wl.employee_id=e.id
     WHERE e.employment_status='active' AND e.is_active=1
     GROUP BY e.id, e.name, e.employee_code, d.dept_name, d.id, pos.position_name
     ORDER BY d.display_order, e.id",
    [$today, $monthFrom, $monthTo, $monthFrom, $monthTo, $today]
);

// 全社員の現在進行中作業マップ
$activeWorkAll = [];
try {
    $activeRows = dbFetchAll(
        "SELECT wl.employee_id, p.process_name, mo.order_no, mo.id AS order_id,
                TIMESTAMPDIFF(MINUTE,wl.started_at,NOW()) AS elapsed_min,
                mop.planned_total_minutes
         FROM work_logs wl
         JOIN processes p  ON wl.process_id=p.id
         JOIN manufacturing_orders mo ON wl.manufacturing_order_id=mo.id
         LEFT JOIN manufacturing_order_processes mop
             ON mop.manufacturing_order_id=mo.id AND mop.process_id=wl.process_id
         WHERE wl.ended_at IS NULL"
    );
    foreach ($activeRows as $r) {
        $activeWorkAll[$r['employee_id']] = $r;
    }
} catch (Exception $e) {}

// 部門ごとに社員をグループ化
$empByDept = [];
foreach ($allEmpData as $emp) {
    $empByDept[$emp['dept_name'] ?? '未所属'][] = $emp;
}

// =====================================================
// 個人タブ用データ（ログイン者自身の実績）
// =====================================================
$w_myUser = dbFetchOne(
    "SELECT u.id, e.id AS emp_id, e.name AS emp_name,
            e.department_id, d.dept_name
     FROM users u
     LEFT JOIN employees e ON u.employee_id = e.id
     LEFT JOIN departments d ON e.department_id = d.id
     WHERE u.id = ?",
    [$currentUser['id']]
);
$w_empId   = $w_myUser['emp_id']        ?? null;
$w_deptId  = $w_myUser['department_id'] ?? null;
$w_empName = $w_myUser['emp_name']      ?? $currentUser['name'];

// 進行中の作業（自分）
$w_activeWork = $w_empId ? dbFetchAll(
    "SELECT wl.id AS wl_id, wl.started_at,
            TIMESTAMPDIFF(MINUTE, wl.started_at, NOW()) AS elapsed_minutes,
            p.process_name, mo.order_no, mo.quantity,
            ct.chair_type_name, mo.due_date, mo.id AS order_id,
            mop.planned_total_minutes
     FROM work_logs wl
     JOIN processes p ON wl.process_id = p.id
     JOIN manufacturing_orders mo ON wl.manufacturing_order_id = mo.id
     JOIN chair_types ct ON mo.chair_type_id = ct.id
     LEFT JOIN manufacturing_order_processes mop
         ON mop.manufacturing_order_id = mo.id AND mop.process_id = wl.process_id
     WHERE wl.employee_id = ? AND wl.ended_at IS NULL
     ORDER BY wl.started_at DESC",
    [$w_empId]
) : [];

// 本日の作業実績（自分）
$w_todayLogs = $w_empId ? dbFetchAll(
    "SELECT wl.*,
            TIMESTAMPDIFF(MINUTE, wl.started_at, COALESCE(wl.ended_at, NOW())) AS actual_minutes,
            p.process_name, mo.order_no, ct.chair_type_name,
            mop.planned_total_minutes
     FROM work_logs wl
     JOIN processes p ON wl.process_id = p.id
     JOIN manufacturing_orders mo ON wl.manufacturing_order_id = mo.id
     JOIN chair_types ct ON mo.chair_type_id = ct.id
     LEFT JOIN manufacturing_order_processes mop
         ON mop.manufacturing_order_id = mo.id AND mop.process_id = wl.process_id
     WHERE wl.employee_id = ? AND DATE(wl.started_at) = CURDATE()
     ORDER BY wl.started_at DESC",
    [$w_empId]
) : [];

// 今月の実績サマリー（自分）
$w_monthSummary = $w_empId ? dbFetchOne(
    "SELECT COUNT(DISTINCT wl.manufacturing_order_id) AS order_count,
            ROUND(SUM(TIMESTAMPDIFF(MINUTE, wl.started_at, wl.ended_at)) / 60.0, 1) AS total_hours,
            COUNT(DISTINCT DATE(wl.started_at)) AS work_days
     FROM work_logs wl
     WHERE wl.employee_id = ? AND wl.ended_at IS NOT NULL
       AND DATE(wl.started_at) BETWEEN ? AND ?",
    [$w_empId, $monthFrom, $monthTo]
) : null;

// 部門の作業キュー（仕掛中・未着手）
$w_deptQueue = dbFetchAll(
    "SELECT mop.id AS mop_id, mop.status, mop.planned_total_minutes,
            p.process_name, p.process_code,
            mo.order_no, mo.due_date, mo.priority, mo.quantity,
            mo.id AS order_id, mo.customer_name, mo.project_name,
            ct.chair_type_name,
            DATEDIFF(mo.due_date, CURDATE()) AS days_left
     FROM manufacturing_order_processes mop
     JOIN processes p ON mop.process_id = p.id
     JOIN manufacturing_orders mo ON mop.manufacturing_order_id = mo.id
     JOIN chair_types ct ON mo.chair_type_id = ct.id
     WHERE mop.status IN ('pending','in_progress')
       AND mo.status NOT IN ('completed','cancelled')
     ORDER BY FIELD(mo.priority,'urgent','high','normal'),
              ISNULL(mo.due_date), mo.due_date
     LIMIT 20"
);

// 自分の職能ランク
$w_skills = $w_empId ? dbFetchAll(
    "SELECT p.process_name, esr.rank_level
     FROM employee_skill_ranks esr
     JOIN processes p ON esr.process_id = p.id
     WHERE esr.employee_id = ? AND esr.rank_level > 0
     ORDER BY esr.rank_level DESC, p.display_order",
    [$w_empId]
) : [];

$w_todayTotalMinutes = array_sum(array_column($w_todayLogs, 'actual_minutes'));
$w_todayPlannedTotal = array_sum(array_column(
    array_filter($w_todayLogs, fn($r) => (float)$r['planned_total_minutes'] > 0),
    'planned_total_minutes'
));

require __DIR__ . '/parts/header.php';
?>

<!-- ===== 経営者ダッシュボード用追加スタイル ===== -->
<style>
.exec-kpi-card {
    border-radius: 10px;
    border-left: 4px solid;
    transition: transform .15s;
}
.exec-kpi-card:hover { transform: translateY(-2px); }
.exec-kpi-num {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.1;
    letter-spacing: -0.03em;
}
@media (max-width:576px) {
    .exec-kpi-num { font-size: 1.6rem; }
}
.progress-thick { height: 22px; border-radius: 6px; }
.alert-row-critical { border-left: 4px solid #dc3545; }
.alert-row-delayed  { border-left: 4px solid #ffc107; }
.section-title {
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #6c757d;
    margin-bottom: .5rem;
}
/* モバイル: スクロール可能なKPIカード横並び */
@media (max-width:576px) {
    .kpi-scroll-wrap {
        display: flex;
        overflow-x: auto;
        gap: .5rem;
        padding-bottom: .25rem;
        -webkit-overflow-scrolling: touch;
    }
    .kpi-scroll-wrap .exec-kpi-card {
        min-width: 140px;
        flex-shrink: 0;
    }
}
/* ワーカータブ共通 */
.worker-kpi { border-left:4px solid; border-radius:8px; }
.diff-over  { color:#dc3545; }
.diff-under { color:#198754; }
.queue-row-urgent { border-left:4px solid #dc3545; }
.queue-row-high   { border-left:4px solid #ffc107; }
</style>

<!-- ===== タブナビゲーション（管理者のみ） ===== -->
<ul class="nav nav-tabs nav-fill mb-3" id="dashTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active fw-bold" id="execTab-btn"
            data-bs-toggle="tab" data-bs-target="#execTabPane"
            type="button" role="tab">
      <i class="bi bi-speedometer2"></i>
      <span class="d-none d-sm-inline"> 経営ダッシュボード</span>
      <span class="d-sm-none"> 経営</span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link fw-bold" id="deptTab-btn"
            data-bs-toggle="tab" data-bs-target="#deptTabPane"
            type="button" role="tab">
      <i class="bi bi-diagram-3"></i>
      <span class="d-none d-sm-inline"> 部門ダッシュボード</span>
      <span class="d-sm-none"> 部門</span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link fw-bold" id="workerTab-btn"
            data-bs-toggle="tab" data-bs-target="#workerTabPane"
            type="button" role="tab">
      <i class="bi bi-people"></i>
      <span class="d-none d-sm-inline"> 個人ダッシュボード</span>
      <span class="d-sm-none"> 個人</span>
    </button>
  </li>
</ul>

<div class="tab-content" id="dashTabContent">
<div class="tab-pane fade show active" id="execTabPane" role="tabpanel">

<!-- ヘッダー行 -->
<div class="d-flex align-items-center mb-3 gap-2">
  <div>
    <h2 class="mb-0"><i class="bi bi-speedometer2"></i> マネジメント</h2>
    <small class="text-muted"><?= date('Y年n月j日（D）', strtotime($today)) ?> &nbsp;
      <span id="liveClock" class="fw-bold"></span>
    </small>
  </div>
  <div class="ms-auto d-flex gap-1">
    <?php if (isPresidentOrAdmin()): ?>
    <a href="admin_settings.php#cost" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-gear"></i><span class="d-none d-md-inline"> 設定</span>
    </a>
    <?php endif; ?>
    <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
      <i class="bi bi-arrow-clockwise"></i>
    </button>
  </div>
</div>

<!-- ===== KPIカード行（モバイル横スクロール） ===== -->
<div class="kpi-scroll-wrap row g-2 mb-3 flex-nowrap flex-md-wrap">

  <!-- WIP 仕掛中 -->
  <div class="col-6 col-md-2">
    <div class="card exec-kpi-card h-100 p-3" style="border-color:#0d6efd">
      <div class="section-title">仕掛中</div>
      <div class="exec-kpi-num text-primary"><?= number_format($wipQty) ?></div>
      <div class="small text-muted">本（<?= $wipCount ?>件）</div>
    </div>
  </div>

  <!-- 今月完成 -->
  <div class="col-6 col-md-2">
    <div class="card exec-kpi-card h-100 p-3" style="border-color:#198754">
      <div class="section-title">今月完成</div>
      <div class="exec-kpi-num text-success"><?= number_format($completedQty) ?></div>
      <div class="small text-muted">本（<?= $completedCount ?>件）</div>
    </div>
  </div>

  <!-- 達成率 -->
  <div class="col-6 col-md-2">
    <div class="card exec-kpi-card h-100 p-3"
         style="border-color:<?= $achieveRate >= 80 ? '#198754' : ($achieveRate >= 50 ? '#ffc107' : '#dc3545') ?>">
      <div class="section-title">月間達成率</div>
      <div class="exec-kpi-num <?= $achieveRate >= 80 ? 'text-success' : ($achieveRate >= 50 ? 'text-warning' : 'text-danger') ?>">
        <?= $achieveRate ?>%
      </div>
      <div class="small text-muted"><?= number_format($completedQty) ?>/<?= number_format($targetQty) ?>本</div>
    </div>
  </div>

  <!-- 納期遵守率 OTD -->
  <div class="col-6 col-md-2">
    <div class="card exec-kpi-card h-100 p-3"
         style="border-color:<?= $otdRate === null ? '#6c757d' : ($otdRate >= 90 ? '#198754' : ($otdRate >= 70 ? '#ffc107' : '#dc3545')) ?>">
      <div class="section-title">納期遵守率</div>
      <div class="exec-kpi-num <?= $otdRate === null ? 'text-secondary' : ($otdRate >= 90 ? 'text-success' : ($otdRate >= 70 ? 'text-warning' : 'text-danger')) ?>">
        <?= $otdRate !== null ? $otdRate . '%' : '―' ?>
      </div>
      <div class="small text-muted">OTD（今月完成分）</div>
    </div>
  </div>

  <!-- 遅延件数 -->
  <div class="col-6 col-md-2">
    <div class="card exec-kpi-card h-100 p-3"
         style="border-color:<?= $delayedCount > 0 ? '#dc3545' : '#198754' ?>">
      <div class="section-title">遅延</div>
      <div class="exec-kpi-num <?= $delayedCount > 0 ? 'text-danger' : 'text-success' ?>">
        <?= $delayedCount ?>
      </div>
      <div class="small text-muted">
        工程（<span class="text-danger fw-bold"><?= $criticalCount ?></span>件 緊急）
      </div>
    </div>
  </div>

  <!-- 1本あたりコスト -->
  <div class="col-6 col-md-2">
    <div class="card exec-kpi-card h-100 p-3" style="border-color:#6f42c1">
      <div class="section-title">1本コスト</div>
      <?php if (isPresidentOrAdmin()): ?>
        <div class="exec-kpi-num text-purple" style="color:#6f42c1">
          <?= $costPerUnit !== null ? '¥' . number_format($costPerUnit) : '―' ?>
        </div>
        <div class="small text-muted"><?= h($costMonth) ?>月分</div>
      <?php else: ?>
        <div class="exec-kpi-num text-secondary">*****</div>
        <div class="small text-muted">管理者のみ</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ===== 月間生産進捗バー ===== -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex justify-content-between mb-1 small fw-bold">
      <span><i class="bi bi-bar-chart-fill text-success"></i> 月間生産進捗
        <span class="text-muted fw-normal ms-1"><?= date('n月') ?></span>
      </span>
      <span class="text-muted">
        完成 <strong class="text-success"><?= number_format($completedQty) ?></strong> 本
        ／ 目標 <strong><?= number_format($targetQty) ?></strong> 本
        <?php if ($wipQty > 0): ?>
          ＋ 仕掛中 <strong class="text-primary"><?= number_format($wipQty) ?></strong> 本
        <?php endif; ?>
      </span>
    </div>
    <div class="progress progress-thick" style="background:#e9ecef">
      <!-- 完成分 -->
      <div class="progress-bar bg-success" style="width:<?= min(100, ($targetQty > 0 ? $completedQty / $targetQty * 100 : 0)) ?>%"
           title="完成: <?= $completedQty ?>本">
        <?= $completedQty > 0 ? number_format($completedQty).'本' : '' ?>
      </div>
      <!-- 仕掛中分 -->
      <?php if ($wipQty > 0 && $targetQty > 0): ?>
      <div class="progress-bar bg-primary bg-opacity-50" style="width:<?= min(100 - ($completedQty / $targetQty * 100), $wipQty / $targetQty * 100) ?>%"
           title="仕掛中: <?= $wipQty ?>本">
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ===== メインコンテンツ 2カラム ===== -->
<div class="row g-3">

  <!-- 左: 日別生産数チャート + 月別推移 -->
  <div class="col-lg-7">

    <!-- 今月の日別生産 -->
    <div class="card mb-3">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-bar-chart"></i> 今月の日別生産実績</span>
        <small class="text-muted"><?= date('Y年n月') ?></small>
      </div>
      <div class="card-body py-2">
        <canvas id="dailyChart" height="90"></canvas>
        <?php if (empty($dailyProduction)): ?>
          <p class="text-muted text-center small mb-0">今月の完成実績なし</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- 月別推移 -->
    <div class="card">
      <div class="card-header py-2 fw-bold">
        <i class="bi bi-graph-up-arrow"></i> 月別完成本数推移（過去6ヶ月）
      </div>
      <div class="card-body py-2">
        <canvas id="monthlyChart" height="80"></canvas>
      </div>
    </div>
  </div>

  <!-- 右: 遅延アラート + 納期迫る案件 -->
  <div class="col-lg-5">

    <!-- 遅延アラート -->
    <div class="card mb-3">
      <div class="card-header py-2 bg-danger text-white d-flex justify-content-between align-items-center">
        <span class="fw-bold">
          <i class="bi bi-exclamation-octagon-fill"></i> 遅延アラート
          <?php if ($delayedCount > 0): ?>
            <span class="badge bg-white text-danger ms-1"><?= $delayedCount ?></span>
          <?php endif; ?>
        </span>
        <?php if ($delayedCount > 8): ?>
          <a href="progress_board.php?filter=delayed" class="btn btn-sm btn-outline-light">全件</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($delayedList)): ?>
          <div class="p-3 text-center text-success small">
            <i class="bi bi-check-circle-fill"></i> 遅延なし
          </div>
        <?php else: ?>
          <div class="list-group list-group-flush small">
          <?php foreach ($delayedList as $d): ?>
            <?php $isCrit = $d['delay_status'] === 'critical'; ?>
            <a href="orders.php?id=<?= $d['order_id'] ?>"
               class="list-group-item list-group-item-action alert-row-<?= $isCrit ? 'critical' : 'delayed' ?> py-2">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <span class="badge bg-<?= $isCrit ? 'danger' : 'warning text-dark' ?> me-1">
                    <?= $isCrit ? '緊急' : '遅延' ?>
                  </span>
                  <strong><?= h($d['order_no']) ?></strong> — <?= h($d['process_name']) ?>
                  <br>
                  <span class="text-muted"><?= h($d['customer_name'] ?? $d['chair_type_name']) ?></span>
                  <?php if ($d['due_date']): ?>
                    <span class="ms-2 text-<?= strtotime($d['due_date']) < time() ? 'danger fw-bold' : 'muted' ?>">
                      納期:<?= formatDate($d['due_date']) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <span class="badge bg-<?= $isCrit ? 'danger' : 'warning text-dark' ?> ms-1 text-nowrap">
                  +<?= formatMinutes((int)$d['delay_minutes']) ?>
                </span>
              </div>
            </a>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 納期迫る案件（7日以内） -->
    <div class="card">
      <div class="card-header py-2 fw-bold bg-warning">
        <i class="bi bi-calendar-event-fill"></i> 納期まで7日以内
        <?php if (!empty($upcomingDue)): ?>
          <span class="badge bg-dark ms-1"><?= count($upcomingDue) ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($upcomingDue)): ?>
          <div class="p-3 text-center text-muted small">
            <i class="bi bi-calendar-check"></i> 7日以内に納期はありません
          </div>
        <?php else: ?>
          <div class="list-group list-group-flush small">
          <?php foreach ($upcomingDue as $u): ?>
            <?php
            $daysLeft = (int)$u['days_left'];
            $dayClass = $daysLeft <= 0 ? 'danger' : ($daysLeft <= 2 ? 'warning' : 'info');
            ?>
            <a href="orders.php?id=<?= $u['id'] ?>"
               class="list-group-item list-group-item-action py-2">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <span class="badge bg-<?= $dayClass ?> me-1">
                    <?= $daysLeft <= 0 ? '超過' : ($daysLeft === 0 ? '本日' : $daysLeft.'日後') ?>
                  </span>
                  <strong><?= h($u['order_no']) ?></strong>
                  <span class="text-muted ms-1"><?= h($u['quantity']) ?>本</span>
                  <br>
                  <span class="text-muted"><?= h($u['customer_name'] ?: $u['chair_type_name']) ?></span>
                  <?php if ($u['project_name']): ?>
                    <span class="text-muted"> / <?= h($u['project_name']) ?></span>
                  <?php endif; ?>
                </div>
                <?= orderStatusBadge($u['status']) ?>
              </div>
            </a>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 部門別稼働状況 -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header py-2 fw-bold">
        <i class="bi bi-people-fill"></i> 部門別稼働状況（本日）
        <span class="fw-normal text-muted small ms-1">
          稼働 <?= $activeWorkers ?>名 / 在籍 <?= $totalEmployees ?>名
        </span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th>部門</th>
              <th class="text-center">在籍</th>
              <th class="text-center">稼働中</th>
              <th class="text-end">本日作業h</th>
              <th>稼働率</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($deptStatus as $ds): ?>
            <?php
            $rate = $ds['emp_cnt'] > 0 ? round($ds['working_cnt'] / $ds['emp_cnt'] * 100) : 0;
            $barColor = $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger');
            ?>
            <tr>
              <td><strong><?= h($ds['dept_name']) ?></strong></td>
              <td class="text-center"><?= $ds['emp_cnt'] ?></td>
              <td class="text-center text-primary fw-bold"><?= $ds['working_cnt'] ?></td>
              <td class="text-end"><?= $ds['today_hours'] ?>h</td>
              <td>
                <div class="progress" style="height:12px;min-width:60px">
                  <div class="progress-bar bg-<?= $barColor ?>"
                       style="width:<?= $rate ?>%"
                       title="<?= $rate ?>%">
                  </div>
                </div>
                <small class="text-muted"><?= $rate ?>%</small>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($deptStatus)): ?>
            <tr><td colspan="5" class="text-center text-muted py-2 small">データなし</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- コスト管理（admin+のみ） -->
  <?php if (isPresidentOrAdmin() && ($salaryTotal > 0 || $overheadCost > 0)): ?>
  <div class="col-md-6">
    <div class="card border-warning">
      <div class="card-header py-2 bg-warning fw-bold d-flex justify-content-between">
        <span><i class="bi bi-currency-yen"></i> コスト管理
          <span class="fw-normal small ms-1"><?= h($costMonth) ?></span>
        </span>
        <a href="admin_settings.php#cost" class="btn btn-xs btn-outline-dark py-0 px-1">
          <i class="bi bi-pencil"></i>
        </a>
      </div>
      <div class="card-body p-2">
        <div class="row g-2 text-center">
          <div class="col-4">
            <div class="border rounded p-2">
              <div class="fw-bold text-primary"><?= $costPerUnit !== null ? '¥'.number_format($costPerUnit) : '―' ?></div>
              <div class="small text-muted">1本あたりコスト</div>
            </div>
          </div>
          <div class="col-4">
            <div class="border rounded p-2">
              <div class="fw-bold text-success"><?= $completedQty > 0 ? '¥'.number_format((int)($salaryTotal/$completedQty)) : '―' ?></div>
              <div class="small text-muted">給与費/本</div>
            </div>
          </div>
          <div class="col-4">
            <div class="border rounded p-2">
              <div class="fw-bold text-info"><?= $completedQty > 0 ? '¥'.number_format((int)($overheadCost/$completedQty)) : '―' ?></div>
              <div class="small text-muted">管理費/本</div>
            </div>
          </div>
        </div>
        <div class="mt-2 small text-muted d-flex justify-content-between">
          <span>給与総額: <strong>¥<?= number_format($salaryTotal) ?></strong></span>
          <span>管理費: <strong>¥<?= number_format($overheadCost) ?></strong></span>
          <span>1人あたり: <strong><?= $perPersonQty ?>本</strong></span>
        </div>
      </div>
    </div>
  </div>
  <?php elseif (!isPresidentOrAdmin()): ?>
  <!-- 部長：コストは非表示、稼働効率のみ -->
  <?php endif; ?>

  <!-- ガントチャート -->
  <div class="col-12">
    <div class="card">
      <div class="card-header py-2 d-flex align-items-center gap-2">
        <span class="fw-bold"><i class="bi bi-bar-chart-steps"></i> 製造スケジュール</span>
        <div class="btn-group btn-group-sm ms-auto">
          <?php foreach (['tomorrow'=>'明日','week'=>'今週','month'=>'今月'] as $k=>$l): ?>
          <a href="?gantt_period=<?= $k ?>"
             class="btn btn-sm <?= $ganttPeriod === $k ? 'btn-primary' : 'btn-outline-primary' ?>">
            <?= $l ?>
          </a>
          <?php endforeach; ?>
        </div>
        <a href="gantt.php?date_from=<?= $ganttFrom ?>&date_to=<?= $ganttTo ?>"
           class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrows-fullscreen"></i>
        </a>
      </div>
      <div class="card-body p-0">
        <iframe src="gantt.php?date_from=<?= $ganttFrom ?>&date_to=<?= $ganttTo ?>&embed=1"
                class="w-100 border-0" style="height:280px" title="ガントチャート"></iframe>
      </div>
    </div>
  </div>

  <!-- 社長の言葉 -->
  <?php if ($word): ?>
  <div class="col-12">
    <div class="card bg-dark text-white">
      <div class="card-body py-3 text-center">
        <i class="bi bi-chat-quote fs-4 text-warning"></i>
        <p class="fst-italic mb-1 mt-2" id="presidentWordText">
          "<?= h($word['message']) ?>"
        </p>
        <button id="presidentWordToggle" class="btn btn-link btn-sm text-white-50 p-0 d-none"
                onclick="togglePresidentWord()">
          続きを読む <i class="bi bi-chevron-down"></i>
        </button>
        <footer class="blockquote-footer text-white-50 mt-1">
          <?= h($word['speaker_name']) ?>
        </footer>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /row -->

</div><!-- /execTabPane -->

<!-- ===== 部門タブ ===== -->
<div class="tab-pane fade" id="deptTabPane" role="tabpanel">

<div class="d-flex align-items-center mb-3 gap-2">
  <h2 class="mb-0"><i class="bi bi-diagram-3"></i> 部門ダッシュボード</h2>
  <small class="text-muted ms-2"><?= date('Y年n月j日', strtotime($today)) ?></small>
  <button class="ms-auto btn btn-outline-secondary btn-sm" onclick="location.reload()">
    <i class="bi bi-arrow-clockwise"></i>
  </button>
</div>

<!-- 部門サマリーカード -->
<div class="row g-3 mb-4">
<?php foreach ($deptCards as $dept): ?>
  <?php
    $util = $dept['emp_cnt'] > 0
        ? round($dept['working_today'] / $dept['emp_cnt'] * 100)
        : 0;
    $utilColor = $util >= 70 ? '#198754' : ($util >= 40 ? '#ffc107' : '#6c757d');
  ?>
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card h-100" style="border-left:4px solid <?= $utilColor ?>">
      <div class="card-header fw-bold d-flex justify-content-between align-items-center py-2">
        <span><i class="bi bi-building"></i> <?= h($dept['dept_name']) ?></span>
        <span class="badge" style="background:<?= $utilColor ?>"><?= $util ?>% 稼働</span>
      </div>
      <div class="card-body py-2">
        <div class="row g-2 text-center mb-2">
          <div class="col-3">
            <div class="small text-muted">人数</div>
            <div class="fw-bold"><?= $dept['emp_cnt'] ?>人</div>
          </div>
          <div class="col-3">
            <div class="small text-muted">今作業中</div>
            <div class="fw-bold text-success"><?= $dept['active_now'] ?>人</div>
          </div>
          <div class="col-3">
            <div class="small text-muted">今日時間</div>
            <div class="fw-bold"><?= $dept['today_hours'] ?>h</div>
          </div>
          <div class="col-3">
            <div class="small text-muted">月間時間</div>
            <div class="fw-bold"><?= $dept['month_hours'] ?>h</div>
          </div>
        </div>
        <!-- 稼働率バー -->
        <div class="progress" style="height:8px">
          <div class="progress-bar" role="progressbar"
               style="width:<?= $util ?>%;background:<?= $utilColor ?>"
               title="稼働率 <?= $util ?>%"></div>
        </div>
        <!-- 部門内社員テーブル -->
        <?php
          $deptEmps = array_filter($allEmpData, fn($e) => $e['dept_id'] == $dept['dept_id']);
        ?>
        <?php if (count($deptEmps)): ?>
        <div class="table-responsive mt-2" style="max-height:200px;overflow-y:auto">
          <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
            <thead class="table-light sticky-top">
              <tr>
                <th>氏名</th>
                <th class="text-center">状態</th>
                <th>現在工程</th>
                <th class="text-end">今日h</th>
                <th class="text-end">月間h</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($deptEmps as $emp): ?>
              <?php $aw = $activeWorkAll[$emp['emp_id']] ?? null; ?>
              <tr>
                <td class="text-nowrap"><?= h($emp['name']) ?></td>
                <td class="text-center">
                  <?php if ($aw): ?>
                    <span class="badge bg-success">作業中</span>
                  <?php elseif ($emp['worked_today']): ?>
                    <span class="badge bg-secondary">休憩中</span>
                  <?php else: ?>
                    <span class="badge bg-light text-muted border">未開始</span>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap text-truncate" style="max-width:100px">
                  <?php if ($aw): ?>
                    <span class="text-success"><?= h($aw['process_name']) ?></span>
                    <span class="text-muted small">(<?= $aw['elapsed_min'] ?>分)</span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-end"><?= $emp['today_hours'] ?></td>
                <td class="text-end"><?= $emp['month_hours'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($deptCards)): ?>
  <div class="col-12">
    <div class="alert alert-info"><i class="bi bi-info-circle"></i> 部門データがありません。</div>
  </div>
<?php endif; ?>
</div>

</div><!-- /deptTabPane -->

<!-- ===== 個人タブ ===== -->
<div class="tab-pane fade" id="workerTabPane" role="tabpanel">

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h2 class="mb-0"><i class="bi bi-people"></i> 個人ダッシュボード</h2>
  <small class="text-muted ms-2"><?= date('Y年n月j日', strtotime($today)) ?></small>
  <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
    <select id="indivDeptFilter" class="form-select form-select-sm" style="width:auto">
      <option value="">全部門</option>
      <?php foreach ($deptCards as $dc): ?>
        <option value="<?= h($dc['dept_name']) ?>"><?= h($dc['dept_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" id="indivSearch" class="form-control form-control-sm" placeholder="氏名検索" style="width:120px">
    <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
      <i class="bi bi-arrow-clockwise"></i>
    </button>
  </div>
</div>

<!-- 集計サマリー行 -->
<?php
  $totalActive   = count(array_filter($allEmpData, fn($e) => isset($activeWorkAll[$e['emp_id']])));
  $totalWorkedToday = count(array_filter($allEmpData, fn($e) => $e['worked_today']));
  $totalEmpCnt   = count($allEmpData);
  $totalTodayH   = round(array_sum(array_column($allEmpData, 'today_hours')), 1);
  $totalMonthH   = round(array_sum(array_column($allEmpData, 'month_hours')), 1);
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="card worker-kpi p-2 text-center" style="border-color:#0d6efd">
      <div class="fw-bold fs-5 text-primary"><?= $totalEmpCnt ?></div>
      <div class="small text-muted">在籍人数</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card worker-kpi p-2 text-center" style="border-color:#198754">
      <div class="fw-bold fs-5 text-success"><?= $totalActive ?></div>
      <div class="small text-muted">現在作業中</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card worker-kpi p-2 text-center" style="border-color:#ffc107">
      <div class="fw-bold fs-5 text-warning"><?= $totalTodayH ?>h</div>
      <div class="small text-muted">全社 今日作業時間</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card worker-kpi p-2 text-center" style="border-color:#6f42c1">
      <div class="fw-bold fs-5" style="color:#6f42c1"><?= $totalMonthH ?>h</div>
      <div class="small text-muted">全社 月間作業時間</div>
    </div>
  </div>
</div>

<!-- 部門別アコーディオン -->
<div class="accordion" id="indivAccordion">
<?php
$deptIdx = 0;
foreach ($empByDept as $deptName => $emps):
  $deptIdx++;
  $deptActiveCount = count(array_filter($emps, fn($e) => isset($activeWorkAll[$e['emp_id']])));
  $deptId = 'deptSection_' . $deptIdx;
?>
<div class="accordion-item indiv-dept-block" data-dept="<?= h($deptName) ?>">
  <h2 class="accordion-header">
    <button class="accordion-button <?= $deptIdx > 1 ? 'collapsed' : '' ?>" type="button"
            data-bs-toggle="collapse" data-bs-target="#<?= $deptId ?>">
      <i class="bi bi-building me-2"></i>
      <strong><?= h($deptName) ?></strong>
      <span class="ms-2 badge bg-secondary"><?= count($emps) ?>人</span>
      <?php if ($deptActiveCount): ?>
        <span class="ms-1 badge bg-success"><?= $deptActiveCount ?>人作業中</span>
      <?php endif; ?>
    </button>
  </h2>
  <div id="<?= $deptId ?>" class="accordion-collapse collapse <?= $deptIdx === 1 ? 'show' : '' ?>">
    <div class="accordion-body p-0">
      <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 indiv-table">
        <thead class="table-light">
          <tr>
            <th>氏名</th>
            <th>役職</th>
            <th class="text-center">状態</th>
            <th>現在工程</th>
            <th class="text-end">経過</th>
            <th class="text-end">今日h</th>
            <th class="text-end">月間h</th>
            <th class="text-end">月間指示</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($emps as $emp):
          $aw = $activeWorkAll[$emp['emp_id']] ?? null;
          $planned = $aw ? (int)($aw['planned_total_minutes'] ?? 0) : 0;
          $elapsed = $aw ? (int)$aw['elapsed_min'] : 0;
          $pct     = ($planned > 0) ? min(120, round($elapsed / $planned * 100)) : 0;
        ?>
          <tr class="indiv-emp-row" data-name="<?= h(mb_strtolower($emp['name'])) ?>">
            <td class="fw-semibold text-nowrap">
              <?= h($emp['name']) ?>
              <span class="text-muted small ms-1"><?= h($emp['employee_code']) ?></span>
            </td>
            <td class="small text-muted text-nowrap"><?= h($emp['position_name'] ?? '') ?></td>
            <td class="text-center">
              <?php if ($aw): ?>
                <span class="badge bg-success">作業中</span>
              <?php elseif ($emp['worked_today']): ?>
                <span class="badge bg-secondary">休憩中</span>
              <?php else: ?>
                <span class="badge bg-light text-muted border">未開始</span>
              <?php endif; ?>
            </td>
            <td class="text-nowrap" style="max-width:130px;overflow:hidden;text-overflow:ellipsis">
              <?php if ($aw): ?>
                <span class="text-success small">
                  <?= h($aw['process_name']) ?>
                  <span class="text-muted">(<?= h($aw['order_no']) ?>)</span>
                </span>
                <?php if ($planned > 0): ?>
                <div class="progress mt-1" style="height:4px;min-width:60px">
                  <div class="progress-bar <?= $pct > 100 ? 'bg-danger' : 'bg-success' ?>"
                       style="width:<?= min(100,$pct) ?>%"
                       title="<?= $pct ?>%"></div>
                </div>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end small">
              <?php if ($aw): ?>
                <span class="<?= ($planned > 0 && $elapsed > $planned) ? 'text-danger fw-bold' : 'text-success' ?>">
                  <?= floor($elapsed/60) ?>h<?= str_pad($elapsed%60,2,'0',STR_PAD_LEFT) ?>m
                </span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= $emp['today_hours'] ?></td>
            <td class="text-end"><?= $emp['month_hours'] ?></td>
            <td class="text-end"><?= (int)$emp['month_orders'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if (empty($allEmpData)): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle"></i> 社員データがありません。</div>
<?php endif; ?>
</div><!-- /accordion -->

  <?php /* $w_ vars still computed above, used here to prevent PHP notices */ ?>
  <?php $_ = [$w_monthSummary, $w_todayTotalMinutes, $w_todayPlannedTotal, $w_activeWork, $w_todayLogs, $w_deptQueue, $w_skills]; ?>


</div><!-- /workerTabPane -->
</div><!-- /tab-content -->

<?php
$extraJs = <<<JSCODE
// タブ状態をlocalStorageで記憶
(function(){
    const key = 'dashActiveTab';
    const saved = localStorage.getItem(key);
    if (saved) {
        const btn = document.getElementById(saved);
        if (btn) { bootstrap.Tab.getOrCreateInstance(btn).show(); }
    }
    document.querySelectorAll('#dashTabs .nav-link').forEach(function(btn){
        btn.addEventListener('shown.bs.tab', function(e){
            localStorage.setItem(key, e.target.id);
        });
    });
})();

// ライブクロック
(function tick(){
    const el = document.getElementById('liveClock');
    if(el){ const n=new Date(); el.textContent=n.getHours().toString().padStart(2,'0')+':'+n.getMinutes().toString().padStart(2,'0')+':'+n.getSeconds().toString().padStart(2,'0'); }
    setTimeout(tick,1000);
})();

// 日別生産チャート
(function(){
    const raw = $jsDaily;
    const ctx = document.getElementById('dailyChart');
    if(!ctx || !raw.length) return;
    const labels = raw.map(r=>r.prod_date.slice(5));
    const data   = raw.map(r=>parseInt(r.qty));
    new Chart(ctx, {
        type:'bar',
        data:{
            labels,
            datasets:[{
                label:'完成本数',
                data,
                backgroundColor:'rgba(25,135,84,0.7)',
                borderColor:'rgb(25,135,84)',
                borderWidth:1,
                borderRadius:3,
            }]
        },
        options:{
            responsive:true,
            plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>ctx.parsed.y+'本'}}},
            scales:{
                y:{beginAtZero:true,ticks:{stepSize:1},title:{display:false}},
                x:{ticks:{maxRotation:0,font:{size:10}}}
            }
        }
    });
})();

// 月別推移チャート
(function(){
    const raw = $jsMonthly;
    const ctx = document.getElementById('monthlyChart');
    if(!ctx) return;
    const labels = raw.length ? raw.map(r=>r.ym) : ['データなし'];
    const data   = raw.length ? raw.map(r=>parseInt(r.qty)) : [0];
    new Chart(ctx, {
        type:'line',
        data:{
            labels,
            datasets:[{
                label:'完成本数',
                data,
                borderColor:'rgb(13,110,253)',
                backgroundColor:'rgba(13,110,253,0.08)',
                tension:.3,
                fill:true,
                pointRadius:4,
                pointHoverRadius:6,
            }]
        },
        options:{
            responsive:true,
            plugins:{legend:{display:false}},
            scales:{
                y:{beginAtZero:true,ticks:{stepSize:5}},
                x:{ticks:{font:{size:11}}}
            }
        }
    });
})();

// 社長の言葉 2行クランプ
(function(){
    const el=document.getElementById('presidentWordText');
    const btn=document.getElementById('presidentWordToggle');
    if(!el||!btn) return;
    const lh=parseFloat(getComputedStyle(el).lineHeight)||22;
    const max=lh*2+4;
    if(el.scrollHeight>max+4){
        el.style.overflow='hidden'; el.style.maxHeight=max+'px';
        el.style.transition='max-height .3s';
        btn.classList.remove('d-none');
    }
})();
function togglePresidentWord(){
    const el=document.getElementById('presidentWordText');
    const btn=document.getElementById('presidentWordToggle');
    const exp=parseInt(el.style.maxHeight)>80;
    if(exp){const lh=parseFloat(getComputedStyle(el).lineHeight)||22; el.style.maxHeight=(lh*2+4)+'px'; btn.innerHTML='続きを読む <i class="bi bi-chevron-down"></i>';}
    else{el.style.maxHeight=el.scrollHeight+'px'; btn.innerHTML='閉じる <i class="bi bi-chevron-up"></i>';}
}

// 個人タブ: 部門フィルター + 氏名検索
(function(){
    const deptSel  = document.getElementById('indivDeptFilter');
    const nameInp  = document.getElementById('indivSearch');
    function applyFilter(){
        const dept = (deptSel ? deptSel.value.toLowerCase() : '');
        const name = (nameInp ? nameInp.value.toLowerCase() : '');
        document.querySelectorAll('.indiv-dept-block').forEach(function(block){
            const blockDept = block.dataset.dept.toLowerCase();
            const deptMatch = !dept || blockDept.includes(dept);
            let anyRow = false;
            block.querySelectorAll('.indiv-emp-row').forEach(function(row){
                const nameMatch = !name || row.dataset.name.includes(name);
                const show = deptMatch && nameMatch;
                row.style.display = show ? '' : 'none';
                if(show) anyRow = true;
            });
            block.style.display = deptMatch ? '' : 'none';
            if(deptMatch && name){
                const collapse = block.querySelector('.accordion-collapse');
                if(collapse && anyRow && !collapse.classList.contains('show')){
                    bootstrap.Collapse.getOrCreateInstance(collapse).show();
                }
            }
        });
    }
    if(deptSel) deptSel.addEventListener('change', applyFilter);
    if(nameInp) nameInp.addEventListener('input', applyFilter);
})();
JSCODE;
require __DIR__ . '/parts/footer.php';
?>
