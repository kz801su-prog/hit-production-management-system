<?php
// =====================================================
// バーコードスキャンステーション
// 目的: 作業指示→工程→社員の順にバーコードを読み作業開始、
//       終了バーコード（FINISH）→社員→品質グレード→作業終了
//       時間は自動計測・work_logsに記録。評価に即時反映。
// 接続テーブル: work_logs, manufacturing_order_processes,
//              manufacturing_orders, processes, employees
//              monthly_worker_scores
// 権限: worker以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/progress_service.php';
require_once __DIR__ . '/../app/evaluation_service.php';

requireLogin();
requireRole('worker');

$pageTitle = 'バーコードスキャンステーション';

// =====================================================
// モードとステップ（GETパラメータで状態管理）
// mode=start : 作業開始フロー
//   step=1 → 作業番号スキャン
//   step=2 → 工程バーコードスキャン (?oid=作業指示ID)
//   step=3 → 社員バーコードスキャン (?oid=&pid=工程ID)
// mode=end   : 作業終了フロー
//   step=1 → 社員バーコードスキャン
//   step=2 → 進行中の作業一覧・品質入力 (?eid=社員ID)
// =====================================================
$mode = $_GET['mode'] ?? 'start';
$step = (int)($_GET['step'] ?? 1);
$oid  = (int)($_GET['oid']  ?? 0); // 作業指示ID
$pid  = (int)($_GET['pid']  ?? 0); // 工程ID
$eid  = (int)($_GET['eid']  ?? 0); // 社員ID（終了フロー）
$scan = trim($_GET['scan']  ?? '');
$error = '';

// プリロードデータ
$order   = null;
$process = null;
$employee = null;

// =====================================================
// POSTハンドラ（作業開始 / 作業終了）
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    // --- 作業開始 ---
    if ($postAction === 'start_work') {
        $orderId   = postInt('oid');
        $processId = postInt('pid');
        $empCode   = postStr('scan');

        $emp = dbFetchOne(
            "SELECT id, name FROM employees WHERE employee_code = ? AND is_active = 1",
            [$empCode]
        );
        if (!$emp) {
            setFlash("社員コード「" . h($empCode) . "」が見つかりません。", 'danger');
            header('Location: ' . APP_URL . "/barcode_station.php?mode=start&step=3&oid={$orderId}&pid={$processId}");
            exit;
        }
        try {
            startWork($orderId, $processId, $emp['id']);
            setFlash(h($emp['name']) . " の作業を開始しました。次の工程は作業番号から再スキャンしてください。", 'success');
        } catch (RuntimeException $e) {
            setFlash($e->getMessage(), 'warning');
        }
        header('Location: ' . APP_URL . '/barcode_station.php?mode=start&step=1');
        exit;
    }

    // --- 作業終了 ---
    if ($postAction === 'end_work') {
        $logId        = postInt('log_id');
        $qualityGrade = postStr('quality_grade') ?: null;
        if ($qualityGrade && !in_array($qualityGrade, ['S','A','B','C','D'])) {
            $qualityGrade = null;
        }

        $log = dbFetchOne("SELECT * FROM work_logs WHERE id = ? AND ended_at IS NULL", [$logId]);
        if (!$log) {
            setFlash('作業ログが見つかりません。', 'danger');
            header('Location: ' . APP_URL . '/barcode_station.php?mode=end&step=1');
            exit;
        }
        try {
            finishWork($logId, [
                'completed_qty' => postInt('completed_qty'),
                'defect_qty'    => postInt('defect_qty'),
                'rework_qty'    => 0,
                'break_minutes' => postFloat('break_minutes'),
                'memo'          => postStr('memo'),
                'quality_grade' => $qualityGrade,
            ]);
            // 品質グレードが入力された場合は即座に月次評価を再計算
            if ($qualityGrade) {
                $month = date('Y-m', strtotime($log['started_at']));
                calcAndSaveMonthlyScore((int)$log['employee_id'], $month);
            }
            $gradeMsg = $qualityGrade ? "（品質評価: <strong>{$qualityGrade}</strong>）" : '';
            setFlash("作業を終了しました。{$gradeMsg}", 'success');
        } catch (RuntimeException $e) {
            setFlash($e->getMessage(), 'danger');
        }
        header('Location: ' . APP_URL . "/barcode_station.php?mode=end&step=2&eid={$log['employee_id']}");
        exit;
    }
}

// =====================================================
// GETスキャン解析（バーコードリーダーはEnterで送信）
// =====================================================
if ($scan) {
    $upperScan = strtoupper($scan);

    // 特殊コード: 終了モードに切り替え
    if (in_array($upperScan, ['FINISH', 'END', 'STATION-END', '終了'])) {
        header('Location: ' . APP_URL . '/barcode_station.php?mode=end&step=1');
        exit;
    }
    // 特殊コード: 開始モードにリセット
    if (in_array($upperScan, ['RESET', 'START', 'STATION-START', 'リセット'])) {
        header('Location: ' . APP_URL . '/barcode_station.php?mode=start&step=1');
        exit;
    }

    if ($mode === 'start') {
        if ($step === 1) {
            // 作業番号を検索
            $found = dbFetchOne(
                "SELECT id FROM manufacturing_orders
                 WHERE order_no = ? AND status IN ('planned','in_progress')",
                [$scan]
            );
            if ($found) {
                header('Location: ' . APP_URL . "/barcode_station.php?mode=start&step=2&oid={$found['id']}");
                exit;
            }
            $error = "「" . h($scan) . "」に一致する作業指示が見つかりません（完了・取消済みは対象外）。";
        } elseif ($step === 2 && $oid) {
            // 工程コードを検索
            $found = dbFetchOne(
                "SELECT id FROM processes WHERE process_code = ? AND is_active = 1",
                [$scan]
            );
            if ($found) {
                // この作業指示にこの工程が登録されているか確認
                $mop = dbFetchOne(
                    "SELECT id, status FROM manufacturing_order_processes
                     WHERE manufacturing_order_id = ? AND process_id = ?",
                    [$oid, $found['id']]
                );
                if ($mop) {
                    if ($mop['status'] === 'completed') {
                        $error = "この工程はすでに完了しています。";
                    } else {
                        header('Location: ' . APP_URL . "/barcode_station.php?mode=start&step=3&oid={$oid}&pid={$found['id']}");
                        exit;
                    }
                } else {
                    $error = "この作業指示にその工程は登録されていません。";
                }
            } else {
                $error = "「" . h($scan) . "」に一致する工程コードが見つかりません。";
            }
        }
    } elseif ($mode === 'end' && $step === 1) {
        // 社員コードを検索
        $found = dbFetchOne(
            "SELECT id, name FROM employees WHERE employee_code = ? AND is_active = 1",
            [$scan]
        );
        if ($found) {
            header('Location: ' . APP_URL . "/barcode_station.php?mode=end&step=2&eid={$found['id']}");
            exit;
        }
        $error = "「" . h($scan) . "」に一致する社員コードが見つかりません。";
    }
}

// =====================================================
// 表示用データのプリロード
// =====================================================
if ($oid) {
    $order = dbFetchOne(
        "SELECT mo.*, ct.chair_type_code, ct.chair_type_name
         FROM manufacturing_orders mo
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         WHERE mo.id = ?",
        [$oid]
    );
}
if ($pid) {
    $process = dbFetchOne("SELECT * FROM processes WHERE id = ?", [$pid]);
}

// 終了モードstep2: 指定社員の進行中作業ログ
$activeLogs = [];
if ($mode === 'end' && $step === 2 && $eid) {
    $employee = dbFetchOne("SELECT id, name, employee_code FROM employees WHERE id = ?", [$eid]);
    $activeLogs = dbFetchAll(
        "SELECT wl.id AS log_id, wl.started_at, wl.manufacturing_order_id, wl.process_id,
                mo.order_no, ct.chair_type_name, p.process_name,
                TIMESTAMPDIFF(MINUTE, wl.started_at, NOW()) AS elapsed_minutes
         FROM work_logs wl
         JOIN manufacturing_orders mo ON wl.manufacturing_order_id = mo.id
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         JOIN processes p ON wl.process_id = p.id
         WHERE wl.employee_id = ? AND wl.ended_at IS NULL
         ORDER BY wl.started_at",
        [$eid]
    );
}

// 品質グレード定義
$gradeConfig = [
    'S' => ['label' => 'S', 'desc' => '最高品質', 'color' => 'success',  'score' => 100],
    'A' => ['label' => 'A', 'desc' => '良好',     'color' => 'primary',  'score' => 80],
    'B' => ['label' => 'B', 'desc' => '標準',     'color' => 'info',     'score' => 60],
    'C' => ['label' => 'C', 'desc' => '要改善',   'color' => 'warning',  'score' => 40],
    'D' => ['label' => 'D', 'desc' => '不良',     'color' => 'danger',   'score' => 20],
];

require __DIR__ . '/parts/header.php';
?>

<!-- ステーションヘッダー -->
<div class="station-header d-flex align-items-center gap-3 mb-3 flex-wrap">
  <h2 class="mb-0"><i class="bi bi-upc-scan"></i> バーコードスキャンステーション</h2>
  <div class="ms-auto d-flex gap-2">
    <!-- モード切替ボタン -->
    <a href="?mode=start&step=1"
       class="btn <?= $mode === 'start' ? 'btn-success' : 'btn-outline-success' ?> btn-lg">
      <i class="bi bi-play-circle-fill"></i> 開始
    </a>
    <a href="?mode=end&step=1"
       class="btn <?= $mode === 'end' ? 'btn-danger' : 'btn-outline-danger' ?> btn-lg">
      <i class="bi bi-stop-circle-fill"></i> 終了
    </a>
  </div>
</div>

<?= getFlashHtml() ?>

<!-- ステッププログレス表示 -->
<?php if ($mode === 'start'): ?>
<div class="step-progress mb-4">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <?php
    $steps = [
        1 => ['icon' => 'bi-clipboard2-check', 'label' => '①作業番号'],
        2 => ['icon' => 'bi-gear',             'label' => '②工程'],
        3 => ['icon' => 'bi-person-badge',      'label' => '③作業者'],
    ];
    foreach ($steps as $s => $info):
        $cls = $step === $s ? 'step-active' : ($step > $s ? 'step-done' : 'step-todo');
    ?>
    <div class="step-node <?= $cls ?>">
      <i class="bi <?= $info['icon'] ?> me-1"></i><?= $info['label'] ?>
    </div>
    <?php if ($s < 3): ?>
    <div class="step-arrow">→</div>
    <?php endif; ?>
    <?php endforeach; ?>
    <div class="step-arrow">→</div>
    <div class="step-node step-todo"><i class="bi bi-check-circle me-1"></i>作業開始</div>
  </div>
</div>
<?php else: ?>
<div class="step-progress mb-4">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <?php
    $endSteps = [
        1 => ['icon' => 'bi-person-badge', 'label' => '①作業者バーコード'],
        2 => ['icon' => 'bi-list-check',   'label' => '②作業選択・品質入力'],
    ];
    foreach ($endSteps as $s => $info):
        $cls = $step === $s ? 'step-active' : ($step > $s ? 'step-done' : 'step-todo');
    ?>
    <div class="step-node <?= $cls ?>">
      <i class="bi <?= $info['icon'] ?> me-1"></i><?= $info['label'] ?>
    </div>
    <?php if ($s < 2): ?>
    <div class="step-arrow">→</div>
    <?php endif; ?>
    <?php endforeach; ?>
    <div class="step-arrow">→</div>
    <div class="step-node step-todo"><i class="bi bi-stop-circle me-1"></i>作業終了</div>
  </div>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible">
  <i class="bi bi-exclamation-triangle"></i> <?= $error ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- =====================================================
     開始フロー
     ===================================================== -->
<?php if ($mode === 'start'): ?>

  <?php if ($order): ?>
  <!-- スキャン済み情報カード -->
  <div class="scanned-info mb-3">
    <div class="card border-success border-2">
      <div class="card-body py-2">
        <div class="row g-2 align-items-center">
          <div class="col-auto">
            <i class="bi bi-check-circle-fill text-success fs-4"></i>
          </div>
          <div class="col">
            <div class="fw-bold"><?= h($order['order_no']) ?></div>
            <div class="small text-muted">
              <?= h($order['chair_type_code']) ?> <?= h($order['chair_type_name']) ?>
              | 数量: <?= h($order['quantity']) ?>本
              <?= orderStatusBadge($order['status']) ?>
            </div>
          </div>
          <div class="col-auto">
            <a href="?mode=start&step=1" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-x"></i> やり直し
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php if ($process): ?>
    <div class="card border-primary border-2 mt-2">
      <div class="card-body py-2">
        <div class="row g-2 align-items-center">
          <div class="col-auto">
            <i class="bi bi-check-circle-fill text-primary fs-4"></i>
          </div>
          <div class="col">
            <div class="fw-bold"><?= h($process['process_name']) ?></div>
            <div class="small text-muted font-monospace"><?= h($process['process_code']) ?></div>
          </div>
          <div class="col-auto">
            <a href="?mode=start&step=2&oid=<?= $oid ?>" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-x"></i> 工程を変更
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- スキャン入力 -->
  <div class="card shadow-sm border-<?= ['','success','primary','dark'][$step] ?? 'dark' ?> border-3">
    <div class="card-header bg-<?= ['','success','primary','dark'][$step] ?? 'dark' ?> text-white fs-5 fw-bold">
      <?php if ($step === 1): ?>
        <i class="bi bi-clipboard2-check"></i> STEP1: 作業番号バーコードをスキャン
      <?php elseif ($step === 2): ?>
        <i class="bi bi-gear"></i> STEP2: 工程バーコードをスキャン
      <?php elseif ($step === 3): ?>
        <i class="bi bi-person-badge"></i> STEP3: 社員（作業者）バーコードをスキャン
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($step < 3): ?>
      <!-- STEP1, 2: GETフォーム（スキャン後リダイレクト） -->
      <form method="get" id="scanForm" class="d-flex gap-2">
        <input type="hidden" name="mode" value="start">
        <input type="hidden" name="step" value="<?= $step ?>">
        <?php if ($oid): ?><input type="hidden" name="oid" value="<?= $oid ?>"><?php endif; ?>
        <input type="text" name="scan" id="scanInput"
               class="form-control form-control-lg scan-input"
               placeholder="<?= $step === 1 ? '作業番号 (例: WO-2026-0001)' : '工程コード (例: CUT)' ?>"
               autofocus autocomplete="off">
        <button type="submit" class="btn btn-<?= $step === 1 ? 'success' : 'primary' ?> btn-lg px-4">
          <i class="bi bi-arrow-right-circle-fill"></i>
        </button>
      </form>
      <div class="form-text mt-2">
        <i class="bi bi-info-circle"></i>
        バーコードリーダーで読み込むか、手入力してEnterを押してください。
        <?php if ($step === 1): ?>
        「FINISH」をスキャンすると終了モードに切り替わります。
        <?php endif; ?>
      </div>
      <?php else: ?>
      <!-- STEP3: POSTフォーム（社員バーコード → 作業開始） -->
      <form method="post" id="startWorkForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="start_work">
        <input type="hidden" name="oid"   value="<?= $oid ?>">
        <input type="hidden" name="pid"   value="<?= $pid ?>">
        <div class="d-flex gap-2">
          <input type="text" name="scan" id="scanInput"
                 class="form-control form-control-lg scan-input"
                 placeholder="社員コード (例: EMP001)"
                 autofocus autocomplete="off">
          <button type="submit" class="btn btn-dark btn-lg px-4">
            <i class="bi bi-play-circle-fill"></i> 開始
          </button>
        </div>
        <div class="form-text mt-2">
          <i class="bi bi-info-circle"></i>
          社員バーコードを読み込むと即時に作業が開始されます。
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- 作業指示の工程一覧（STEP2以降で表示） -->
  <?php if ($order && $step >= 2): ?>
  <?php
  $orderProcesses = dbFetchAll(
      "SELECT mop.status, p.process_name, p.process_code, p.id AS proc_id
       FROM manufacturing_order_processes mop
       JOIN processes p ON mop.process_id = p.id
       WHERE mop.manufacturing_order_id = ?
       ORDER BY mop.process_sequence, p.display_order",
      [$oid]
  );
  ?>
  <div class="card mt-3">
    <div class="card-header small fw-bold text-muted">この作業指示の工程一覧</div>
    <div class="card-body py-2">
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($orderProcesses as $op):
            $statusArr = processStatusLabel($op['status']);
        ?>
          <span class="badge bg-<?= $statusArr['class'] ?> fs-6
                <?= ($pid == $op['proc_id']) ? 'border border-dark border-3' : '' ?>">
            <?= h($op['process_name']) ?>
            <?= $statusArr['label'] !== '未着手' ? "({$statusArr['label']})" : '' ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

<!-- =====================================================
     終了フロー
     ===================================================== -->
<?php elseif ($mode === 'end'): ?>

  <?php if ($step === 1): ?>
  <!-- 社員バーコードスキャン -->
  <div class="card shadow-sm border-danger border-3">
    <div class="card-header bg-danger text-white fs-5 fw-bold">
      <i class="bi bi-person-badge"></i> STEP1: 社員（作業者）バーコードをスキャン
    </div>
    <div class="card-body">
      <form method="get" id="scanForm" class="d-flex gap-2">
        <input type="hidden" name="mode" value="end">
        <input type="hidden" name="step" value="1">
        <input type="text" name="scan" id="scanInput"
               class="form-control form-control-lg scan-input"
               placeholder="社員コード (例: EMP001)"
               autofocus autocomplete="off">
        <button type="submit" class="btn btn-danger btn-lg px-4">
          <i class="bi bi-arrow-right-circle-fill"></i>
        </button>
      </form>
      <div class="form-text mt-2">
        <i class="bi bi-info-circle"></i>
        終了する作業者の社員バーコードを読み込んでください。
      </div>
    </div>
  </div>

  <?php elseif ($step === 2 && $employee): ?>
  <!-- 進行中作業一覧・品質入力・終了 -->
  <div class="d-flex align-items-center gap-2 mb-3">
    <div class="badge bg-danger fs-5 p-2">
      <i class="bi bi-person-circle"></i> <?= h($employee['name']) ?>
      <small class="opacity-75">(<?= h($employee['employee_code']) ?>)</small>
    </div>
    <a href="?mode=end&step=1" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-x"></i> 別の社員
    </a>
  </div>

  <?php if (empty($activeLogs)): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle"></i>
      <?= h($employee['name']) ?> さんには現在進行中の作業がありません。
    </div>
  <?php else: ?>
  <div class="row g-3">
    <?php foreach ($activeLogs as $log): ?>
    <?php $elapsed = (int)($log['elapsed_minutes'] ?? 0); ?>
    <div class="col-12 col-lg-6">
      <div class="card border-warning border-2">
        <div class="card-header bg-warning d-flex align-items-center gap-2">
          <strong><?= h($log['order_no']) ?></strong>
          <span class="badge bg-dark"><?= h($log['process_name']) ?></span>
          <span class="ms-auto text-dark">
            <i class="bi bi-clock"></i>
            <?= $elapsed >= 60 ? floor($elapsed/60) . '時間' . ($elapsed%60) . '分' : $elapsed . '分' ?>経過
          </span>
        </div>
        <div class="card-body">
          <div class="small text-muted mb-3">
            <?= h($log['chair_type_name']) ?> | 開始: <?= formatDatetime($log['started_at']) ?>
          </div>

          <!-- 終了フォーム（品質グレード入力） -->
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="end_work">
            <input type="hidden" name="log_id"  value="<?= $log['log_id'] ?>">

            <!-- 品質グレード（上司が入力） -->
            <div class="mb-3">
              <label class="form-label fw-bold">
                <i class="bi bi-star-fill text-warning"></i>
                品質評価グレード <span class="text-muted fw-normal small">（上司が入力・任意）</span>
              </label>
              <div class="d-flex gap-2 flex-wrap">
                <label class="grade-label">
                  <input type="radio" name="quality_grade" value="" class="d-none grade-radio" checked>
                  <div class="grade-btn bg-light border rounded-3 text-center" style="width:64px; cursor:pointer">
                    <div class="fs-4 text-muted">―</div>
                    <div class="very-small">未評価</div>
                  </div>
                </label>
                <?php foreach ($gradeConfig as $g => $gc): ?>
                <label class="grade-label">
                  <input type="radio" name="quality_grade" value="<?= $g ?>" class="d-none grade-radio">
                  <div class="grade-btn border rounded-3 text-center text-white bg-<?= $gc['color'] ?>"
                       style="width:64px; cursor:pointer">
                    <div class="fs-3 fw-bold"><?= $g ?></div>
                    <div class="very-small"><?= $gc['desc'] ?></div>
                  </div>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- 数量・メモ（折りたたみ） -->
            <div class="accordion mb-3" id="acc-<?= $log['log_id'] ?>">
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button class="accordion-button collapsed py-2 small" type="button"
                          data-bs-toggle="collapse"
                          data-bs-target="#col-<?= $log['log_id'] ?>">
                    数量・メモを入力する（任意）
                  </button>
                </h2>
                <div id="col-<?= $log['log_id'] ?>" class="accordion-collapse collapse">
                  <div class="accordion-body py-2">
                    <div class="row g-2">
                      <div class="col-4">
                        <label class="form-label small">完了数</label>
                        <input type="number" name="completed_qty" class="form-control form-control-sm" min="0" value="0">
                      </div>
                      <div class="col-4">
                        <label class="form-label small">不良数</label>
                        <input type="number" name="defect_qty" class="form-control form-control-sm" min="0" value="0">
                      </div>
                      <div class="col-4">
                        <label class="form-label small">休憩(分)</label>
                        <input type="number" name="break_minutes" class="form-control form-control-sm" min="0" step="5" value="0">
                      </div>
                      <div class="col-12">
                        <label class="form-label small">メモ</label>
                        <textarea name="memo" class="form-control form-control-sm" rows="2"
                                  placeholder="問題点など"></textarea>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <button type="submit" class="btn btn-danger w-100 btn-lg"
                    onclick="return confirm('作業を終了しますか？')">
              <i class="bi bi-stop-circle-fill"></i> この工程を終了する
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

<?php endif; ?>

<!-- 特殊バーコード一覧（ヘルプ） -->
<div class="card mt-4 border-secondary d-print-none">
  <div class="card-header small text-muted">
    <a data-bs-toggle="collapse" href="#specialCodes" class="text-decoration-none text-muted">
      <i class="bi bi-question-circle"></i> 特殊コード一覧
    </a>
  </div>
  <div class="collapse" id="specialCodes">
    <div class="card-body py-2">
      <table class="table table-sm small mb-0">
        <tr><td class="fw-bold font-monospace">FINISH</td><td>終了モードに切り替え</td></tr>
        <tr><td class="fw-bold font-monospace">START</td><td>開始モードにリセット</td></tr>
        <tr><td class="fw-bold font-monospace">RESET</td><td>最初からやり直し</td></tr>
      </table>
    </div>
  </div>
</div>

<style>
.step-node {
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 0.9rem;
  font-weight: bold;
}
.step-active { background: #0d6efd; color: #fff; }
.step-done   { background: #198754; color: #fff; }
.step-todo   { background: #e9ecef; color: #666; }
.step-arrow  { font-size: 1.4rem; color: #999; }
.scan-input  { font-size: 1.4rem; letter-spacing: .05em; }
.grade-btn   { padding: 8px 4px; transition: transform .1s; }
.grade-label input:checked + .grade-btn {
  outline: 3px solid #212529;
  transform: scale(1.08);
}
.grade-label:hover .grade-btn { transform: scale(1.05); }
.very-small { font-size: 0.65rem; }
</style>

<?php
$extraJs = <<<JS
// バーコードリーダー: Enterでフォーム送信
(function() {
    var input = document.getElementById('scanInput');
    if (!input) return;
    input.focus();
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var form = document.getElementById('scanForm') || document.getElementById('startWorkForm');
            if (form) form.submit();
        }
    });
})();

// 品質グレードボタンのトグル
document.querySelectorAll('.grade-radio').forEach(function(radio) {
    radio.closest('.grade-label').querySelector('.grade-btn').addEventListener('click', function() {
        radio.checked = true;
    });
});
JS;
require __DIR__ . '/parts/footer.php';
?>
