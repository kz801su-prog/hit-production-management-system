<?php
// =====================================================
// 従業員ダッシュボード（process_leader / worker）
// 計画 vs 実績 差・本日タスク・自分の稼働状況
// =====================================================
if (!defined('APP_URL')) {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../app/db.php';
    require_once __DIR__ . '/../app/auth.php';
    require_once __DIR__ . '/../app/permissions.php';
    require_once __DIR__ . '/../app/functions.php';
    requireLogin();
}

$pageTitle   = 'マイダッシュボード';
$today       = date('Y-m-d');
$thisMonth   = date('Y-m');
$monthFrom   = date('Y-m-01');
$monthTo     = date('Y-m-t');
$currentUser = getCurrentUser();
$empId       = null;

// 自分の社員IDを取得
$myUser = dbFetchOne(
    "SELECT u.id, u.role, e.id AS emp_id, e.name AS emp_name,
            e.department_id, d.dept_name, e.joined_date
     FROM users u
     LEFT JOIN employees e ON u.employee_id = e.id
     LEFT JOIN departments d ON e.department_id = d.id
     WHERE u.id = ?",
    [$currentUser['id']]
);
$empId   = $myUser ? $myUser['emp_id']   : null;
$deptId  = $myUser ? $myUser['department_id'] : null;
$empName = $myUser ? $myUser['emp_name']  : $currentUser['name'];

// ===== 現在進行中の作業（自分）=====
$myActiveWork = [];
if ($empId) {
    $myActiveWork = dbFetchAll(
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
        [$empId]
    );
}

// ===== 本日の作業実績（自分）=====
$myTodayLogs = [];
if ($empId) {
    $myTodayLogs = dbFetchAll(
        "SELECT wl.*,
                TIMESTAMPDIFF(MINUTE, wl.started_at, COALESCE(wl.ended_at, NOW())) AS actual_minutes,
                p.process_name,
                mo.order_no, ct.chair_type_name,
                mop.planned_total_minutes
         FROM work_logs wl
         JOIN processes p ON wl.process_id = p.id
         JOIN manufacturing_orders mo ON wl.manufacturing_order_id = mo.id
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         LEFT JOIN manufacturing_order_processes mop
             ON mop.manufacturing_order_id = mo.id AND mop.process_id = wl.process_id
         WHERE wl.employee_id = ? AND DATE(wl.started_at) = ?
         ORDER BY wl.started_at DESC",
        [$empId, $today]
    );
}

// 本日の作業合計
$todayTotalMinutes  = array_sum(array_column($myTodayLogs, 'actual_minutes'));
$todayPlannedTotal  = array_sum(array_column(
    array_filter($myTodayLogs, fn($r) => $r['planned_total_minutes'] > 0),
    'planned_total_minutes'
));

// ===== 部門の仕掛中・未着手工程キュー =====
$deptQueue = [];
if ($deptId) {
    $deptQueue = dbFetchAll(
        "SELECT mop.id AS mop_id, mop.status, mop.planned_total_minutes,
                mop.actual_start, mop.actual_end,
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
           AND EXISTS (
               SELECT 1 FROM employees e
               WHERE e.department_id = ? AND e.is_active = 1
           )
         ORDER BY FIELD(mo.priority,'urgent','high','normal'),
                  mo.due_date IS NULL, mo.due_date
         LIMIT 20",
        [$deptId]
    );
} else {
    // 部門未設定: 全体の仕掛中
    $deptQueue = dbFetchAll(
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
         ORDER BY FIELD(mo.priority,'urgent','high','normal'), mo.due_date
         LIMIT 20"
    );
}

// ===== 今月の自分の実績サマリー =====
$myMonthSummary = null;
if ($empId) {
    $myMonthSummary = dbFetchOne(
        "SELECT
             COUNT(DISTINCT wl.manufacturing_order_id) AS order_count,
             ROUND(SUM(TIMESTAMPDIFF(MINUTE, wl.started_at, wl.ended_at)) / 60.0, 1) AS total_hours,
             COUNT(DISTINCT DATE(wl.started_at)) AS work_days
         FROM work_logs wl
         WHERE wl.employee_id = ? AND wl.ended_at IS NOT NULL
           AND DATE(wl.started_at) BETWEEN ? AND ?",
        [$empId, $monthFrom, $monthTo]
    );
}

// ===== 自分の職能ランク =====
$mySkills = [];
if ($empId) {
    $mySkills = dbFetchAll(
        "SELECT p.process_name, esr.rank_level
         FROM employee_skill_ranks esr
         JOIN processes p ON esr.process_id = p.id
         WHERE esr.employee_id = ? AND esr.rank_level > 0
         ORDER BY esr.rank_level DESC, p.display_order",
        [$empId]
    );
}

require __DIR__ . '/parts/header.php';
?>

<style>
.worker-kpi { border-left:4px solid; border-radius:8px; }
.diff-over  { color:#dc3545; }
.diff-under { color:#198754; }
.queue-row-urgent  { border-left:4px solid #dc3545; }
.queue-row-high    { border-left:4px solid #ffc107; }
.queue-row-normal  { border-left:4px solid transparent; }
.skill-star { color:#ffc107; letter-spacing:.05em; }
</style>

<!-- 挨拶ヘッダー -->
<div class="d-flex align-items-center mb-3">
  <div>
    <h2 class="mb-0">
      <?php
      $hour = (int)date('H');
      echo $hour < 12 ? 'おはようございます' : ($hour < 18 ? 'お疲れ様です' : 'お疲れ様でした');
      ?>、<strong><?= h($empName) ?></strong>
    </h2>
    <small class="text-muted">
      <?= date('Y年n月j日（D）') ?>
      <?php if ($myUser && $myUser['dept_name']): ?>
        &nbsp;|&nbsp; <?= h($myUser['dept_name']) ?>
      <?php endif; ?>
    </small>
  </div>
  <div class="ms-auto d-flex gap-1">
    <a href="work_start.php" class="btn btn-success btn-sm">
      <i class="bi bi-play-fill"></i> 作業開始
    </a>
    <a href="barcode_scan.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-upc-scan"></i> スキャン
    </a>
  </div>
</div>

<!-- 月間サマリー KPI -->
<div class="row g-2 mb-3">
  <div class="col-4">
    <div class="card worker-kpi p-2 text-center" style="border-color:#0d6efd">
      <div class="fw-bold fs-5 text-primary"><?= $myMonthSummary['work_days'] ?? 0 ?></div>
      <div class="small text-muted">今月稼働日数</div>
    </div>
  </div>
  <div class="col-4">
    <div class="card worker-kpi p-2 text-center" style="border-color:#198754">
      <div class="fw-bold fs-5 text-success"><?= $myMonthSummary['total_hours'] ?? 0 ?>h</div>
      <div class="small text-muted">今月作業時間</div>
    </div>
  </div>
  <div class="col-4">
    <div class="card worker-kpi p-2 text-center" style="border-color:#6f42c1">
      <div class="fw-bold fs-5" style="color:#6f42c1"><?= $myMonthSummary['order_count'] ?? 0 ?></div>
      <div class="small text-muted">今月担当指示数</div>
    </div>
  </div>
</div>

<!-- ===== 現在進行中の作業 ===== -->
<?php if (!empty($myActiveWork)): ?>
<div class="card mb-3 border-success">
  <div class="card-header bg-success text-white py-2 fw-bold">
    <i class="bi bi-play-circle-fill"></i> 現在進行中の作業
  </div>
  <div class="card-body p-0">
  <?php foreach ($myActiveWork as $aw): ?>
    <?php
    $elapsed = (int)$aw['elapsed_minutes'];
    $planned = (int)($aw['planned_total_minutes'] ?? 0);
    $diffMin = $planned > 0 ? $elapsed - $planned : null;
    ?>
    <div class="p-3 border-bottom">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <span class="badge bg-success me-1">稼働中</span>
          <strong><?= h($aw['process_name']) ?></strong>
          <span class="text-muted mx-1">—</span>
          <a href="orders.php?id=<?= $aw['order_id'] ?>" class="text-decoration-none">
            <?= h($aw['order_no']) ?>
          </a>
          <br>
          <small class="text-muted">
            <?= h($aw['chair_type_name']) ?> <?= $aw['quantity'] ?>本
            <?php if ($aw['due_date']): ?>
              &nbsp;|&nbsp; 納期: <?= formatDate($aw['due_date']) ?>
            <?php endif; ?>
          </small>
        </div>
        <div class="text-end">
          <div class="fw-bold fs-5 text-success"><?= formatMinutes($elapsed) ?></div>
          <?php if ($diffMin !== null): ?>
          <small class="<?= $diffMin > 0 ? 'diff-over' : 'diff-under' ?>">
            <?= $diffMin > 0 ? '▲超過 ' . formatMinutes($diffMin) : '▽余裕 ' . formatMinutes(-$diffMin) ?>
          </small>
          <?php endif; ?>
          <?php if ($planned > 0): ?>
          <div class="small text-muted">計画: <?= formatMinutes($planned) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($planned > 0): ?>
      <div class="progress mt-2" style="height:8px">
        <?php $pct = min(120, round($elapsed / $planned * 100)); ?>
        <div class="progress-bar <?= $pct > 100 ? 'bg-danger' : 'bg-success' ?>"
             style="width:<?= min(100, $pct) ?>%"></div>
      </div>
      <div class="d-flex justify-content-between">
        <small class="text-muted">開始: <?= formatDatetime($aw['started_at']) ?></small>
        <small class="text-muted"><?= $pct ?>%</small>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">

  <!-- 本日の作業実績 -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header py-2 fw-bold d-flex justify-content-between">
        <span><i class="bi bi-clock-history"></i> 本日の作業実績</span>
        <span class="text-muted small fw-normal">
          合計 <strong class="text-dark"><?= formatMinutes($todayTotalMinutes) ?></strong>
          <?php if ($todayPlannedTotal > 0): ?>
            / 計画 <?= formatMinutes($todayPlannedTotal) ?>
          <?php endif; ?>
        </span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($myTodayLogs)): ?>
          <div class="p-3 text-center text-muted small">
            <i class="bi bi-calendar-x"></i> 本日の作業記録なし
          </div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>工程</th>
                <th>作業指示</th>
                <th class="text-end">実績</th>
                <th class="text-end">計画</th>
                <th class="text-end">差</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($myTodayLogs as $tl):
                $actual  = (int)$tl['actual_minutes'];
                $planned = (int)($tl['planned_total_minutes'] ?? 0);
                $diff    = $planned > 0 ? $actual - $planned : null;
            ?>
              <tr>
                <td><?= h($tl['process_name']) ?></td>
                <td>
                  <a href="orders.php?id=<?= $tl['manufacturing_order_id'] ?>"
                     class="text-decoration-none small">
                    <?= h($tl['order_no']) ?>
                  </a>
                </td>
                <td class="text-end fw-bold"><?= formatMinutes($actual) ?></td>
                <td class="text-end text-muted">
                  <?= $planned > 0 ? formatMinutes($planned) : '―' ?>
                </td>
                <td class="text-end fw-bold">
                  <?php if ($diff !== null): ?>
                    <span class="<?= $diff > 0 ? 'diff-over' : 'diff-under' ?>">
                      <?= $diff > 0 ? '+' : '' ?><?= formatMinutes($diff) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">―</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php
          // 差分サマリー
          $diffs = array_filter(
              array_map(fn($r) => (int)$r['planned_total_minutes'] > 0
                  ? (int)$r['actual_minutes'] - (int)$r['planned_total_minutes'] : null,
                  $myTodayLogs),
              fn($v) => $v !== null
          );
          if (!empty($diffs)):
            $totalDiff = array_sum($diffs);
          ?>
          <div class="px-3 py-2 border-top text-end small">
            本日の計画対比:
            <strong class="<?= $totalDiff > 0 ? 'diff-over' : 'diff-under' ?>">
              <?= $totalDiff > 0 ? '▲超過 ' : '▽前倒し ' ?><?= formatMinutes(abs($totalDiff)) ?>
            </strong>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 作業キュー（部門の仕掛中・未着手） -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header py-2 fw-bold">
        <i class="bi bi-list-task"></i>
        <?= $deptId ? h($myUser['dept_name'] ?? '部門') : '全体' ?>の作業キュー
        <span class="badge bg-secondary ms-1"><?= count($deptQueue) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($deptQueue)): ?>
          <div class="p-3 text-center text-success small">
            <i class="bi bi-check-circle-fill"></i> 作業キューは空です
          </div>
        <?php else: ?>
          <div class="list-group list-group-flush small">
          <?php foreach ($deptQueue as $q):
            $qRowClass = match($q['priority'] ?? 'normal') {
                'urgent' => 'queue-row-urgent',
                'high'   => 'queue-row-high',
                default  => 'queue-row-normal',
            };
            $daysLeft = $q['days_left'];
          ?>
            <a href="orders.php?id=<?= $q['order_id'] ?>"
               class="list-group-item list-group-item-action <?= $qRowClass ?> py-2">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <?= processStatusBadge($q['status']) ?>
                  <?php if ($q['priority'] !== 'normal'): ?>
                    <?= priorityBadge($q['priority']) ?>
                  <?php endif; ?>
                  <strong class="ms-1"><?= h($q['process_name']) ?></strong>
                  <span class="text-muted"> — <?= h($q['order_no']) ?></span>
                  <br>
                  <span class="text-muted">
                    <?= h($q['customer_name'] ?: $q['chair_type_name']) ?>
                    <?= $q['quantity'] ?>本
                  </span>
                </div>
                <div class="text-end text-nowrap">
                  <?php if ($q['planned_total_minutes'] > 0): ?>
                    <span class="badge bg-light text-dark border">
                      <?= formatMinutes((int)$q['planned_total_minutes']) ?>
                    </span><br>
                  <?php endif; ?>
                  <?php if ($daysLeft !== null): ?>
                    <small class="<?= $daysLeft <= 0 ? 'text-danger fw-bold' : ($daysLeft <= 2 ? 'text-warning' : 'text-muted') ?>">
                      <?= $daysLeft <= 0 ? '納期超過' : $daysLeft.'日後' ?>
                    </small>
                  <?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 自分の職能ランク -->
  <?php if (!empty($mySkills)): ?>
  <div class="col-12">
    <div class="card">
      <div class="card-header py-2 fw-bold">
        <i class="bi bi-award-fill text-warning"></i> 私の職能ランク
      </div>
      <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2">
          <?php
          $rankLabels = [1=>'見習',2=>'補助',3=>'一般',4=>'熟練',5=>'マスター'];
          $rankColors = [1=>'secondary',2=>'info',3=>'primary',4=>'warning',5=>'danger'];
          foreach ($mySkills as $sk):
          ?>
            <span class="badge bg-<?= $rankColors[$sk['rank_level']] ?> fs-6 px-3 py-2">
              <i class="bi bi-star-fill me-1"></i>
              <?= h($sk['process_name']) ?>
              <span class="ms-1 opacity-75"><?= $rankLabels[$sk['rank_level']] ?></span>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php
$extraJs = <<<JS
// 進行中作業の経過時間をリアルタイム更新
setInterval(function() {
    fetch(location.href, {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .catch(()=>{});
}, 60000);
JS;
require __DIR__ . '/parts/footer.php';
?>
