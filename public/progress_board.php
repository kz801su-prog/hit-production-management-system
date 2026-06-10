<?php
// =====================================================
// 進捗ボード
// 目的: 作業指示×工程のマトリックスで進捗を確認
// 接続テーブル: manufacturing_orders, manufacturing_order_processes, processes
// 呼び出し元: dashboard.php, orders.php
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/progress_service.php';

requireLogin();
$pageTitle = '進捗ボード';

$orderId     = getInt('order_id');
$statusFilter = $_GET['filter'] ?? '';

// 特定の作業指示が指定された場合
if ($orderId) {
    $order = dbFetchOne(
        "SELECT mo.*, ct.chair_type_code, ct.chair_type_name
         FROM manufacturing_orders mo
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         WHERE mo.id = ?",
        [$orderId]
    );
    $processes = dbFetchAll(
        "SELECT mop.*, p.process_name, p.process_code
         FROM manufacturing_order_processes mop
         JOIN processes p ON mop.process_id = p.id
         WHERE mop.manufacturing_order_id = ?
         ORDER BY mop.process_sequence, p.display_order",
        [$orderId]
    );
    // この注文の担当者ログ
    $workerLogs = dbFetchAll(
        "SELECT wl.*, e.name AS worker_name, p.process_name
         FROM work_logs wl
         JOIN employees e ON wl.employee_id = e.id
         JOIN processes p ON wl.process_id = p.id
         WHERE wl.manufacturing_order_id = ?
         ORDER BY wl.started_at DESC",
        [$orderId]
    );
} else {
    // 全体進捗ボード
    $filters = [];
    if ($statusFilter === 'delayed') {
        $filters['status'] = ['in_progress', 'planned'];
    } else {
        $filters['status'] = ['planned', 'in_progress'];
    }
    $boardData = getProgressBoardData($filters);
}

require __DIR__ . '/parts/header.php';
?>

<?php if ($orderId && isset($order)): ?>
<!-- 特定作業指示の詳細進捗 -->
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h2><i class="bi bi-clipboard-check"></i> 進捗詳細</h2>
    <div class="d-flex gap-2 align-items-center">
      <h4 class="mb-0"><?= h($order['order_no']) ?></h4>
      <?php $st = orderStatusLabel($order['status']); ?>
      <span class="badge bg-<?= $st['class'] ?>"><?= $st['label'] ?></span>
      <?php $pr = priorityLabel($order['priority']); ?>
      <span class="badge bg-<?= $pr['class'] ?>"><?= $pr['label'] ?></span>
    </div>
    <p class="text-muted mb-0"><?= h($order['chair_type_name']) ?> | <?= h($order['quantity']) ?>本 | 納期: <?= formatDate($order['due_date']) ?></p>
  </div>
  <div>
    <a href="orders.php" class="btn btn-outline-secondary btn-sm">一覧へ戻る</a>
    <a href="gantt.php?order_id=<?= $orderId ?>" class="btn btn-outline-info btn-sm ms-1">ガントチャート</a>
  </div>
</div>

<!-- 工程別進捗 -->
<div class="row g-3 mb-4">
<?php foreach ($processes as $mop): ?>
  <?php
  $s = processStatusLabel($mop['status']);
  $perfRate = $mop['performance_rate'];
  $perf = $perfRate ? performanceLabel((float)$perfRate) : null;
  ?>
  <div class="col-md-3">
    <div class="card h-100 border-<?= $s['class'] ?>">
      <div class="card-header bg-<?= $s['class'] ?> <?= in_array($mop['status'], ['in_progress','completed']) ? 'text-white' : '' ?> py-1">
        <small><?= h($mop['process_name']) ?></small>
        <span class="badge bg-white text-dark float-end"><?= $s['label'] ?></span>
      </div>
      <div class="card-body p-2">
        <div class="row g-1 text-center">
          <div class="col-6">
            <small class="text-muted d-block">予定</small>
            <strong><?= formatMinutes($mop['planned_total_minutes']) ?></strong>
          </div>
          <div class="col-6">
            <small class="text-muted d-block">実績</small>
            <strong><?= $mop['actual_minutes'] > 0 ? formatMinutes($mop['actual_minutes']) : '―' ?></strong>
          </div>
        </div>
        <?php if ($mop['delay_minutes'] != 0): ?>
          <div class="text-center mt-1">
            <small class="<?= $mop['delay_minutes'] > 0 ? 'text-danger' : 'text-success' ?>">
              <?= $mop['delay_minutes'] > 0 ? '+' : '' ?><?= formatMinutes(abs($mop['delay_minutes'])) ?>
            </small>
          </div>
        <?php endif; ?>
        <?php if ($perf): ?>
          <div class="text-center mt-1">
            <span class="badge bg-<?= $perf['class'] ?>"><?= $perf['label'] ?> (<?= round($perfRate, 1) ?>%)</span>
          </div>
        <?php endif; ?>
        <!-- 進捗バー -->
        <div class="progress mt-2" style="height:6px">
          <div class="progress-bar bg-<?= $s['class'] ?>" style="width:<?= h($mop['progress_rate']) ?>%"></div>
        </div>
        <div class="d-flex justify-content-between mt-1">
          <?php if ($mop['status'] === 'not_started' || $mop['status'] === 'on_hold'): ?>
            <a href="work_start.php?order_id=<?= $orderId ?>&process_id=<?= $mop['process_id'] ?>"
               class="btn btn-xs btn-outline-success w-100">開始</a>
          <?php elseif ($mop['status'] === 'in_progress'): ?>
            <a href="work_finish.php?order_id=<?= $orderId ?>&process_id=<?= $mop['process_id'] ?>"
               class="btn btn-xs btn-outline-warning w-100">終了</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- 作業ログ -->
<div class="card">
  <div class="card-header">作業ログ</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead class="table-dark">
        <tr><th>工程</th><th>作業者</th><th>開始</th><th>終了</th><th>実作業</th><th>完了数</th><th>不良数</th><th>メモ</th></tr>
      </thead>
      <tbody>
      <?php foreach ($workerLogs as $wl): ?>
        <tr>
          <td><?= h($wl['process_name']) ?></td>
          <td><?= h($wl['worker_name']) ?></td>
          <td><?= formatDatetime($wl['started_at']) ?></td>
          <td><?= formatDatetime($wl['ended_at']) ?></td>
          <td><?= $wl['actual_minutes'] > 0 ? formatMinutes($wl['actual_minutes']) : '作業中' ?></td>
          <td><?= $wl['completed_qty'] ?></td>
          <td><?= $wl['defect_qty'] > 0 ? '<span class="text-danger">' . $wl['defect_qty'] . '</span>' : 0 ?></td>
          <td><small><?= h($wl['memo']) ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<!-- 全体進捗ボード（マトリックス） -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2><i class="bi bi-grid-3x3-gap"></i> 進捗ボード</h2>
  <div>
    <a href="progress_board.php" class="btn btn-sm btn-<?= !$statusFilter ? 'primary' : 'outline-primary' ?>">全体</a>
    <a href="progress_board.php?filter=delayed" class="btn btn-sm btn-<?= $statusFilter === 'delayed' ? 'danger' : 'outline-danger' ?>">遅延のみ</a>
    <a href="gantt.php" class="btn btn-sm btn-outline-info ms-2">ガントチャート</a>
  </div>
</div>

<?php
$orders    = $boardData['orders']    ?? [];
$processes = $boardData['processes'] ?? [];
$pmap      = $boardData['progress_map'] ?? [];
?>

<div class="table-responsive">
  <table class="table table-bordered table-sm" style="min-width:800px">
    <thead class="table-dark">
      <tr>
        <th style="min-width:130px">作業番号</th>
        <th>製品タイプ</th>
        <th>数量</th>
        <th>納期</th>
        <?php foreach ($processes as $p): ?>
          <th class="text-center" style="min-width:80px"><?= h($p['process_name']) ?></th>
        <?php endforeach; ?>
        <th>遅延</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $o):
      $totalDelay = 0;
    ?>
      <tr>
        <td>
          <a href="progress_board.php?order_id=<?= $o['id'] ?>">
            <strong><?= h($o['order_no']) ?></strong>
          </a>
          <?php $pr = priorityLabel($o['priority']); ?>
          <span class="badge bg-<?= $pr['class'] ?> ms-1"><?= $pr['label'] ?></span>
        </td>
        <td><small><?= h($o['chair_type_name']) ?></small></td>
        <td><?= $o['quantity'] ?>本</td>
        <td><?= formatDate($o['due_date']) ?></td>
        <?php foreach ($processes as $p):
          $mop = $pmap[$o['id']][$p['id']] ?? null;
          if ($mop) $totalDelay += max(0, (float)$mop['delay_minutes']);
        ?>
          <td class="text-center">
          <?php if ($mop): ?>
            <?php $s = processStatusLabel($mop['status']); ?>
            <span class="badge bg-<?= $s['class'] ?>"><?= $s['label'] ?></span>
            <?php if ($mop['delay_minutes'] > DELAY_WARNING): ?>
              <br><small class="text-danger">+<?= formatMinutes($mop['delay_minutes']) ?></small>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted">―</span>
          <?php endif; ?>
          </td>
        <?php endforeach; ?>
        <td>
          <?php if ($totalDelay > 0): ?>
            <span class="text-danger fw-bold">+<?= formatMinutes($totalDelay) ?></span>
          <?php else: ?>
            <span class="text-success"><i class="bi bi-check"></i></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?>
      <tr><td colspan="<?= 4 + count($processes) + 1 ?>" class="text-center text-muted py-3">作業指示なし</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/parts/footer.php'; ?>
