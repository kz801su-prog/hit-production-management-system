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
</style>

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

<?php
$extraJs = <<<JSCODE
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
JSCODE;
require __DIR__ . '/parts/footer.php';
?>
