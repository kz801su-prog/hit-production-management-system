<?php
// =====================================================
// ダッシュボード
// 目的: 今日の進捗サマリー・遅延アラート・KPIを一覧表示
// 接続テーブル: manufacturing_orders, manufacturing_order_processes, work_logs
// 呼び出し先: 各詳細ページへのリンク
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/progress_service.php';

requireLogin();
$pageTitle = 'ダッシュボード';

// --- KPI集計 ---
$today = date('Y-m-d');

// 進行中の作業指示数
$activeOrders = (int)(dbFetchOne(
    "SELECT COUNT(*) AS cnt FROM manufacturing_orders WHERE status = 'in_progress'"
)['cnt'] ?? 0);

// 今日完了した作業指示数
$todayCompleted = (int)(dbFetchOne(
    "SELECT COUNT(*) AS cnt FROM manufacturing_orders WHERE status = 'completed' AND DATE(updated_at) = ?",
    [$today]
)['cnt'] ?? 0);

// 遅延中の工程数
$delayedCount = (int)(dbFetchOne(
    "SELECT COUNT(*) AS cnt FROM manufacturing_order_processes
     WHERE delay_status IN ('delayed','critical')"
)['cnt'] ?? 0);

// 今日の作業者数（今日始めた人数）
$todayWorkers = (int)(dbFetchOne(
    "SELECT COUNT(DISTINCT employee_id) AS cnt FROM work_logs WHERE DATE(started_at) = ?",
    [$today]
)['cnt'] ?? 0);

// 遅延工程一覧（上位5件）
$delayedList = dbFetchAll(
    "SELECT mop.*, mo.order_no, mo.priority,
            ct.chair_type_name, p.process_name
     FROM manufacturing_order_processes mop
     JOIN manufacturing_orders mo ON mop.manufacturing_order_id = mo.id
     JOIN chair_types ct ON mo.chair_type_id = ct.id
     JOIN processes p ON mop.process_id = p.id
     WHERE mop.delay_status IN ('delayed','critical')
       AND mo.status NOT IN ('completed','cancelled')
     ORDER BY FIELD(mop.delay_status,'critical','delayed'), mop.delay_minutes DESC
     LIMIT 5"
);

// 進行中の作業指示（優先度高・緊急のみ）
$urgentOrders = dbFetchAll(
    "SELECT mo.*, ct.chair_type_name FROM manufacturing_orders mo
     JOIN chair_types ct ON mo.chair_type_id = ct.id
     WHERE mo.status = 'in_progress' AND mo.priority IN ('high','urgent')
     ORDER BY FIELD(mo.priority,'urgent','high'), mo.due_date
     LIMIT 10"
);

// 月別効率グラフ用データ（過去6ヶ月）
$efficiencyData = dbFetchAll(
    "SELECT DATE_FORMAT(wl.started_at, '%Y-%m') AS ym,
            AVG(CASE WHEN mop.planned_total_minutes > 0
                THEN mop.planned_total_minutes / mop.actual_minutes * 100 END) AS avg_perf
     FROM work_logs wl
     JOIN manufacturing_order_processes mop
         ON mop.manufacturing_order_id = wl.manufacturing_order_id
         AND mop.process_id = wl.process_id
     WHERE wl.ended_at IS NOT NULL
       AND wl.started_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY ym ORDER BY ym"
);

// 社長の言葉（ランダム）
$word = dbFetchOne("SELECT * FROM president_words WHERE is_active = 1 ORDER BY RAND() LIMIT 1");

// ガントチャート期間設定
$ganttPeriod = $_GET['gantt_period'] ?? 'week';
switch ($ganttPeriod) {
    case 'tomorrow':
        $ganttFrom = date('Y-m-d', strtotime('+1 day'));
        $ganttTo   = $ganttFrom;
        $ganttLabel = '明日';
        break;
    case 'month':
        $ganttFrom = date('Y-m-01');
        $ganttTo   = date('Y-m-t');
        $ganttLabel = '今月';
        break;
    default: // week
        $ganttFrom = date('Y-m-d');
        $ganttTo   = date('Y-m-d', strtotime('+6 days'));
        $ganttLabel = '今週';
        break;
}

require __DIR__ . '/parts/header.php';
?>

<!-- KPIカード -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="card border-primary h-100">
      <div class="card-body text-center py-2">
        <div class="fs-2 text-primary fw-bold"><?= $activeOrders ?></div>
        <div class="text-muted small">進行中の作業指示</div>
        <i class="bi bi-gear-fill text-primary"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-success h-100">
      <div class="card-body text-center py-2">
        <div class="fs-2 text-success fw-bold"><?= $todayCompleted ?></div>
        <div class="text-muted small">本日完了</div>
        <i class="bi bi-check-circle-fill text-success"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card <?= $delayedCount > 0 ? 'border-danger' : 'border-secondary' ?> h-100">
      <div class="card-body text-center py-2">
        <div class="fs-2 <?= $delayedCount > 0 ? 'text-danger' : 'text-secondary' ?> fw-bold">
          <?= $delayedCount ?>
        </div>
        <div class="text-muted small">遅延工程数</div>
        <i class="bi bi-exclamation-triangle-fill <?= $delayedCount > 0 ? 'text-danger' : 'text-secondary' ?>"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-info h-100">
      <div class="card-body text-center py-2">
        <div class="fs-2 text-info fw-bold"><?= $todayWorkers ?></div>
        <div class="text-muted small">本日の作業者数</div>
        <i class="bi bi-people-fill text-info"></i>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- 遅延アラート -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header bg-danger text-white">
        <i class="bi bi-exclamation-triangle"></i> 遅延アラート
        <?php if ($delayedCount > 5): ?>
          <a href="<?= APP_URL ?>/progress_board.php?filter=delayed" class="btn btn-sm btn-light float-end">全件表示</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($delayedList)): ?>
          <div class="p-3 text-center text-success"><i class="bi bi-check-circle"></i> 遅延工程なし</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
          <?php foreach ($delayedList as $d): ?>
            <?php $isCritical = $d['delay_status'] === 'critical'; ?>
            <div class="list-group-item list-group-item-<?= $isCritical ? 'danger' : 'warning' ?>">
              <div class="d-flex justify-content-between">
                <strong><?= h($d['order_no']) ?> - <?= h($d['process_name']) ?></strong>
                <span class="badge bg-<?= $isCritical ? 'danger' : 'warning' ?>">
                  +<?= formatMinutes($d['delay_minutes']) ?>
                </span>
              </div>
              <small><?= h($d['chair_type_name']) ?></small>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 優先度高・緊急の作業指示 -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header bg-warning">
        <i class="bi bi-flag-fill"></i> 優先度高・緊急の作業
      </div>
      <div class="card-body p-0">
        <?php if (empty($urgentOrders)): ?>
          <div class="p-3 text-center text-muted">該当なし</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
          <?php foreach ($urgentOrders as $o): ?>
            <?php $p = priorityLabel($o['priority']); ?>
            <a href="<?= APP_URL ?>/orders.php?id=<?= $o['id'] ?>" class="list-group-item list-group-item-action">
              <div class="d-flex justify-content-between">
                <strong><?= h($o['order_no']) ?></strong>
                <span class="badge bg-<?= $p['class'] ?>"><?= $p['label'] ?></span>
              </div>
              <small class="text-muted"><?= h($o['chair_type_name']) ?> | 納期: <?= formatDate($o['due_date']) ?></small>
            </a>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 効率推移グラフ -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-graph-up"></i> 月別作業効率推移（過去6ヶ月）
      </div>
      <div class="card-body">
        <canvas id="efficiencyChart" height="120"></canvas>
      </div>
    </div>
  </div>

  <!-- 社長の言葉 -->
  <div class="col-md-4">
    <div class="card bg-dark text-white h-100">
      <div class="card-header border-secondary">
        <i class="bi bi-chat-quote"></i> 社長の言葉
      </div>
      <div class="card-body d-flex flex-column justify-content-center">
        <?php if ($word): ?>
          <blockquote class="blockquote text-center mb-0">
            <p class="fst-italic president-word-text" id="presidentWordText">
              "<?= h($word['message']) ?>"
            </p>
            <button id="presidentWordToggle" class="btn btn-link btn-sm text-white-50 p-0 d-none"
                    onclick="togglePresidentWord()">
              続きを読む <i class="bi bi-chevron-down"></i>
            </button>
            <footer class="blockquote-footer text-white-50"><?= h($word['speaker_name']) ?></footer>
          </blockquote>
        <?php else: ?>
          <p class="text-muted">登録なし</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ガントチャート（期間ボタン付き） -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-bar-chart-steps"></i>
        <span>スケジュール ガントチャート</span>
        <div class="btn-group ms-auto" role="group">
          <a href="?gantt_period=tomorrow"
             class="btn btn-sm <?= $ganttPeriod === 'tomorrow' ? 'btn-primary' : 'btn-outline-primary' ?>">
            明日
          </a>
          <a href="?gantt_period=week"
             class="btn btn-sm <?= $ganttPeriod === 'week' ? 'btn-primary' : 'btn-outline-primary' ?>">
            今週
          </a>
          <a href="?gantt_period=month"
             class="btn btn-sm <?= $ganttPeriod === 'month' ? 'btn-primary' : 'btn-outline-primary' ?>">
            今月
          </a>
        </div>
        <a href="gantt.php?date_from=<?= $ganttFrom ?>&date_to=<?= $ganttTo ?>"
           class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrows-fullscreen"></i> 全画面
        </a>
      </div>
      <div class="card-body p-0">
        <iframe src="gantt.php?date_from=<?= $ganttFrom ?>&date_to=<?= $ganttTo ?>&embed=1"
                class="w-100 border-0" style="height:320px; overflow:hidden;"
                title="ガントチャート <?= $ganttLabel ?>"></iframe>
      </div>
    </div>
  </div>
</div>

<?php
// グラフ用データをPHPからJSへ渡す
$chartLabels = json_encode(array_column($efficiencyData, 'ym'));
$chartValues = json_encode(array_map(fn($r) => round((float)$r['avg_perf'], 1), $efficiencyData));
$extraJs = <<<JS
(function(){
  const ctx = document.getElementById('efficiencyChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: {$chartLabels},
      datasets: [{
        label: '平均達成率（%）',
        data: {$chartValues},
        borderColor: 'rgb(75,192,192)',
        backgroundColor: 'rgba(75,192,192,0.1)',
        tension: 0.3,
        fill: true,
      }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: {
        y: { min: 70, max: 140, title: { display: true, text: '達成率（%）' } }
      }
    }
  });
})();

// 社長の言葉: 2行クランプ
(function(){
  const el  = document.getElementById('presidentWordText');
  const btn = document.getElementById('presidentWordToggle');
  if (!el || !btn) return;
  const lineH = parseFloat(getComputedStyle(el).lineHeight) || 24;
  const maxH  = lineH * 2 + 4;
  if (el.scrollHeight > maxH + 4) {
    el.style.overflow    = 'hidden';
    el.style.maxHeight   = maxH + 'px';
    el.style.transition  = 'max-height .3s ease';
    btn.classList.remove('d-none');
  }
})();
function togglePresidentWord() {
  const el  = document.getElementById('presidentWordText');
  const btn = document.getElementById('presidentWordToggle');
  const expanded = el.style.maxHeight === 'none' || parseInt(el.style.maxHeight) > 80;
  if (expanded) {
    const lineH = parseFloat(getComputedStyle(el).lineHeight) || 24;
    el.style.maxHeight = (lineH * 2 + 4) + 'px';
    btn.innerHTML = '続きを読む <i class="bi bi-chevron-down"></i>';
  } else {
    el.style.maxHeight = el.scrollHeight + 'px';
    btn.innerHTML = '閉じる <i class="bi bi-chevron-up"></i>';
  }
}
JS;
require __DIR__ . '/parts/footer.php';
?>
