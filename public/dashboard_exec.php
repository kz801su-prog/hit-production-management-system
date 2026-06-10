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

// 月次予算対比（過去6ヶ月）
$budgetComparison = [];
$currentBudget = null;
try {
    $budgetComparison = dbFetchAll(
        "SELECT mb.year_month, mb.target_qty, mb.salary_forecast, mb.overhead_forecast,
                (mb.salary_forecast + mb.overhead_forecast) AS total_budget,
                COALESCE((SELECT SUM(quantity) FROM manufacturing_orders mo
                           WHERE mo.status='completed'
                             AND DATE_FORMAT(mo.updated_at,'%Y-%m')=mb.year_month), 0) AS actual_qty
         FROM monthly_budget mb
         WHERE mb.year_month >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH), '%Y-%m')
         ORDER BY mb.year_month"
    );
    foreach ($budgetComparison as $bc) {
        if ($bc['year_month'] === $thisMonth) { $currentBudget = $bc; break; }
    }
} catch (Exception $e) {}

// 工程ステータス分布（現在進行中の作業指示の工程）
$processStatusDist = [];
try {
    $processStatusDist = dbFetchAll(
        "SELECT delay_status, COUNT(*) AS cnt
         FROM manufacturing_order_processes mop
         JOIN manufacturing_orders mo ON mop.manufacturing_order_id=mo.id
         WHERE mo.status NOT IN ('completed','cancelled') AND mop.delay_status IS NOT NULL
         GROUP BY delay_status
         ORDER BY FIELD(delay_status,'critical','delayed','warning','normal')"
    );
} catch (Exception $e) {}

// 今日の完成本数（現場モニター用）
$todayCompleted = (int)(dbFetchOne(
    "SELECT COALESCE(SUM(quantity),0) AS q FROM manufacturing_orders
     WHERE status='completed' AND DATE(updated_at)=?", [$today]
)['q'] ?? 0);
$todayCompletedCount = (int)(dbFetchOne(
    "SELECT COUNT(*) AS c FROM manufacturing_orders
     WHERE status='completed' AND DATE(updated_at)=?", [$today]
)['c'] ?? 0);

// 今日の目標（system_settings月間目標 ÷ 月の営業日数で簡易換算、または固定値）
$workingDays = max(1, (int)dbFetchOne(
    "SELECT COUNT(DISTINCT DATE(started_at)) AS c FROM work_logs
     WHERE started_at >= ? AND started_at < DATE_ADD(?, INTERVAL 1 MONTH)",
    [$monthFrom, $monthFrom]
)['c'] ?? 20);
$todayTarget = $targetQty > 0 ? max(1, (int)ceil($targetQty / 20)) : 0;

// 現在作業中の工程詳細（現場モニター用）
$activeProcesses = dbFetchAll(
    "SELECT mo.order_no, mo.quantity, mo.due_date, mo.priority,
            ct.chair_type_name,
            p.process_name, d.dept_name,
            mop.delay_status, mop.planned_total_minutes,
            TIMESTAMPDIFF(MINUTE, wl.started_at, NOW()) AS elapsed_min,
            e.name AS worker_name
     FROM work_logs wl
     JOIN employees e ON wl.employee_id=e.id
     LEFT JOIN departments d ON e.department_id=d.id
     JOIN manufacturing_orders mo ON wl.manufacturing_order_id=mo.id
     JOIN chair_types ct ON mo.chair_type_id=ct.id
     JOIN processes p ON wl.process_id=p.id
     LEFT JOIN manufacturing_order_processes mop
         ON mop.manufacturing_order_id=mo.id AND mop.process_id=wl.process_id
     WHERE wl.ended_at IS NULL
     ORDER BY mop.delay_status DESC, ISNULL(mo.due_date), mo.due_date",
    []
);

// ダッシュボード表示設定
$dWidgets = [];
try {
    $dw = dbFetchOne("SELECT setting_value FROM system_settings WHERE setting_key='dashboard_widgets'")['setting_value'] ?? '';
    $dWidgets = $dw ? (json_decode($dw, true) ?? []) : [];
} catch (Exception $e) {}
$showWidget = fn(string $k): bool => !isset($dWidgets[$k]) || (bool)$dWidgets[$k];

// JS用データ
$jsProcessStatus = json_encode(array_values($processStatusDist), JSON_UNESCAPED_UNICODE);
$jsBudget        = json_encode(array_values($budgetComparison),  JSON_UNESCAPED_UNICODE);

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

<!-- ===== ダッシュボード スタイル ===== -->
<style>
:root {
    --c-blue:   #1a56db; --c-blue-l:  #3b82f6;
    --c-green:  #0f9060; --c-green-l: #10b981;
    --c-amber:  #b45309; --c-amber-l: #f59e0b;
    --c-red:    #c81e1e; --c-red-l:   #ef4444;
    --c-purple: #6d28d9; --c-purple-l:#a78bfa;
    --c-teal:   #0e7490; --c-teal-l:  #22d3ee;
    --c-gray:   #374151; --c-gray-l:  #6b7280;
    --shadow:  0 1px 3px rgba(0,0,0,.1), 0 1px 2px rgba(0,0,0,.07);
    --shadow-h:0 6px 16px rgba(0,0,0,.14);
}

/* ── KPI Cards ── */
.kpi-card {
    border-radius: 14px;
    border: none;
    box-shadow: var(--shadow);
    transition: transform .2s, box-shadow .2s;
    overflow: hidden;
    position: relative;
    padding: 1rem 1.1rem;
    color: white;
}
.kpi-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-h); }
.kpi-card.kpi-blue   { background: linear-gradient(135deg,var(--c-blue) 0%,var(--c-blue-l) 100%); }
.kpi-card.kpi-green  { background: linear-gradient(135deg,var(--c-green) 0%,var(--c-green-l) 100%); }
.kpi-card.kpi-amber  { background: linear-gradient(135deg,var(--c-amber) 0%,var(--c-amber-l) 100%); }
.kpi-card.kpi-red    { background: linear-gradient(135deg,var(--c-red) 0%,var(--c-red-l) 100%); }
.kpi-card.kpi-purple { background: linear-gradient(135deg,var(--c-purple) 0%,var(--c-purple-l) 100%); }
.kpi-card.kpi-teal   { background: linear-gradient(135deg,var(--c-teal) 0%,var(--c-teal-l) 100%); }
.kpi-card.kpi-gray   { background: linear-gradient(135deg,var(--c-gray) 0%,var(--c-gray-l) 100%); }
.kpi-bg-icon {
    position: absolute; right: 10px; top: 50%;
    transform: translateY(-50%);
    font-size: 3rem; opacity: .18; pointer-events: none;
}
.kpi-label {
    font-size: .67rem; font-weight: 700;
    letter-spacing: .09em; text-transform: uppercase; opacity: .85;
    margin-bottom: .25rem;
}
.kpi-value {
    font-size: 2.1rem; font-weight: 800;
    line-height: 1; letter-spacing: -.03em;
}
.kpi-sub { font-size: .76rem; opacity: .82; margin-top: .2rem; }
@media (max-width:576px) {
    .kpi-value { font-size: 1.6rem; }
    .kpi-scroll-wrap {
        display:flex; overflow-x:auto; gap:.5rem;
        padding-bottom:.3rem; -webkit-overflow-scrolling:touch;
    }
    .kpi-scroll-wrap .kpi-card { min-width:130px; flex-shrink:0; }
}

/* ── Tabs ── */
#dashTabs { border-bottom: 2px solid #dee2e6; }
#dashTabs .nav-link {
    color:#6b7280; border:none;
    border-bottom: 3px solid transparent;
    border-radius:0; padding:.65rem 1rem;
    transition: color .15s, border-color .15s;
}
#dashTabs .nav-link:hover { color:var(--c-blue); border-bottom-color:#bfdbfe; }
#dashTabs .nav-link.active {
    color:var(--c-blue); font-weight:700;
    border-bottom: 3px solid var(--c-blue); background:none;
}

/* ── Progress ── */
.progress-thick { height:24px; border-radius:8px; font-size:.8rem; }
.progress-thin  { height:7px;  border-radius:4px; }

/* ── Section label ── */
.dash-section { font-size:.67rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#9ca3af; margin-bottom:.4rem; }

/* ── Alert rows ── */
.alert-row-critical { border-left:4px solid #dc3545; background:#fff5f5; }
.alert-row-delayed  { border-left:4px solid #f59e0b; background:#fffbeb; }

/* ── Dept cards ── */
.dept-card { border-radius:12px; border:none; box-shadow:var(--shadow); overflow:hidden; transition:box-shadow .15s; }
.dept-card:hover { box-shadow:var(--shadow-h); }
.dept-header { padding:.7rem 1rem; font-weight:700; font-size:.95rem; color:white; }
.dept-util-bar { height:7px; border-radius:4px; background:rgba(255,255,255,.3); margin-top:.35rem; }
.dept-util-fill { height:100%; border-radius:4px; background:rgba(255,255,255,.9); transition:width .6s; }
.dept-stat-val { font-size:1.25rem; font-weight:800; line-height:1; }
.dept-stat-lbl { font-size:.67rem; opacity:.75; }

/* ── Worker tiles (個人タブ) ── */
.worker-tile {
    border-radius:10px; border:1px solid #e5e7eb;
    box-shadow:var(--shadow); overflow:hidden;
    transition: transform .15s, box-shadow .15s;
}
.worker-tile:hover { transform:translateY(-2px); box-shadow:var(--shadow-h); }
.tile-hd { padding:.45rem .75rem; display:flex; align-items:center; gap:.35rem; }
.tile-active .tile-hd { background:#059669; color:white; }
.tile-break  .tile-hd { background:#d97706; color:white; }
.tile-idle   .tile-hd { background:#9ca3af; color:white; }
.tile-bd { padding:.5rem .75rem; background:white; }
.tile-name { font-weight:700; font-size:.85rem; }
.tile-proc { font-size:.76rem; color:#4b5563; }
.tile-stats { font-size:.72rem; color:#6b7280; }
.tile-prog { height:4px; border-radius:2px; background:#e5e7eb; margin-top:.4rem; }
.tile-prog-fill { height:100%; border-radius:2px; transition:width .4s; }

/* pulse dot for active worker */
.pulse-dot {
    width:8px; height:8px; border-radius:50%;
    background:#fff; opacity:.9; display:inline-block;
    animation: pulse-kf 1.4s ease-in-out infinite;
}
@keyframes pulse-kf {
    0%,100%{opacity:.9;transform:scale(1)} 50%{opacity:.45;transform:scale(1.4)}
}

/* ── Word banner ── */
.word-banner {
    background:#ffffff;
    border-radius:10px; border:none;
    box-shadow:0 2px 12px rgba(0,0,0,.15);
    padding:.55rem .9rem; display:flex; align-items:flex-start; gap:.6rem;
    margin-bottom:.85rem;
}
.word-banner-text {
    color:#1e293b; font-style:italic; font-size:.88rem; line-height:1.55;
    overflow:hidden; max-height:1.55em; transition:max-height .3s ease; flex:1;
}
.word-banner-text.expanded { max-height:20em; }
.word-banner-speaker { color:#6b7280; font-size:.72rem; white-space:nowrap; padding-top:.25rem; }
.word-expand-btn { color:#1d4ed8; font-size:.72rem; white-space:nowrap; background:none; border:none; padding:0; cursor:pointer; padding-top:.25rem; }

/* ── Monitor tab ── */
.monitor-bg {
    background:linear-gradient(160deg,#0f172a 0%,#1e293b 100%);
    border-radius:14px; padding:1.5rem; margin-bottom:1rem;
}
.monitor-kpi-num {
    font-size: clamp(2.5rem, 8vw, 5rem);
    font-weight: 900; line-height: 1; letter-spacing: -.04em;
}
.monitor-kpi-lbl {
    font-size: clamp(.75rem, 2vw, 1rem);
    font-weight: 700; letter-spacing: .08em; text-transform: uppercase; opacity: .7;
    margin-top:.3rem;
}
.monitor-progress-wrap { background:rgba(255,255,255,.1); border-radius:8px; height:16px; overflow:hidden; margin-top:.5rem; }
.monitor-progress-fill { height:100%; border-radius:8px; transition:width .8s ease; }
.process-row-critical { border-left:5px solid #ef4444; background:#1f1215; }
.process-row-delayed  { border-left:5px solid #f59e0b; background:#1c1810; }
.process-row-normal   { border-left:5px solid #10b981; background:#0d1f18; }
.monitor-table td, .monitor-table th { padding:.5rem .75rem; vertical-align:middle; font-size:.85rem; color:#e2e8f0; border-color:rgba(255,255,255,.08); }
.monitor-table thead th { background:rgba(255,255,255,.06); font-size:.72rem; letter-spacing:.08em; text-transform:uppercase; color:#67e8f9; }

/* ── misc ── */
.diff-over { color:#dc3545; } .diff-under { color:#198754; }
.queue-row-urgent { border-left:4px solid #dc3545; }
.queue-row-high   { border-left:4px solid #ffc107; }
.worker-kpi { border-left:4px solid; border-radius:8px; }
.exec-kpi-card { border-radius:10px; border-left:4px solid; }

/* ════════════════════════════════════════
   EXEC GRAY THEME  (黒→グレー)
════════════════════════════════════════ */
.exec-dark {
    background: #1e293b;
    background-image:
        radial-gradient(ellipse 55% 40% at 8%  8%,  rgba(0,180,255,.12) 0%, transparent 65%),
        radial-gradient(ellipse 45% 45% at 92% 90%, rgba(120,0,255,.08) 0%, transparent 60%);
    border-radius: 14px;
    padding: 1.25rem 1rem;
}
/* gray tab nav */
#dashTabs {
    background: #1e293b !important;
    border-radius: 10px 10px 0 0;
    border-bottom: 1px solid rgba(255,255,255,.15) !important;
    padding: 0 .25rem;
}
#dashTabs .nav-link { color: #94a3b8 !important; border-bottom-color: transparent !important; }
#dashTabs .nav-link:hover { color: #e2e8f0 !important; border-bottom-color: rgba(56,189,248,.4) !important; }
#dashTabs .nav-link.active { color: #38bdf8 !important; border-bottom-color: #38bdf8 !important; }
/* KPI cards */
.ex-kpi {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.13);
    border-radius: 12px; padding: .8rem 1rem;
    position: relative; overflow: hidden;
    transition: border-color .2s, box-shadow .2s; height: 100%;
}
.ex-kpi:hover { border-color: rgba(255,255,255,.28); box-shadow: 0 0 22px rgba(56,189,248,.12); }
.ex-kpi .kpi-bg-icon { font-size: 2.8rem; opacity: .11; }
.ex-kpi .kpi-label { color: #67e8f9; font-size: .63rem; letter-spacing: .1em; text-transform: uppercase; font-weight: 700; margin-bottom: .2rem; }
.ex-kpi .kpi-value { font-size: 1.9rem; font-weight: 900; line-height: 1; letter-spacing: -.03em; }
.ex-kpi .kpi-sub   { font-size: .72rem; color: #cbd5e1; margin-top: .18rem; }
/* neon colors */
.neon-b { color: #38bdf8; text-shadow: 0 0 14px rgba(56,189,248,.6),  0 0 28px rgba(56,189,248,.2); }
.neon-g { color: #4ade80; text-shadow: 0 0 14px rgba(74,222,128,.6),  0 0 28px rgba(74,222,128,.2); }
.neon-a { color: #fbbf24; text-shadow: 0 0 14px rgba(251,191,36,.6),  0 0 28px rgba(251,191,36,.2); }
.neon-r { color: #f87171; text-shadow: 0 0 14px rgba(248,113,113,.6), 0 0 28px rgba(248,113,113,.2); }
.neon-p { color: #c084fc; text-shadow: 0 0 14px rgba(192,132,252,.6), 0 0 28px rgba(192,132,252,.2); }
.neon-t { color: #2dd4bf; text-shadow: 0 0 14px rgba(45,212,191,.6),  0 0 28px rgba(45,212,191,.2); }
/* content cards */
.ex-card {
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,.3);
}
.ex-card-hd {
    background: rgba(255,255,255,.08);
    border-bottom: 1px solid rgba(255,255,255,.1);
    padding: .5rem .9rem; color: #e2e8f0;
    font-size: .7rem; font-weight: 700;
    letter-spacing: .09em; text-transform: uppercase;
    display: flex; align-items: center; justify-content: space-between;
}
.ex-card-bd { padding: .6rem .75rem; }
/* progress bar */
.ex-prog { background: rgba(255,255,255,.1); border-radius: 10px; height: 12px; overflow: hidden; }
.ex-prog-g { height: 100%; border-radius: 10px; background: linear-gradient(90deg,#065f46,#059669,#10b981,#34d399); box-shadow: 0 0 12px rgba(16,185,129,.45); transition: width .8s; }
/* section dividers */
.ex-sec {
    font-size: .62rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase;
    color: #cbd5e1; padding: .2rem 0 .4rem;
    display: flex; align-items: center; gap: .5rem;
    margin-top: .5rem; margin-bottom: .6rem;
}
.ex-sec::before { content:''; display:inline-block; width:3px; height:14px; border-radius:2px; background:#38bdf8; box-shadow:0 0 8px #38bdf8; }
.ex-sec::after  { content:''; flex:1; height:1px; background:rgba(255,255,255,.18); }
/* alert rows */
.ex-al { padding: .5rem .8rem; border-bottom: 1px solid rgba(255,255,255,.06); color: #f1f5f9; font-size: .82rem; display: block; text-decoration: none; }
.ex-al:last-child { border-bottom: none; }
.ex-al:hover { background: rgba(255,255,255,.05); }
.ex-al.c { border-left: 3px solid #f87171; }
.ex-al.d { border-left: 3px solid #fbbf24; }
/* dept cards */
.ex-dept { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); border-radius: 10px; overflow: hidden; transition: border-color .2s; }
.ex-dept:hover { border-color: rgba(255,255,255,.24); }
.ex-dept-hd { padding: .5rem .75rem; font-weight: 700; font-size: .85rem; }
.ex-dept-bar { height: 5px; border-radius: 3px; background: rgba(255,255,255,.15); margin-top: .3rem; }
.ex-dept-fill { height: 100%; border-radius: 3px; background: rgba(255,255,255,.9); box-shadow: 0 0 6px rgba(255,255,255,.4); transition: width .6s; }
.ex-dept-bd { padding: .45rem .6rem; background: rgba(0,0,0,.15); }
.ex-dept-stat-val { font-size: 1.15rem; font-weight: 800; line-height: 1.1; }
.ex-dept-stat-lbl { font-size: .63rem; color: #cbd5e1; }
/* budget kpi */
.ex-bkpi { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.11); border-radius: 10px; padding: .65rem .9rem; position: relative; overflow: hidden; }
.ex-bkpi .kpi-bg-icon { font-size: 2.5rem; opacity: .09; }
.ex-bkpi .kpi-label { color: #67e8f9; font-size: .63rem; text-transform: uppercase; letter-spacing: .09em; font-weight: 700; margin-bottom: .15rem; }
.ex-bkpi .kpi-value { font-size: 1.65rem; font-weight: 900; line-height: 1.1; letter-spacing: -.03em; }
.ex-bkpi .kpi-sub   { font-size: .7rem; color: #cbd5e1; margin-top: .15rem; }
/* cost values */
.ex-cost-val { font-weight: 800; font-size: 1.05rem; }
.ex-cost-lbl { font-size: .7rem; color: #cbd5e1; margin-top: .1rem; }
/* ── Gauge (タコメーター) ── */
.gauge-wrap { position:relative; }
.gauge-overlay {
    position:absolute; bottom:2px; left:50%; transform:translateX(-50%);
    text-align:center; pointer-events:none; width:100%;
}
.gauge-pct  { font-size:clamp(1.3rem,3.5vw,1.8rem); font-weight:900; line-height:1; letter-spacing:-.04em; }
.gauge-qty  { font-size:.75rem; color:#cbd5e1; margin-top:.1rem; }
.gauge-lbl  { font-size:.58rem; letter-spacing:.1em; text-transform:uppercase; color:#94a3b8; margin-top:.1rem; }
/* ── セクション設定パネル ── */
.exec-section.sec-hidden { display: none !important; }
#exSec-kpi      { --sec-clr: #67e8f9; }
#exSec-progress { --sec-clr: #38bdf8; }
#exSec-budget   { --sec-clr: #2dd4bf; }
#exChart-daily  { --sec-clr: #38bdf8; }
#exChart-monthly{ --sec-clr: #2dd4bf; }
#exChart-budget { --sec-clr: #fbbf24; }
#exChart-process{ --sec-clr: #f87171; }
#exSec-alerts   { --sec-clr: #f87171; }
#exSec-upcoming { --sec-clr: #fbbf24; }
#exSec-dept     { --sec-clr: #38bdf8; }
#exSec-cost     { --sec-clr: #fbbf24; }
#exSec-gantt    { --sec-clr: #38bdf8; }
#exSec-kpi .kpi-label, #exSec-budget .kpi-label { color: var(--sec-clr); }
.exec-section .sec-icon { color: var(--sec-clr) !important; }
/* 設定パネル offcanvas */
#dashSettingsPanel .sp-bg-swatch:hover { transform:scale(1.2); }
#dashSettingsPanel .sp-bg-swatch.active { outline:2px solid #67e8f9; outline-offset:2px; }
</style>

<!-- ===== タブナビゲーション ===== -->
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
      <i class="bi bi-people-fill"></i>
      <span class="d-none d-sm-inline"> 部門・個人</span>
      <span class="d-sm-none"> 部門</span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link fw-bold" id="monitorTab-btn"
            data-bs-toggle="tab" data-bs-target="#monitorTabPane"
            type="button" role="tab">
      <i class="bi bi-display"></i>
      <span class="d-none d-sm-inline"> 現場モニター</span>
      <span class="d-sm-none"> 現場</span>
    </button>
  </li>
</ul>

<div class="tab-content" id="dashTabContent">
<div class="tab-pane fade show active" id="execTabPane" role="tabpanel">

<?php if (isPresidentOrAdmin()): ?>
<!-- ═══ ダッシュボード設定パネル ═══ -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="dashSettingsPanel"
     style="width:340px;max-width:96vw;background:#0f172a;color:#e2e8f0;border-left:1px solid rgba(255,255,255,.15)">
  <div class="offcanvas-header py-3" style="background:#1e293b;border-bottom:1px solid rgba(255,255,255,.1)">
    <h5 class="offcanvas-title fw-bold mb-0" style="font-size:1rem">
      <i class="bi bi-palette-fill me-2" style="color:#67e8f9"></i>ダッシュボード設定
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body px-3 py-2" style="overflow-y:auto">

    <!-- タブ選択 -->
    <ul class="nav nav-pills nav-fill gap-1 my-2" id="spTabPills">
      <li class="nav-item">
        <button class="nav-link active py-1 fw-bold" data-bs-toggle="pill"
                data-bs-target="#spView" style="font-size:.8rem;color:#e2e8f0">
          <i class="bi bi-eye"></i> 表示設定
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link py-1 fw-bold" data-bs-toggle="pill"
                data-bs-target="#spColor" style="font-size:.8rem;color:#e2e8f0">
          <i class="bi bi-palette"></i> カラー設定
        </button>
      </li>
    </ul>

    <div class="tab-content">

      <!-- ── 表示設定タブ ── -->
      <div class="tab-pane fade show active" id="spView">

        <div class="small fw-bold mt-2 mb-1" style="color:#67e8f9;letter-spacing:.08em;text-transform:uppercase">
          セクション表示 / 非表示
        </div>
        <div id="spSectionToggles">
          <!-- JS で生成 -->
        </div>
        <div class="d-flex gap-2 mt-2 mb-4">
          <button class="btn btn-sm flex-fill" onclick="spShowAll()"
                  style="background:rgba(103,232,249,.1);border:1px solid rgba(103,232,249,.3);color:#67e8f9;font-size:.8rem">
            <i class="bi bi-eye"></i> 全表示
          </button>
          <button class="btn btn-sm flex-fill" onclick="spHideAll()"
                  style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#94a3b8;font-size:.8rem">
            <i class="bi bi-eye-slash"></i> 全非表示
          </button>
        </div>

        <!-- 全体背景色 -->
        <div class="small fw-bold mb-2" style="color:#67e8f9;letter-spacing:.08em;text-transform:uppercase">
          全体背景カラー
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
          <?php foreach([
            '#070d1a'=>'ほぼ黒',
            '#0f172a'=>'ダーク',
            '#1e293b'=>'スレートグレー',
            '#1f2937'=>'グレー',
            '#0d1b2a'=>'ネイビー',
            '#0d2419'=>'フォレスト',
          ] as $hex=>$name): ?>
          <div class="sp-bg-swatch" data-color="<?= $hex ?>" title="<?= $name ?>"
               style="width:26px;height:26px;border-radius:50%;background:<?= $hex ?>;
                      border:2px solid rgba(255,255,255,.2);cursor:pointer;
                      transition:transform .15s,outline .1s;flex-shrink:0"></div>
          <?php endforeach; ?>
          <label title="カスタム背景色" style="cursor:pointer;line-height:1" id="spBgCustomLabel">
            <i class="bi bi-plus-circle" style="color:#94a3b8;font-size:1.1rem"></i>
            <input type="color" id="spBgCustom" value="#1e293b"
                   style="position:absolute;width:0;height:0;opacity:0;pointer-events:none">
          </label>
        </div>

      </div><!-- /spView -->

      <!-- ── カラー設定タブ ── -->
      <div class="tab-pane fade" id="spColor">

        <div class="small fw-bold mt-2 mb-2" style="color:#67e8f9;letter-spacing:.08em;text-transform:uppercase">
          セクション別アクセントカラー
        </div>
        <div id="spColorRows">
          <!-- JS で生成 -->
        </div>
        <button class="btn btn-sm w-100 mt-2"
                style="background:rgba(255,100,100,.08);border:1px solid rgba(255,100,100,.25);color:#fca5a5;font-size:.8rem"
                onclick="spResetColors()">
          <i class="bi bi-arrow-counterclockwise"></i> カラーをリセット
        </button>

      </div><!-- /spColor -->

    </div><!-- /tab-content -->

    <!-- 全設定リセット -->
    <div class="mt-4 pt-3" style="border-top:1px solid rgba(255,255,255,.08)">
      <button class="btn btn-sm w-100"
              style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#f87171;font-size:.8rem"
              onclick="spResetAll()">
        <i class="bi bi-arrow-counterclockwise"></i> 全設定をリセット
      </button>
    </div>

  </div><!-- /offcanvas-body -->
</div><!-- /dashSettingsPanel -->
<?php endif; ?>

<div class="exec-dark">

<?php if ($word): ?>
<div class="word-banner mb-3">
  <i class="bi bi-chat-quote-fill flex-shrink-0 mt-1" style="color:#1d4ed8"></i>
  <div class="word-banner-text" id="wb-exec"><?= h($word['message']) ?></div>
  <small class="word-banner-speaker"><?= h($word['speaker_name']) ?></small>
  <button class="word-expand-btn" onclick="toggleWord('wb-exec',this)">▼</button>
</div>
<?php endif; ?>

<!-- ヘッダーバー -->
<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <div>
    <div style="font-size:.58rem;letter-spacing:.2em;text-transform:uppercase;color:#64748b;font-weight:700">MANUFACTURING MANAGEMENT DASHBOARD</div>
    <h2 class="mb-0" style="color:#e2e8f0;font-weight:900;letter-spacing:-.02em">
      <i class="bi bi-speedometer2 neon-b me-1"></i>生産管理ダッシュボード
    </h2>
    <small style="color:#cbd5e1">
      <?= date('Y年n月j日（D）', strtotime($today)) ?>
      &nbsp;<span id="liveClock" class="fw-bold neon-b" style="font-size:.9rem"></span>
    </small>
  </div>
  <div class="ms-auto d-flex gap-1">
    <?php if (isPresidentOrAdmin()): ?>
    <button class="btn btn-sm" data-bs-toggle="offcanvas" data-bs-target="#dashSettingsPanel"
            style="background:rgba(103,232,249,.12);border:1px solid rgba(103,232,249,.35);color:#67e8f9"
            title="ダッシュボード設定">
      <i class="bi bi-palette-fill"></i><span class="d-none d-md-inline"> 編集</span>
    </button>
    <a href="admin_settings.php#cost" class="btn btn-sm"
       style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#e2e8f0"
       title="システム設定">
      <i class="bi bi-gear"></i>
    </a>
    <?php endif; ?>
    <button class="btn btn-sm" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#e2e8f0" onclick="location.reload()">
      <i class="bi bi-arrow-clockwise"></i>
    </button>
  </div>
</div>

<!-- ═══════ KPI カード 6枚 ═══════ -->
<div id="exSec-kpi" class="exec-section row g-2 mb-3">
  <div class="col-6 col-md-2">
    <div class="ex-kpi">
      <i class="bi bi-hourglass-split kpi-bg-icon"></i>
      <div class="kpi-label">仕掛中</div>
      <div class="kpi-value neon-b"><?= number_format($wipQty) ?></div>
      <div class="kpi-sub">本 · <?= $wipCount ?>件</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="ex-kpi">
      <i class="bi bi-check2-circle kpi-bg-icon"></i>
      <div class="kpi-label">今月完成</div>
      <div class="kpi-value neon-g"><?= number_format($completedQty) ?></div>
      <div class="kpi-sub">本 · <?= $completedCount ?>件</div>
    </div>
  </div>
  <?php $nAch = $achieveRate >= 80 ? 'neon-g' : ($achieveRate >= 50 ? 'neon-a' : 'neon-r'); ?>
  <div class="col-6 col-md-2">
    <div class="ex-kpi">
      <i class="bi bi-bar-chart-fill kpi-bg-icon"></i>
      <div class="kpi-label">月間達成率</div>
      <div class="kpi-value <?= $nAch ?>"><?= $achieveRate ?>%</div>
      <div class="kpi-sub"><?= number_format($completedQty) ?> / <?= number_format($targetQty) ?>本</div>
    </div>
  </div>
  <?php $nOtd = $otdRate === null ? 'neon-t' : ($otdRate >= 90 ? 'neon-g' : ($otdRate >= 70 ? 'neon-a' : 'neon-r')); ?>
  <div class="col-6 col-md-2">
    <div class="ex-kpi">
      <i class="bi bi-calendar-check kpi-bg-icon"></i>
      <div class="kpi-label">納期遵守率</div>
      <div class="kpi-value <?= $nOtd ?>"><?= $otdRate !== null ? $otdRate.'%' : '―' ?></div>
      <div class="kpi-sub">OTD（今月）</div>
    </div>
  </div>
  <?php $nDly = $delayedCount > 0 ? 'neon-r' : 'neon-g'; ?>
  <div class="col-6 col-md-2">
    <div class="ex-kpi">
      <i class="bi bi-exclamation-triangle kpi-bg-icon"></i>
      <div class="kpi-label">遅延工程</div>
      <div class="kpi-value <?= $nDly ?>"><?= $delayedCount ?></div>
      <div class="kpi-sub">緊急 <?= $criticalCount ?>件</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="ex-kpi">
      <i class="bi bi-currency-yen kpi-bg-icon"></i>
      <div class="kpi-label">1本コスト</div>
      <?php if (isPresidentOrAdmin()): ?>
        <div class="kpi-value neon-p" style="font-size:1.3rem"><?= $costPerUnit !== null ? '¥'.number_format($costPerUnit) : '―' ?></div>
        <div class="kpi-sub"><?= h($costMonth) ?>月分</div>
      <?php else: ?>
        <div class="kpi-value neon-p" style="font-size:1.3rem">*****</div>
        <div class="kpi-sub">管理者のみ</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════ 月間生産進捗バー ═══════ -->
<?php $completePct = $targetQty > 0 ? min(100, $completedQty / $targetQty * 100) : 0; ?>
<div id="exSec-progress" class="exec-section ex-card mb-3">
  <div class="ex-card-hd">
    <span><i class="bi bi-bar-chart-fill sec-icon me-1"></i>月間生産進捗 <span style="color:#38bdf8"><?= date('n月') ?></span></span>
    <span style="color:#cbd5e1;font-size:.75rem;font-weight:400;letter-spacing:0;text-transform:none">
      完成 <strong class="neon-g"><?= number_format($completedQty) ?></strong>本 ／ 目標 <strong style="color:#cbd5e1"><?= number_format($targetQty) ?></strong>本
      <?php if ($wipQty > 0): ?>&nbsp;＋仕掛 <strong style="color:#38bdf8"><?= number_format($wipQty) ?></strong>本<?php endif; ?>
      &nbsp;<strong class="<?= $achieveRate >= 80 ? 'neon-g' : ($achieveRate >= 50 ? 'neon-a' : 'neon-r') ?>"><?= $achieveRate ?>%</strong>
    </span>
  </div>
  <div class="ex-card-bd">
    <div class="ex-prog"><div class="ex-prog-g" style="width:<?= $completePct ?>%" title="完成: <?= $completedQty ?>本"></div></div>
    <?php if ($wipQty > 0 && $targetQty > 0): ?>
    <div style="font-size:.68rem;color:#1e3a5f;margin-top:.3rem">
      仕掛中 <?= number_format($wipQty) ?>本が進行中（目標の<?= min(100, round($wipQty / $targetQty * 100)) ?>%相当）
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($currentBudget): ?>
<div id="exSec-budget" class="exec-section">
<!-- ═══════ 予算対比 ═══════ -->
<?php
  $budgetAch = $currentBudget['target_qty'] > 0 ? round($currentBudget['actual_qty'] / $currentBudget['target_qty'] * 100, 1) : null;
  $ytdTarget = 0; $ytdActual = 0; $yearFrom = date('Y').'-01';
  foreach ($budgetComparison as $bc) {
      if ($bc['year_month'] >= $yearFrom && $bc['year_month'] <= $thisMonth) { $ytdTarget += $bc['target_qty']; $ytdActual += $bc['actual_qty']; }
  }
  $ytdAch  = $ytdTarget > 0 ? round($ytdActual / $ytdTarget * 100, 1) : null;
  $diffQty = (int)$currentBudget['actual_qty'] - (int)$currentBudget['target_qty'];
?>
<div class="ex-sec"><span>予算対比</span></div>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="ex-bkpi">
      <i class="bi bi-bullseye kpi-bg-icon"></i>
      <div class="kpi-label">当月予算目標</div>
      <div class="kpi-value neon-t"><?= number_format($currentBudget['target_qty']) ?>本</div>
      <div class="kpi-sub">¥<?= number_format((int)$currentBudget['total_budget']) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <?php $bn = $budgetAch === null ? 'neon-t' : ($budgetAch >= 100 ? 'neon-g' : ($budgetAch >= 80 ? 'neon-a' : 'neon-r')); ?>
    <div class="ex-bkpi">
      <i class="bi bi-percent kpi-bg-icon"></i>
      <div class="kpi-label">当月予算達成率</div>
      <div class="kpi-value <?= $bn ?>"><?= $budgetAch !== null ? $budgetAch.'%' : '―' ?></div>
      <div class="kpi-sub"><?= number_format((int)$currentBudget['actual_qty']) ?> / <?= number_format((int)$currentBudget['target_qty']) ?>本</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <?php $yn = $ytdAch === null ? 'neon-t' : ($ytdAch >= 100 ? 'neon-g' : ($ytdAch >= 80 ? 'neon-a' : 'neon-r')); ?>
    <div class="ex-bkpi">
      <i class="bi bi-graph-up kpi-bg-icon"></i>
      <div class="kpi-label">累計達成率（今年）</div>
      <div class="kpi-value <?= $yn ?>"><?= $ytdAch !== null ? $ytdAch.'%' : '―' ?></div>
      <div class="kpi-sub"><?= number_format($ytdActual) ?> / <?= number_format($ytdTarget) ?>本</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="ex-bkpi">
      <i class="bi bi-plus-slash-minus kpi-bg-icon"></i>
      <div class="kpi-label">当月 ±（予算差）</div>
      <div class="kpi-value <?= $diffQty >= 0 ? 'neon-g' : 'neon-r' ?>"><?= ($diffQty >= 0 ? '+' : '').number_format($diffQty) ?></div>
      <div class="kpi-sub">本 / 予算対比</div>
    </div>
  </div>
</div>
</div><!-- /exSec-budget -->
<?php endif; ?>

<!-- ═══════ 生産分析 ═══════ -->
<div class="ex-sec"><span>生産分析</span></div>
<div class="row g-3">

  <!-- 左: チャート 2×2 -->
  <div class="col-lg-8">
    <div class="row g-3">

    <?php if ($showWidget('daily_chart')): ?>
    <div id="exChart-daily" class="exec-section col-md-6">
      <div class="ex-card h-100">
        <div class="ex-card-hd">
          <span><i class="bi bi-bar-chart sec-icon me-1"></i>日別生産（<?= date('n月') ?>）</span>
          <span style="color:var(--sec-clr,#38bdf8);font-weight:400;text-transform:none;letter-spacing:0"><?= $completedQty ?>本</span>
        </div>
        <div class="ex-card-bd">
          <canvas id="dailyChart" height="110"></canvas>
          <?php if (empty($dailyProduction)): ?>
            <p class="text-center small mb-0" style="color:#1e3a5f">実績なし</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($showWidget('monthly_chart')): ?>
    <div id="exChart-monthly" class="exec-section col-md-6">
      <div class="ex-card h-100">
        <div class="ex-card-hd"><i class="bi bi-graph-up-arrow sec-icon me-1"></i>月別推移（過去6ヶ月）</div>
        <div class="ex-card-bd"><canvas id="monthlyChart" height="110"></canvas></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($showWidget('budget_chart') && !empty($budgetComparison)): ?>
    <div id="exChart-budget" class="exec-section col-md-6">
      <div class="ex-card h-100">
        <div class="ex-card-hd"><i class="bi bi-bar-chart-line sec-icon me-1"></i>予算対比（本数）</div>
        <div class="ex-card-bd"><canvas id="budgetChart" height="110"></canvas></div>
      </div>
    </div>
    <?php endif; ?>

    <div id="exChart-process" class="exec-section col-md-6">
      <div class="ex-card h-100">
        <div class="ex-card-hd">
          <span><i class="bi bi-pie-chart sec-icon me-1"></i>工程状況 / 達成率</span>
        </div>
        <div class="ex-card-bd">
          <div class="d-flex align-items-center gap-2">
            <!-- 工程遅延ドーナツ -->
            <div style="flex:0 0 42%;min-width:0;display:flex;align-items:center;justify-content:center;min-height:110px">
              <?php if (!empty($processStatusDist)): ?>
                <canvas id="processStatusChart" style="max-height:110px;max-width:110px"></canvas>
              <?php else: ?>
                <div class="text-center small" style="color:#059669"><i class="bi bi-check-circle-fill fs-3"></i><br>遅延なし</div>
              <?php endif; ?>
            </div>
            <!-- 達成率タコメーター -->
            <div style="flex:1;min-width:0" class="gauge-wrap">
              <canvas id="achieveGaugeChart" height="90"></canvas>
              <div class="gauge-overlay">
                <div class="gauge-pct <?= $achieveRate >= 80 ? 'neon-g' : ($achieveRate >= 50 ? 'neon-a' : 'neon-r') ?>"><?= $achieveRate ?>%</div>
                <div class="gauge-qty" style="color:#cbd5e1"><?= number_format($completedQty) ?><span style="font-size:.85em;opacity:.75"> / <?= number_format($targetQty) ?>本</span></div>
                <div class="gauge-lbl">月間達成率</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    </div><!-- /row charts -->
  </div>

  <!-- 右: アラート + 納期 -->
  <div class="col-lg-4">

    <!-- 遅延アラート -->
    <div id="exSec-alerts" class="exec-section ex-card mb-3">
      <div class="ex-card-hd">
        <span><i class="bi bi-exclamation-octagon-fill sec-icon me-1"></i>遅延アラート
          <?php if ($delayedCount > 0): ?><span class="badge ms-1" style="background:#ef4444"><?= $delayedCount ?></span><?php endif; ?>
        </span>
        <?php if ($delayedCount > 8): ?>
          <a href="progress_board.php?filter=delayed" style="color:#38bdf8;text-decoration:none;font-size:.72rem;font-weight:400;text-transform:none;letter-spacing:0">全件→</a>
        <?php endif; ?>
      </div>
      <?php if (empty($delayedList)): ?>
        <div class="ex-card-bd text-center" style="color:#059669;padding:1rem"><i class="bi bi-check-circle-fill fs-4"></i><br><small>遅延なし</small></div>
      <?php else: ?>
        <?php foreach ($delayedList as $d): $isCrit = $d['delay_status'] === 'critical'; ?>
          <a href="orders.php?id=<?= $d['order_id'] ?>" class="ex-al <?= $isCrit ? 'c' : 'd' ?>">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <span class="badge me-1" style="background:<?= $isCrit ? '#ef4444' : '#d97706' ?>;font-size:.62rem"><?= $isCrit ? '緊急' : '遅延' ?></span>
                <strong style="color:#e2e8f0"><?= h($d['order_no']) ?></strong>
                <span style="color:#cbd5e1"> — <?= h($d['process_name']) ?></span><br>
                <span style="color:#94a3b8;font-size:.77rem"><?= h($d['customer_name'] ?? $d['chair_type_name']) ?></span>
                <?php if ($d['due_date']): ?>
                  <span class="ms-2" style="font-size:.74rem;color:<?= strtotime($d['due_date']) < time() ? '#f87171' : '#94a3b8' ?>">納期:<?= formatDate($d['due_date']) ?></span>
                <?php endif; ?>
              </div>
              <span class="badge ms-1 text-nowrap" style="background:<?= $isCrit ? '#ef4444' : '#d97706' ?>;font-size:.65rem">+<?= formatMinutes((int)$d['delay_minutes']) ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- 納期7日以内 -->
    <div id="exSec-upcoming" class="exec-section ex-card">
      <div class="ex-card-hd">
        <span><i class="bi bi-calendar-event-fill sec-icon me-1"></i>納期まで7日以内
          <?php if (!empty($upcomingDue)): ?><span class="badge ms-1" style="background:rgba(251,191,36,.25);color:#fbbf24"><?= count($upcomingDue) ?></span><?php endif; ?>
        </span>
      </div>
      <?php if (empty($upcomingDue)): ?>
        <div class="ex-card-bd text-center" style="color:#94a3b8;font-size:.82rem;padding:.75rem"><i class="bi bi-calendar-check"></i> 7日以内に納期はありません</div>
      <?php else: ?>
        <?php foreach ($upcomingDue as $u):
          $daysLeft = (int)$u['days_left'];
          $dayBg = $daysLeft <= 0 ? '#ef4444' : ($daysLeft <= 2 ? '#d97706' : '#0e7490');
        ?>
          <a href="orders.php?id=<?= $u['id'] ?>" class="ex-al d" style="border-left:3px solid <?= $dayBg ?>">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <span class="badge me-1" style="background:<?= $dayBg ?>;font-size:.62rem">
                  <?= $daysLeft <= 0 ? '超過' : ($daysLeft === 0 ? '本日' : $daysLeft.'日後') ?>
                </span>
                <strong style="color:#e2e8f0"><?= h($u['order_no']) ?></strong>
                <span style="color:#cbd5e1;font-size:.8rem"> <?= h($u['quantity']) ?>本</span><br>
                <span style="color:#94a3b8;font-size:.77rem"><?= h($u['customer_name'] ?: $u['chair_type_name']) ?></span>
                <?php if ($u['project_name']): ?><span style="color:#94a3b8;font-size:.77rem"> / <?= h($u['project_name']) ?></span><?php endif; ?>
              </div>
              <?= orderStatusBadge($u['status']) ?>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /row 生産分析 -->

<!-- ═══════ 部門稼働状況 ═══════ -->
<div class="ex-sec mt-2"><span>部門稼働状況 — 本日</span></div>
<div class="row g-3">

  <div id="exSec-dept" class="exec-section col-md-<?= (isPresidentOrAdmin() && ($salaryTotal > 0 || $overheadCost > 0)) ? '8' : '12' ?>">
    <div class="ex-card">
      <div class="ex-card-hd">
        <span><i class="bi bi-people-fill sec-icon me-1"></i>部門別稼働状況</span>
        <span style="color:#cbd5e1;font-weight:400;text-transform:none;letter-spacing:0">
          稼働 <strong style="color:#4ade80"><?= $activeWorkers ?></strong>名 / 在籍 <?= $totalEmployees ?>名
        </span>
      </div>
      <div class="ex-card-bd">
        <div class="row g-2">
        <?php
          $deptBgColors = ['#1a56db','#0f9060','#6d28d9','#0e7490','#b45309','#c81e1e'];
          $dci = 0;
          foreach ($deptStatus as $ds):
            $rate    = $ds['emp_cnt'] > 0 ? round($ds['working_cnt'] / $ds['emp_cnt'] * 100) : 0;
            $bgColor = $deptBgColors[$dci % count($deptBgColors)]; $dci++;
        ?>
          <div class="col-6 col-lg-4">
            <div class="ex-dept">
              <div class="ex-dept-hd" style="background:linear-gradient(135deg,<?= $bgColor ?>,<?= $bgColor ?>bb)">
                <div class="d-flex justify-content-between align-items-start">
                  <span class="text-truncate" style="max-width:100px"><?= h($ds['dept_name']) ?></span>
                  <span style="font-size:.73rem;opacity:.85"><?= $rate ?>%</span>
                </div>
                <div class="ex-dept-bar"><div class="ex-dept-fill" style="width:<?= $rate ?>%"></div></div>
              </div>
              <div class="ex-dept-bd">
                <div class="row g-0 text-center">
                  <div class="col-4">
                    <div class="ex-dept-stat-val neon-g"><?= $ds['working_cnt'] ?></div>
                    <div class="ex-dept-stat-lbl">稼働中</div>
                  </div>
                  <div class="col-4" style="border-left:1px solid rgba(255,255,255,.06);border-right:1px solid rgba(255,255,255,.06)">
                    <div class="ex-dept-stat-val" style="color:#e2e8f0"><?= $ds['emp_cnt'] ?></div>
                    <div class="ex-dept-stat-lbl">在籍</div>
                  </div>
                  <div class="col-4">
                    <div class="ex-dept-stat-val neon-b"><?= $ds['today_hours'] ?></div>
                    <div class="ex-dept-stat-lbl">今日h</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($deptStatus)): ?>
          <div class="col-12 text-center py-2" style="color:#94a3b8;font-size:.85rem">データなし</div>
        <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (isPresidentOrAdmin() && ($salaryTotal > 0 || $overheadCost > 0)): ?>
  <div id="exSec-cost" class="exec-section col-md-4">
    <div class="ex-card h-100">
      <div class="ex-card-hd">
        <span><i class="bi bi-currency-yen sec-icon me-1"></i>コスト管理 <span style="color:#cbd5e1;font-weight:400;text-transform:none;letter-spacing:0"><?= h($costMonth) ?></span></span>
        <a href="admin_settings.php#cost" style="color:#38bdf8;text-decoration:none;font-size:.72rem;font-weight:400;text-transform:none;letter-spacing:0"><i class="bi bi-pencil"></i></a>
      </div>
      <div class="ex-card-bd">
        <div class="row g-2 text-center mb-2">
          <div class="col-4">
            <div class="ex-cost-val neon-p"><?= $costPerUnit !== null ? '¥'.number_format($costPerUnit) : '―' ?></div>
            <div class="ex-cost-lbl">1本コスト</div>
          </div>
          <div class="col-4" style="border-left:1px solid rgba(255,255,255,.06);border-right:1px solid rgba(255,255,255,.06)">
            <div class="ex-cost-val neon-g"><?= $completedQty > 0 ? '¥'.number_format((int)($salaryTotal/$completedQty)) : '―' ?></div>
            <div class="ex-cost-lbl">給与費/本</div>
          </div>
          <div class="col-4">
            <div class="ex-cost-val neon-t"><?= $completedQty > 0 ? '¥'.number_format((int)($overheadCost/$completedQty)) : '―' ?></div>
            <div class="ex-cost-lbl">管理費/本</div>
          </div>
        </div>
        <div style="font-size:.7rem;color:#94a3b8;display:flex;justify-content:space-between;flex-wrap:wrap;gap:.2rem;margin-top:.5rem;padding-top:.5rem;border-top:1px solid rgba(255,255,255,.08)">
          <span>給与総額 <strong style="color:#e2e8f0">¥<?= number_format($salaryTotal) ?></strong></span>
          <span>管理費 <strong style="color:#e2e8f0">¥<?= number_format($overheadCost) ?></strong></span>
          <span>1人あたり <strong style="color:#e2e8f0"><?= $perPersonQty ?>本</strong></span>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /row 部門稼働 -->

<!-- ═══════ ガントチャート ═══════ -->
<?php if ($showWidget('gantt')): ?>
<div id="exSec-gantt" class="exec-section">
<div class="ex-sec mt-2"><span>製造スケジュール</span></div>
<div class="ex-card">
  <div class="ex-card-hd">
    <span><i class="bi bi-bar-chart-steps sec-icon me-1"></i>製造スケジュール</span>
    <div class="d-flex gap-1 align-items-center">
      <div class="btn-group btn-group-sm">
        <?php foreach (['tomorrow'=>'明日','week'=>'今週','month'=>'今月'] as $k=>$l): ?>
        <a href="?gantt_period=<?= $k ?>" class="btn btn-sm"
           style="<?= $ganttPeriod === $k ? 'background:#1d4ed8;border-color:#3b82f6;color:#fff' : 'background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);color:#cbd5e1' ?>">
          <?= $l ?>
        </a>
        <?php endforeach; ?>
      </div>
      <a href="gantt.php?date_from=<?= $ganttFrom ?>&date_to=<?= $ganttTo ?>"
         class="btn btn-sm" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#cbd5e1">
        <i class="bi bi-arrows-fullscreen"></i>
      </a>
    </div>
  </div>
  <iframe src="gantt.php?date_from=<?= $ganttFrom ?>&date_to=<?= $ganttTo ?>&embed=1"
          class="w-100 border-0" style="height:280px" title="ガントチャート"></iframe>
</div>
</div><!-- /exSec-gantt -->
<?php endif; ?>

</div><!-- /exec-dark -->

</div><!-- /execTabPane -->

<!-- ===== 部門ダッシュボードタブ ===== -->
<div class="tab-pane fade" id="deptTabPane" role="tabpanel">

<?php if ($word): ?>
<div class="word-banner">
  <i class="bi bi-chat-quote-fill flex-shrink-0 mt-1" style="color:#1d4ed8"></i>
  <div class="word-banner-text" id="wb-dept"><?= h($word['message']) ?></div>
  <small class="word-banner-speaker"><?= h($word['speaker_name']) ?></small>
  <button class="word-expand-btn" onclick="toggleWord('wb-dept',this)">▼</button>
</div>
<?php endif; ?>

<!-- ヘッダー -->
<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <div>
    <h2 class="mb-0"><i class="bi bi-people-fill"></i> 部門ダッシュボード</h2>
    <small class="text-muted"><?= date('Y年n月j日（D）', strtotime($today)) ?></small>
  </div>
  <div class="ms-auto d-flex gap-2 align-items-center">
    <input type="search" id="empSearch" class="form-control form-control-sm" placeholder="氏名検索" style="width:110px">
    <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
      <i class="bi bi-arrow-clockwise"></i>
    </button>
  </div>
</div>

<!-- 全社サマリーKPI（4枚） -->
<?php
  $totalActive  = count(array_filter($allEmpData, fn($e) => isset($activeWorkAll[$e['emp_id']])));
  $totalEmpCnt  = count($allEmpData);
  $totalTodayH  = round(array_sum(array_column($allEmpData, 'today_hours')), 1);
  $totalMonthH  = round(array_sum(array_column($allEmpData, 'month_hours')), 1);
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-sm-3">
    <div class="kpi-card kpi-blue">
      <i class="bi bi-people kpi-bg-icon"></i>
      <div class="kpi-label">在籍</div>
      <div class="kpi-value"><?= $totalEmpCnt ?></div>
      <div class="kpi-sub">名</div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="kpi-card kpi-green">
      <i class="bi bi-person-check kpi-bg-icon"></i>
      <div class="kpi-label">作業中</div>
      <div class="kpi-value"><?= $totalActive ?></div>
      <div class="kpi-sub">名 / 今日</div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="kpi-card kpi-teal">
      <i class="bi bi-clock kpi-bg-icon"></i>
      <div class="kpi-label">今日 作業h</div>
      <div class="kpi-value"><?= $totalTodayH ?></div>
      <div class="kpi-sub">全社合計</div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="kpi-card kpi-purple">
      <i class="bi bi-calendar3 kpi-bg-icon"></i>
      <div class="kpi-label">月間 作業h</div>
      <div class="kpi-value"><?= $totalMonthH ?></div>
      <div class="kpi-sub">全社合計</div>
    </div>
  </div>
</div>

<!-- 部門カード -->
<?php
  $deptPalette = [
      ['#1a56db','#3b82f6'], ['#0f9060','#10b981'], ['#6d28d9','#a78bfa'],
      ['#0e7490','#22d3ee'], ['#b45309','#f59e0b'], ['#c81e1e','#ef4444'],
  ];
  $dpi = 0;
?>
<div class="row g-3 mb-4">
<?php foreach ($deptCards as $dept): ?>
  <?php
    $util = $dept['emp_cnt'] > 0
        ? round($dept['active_now'] / $dept['emp_cnt'] * 100)
        : 0;
    [$dc1,$dc2] = $deptPalette[$dpi % count($deptPalette)]; $dpi++;
  ?>
  <div class="col-12 col-md-6 col-xl-4 dept-card-col">
    <div class="dept-card h-100">
      <!-- header gradient -->
      <div class="dept-header" style="background:linear-gradient(135deg,<?= $dc1 ?>,<?= $dc2 ?>)">
        <div class="d-flex justify-content-between align-items-center">
          <span><i class="bi bi-building me-1"></i><?= h($dept['dept_name']) ?></span>
          <span style="font-size:.75rem;opacity:.9"><?= $util ?>% 稼働</span>
        </div>
        <div class="dept-util-bar mt-2">
          <div class="dept-util-fill" style="width:<?= $util ?>%"></div>
        </div>
      </div>
      <!-- KPI行 -->
      <div class="p-2" style="background:white;border-bottom:1px solid #f3f4f6">
        <div class="row g-0 text-center">
          <div class="col-3">
            <div class="dept-stat-val text-dark"><?= $dept['emp_cnt'] ?></div>
            <div class="dept-stat-lbl text-muted">在籍</div>
          </div>
          <div class="col-3 border-start">
            <div class="dept-stat-val text-success"><?= $dept['active_now'] ?></div>
            <div class="dept-stat-lbl text-muted">作業中</div>
          </div>
          <div class="col-3 border-start">
            <div class="dept-stat-val text-primary"><?= $dept['today_hours'] ?></div>
            <div class="dept-stat-lbl text-muted">今日h</div>
          </div>
          <div class="col-3 border-start">
            <div class="dept-stat-val" style="color:var(--c-purple)"><?= $dept['month_hours'] ?></div>
            <div class="dept-stat-lbl text-muted">月間h</div>
          </div>
        </div>
      </div>
      <!-- 社員タイルグリッド -->
      <?php
        $deptEmps = array_filter($allEmpData, fn($e) => $e['dept_id'] == $dept['dept_id']);
      ?>
      <?php if (count($deptEmps)): ?>
      <div class="p-2" style="background:#f9fafb">
        <div class="row g-1">
        <?php foreach ($deptEmps as $emp):
          $aw = $activeWorkAll[$emp['emp_id']] ?? null;
          $tileClass = $aw ? 'tile-active' : ($emp['worked_today'] ? 'tile-break' : 'tile-idle');
        ?>
          <div class="col-12">
            <div class="worker-tile <?= $tileClass ?>">
              <div class="tile-hd">
                <?php if ($aw): ?><span class="pulse-dot me-1"></span><?php endif; ?>
                <span class="tile-name"><?= h($emp['name']) ?></span>
                <?php if ($aw): ?>
                  <span class="ms-auto" style="font-size:.7rem;opacity:.9"><?= h($aw['process_name']) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($aw): ?>
              <div class="tile-bd">
                <div class="d-flex justify-content-between">
                  <span class="tile-proc"><?= h($aw['order_no']) ?></span>
                  <span class="tile-stats"><?= $aw['elapsed_min'] ?>分経過</span>
                </div>
                <?php if ((int)($aw['planned_total_minutes'] ?? 0) > 0):
                  $pp = min(100, round($aw['elapsed_min'] / $aw['planned_total_minutes'] * 100));
                ?>
                <div class="tile-prog">
                  <div class="tile-prog-fill" style="width:<?= $pp ?>%;background:<?= $pp > 100 ? '#ef4444' : '#059669' ?>"></div>
                </div>
                <?php endif; ?>
                <div class="tile-stats mt-1">今日 <?= $emp['today_hours'] ?>h &nbsp;月間 <?= $emp['month_hours'] ?>h</div>
              </div>
              <?php else: ?>
              <div class="tile-bd">
                <div class="tile-stats">今日 <?= $emp['today_hours'] ?>h &nbsp;月間 <?= $emp['month_hours'] ?>h</div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div><!-- /dept-card -->
  </div><!-- /col -->
<?php endforeach; ?>
<?php if (empty($deptCards)): ?>
  <div class="col-12">
    <div class="alert alert-info"><i class="bi bi-info-circle"></i> 部門データがありません。</div>
  </div>
<?php endif; ?>
</div><!-- /row deptCards -->

<?php $_ = [$w_monthSummary, $w_todayTotalMinutes, $w_todayPlannedTotal, $w_activeWork, $w_todayLogs, $w_deptQueue, $w_skills]; ?>

</div><!-- /deptTabPane -->

<!-- ===== 現場モニタータブ ===== -->
<div class="tab-pane fade" id="monitorTabPane" role="tabpanel">

<?php if ($word): ?>
<div class="word-banner mb-2">
  <i class="bi bi-chat-quote-fill flex-shrink-0 mt-1" style="color:#1d4ed8"></i>
  <div class="word-banner-text" id="wb-monitor"><?= h($word['message']) ?></div>
  <small class="word-banner-speaker"><?= h($word['speaker_name']) ?></small>
  <button class="word-expand-btn" onclick="toggleWord('wb-monitor',this)">▼</button>
</div>
<?php endif; ?>

<!-- 現場モニター ヘッダー -->
<div class="d-flex align-items-center mb-3 gap-2">
  <div>
    <h2 class="mb-0 text-dark"><i class="bi bi-display"></i> 現場モニター</h2>
    <small class="text-muted"><?= date('Y年n月j日（D）', strtotime($today)) ?>
      &nbsp;<span id="monitorClock" class="fw-bold"></span>
    </small>
  </div>
  <div class="ms-auto d-flex gap-1">
    <button class="btn btn-outline-secondary btn-sm" id="monitorFullscreenBtn" title="全画面表示">
      <i class="bi bi-fullscreen"></i>
    </button>
    <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
      <i class="bi bi-arrow-clockwise"></i>
    </button>
  </div>
</div>

<!-- ===== 大型KPIカード ===== -->
<div class="monitor-bg mb-3">
  <div class="row g-3 text-center">

    <!-- 今日の目標 -->
    <div class="col-6 col-md-3 border-end border-white border-opacity-10">
      <div class="monitor-kpi-lbl text-info">今日の目標</div>
      <div class="monitor-kpi-num text-info">
        <?= $todayTarget > 0 ? number_format($todayTarget) : '―' ?>
      </div>
      <div class="monitor-kpi-lbl" style="opacity:.6">本</div>
    </div>

    <!-- 仕掛中 -->
    <div class="col-6 col-md-3 border-end border-white border-opacity-10">
      <div class="monitor-kpi-lbl text-warning">仕掛中</div>
      <div class="monitor-kpi-num text-warning"><?= number_format($wipQty) ?></div>
      <div class="monitor-kpi-lbl" style="opacity:.6">本 (<?= $wipCount ?>件)</div>
    </div>

    <!-- 今日完成 -->
    <div class="col-6 col-md-3 border-end border-white border-opacity-10">
      <?php $todayAch = ($todayTarget > 0) ? min(100, round($todayCompleted / $todayTarget * 100)) : null; ?>
      <div class="monitor-kpi-lbl text-success">今日完成</div>
      <div class="monitor-kpi-num text-success"><?= number_format($todayCompleted) ?></div>
      <div class="monitor-kpi-lbl" style="opacity:.6">本
        <?= $todayAch !== null ? "/ 目標比 {$todayAch}%" : '' ?>
      </div>
    </div>

    <!-- 遅延 -->
    <div class="col-6 col-md-3">
      <div class="monitor-kpi-lbl <?= $delayedCount > 0 ? 'text-danger' : 'text-success' ?>">遅延工程</div>
      <div class="monitor-kpi-num <?= $delayedCount > 0 ? 'text-danger' : 'text-success' ?>">
        <?= $delayedCount ?>
      </div>
      <div class="monitor-kpi-lbl" style="opacity:.6">
        <?= $criticalCount > 0 ? "うち緊急 {$criticalCount}件" : ($delayedCount === 0 ? '全工程正常' : '遅延あり') ?>
      </div>
    </div>

  </div>

  <!-- 今日の進捗バー -->
  <?php if ($todayTarget > 0): ?>
  <div class="mt-3">
    <?php $todayPct = min(100, round($todayCompleted / $todayTarget * 100)); ?>
    <div class="d-flex justify-content-between text-white small mb-1" style="opacity:.7">
      <span>今日の進捗</span>
      <span><?= $todayCompleted ?> / <?= $todayTarget ?>本 — <?= $todayPct ?>%</span>
    </div>
    <div class="monitor-progress-wrap">
      <div class="monitor-progress-fill"
           style="width:<?= $todayPct ?>%;background:<?= $todayPct >= 100 ? '#10b981' : ($todayPct >= 60 ? '#f59e0b' : '#3b82f6') ?>">
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ===== 現在作業中一覧 ===== -->
<div class="row g-3">
<div class="col-lg-7">
  <div class="card" style="border:none;box-shadow:var(--shadow)">
    <div class="card-header fw-bold py-2 d-flex justify-content-between align-items-center"
         style="background:#0f172a;color:#e2e8f0;border:none">
      <span><i class="bi bi-play-circle-fill text-success me-1"></i>
        現在作業中 <span class="badge bg-success ms-1"><?= count($activeProcesses) ?>名</span>
      </span>
      <small style="opacity:.6"><?= date('H:i') ?> 更新</small>
    </div>
    <div class="card-body p-0" style="background:#0f172a">
      <?php if (empty($activeProcesses)): ?>
        <div class="text-center py-4" style="color:#94a3b8">
          <i class="bi bi-moon-stars fs-3"></i><br>
          <small>現在作業中の工員はいません</small>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table monitor-table mb-0">
          <thead>
            <tr>
              <th>氏名</th><th>部門</th><th>工程</th><th>指示No.</th>
              <th class="text-end">経過</th><th class="text-center">状態</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($activeProcesses as $ap):
            $rowClass = match($ap['delay_status'] ?? 'normal') {
                'critical' => 'process-row-critical',
                'delayed'  => 'process-row-delayed',
                default    => 'process-row-normal',
            };
            $elapsed_h = floor($ap['elapsed_min'] / 60);
            $elapsed_m = $ap['elapsed_min'] % 60;
          ?>
            <tr class="<?= $rowClass ?>">
              <td class="fw-bold"><?= h($ap['worker_name']) ?></td>
              <td style="opacity:.75"><?= h($ap['dept_name'] ?? '―') ?></td>
              <td><span class="badge bg-success bg-opacity-25 text-success"><?= h($ap['process_name']) ?></span></td>
              <td style="opacity:.75"><?= h($ap['order_no']) ?></td>
              <td class="text-end fw-bold">
                <?= $elapsed_h ?>h<?= str_pad($elapsed_m, 2, '0', STR_PAD_LEFT) ?>m
              </td>
              <td class="text-center">
                <?php if (($ap['delay_status'] ?? '') === 'critical'): ?>
                  <span class="badge bg-danger">緊急遅延</span>
                <?php elseif (($ap['delay_status'] ?? '') === 'delayed'): ?>
                  <span class="badge bg-warning text-dark">遅延</span>
                <?php else: ?>
                  <span class="badge" style="background:rgba(16,185,129,.25);color:#10b981">正常</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- 遅延アラート（現場モニター版） -->
<div class="col-lg-5">
  <div class="card" style="border:none;box-shadow:var(--shadow)">
    <div class="card-header fw-bold py-2" style="background:#1a0a0a;color:#f87171;border:none">
      <i class="bi bi-exclamation-octagon-fill me-1"></i>
      遅延アラート
      <?php if ($delayedCount > 0): ?>
        <span class="badge bg-danger ms-1"><?= $delayedCount ?></span>
      <?php endif; ?>
    </div>
    <div class="card-body p-0" style="background:#0f172a;max-height:320px;overflow-y:auto">
      <?php if (empty($delayedList)): ?>
        <div class="text-center py-4" style="color:#22c55e">
          <i class="bi bi-check-circle-fill fs-3"></i><br>
          <small style="color:#94a3b8">遅延なし — 全工程正常</small>
        </div>
      <?php else: ?>
        <?php foreach ($delayedList as $d):
          $isCrit = $d['delay_status'] === 'critical';
        ?>
          <div class="px-3 py-2 border-bottom <?= $isCrit ? 'process-row-critical' : 'process-row-delayed' ?>"
               style="border-color:rgba(255,255,255,.06)!important">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <span class="badge bg-<?= $isCrit ? 'danger' : 'warning text-dark' ?> me-1">
                  <?= $isCrit ? '緊急' : '遅延' ?>
                </span>
                <strong style="color:#e2e8f0;font-size:.88rem"><?= h($d['order_no']) ?></strong>
                <span style="color:#67e8f9;font-size:.8rem"> — <?= h($d['process_name']) ?></span><br>
                <small style="color:#94a3b8"><?= h($d['customer_name'] ?? $d['chair_type_name']) ?>
                  <?php if ($d['due_date']): ?>
                    &nbsp;|&nbsp; 納期: <span class="<?= strtotime($d['due_date']) < time() ? 'text-danger' : '' ?>"><?= formatDate($d['due_date']) ?></span>
                  <?php endif; ?>
                </small>
              </div>
              <span class="badge bg-<?= $isCrit ? 'danger' : 'warning text-dark' ?> ms-1 text-nowrap">
                +<?= formatMinutes((int)$d['delay_minutes']) ?>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- 部門別遅延サマリー -->
  <?php
    $deptDelayMap = [];
    foreach ($delayedList as $dl) {
        // order_no から部門は取得困難なのでシンプルに件数のみ
    }
    // 部門別稼働 + 遅延中工程
    $deptDelayData = dbFetchAll(
        "SELECT d.dept_name,
                COUNT(DISTINCT CASE WHEN mop.delay_status IN ('delayed','critical') THEN mop.id END) AS delay_cnt,
                COUNT(DISTINCT CASE WHEN mop.delay_status='critical' THEN mop.id END) AS critical_cnt
         FROM departments d
         LEFT JOIN employees e ON e.department_id=d.id AND e.is_active=1
         LEFT JOIN work_logs wl ON wl.employee_id=e.id AND wl.ended_at IS NULL
         LEFT JOIN manufacturing_order_processes mop
             ON mop.manufacturing_order_id=wl.manufacturing_order_id AND mop.process_id=wl.process_id
         GROUP BY d.id, d.dept_name ORDER BY d.display_order"
    ) ?: [];
  ?>
  <?php if (!empty($deptDelayData)): ?>
  <div class="card mt-2" style="border:none;box-shadow:var(--shadow)">
    <div class="card-header py-2 fw-bold small" style="background:#1e293b;color:#67e8f9;border:none">
      <i class="bi bi-building"></i> 部門別 遅延状況
    </div>
    <div class="card-body p-0" style="background:#0f172a">
      <?php foreach ($deptDelayData as $dd): ?>
        <div class="d-flex align-items-center justify-content-between px-3 py-2"
             style="border-bottom:1px solid rgba(255,255,255,.06);color:#e2e8f0">
          <span style="font-size:.85rem"><?= h($dd['dept_name']) ?></span>
          <span>
            <?php if ((int)$dd['critical_cnt'] > 0): ?>
              <span class="badge bg-danger"><?= $dd['critical_cnt'] ?>件 緊急</span>
            <?php endif; ?>
            <?php if ((int)$dd['delay_cnt'] > (int)$dd['critical_cnt']): ?>
              <span class="badge bg-warning text-dark ms-1"><?= (int)$dd['delay_cnt'] - (int)$dd['critical_cnt'] ?>件 遅延</span>
            <?php endif; ?>
            <?php if ((int)$dd['delay_cnt'] === 0): ?>
              <span style="color:#22c55e;font-size:.8rem"><i class="bi bi-check-circle"></i> 正常</span>
            <?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
</div><!-- /row -->

</div><!-- /monitorTabPane -->
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

// チャート共通ダーク設定
const _dk = {
    grid:  { color:'rgba(255,255,255,.09)', drawBorder:false },
    ticks: { color:'#64748b', font:{size:10} }
};

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
                label:'完成本数', data,
                backgroundColor:'rgba(56,189,248,.55)',
                borderColor:'#38bdf8', borderWidth:1, borderRadius:4,
            }]
        },
        options:{
            responsive:true,
            plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.parsed.y+'本'}}},
            scales:{
                y:{beginAtZero:true, grid:_dk.grid, ticks:{..._dk.ticks,stepSize:1}},
                x:{grid:{color:'transparent'}, ticks:{..._dk.ticks,maxRotation:0}}
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
                label:'完成本数', data,
                borderColor:'#2dd4bf',
                backgroundColor:'rgba(45,212,191,.12)',
                tension:.35, fill:true, pointRadius:4,
                pointBackgroundColor:'#2dd4bf', pointHoverRadius:6,
            }]
        },
        options:{
            responsive:true,
            plugins:{legend:{display:false}},
            scales:{
                y:{beginAtZero:true, grid:_dk.grid, ticks:{..._dk.ticks,stepSize:5}},
                x:{grid:{color:'transparent'}, ticks:_dk.ticks}
            }
        }
    });
})();

// 社長の言葉バナー 展開/折りたたみ
function toggleWord(id, btn){
    const el = document.getElementById(id);
    if(!el) return;
    const expanded = el.classList.toggle('expanded');
    btn.textContent = expanded ? '▲' : '▼';
}

// 部門ダッシュボード: 氏名検索
(function(){
    const inp = document.getElementById('empSearch');
    if(!inp) return;
    inp.addEventListener('input', function(){
        const q = this.value.toLowerCase();
        document.querySelectorAll('.dept-card-col').forEach(function(col){
            if(!q){ col.style.display = ''; return; }
            const tiles = col.querySelectorAll('.worker-tile');
            let vis = 0;
            tiles.forEach(function(tile){
                const nm = (tile.querySelector('.tile-name') || tile).textContent.toLowerCase();
                const show = nm.includes(q);
                tile.style.display = show ? '' : 'none';
                if(show) vis++;
            });
            col.style.display = vis > 0 ? '' : 'none';
        });
    });
})();

// 工程ステータス ドーナツチャート
(function(){
    const raw = $jsProcessStatus;
    const ctx = document.getElementById('processStatusChart');
    if(!ctx || !raw.length) return;
    const colorMap = {critical:'#ef4444',delayed:'#f59e0b',warning:'#3b82f6',normal:'#10b981'};
    const labelMap = {critical:'緊急遅延',delayed:'遅延',warning:'注意',normal:'正常'};
    new Chart(ctx, {
        type:'doughnut',
        data:{
            labels: raw.map(r => labelMap[r.delay_status] || r.delay_status),
            datasets:[{
                data: raw.map(r => parseInt(r.cnt)),
                backgroundColor: raw.map(r => colorMap[r.delay_status] || '#6b7280'),
                borderWidth: 2,
                borderColor: 'rgba(0,0,0,.3)'
            }]
        },
        options:{
            responsive:true,
            cutout:'65%',
            plugins:{
                legend:{position:'bottom', labels:{color:'#94a3b8',font:{size:10},boxWidth:10,padding:6}},
                tooltip:{callbacks:{label:c=>c.label+': '+c.parsed+'件'}}
            }
        }
    });
})();

// 達成率タコメーター（半円ゲージ）
(function(){
    const ctx = document.getElementById('achieveGaugeChart');
    if(!ctx) return;
    const rate = Math.min(100, Math.max(0, $achieveRate));
    const color = rate >= 80 ? '#4ade80' : (rate >= 50 ? '#fbbf24' : '#f87171');
    const glow  = rate >= 80 ? 'rgba(74,222,128,.4)' : (rate >= 50 ? 'rgba(251,191,36,.4)' : 'rgba(248,113,113,.4)');

    new Chart(ctx, {
        type: 'doughnut',
        data:{
            datasets:[{
                data: [rate, 100 - rate],
                backgroundColor: [color, 'rgba(255,255,255,.07)'],
                borderWidth: 0,
                circumference: 180,
                rotation: -90,
            }]
        },
        options:{
            responsive: true,
            cutout: '72%',
            animation: { animateRotate: true, duration: 1200, easing: 'easeOutQuart' },
            plugins:{
                legend:  { display: false },
                tooltip: { enabled: false }
            }
        },
        plugins:[{
            id:'gaugeGlow',
            afterDraw(chart){
                const {ctx:c, chartArea:{width,height,top,left}} = chart;
                const cx = left + width/2, cy = top + height;
                c.save();
                c.shadowColor = glow;
                c.shadowBlur  = 18;
                c.restore();
            }
        }]
    });
})();

// 予算対比チャート
(function(){
    const raw = $jsBudget;
    const ctx = document.getElementById('budgetChart');
    if(!ctx || !raw.length) return;
    new Chart(ctx, {
        type:'bar',
        data:{
            labels: raw.map(r=>r.year_month.slice(5)+'月'),
            datasets:[
                {label:'目標', data:raw.map(r=>parseInt(r.target_qty)), backgroundColor:'rgba(56,189,248,.35)', borderColor:'#38bdf8', borderWidth:1, borderRadius:3},
                {label:'実績', data:raw.map(r=>parseInt(r.actual_qty)), backgroundColor:'rgba(74,222,128,.45)', borderColor:'#4ade80', borderWidth:1, borderRadius:3}
            ]
        },
        options:{
            responsive:true,
            plugins:{
                legend:{position:'bottom',labels:{color:'#94a3b8',font:{size:10},boxWidth:10}},
                tooltip:{callbacks:{label:c=>c.dataset.label+': '+c.parsed.y+'本'}}
            },
            scales:{
                y:{beginAtZero:true, grid:_dk.grid, ticks:_dk.ticks},
                x:{grid:{color:'transparent'}, ticks:_dk.ticks}
            }
        }
    });
})();

// 現場モニタークロック
(function tick(){
    const el = document.getElementById('monitorClock');
    if(el){ const n=new Date(); el.textContent=n.getHours().toString().padStart(2,'0')+':'+n.getMinutes().toString().padStart(2,'0')+':'+n.getSeconds().toString().padStart(2,'0'); }
    setTimeout(tick, 1000);
})();

// 現場モニター全画面
(function(){
    const btn = document.getElementById('monitorFullscreenBtn');
    if(!btn) return;
    btn.addEventListener('click', function(){
        const pane = document.getElementById('monitorTabPane');
        if(!document.fullscreenElement){
            (pane || document.documentElement).requestFullscreen().catch(()=>{});
            btn.innerHTML='<i class="bi bi-fullscreen-exit"></i>';
        } else {
            document.exitFullscreen();
            btn.innerHTML='<i class="bi bi-fullscreen"></i>';
        }
    });
    document.addEventListener('fullscreenchange', function(){
        if(!document.fullscreenElement) btn.innerHTML='<i class="bi bi-fullscreen"></i>';
    });
})();

// 現場モニター自動リロード（5分おき）
(function(){
    const pane = document.getElementById('monitorTabPane');
    if(!pane) return;
    let timer;
    function startAuto(){
        timer = setTimeout(function(){ location.reload(); }, 5 * 60 * 1000);
    }
    function stopAuto(){ clearTimeout(timer); }
    document.querySelectorAll('#dashTabs .nav-link').forEach(function(btn){
        btn.addEventListener('shown.bs.tab', function(e){
            if(e.target.id === 'monitorTab-btn') startAuto();
            else stopAuto();
        });
    });
})();

// ─────────────────────────────────────────────
// ダッシュボード設定パネル
// ─────────────────────────────────────────────
(function(){
    if(!document.getElementById('dashSettingsPanel')) return;

    const STORE_KEY = 'execDash_v2';
    const PALETTE   = [
        '#67e8f9','#38bdf8','#818cf8','#a78bfa','#f472b6',
        '#f87171','#fb923c','#fbbf24','#a3e635','#4ade80','#2dd4bf','#ffffff'
    ];

    // 設定可能なセクション一覧（DOMに存在するものだけ使用）
    const SECTION_DEFS = [
        {id:'exSec-kpi',       label:'KPI カード',       icon:'bi-grid-1x2-fill', defColor:'#67e8f9'},
        {id:'exSec-progress',  label:'月間生産進捗',      icon:'bi-bar-chart-fill',defColor:'#38bdf8'},
        {id:'exSec-budget',    label:'予算対比',          icon:'bi-bullseye',      defColor:'#2dd4bf'},
        {id:'exChart-daily',   label:'日別生産チャート',  icon:'bi-bar-chart',     defColor:'#38bdf8'},
        {id:'exChart-monthly', label:'月別推移チャート',  icon:'bi-graph-up-arrow',defColor:'#2dd4bf'},
        {id:'exChart-budget',  label:'予算対比チャート',  icon:'bi-bar-chart-line',defColor:'#fbbf24'},
        {id:'exChart-process', label:'工程状況/達成率',   icon:'bi-pie-chart',     defColor:'#f87171'},
        {id:'exSec-alerts',    label:'遅延アラート',      icon:'bi-exclamation-octagon-fill',defColor:'#f87171'},
        {id:'exSec-upcoming',  label:'直近納期 7日',      icon:'bi-calendar-event-fill',defColor:'#fbbf24'},
        {id:'exSec-dept',      label:'部門別稼働状況',    icon:'bi-people-fill',   defColor:'#38bdf8'},
        {id:'exSec-cost',      label:'コスト管理',        icon:'bi-currency-yen',  defColor:'#fbbf24'},
        {id:'exSec-gantt',     label:'製造スケジュール',  icon:'bi-bar-chart-steps',defColor:'#38bdf8'},
    ].filter(function(s){ return !!document.getElementById(s.id); });

    function loadStore(){
        try{ return JSON.parse(localStorage.getItem(STORE_KEY)) || {}; }
        catch(e){ return {}; }
    }
    function saveStore(data){ localStorage.setItem(STORE_KEY, JSON.stringify(data)); }

    function applyBg(color){
        const el = document.querySelector('.exec-dark');
        if(!el) return;
        el.style.background = color;
        el.style.backgroundImage =
            'radial-gradient(ellipse 55% 40% at 8% 8%, rgba(0,180,255,.12) 0%, transparent 65%),' +
            'radial-gradient(ellipse 45% 45% at 92% 90%, rgba(120,0,255,.08) 0%, transparent 60%)';
        const tabs = document.getElementById('dashTabs');
        if(tabs) tabs.style.setProperty('background', color, 'important');
        // スウォッチのアクティブ状態更新
        document.querySelectorAll('.sp-bg-swatch').forEach(function(sw){
            sw.style.outline     = sw.dataset.color === color ? '2px solid #67e8f9' : '';
            sw.style.outlineOffset = '2px';
            sw.style.transform   = sw.dataset.color === color ? 'scale(1.15)' : '';
        });
        const picker = document.getElementById('spBgCustom');
        if(picker) picker.value = color;
    }

    function applyColor(secId, color){
        const el = document.getElementById(secId);
        if(el) el.style.setProperty('--sec-clr', color);
    }

    function applyVisible(secId, visible){
        const el = document.getElementById(secId);
        if(el) el.classList.toggle('sec-hidden', !visible);
    }

    function applyAll(store){
        if(store.bg) applyBg(store.bg);
        const secs = store.sections || {};
        SECTION_DEFS.forEach(function(s){
            const cfg = secs[s.id] || {};
            if(cfg.color)          applyColor(s.id, cfg.color);
            if(cfg.visible === false) applyVisible(s.id, false);
        });
    }

    // ── 表示設定タブ: トグルリスト生成 ──
    function buildVisibility(store){
        const container = document.getElementById('spSectionToggles');
        if(!container) return;
        const secs = store.sections || {};
        container.innerHTML = SECTION_DEFS.map(function(s){
            const vis  = (secs[s.id] || {}).visible !== false;
            const clr  = (secs[s.id] || {}).color || s.defColor;
            return '<div class="d-flex align-items-center gap-2 py-2"' +
                   ' style="border-bottom:1px solid rgba(255,255,255,.06)">' +
                   '<div class="form-check form-switch mb-0">' +
                   '<input class="form-check-input" type="checkbox" role="switch"' +
                   ' id="spV-' + s.id + '"' +
                   (vis ? ' checked' : '') +
                   ' onchange="spToggleVis(\'' + s.id + '\',this.checked)"' +
                   ' style="cursor:pointer"></div>' +
                   '<i class="bi ' + s.icon + '" style="color:' + clr + ';font-size:.9rem;width:18px;text-align:center;flex-shrink:0"></i>' +
                   '<label for="spV-' + s.id + '" class="mb-0"' +
                   ' style="font-size:.82rem;cursor:pointer;flex:1;color:' + (vis ? '#e2e8f0' : '#64748b') + '">' +
                   s.label + '</label></div>';
        }).join('');
    }

    // ── カラー設定タブ: カラー行生成 ──
    function buildColorRows(store){
        const container = document.getElementById('spColorRows');
        if(!container) return;
        const secs = store.sections || {};
        container.innerHTML = SECTION_DEFS.map(function(s){
            const cur = (secs[s.id] || {}).color || s.defColor;
            const swatchHtml = PALETTE.map(function(c){
                return '<div style="width:20px;height:20px;border-radius:50%;background:' + c + ';' +
                       'cursor:pointer;flex-shrink:0;' +
                       'border:2px solid ' + (c===cur ? '#fff' : 'transparent') + ';' +
                       'transition:border-color .12s,transform .1s"' +
                       ' title="' + c + '"' +
                       ' onmouseenter="this.style.transform=\'scale(1.25)\'"' +
                       ' onmouseleave="this.style.transform=\'\'"' +
                       ' onclick="spPickColor(\'' + s.id + '\',\'' + c + '\')"></div>';
            }).join('');
            return '<div class="mb-3">' +
                   '<div class="d-flex align-items-center gap-1 mb-1">' +
                   '<i class="bi ' + s.icon + '" id="spCI-' + s.id + '"' +
                   ' style="color:' + cur + ';font-size:.85rem;width:16px;text-align:center"></i>' +
                   '<span style="font-size:.8rem;color:#cbd5e1">' + s.label + '</span>' +
                   '</div>' +
                   '<div class="d-flex flex-wrap gap-1 align-items-center">' +
                   swatchHtml +
                   '<label title="カスタム" style="cursor:pointer;position:relative;margin-left:2px">' +
                   '<i class="bi bi-plus-circle" style="color:#94a3b8;font-size:1rem"></i>' +
                   '<input type="color" value="' + cur + '" id="spCC-' + s.id + '"' +
                   ' style="position:absolute;width:0;height:0;opacity:0;pointer-events:none"' +
                   ' oninput="spPickColor(\'' + s.id + '\',this.value)"' +
                   ' onchange="spPickColor(\'' + s.id + '\',this.value)">' +
                   '</label></div></div>';
        }).join('');
    }

    // ── グローバル関数 ──
    window.spToggleVis = function(secId, vis){
        const store = loadStore();
        store.sections = store.sections || {};
        store.sections[secId] = store.sections[secId] || {};
        store.sections[secId].visible = vis;
        saveStore(store);
        applyVisible(secId, vis);
        const lbl = document.querySelector('label[for="spV-' + secId + '"]');
        if(lbl) lbl.style.color = vis ? '#e2e8f0' : '#64748b';
    };

    window.spPickColor = function(secId, color){
        const store = loadStore();
        store.sections = store.sections || {};
        store.sections[secId] = store.sections[secId] || {};
        store.sections[secId].color = color;
        saveStore(store);
        applyColor(secId, color);
        // スウォッチ枠線更新
        const rows = document.getElementById('spColorRows');
        if(rows){
            rows.querySelectorAll('div[onclick*="' + secId + '"]').forEach(function(el){
                el.style.borderColor = el.title === color ? '#fff' : 'transparent';
            });
        }
        // アイコン色更新
        const ico = document.getElementById('spCI-' + secId);
        if(ico) ico.style.color = color;
        // 表示設定タブのアイコン色も同期
        const visIco = document.querySelector('#spV-' + secId)?.closest('div')?.querySelector('i.bi');
        if(visIco) visIco.style.color = color;
        // カスタムピッカー値更新
        const cp = document.getElementById('spCC-' + secId);
        if(cp) cp.value = color;
    };

    window.spShowAll = function(){
        const store = loadStore();
        store.sections = store.sections || {};
        SECTION_DEFS.forEach(function(s){
            store.sections[s.id] = store.sections[s.id] || {};
            store.sections[s.id].visible = true;
            applyVisible(s.id, true);
            const chk = document.getElementById('spV-' + s.id);
            if(chk) chk.checked = true;
            const lbl = document.querySelector('label[for="spV-' + s.id + '"]');
            if(lbl) lbl.style.color = '#e2e8f0';
        });
        saveStore(store);
    };

    window.spHideAll = function(){
        const store = loadStore();
        store.sections = store.sections || {};
        SECTION_DEFS.forEach(function(s){
            store.sections[s.id] = store.sections[s.id] || {};
            store.sections[s.id].visible = false;
            applyVisible(s.id, false);
            const chk = document.getElementById('spV-' + s.id);
            if(chk) chk.checked = false;
            const lbl = document.querySelector('label[for="spV-' + s.id + '"]');
            if(lbl) lbl.style.color = '#64748b';
        });
        saveStore(store);
    };

    window.spResetColors = function(){
        const store = loadStore();
        store.sections = store.sections || {};
        SECTION_DEFS.forEach(function(s){
            if(store.sections[s.id]) delete store.sections[s.id].color;
            applyColor(s.id, s.defColor);
        });
        saveStore(store);
        buildColorRows(store);
        buildVisibility(store);
    };

    window.spResetAll = function(){
        if(!confirm('全設定をリセットしますか？背景色・カラー・表示設定がすべてデフォルトに戻ります。')) return;
        localStorage.removeItem(STORE_KEY);
        location.reload();
    };

    // 背景スウォッチ
    document.querySelectorAll('.sp-bg-swatch').forEach(function(sw){
        sw.addEventListener('click', function(){
            const color = this.dataset.color;
            const store = loadStore();
            store.bg = color;
            saveStore(store);
            applyBg(color);
        });
    });

    // カスタム背景ピッカー
    const bgLabel = document.getElementById('spBgCustomLabel');
    const bgInput = document.getElementById('spBgCustom');
    if(bgLabel && bgInput){
        bgLabel.addEventListener('click', function(){ bgInput.click(); });
        bgInput.addEventListener('change', function(){
            const store = loadStore();
            store.bg = this.value;
            saveStore(store);
            applyBg(this.value);
        });
    }

    // 初期化
    const store = loadStore();
    applyAll(store);
    buildVisibility(store);
    buildColorRows(store);
})();
JSCODE;
require __DIR__ . '/parts/footer.php';
?>
