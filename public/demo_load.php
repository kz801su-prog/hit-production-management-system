<?php
// =====================================================
// デモデータ管理（自己完結版）
// 外部サービスファイルに依存せず直接SQLで処理
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('admin');
$pageTitle = 'デモデータ管理';

// =====================================================
// POST 処理
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = postStr('action');
    $uid    = getCurrentUser()['id'];

    if ($action === 'load_demo') {
        try {
            loadDemoData($uid);
            setFlash('デモデータを読み込みました（20件）。ダッシュボードで確認してください。');
            header('Location: ' . APP_URL . '/dashboard.php');
        } catch (Exception $e) {
            setFlash('エラーが発生しました: ' . $e->getMessage(), 'danger');
            header('Location: ' . APP_URL . '/demo_load.php');
        }
        exit;
    }

    if ($action === 'clear_demo') {
        clearMfgData();
        setFlash('製造データをすべてクリアしました。', 'warning');
        header('Location: ' . APP_URL . '/admin_settings.php#demo');
        exit;
    }
}

// =====================================================
// 製造データを全削除
// =====================================================
function clearMfgData(): void {
    $db = getDB();
    $db->exec("DELETE FROM work_logs");
    $db->exec("DELETE FROM manufacturing_order_processes");
    $db->exec("DELETE FROM manufacturing_orders");
}

// =====================================================
// CHAIR-A 標準時間を数量から計算（chair_type_service 不要）
// =====================================================
function calcDemoTimes(int $qty): array {
    // [code, setup, base_work, allowance_rate%, fixed_allowance, base_qty, sequence]
    $stds = [
        ['CUT',   15,  80,  5.0,  5, 10, 10],
        ['SEW',   20, 120,  8.0, 10, 10, 20],
        ['FOAM',  10,  60,  5.0,  5, 10, 30],
        ['FRAME', 15,  90,  5.0,  5, 10, 40],
        ['UPH',   20, 150,  8.0, 10, 10, 50],
        ['INSP',  10,  40,  3.0,  3, 10, 60],
        ['PACK',  10,  50,  3.0,  3, 10, 70],
    ];
    $result = [];
    foreach ($stds as [$code, $setup, $baseWork, $rate, $fixAllow, $baseQty, $seq]) {
        $net   = $baseWork / $baseQty * $qty;
        $total = round($setup + $net * (1 + $rate / 100) + $fixAllow, 2);
        $result[] = [
            'code'     => $code,
            'setup'    => $setup,
            'net'      => round($net, 2),
            'total'    => $total,
            'sequence' => $seq,
        ];
    }
    return $result;
}

// =====================================================
// デモデータ読み込み（直接SQL版）
// =====================================================
function loadDemoData(int $userId): void {
    srand(42);

    clearMfgData();

    $db = getDB();

    // プロセスIDマップ取得
    $procRows = dbFetchAll("SELECT id, process_code FROM processes");
    $procMap  = array_column($procRows, 'id', 'process_code');

    // 椅子タイプIDマップ取得
    $ctRows = dbFetchAll("SELECT id, chair_type_code FROM chair_types");
    $ct     = array_column($ctRows, 'id', 'chair_type_code');

    // 作業者リスト取得
    $empRows   = dbFetchAll("SELECT id FROM employees WHERE employment_status='active' ORDER BY id");
    $workerIds = array_column($empRows, 'id');
    if (empty($workerIds)) $workerIds = [1];

    // =====================================================
    // 注文定義
    // [chair_code, qty, customer, project, order_offset, due_offset, priority, final_status]
    // =====================================================
    $defs = [
        // 完成済み（〜3ヶ月前）
        ['CHAIR-A',    20, 'ホテルリゾート大阪株式会社',   '客室チェア全面入替',         -100, -70, 'normal', 'completed'],
        ['CHAIR-A',    15, '学校法人光陵学園',             '音楽室用チェア整備',         -95,  -63, 'normal', 'completed'],
        // 完成済み（〜2ヶ月前）
        ['CHAIR-A',    25, '株式会社テクノメディア',       'オフィス移転プロジェクト',   -78,  -50, 'high',   'completed'],
        ['CHAIR-A-01', 18, '有限会社みなと食堂',           '店舗用ダイニングチェア',     -72,  -48, 'normal', 'completed'],
        ['CHAIR-A',    20, '医療法人桜会クリニック',       '待合室用チェア',             -60,  -38, 'normal', 'completed'],
        // 完成済み（〜1ヶ月前）
        ['CHAIR-A-01',  8, '株式会社三和コーポレーション', '役員室備品更新',             -50,  -25, 'high',   'completed'],
        ['CHAIR-A',    22, '関西百貨店株式会社',           '休憩スペース用チェア',       -48,  -22, 'normal', 'completed'],
        ['CHAIR-A',    16, '大阪市立コミュニティセンター', '集会室用チェア',             -45,  -20, 'normal', 'completed'],
        // 完成済み（今月）
        ['CHAIR-A',    20, '株式会社フロンティア建設',     '新オフィス会議室用チェア',   -30,  -8,  'normal', 'completed'],
        ['CHAIR-A-02', 12, '京都ホテルアネックス',         '宴会場テーブル席用チェア',   -25,  -5,  'high',   'completed'],
        // 仕掛中
        ['CHAIR-A',    50, '大和ホテルグループ',           '宴会場全面リニューアル',     -20,  14,  'normal', 'in_progress'],
        ['CHAIR-A',    30, '福祉法人あおぞら',             '介護施設用チェア',           -18,  21,  'normal', 'in_progress'],
        ['CHAIR-A-01', 10, '京都迎賓館管理委員会',         'VIP客室用特注チェア',        -10,  5,   'urgent', 'in_progress'],
        ['CHAIR-A',    35, '神戸ポートホテル',             'レストランリニューアル',     -12,  10,  'high',   'in_progress'],
        ['CHAIR-A',    28, '有限会社さくら製作所',         '事務所用チェア',             -8,   28,  'normal', 'in_progress'],
        // 計画中
        ['CHAIR-A',    40, '株式会社名阪観光',             'リゾート施設用チェア',       -5,   42,  'normal', 'planned'],
        ['CHAIR-A',    15, '東洋電機株式会社',             '工場事務所用チェア',         -4,   35,  'normal', 'planned'],
        ['CHAIR-A-01', 25, '株式会社大手チェーン',         '全国店舗用チェア',           -3,   49,  'high',   'planned'],
        ['CHAIR-A',    45, '神戸市立中央図書館',           '閲覧室用チェア',             -2,   60,  'normal', 'planned'],
        // キャンセル
        ['CHAIR-A',     5, 'テスト株式会社',               'キャンセル注文',             -60, -40,  'normal', 'cancelled'],
    ];

    $year    = date('Y');
    $created = [];

    foreach ($defs as $i => $d) {
        [$ctCode, $qty, $customer, $project, $orderOfs, $dueOfs, $priority, $finalStatus] = $d;
        $chairTypeId = $ct[$ctCode] ?? $ct['CHAIR-A'] ?? 1;
        $orderNo     = sprintf('WO-%s-%04d', $year, $i + 1);
        $orderDate   = date('Y-m-d', strtotime("{$orderOfs} days"));
        $dueDate     = date('Y-m-d', strtotime("{$dueOfs} days"));

        $stmt = $db->prepare(
            "INSERT INTO manufacturing_orders
                (order_no, customer_name, project_name, order_date,
                 chair_type_id, quantity, due_date, priority, status, memo, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $orderNo, $customer, $project, $orderDate,
            $chairTypeId, $qty, $dueDate, $priority, $finalStatus, '', $userId,
        ]);
        $orderId = (int)$db->lastInsertId();

        // 完成済み注文は過去の日付を updated_at に設定（月別推移グラフ用）
        if ($finalStatus === 'completed') {
            $completionTs = strtotime($dueDate . ' +1 day');
            $db->prepare(
                "UPDATE manufacturing_orders SET updated_at=? WHERE id=?"
            )->execute([date('Y-m-d H:i:s', $completionTs), $orderId]);
        }

        // キャンセル以外は工程レコードを作成
        if ($finalStatus !== 'cancelled') {
            $times = calcDemoTimes($qty);
            foreach ($times as $t) {
                $pid = $procMap[$t['code']] ?? null;
                if (!$pid) continue;
                $db->prepare(
                    "INSERT INTO manufacturing_order_processes
                        (manufacturing_order_id, process_id, process_sequence,
                         planned_setup_minutes, planned_work_minutes,
                         planned_total_minutes, assigned_worker_count)
                     VALUES (?,?,?,?,?,?,2)"
                )->execute([
                    $orderId, $pid, $t['sequence'],
                    $t['setup'], $t['net'], $t['total'],
                ]);
            }
        }

        $created[] = [
            'id'         => $orderId,
            'status'     => $finalStatus,
            'qty'        => $qty,
            'due_offset' => $dueOfs,
            'ord_offset' => $orderOfs,
        ];
    }

    // ===== 完了注文：全工程完了 + work_logs =====
    foreach ($created as $o) {
        if ($o['status'] !== 'completed') continue;

        $procs = dbFetchAll(
            "SELECT id, process_id, planned_total_minutes, process_sequence
             FROM manufacturing_order_processes
             WHERE manufacturing_order_id=? ORDER BY process_sequence",
            [$o['id']]
        );
        if (!$procs) continue;

        $completionTs = strtotime(date('Y-m-d', strtotime("{$o['due_offset']} days")));
        $processTs    = $completionTs - count($procs) * 86400;

        foreach ($procs as $pi => $p) {
            $plannedMin  = max(30.0, (float)$p['planned_total_minutes']);
            $ratio       = 0.82 + (rand(0, 30) / 100.0);
            $actualMin   = (int)round($plannedMin * $ratio);
            $delayMin    = max(0, $actualMin - (int)$plannedMin);
            $delayStatus = $delayMin > 60 ? 'critical' : ($delayMin > 20 ? 'delayed' : 'normal');

            $startedAt = date('Y-m-d H:i:s', $processTs);
            $endedAt   = date('Y-m-d H:i:s', $processTs + $actualMin * 60);

            $db->prepare(
                "UPDATE manufacturing_order_processes SET
                    status='completed', progress_rate=100,
                    actual_minutes=?, actual_start=?, actual_end=?,
                    completed_qty=?, delay_minutes=?, delay_status=?
                 WHERE id=?"
            )->execute([$actualMin, $startedAt, $endedAt,
                        $o['qty'], $delayMin, $delayStatus, $p['id']]);

            // 作業者2名 work_log
            [$w1, $w2] = demoWorkers($workerIds, $pi);
            foreach ([$w1, $w2] as $wi => $empId) {
                $wStart = $processTs + $wi * 300;
                $wEnd   = $wStart + $actualMin * 60;
                $db->prepare(
                    "INSERT INTO work_logs
                        (manufacturing_order_id, process_id, employee_id,
                         started_at, ended_at, actual_minutes, completed_qty, memo)
                     VALUES (?,?,?,?,?,?,?,?)"
                )->execute([
                    $o['id'], $p['process_id'], $empId,
                    date('Y-m-d H:i:s', $wStart),
                    date('Y-m-d H:i:s', $wEnd),
                    $actualMin, $o['qty'],
                    $wi === 0 ? '' : '補助作業',
                ]);
            }
            $processTs += $actualMin * 60 + 3600;
        }
    }

    // ===== 仕掛中注文：途中まで完了 + 1工程が作業中 =====
    $ipDoneCount = [4, 3, 5, 3, 1];
    $ipIdx = 0;

    foreach ($created as $o) {
        if ($o['status'] !== 'in_progress') continue;

        $procs = dbFetchAll(
            "SELECT id, process_id, planned_total_minutes, process_sequence
             FROM manufacturing_order_processes
             WHERE manufacturing_order_id=? ORDER BY process_sequence",
            [$o['id']]
        );
        if (!$procs) { $ipIdx++; continue; }

        $doneCount = $ipDoneCount[$ipIdx] ?? 2;
        $processTs = strtotime(date('Y-m-d', strtotime("{$o['ord_offset']} days")) . ' 08:30:00');

        foreach ($procs as $pi => $p) {
            $plannedMin = max(30.0, (float)$p['planned_total_minutes']);

            if ($pi < $doneCount) {
                $ratio       = 0.85 + (rand(0, 25) / 100.0);
                $actualMin   = (int)round($plannedMin * $ratio);
                $delayMin    = max(0, $actualMin - (int)$plannedMin);
                $delayStatus = $delayMin > 60 ? 'critical' : ($delayMin > 20 ? 'delayed' : 'normal');

                $startedAt = date('Y-m-d H:i:s', $processTs);
                $endedAt   = date('Y-m-d H:i:s', $processTs + $actualMin * 60);

                $db->prepare(
                    "UPDATE manufacturing_order_processes SET
                        status='completed', progress_rate=100,
                        actual_minutes=?, actual_start=?, actual_end=?,
                        completed_qty=?, delay_minutes=?, delay_status=?
                     WHERE id=?"
                )->execute([$actualMin, $startedAt, $endedAt,
                            $o['qty'], $delayMin, $delayStatus, $p['id']]);

                [$w1, $w2] = demoWorkers($workerIds, $pi);
                foreach ([$w1, $w2] as $wi => $empId) {
                    $wStart = $processTs + $wi * 180;
                    $wEnd   = $wStart + $actualMin * 60;
                    $db->prepare(
                        "INSERT INTO work_logs
                            (manufacturing_order_id, process_id, employee_id,
                             started_at, ended_at, actual_minutes, completed_qty)
                         VALUES (?,?,?,?,?,?,?)"
                    )->execute([
                        $o['id'], $p['process_id'], $empId,
                        date('Y-m-d H:i:s', $wStart),
                        date('Y-m-d H:i:s', $wEnd),
                        $actualMin, $o['qty'],
                    ]);
                }
                $processTs += $actualMin * 60 + 3600;

            } elseif ($pi === $doneCount) {
                $rate      = 40 + rand(0, 40);
                $elapsed   = (int)round($plannedMin * $rate / 100);
                $startedAt = date('Y-m-d 08:30:00');

                $db->prepare(
                    "UPDATE manufacturing_order_processes SET
                        status='in_progress', progress_rate=?,
                        actual_minutes=?, actual_start=?
                     WHERE id=?"
                )->execute([$rate, $elapsed, $startedAt, $p['id']]);

                $worker = $workerIds[$pi % count($workerIds)];
                $db->prepare(
                    "INSERT INTO work_logs
                        (manufacturing_order_id, process_id, employee_id,
                         started_at, actual_minutes, completed_qty)
                     VALUES (?,?,?,?,?,0)"
                )->execute([$o['id'], $p['process_id'], $worker, $startedAt, $elapsed]);
            }
        }
        $ipIdx++;
    }

    // ===== 今日の作業ログ（ワーカータブ表示用） =====
    $completedOrders = array_values(array_filter($created, fn($o) => $o['status'] === 'completed'));
    $todayTargets    = array_slice($completedOrders, -2);
    foreach ($todayTargets as $ci => $o) {
        $lastProc = dbFetchOne(
            "SELECT id, process_id, planned_total_minutes FROM manufacturing_order_processes
             WHERE manufacturing_order_id=? ORDER BY process_sequence DESC LIMIT 1",
            [$o['id']]
        );
        if (!$lastProc) continue;
        $plannedMin = max(30.0, (float)$lastProc['planned_total_minutes']);
        $actualMin  = (int)round($plannedMin * 0.9);
        $empId      = $workerIds[$ci % count($workerIds)];
        $tStart     = date('Y-m-d 09:00:00');
        $tEnd       = date('Y-m-d H:i:s', strtotime($tStart) + $actualMin * 60);
        $db->prepare(
            "INSERT INTO work_logs
                (manufacturing_order_id, process_id, employee_id,
                 started_at, ended_at, actual_minutes, completed_qty, memo)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $o['id'], $lastProc['process_id'], $empId,
            $tStart, $tEnd, $actualMin, $o['qty'], '最終確認',
        ]);
    }

    // ===== コスト設定デモ値 =====
    $demoSettings = [
        'monthly_salary_total'      => '2800000',
        'monthly_overhead_cost'     => '1200000',
        'monthly_production_target' => '50',
        'cost_target_month'         => date('Y-m'),
        'demo_mode'                 => '1',
    ];
    foreach ($demoSettings as $key => $val) {
        $db->prepare(
            "INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                                     updated_by_user_id=VALUES(updated_by_user_id),
                                     updated_at=NOW()"
        )->execute([$key, $val, $userId]);
    }

    auditLog('demo_loaded', 'system_settings', null, null,
        ['orders_created' => count($created)]);
}

function demoWorkers(array $workerIds, int $idx): array {
    $n  = count($workerIds);
    $w1 = $workerIds[$idx % $n];
    $w2 = $workerIds[($idx + 1) % $n];
    if ($w1 === $w2 && $n > 1) {
        $w2 = $workerIds[($idx + 2) % $n];
    }
    return [$w1, $w2];
}

// =====================================================
// 表示
// =====================================================
$demoMode = '0';
try {
    $demoMode = dbFetchOne(
        "SELECT setting_value FROM system_settings WHERE setting_key='demo_mode'"
    )['setting_value'] ?? '0';
} catch (Exception $e) {}

$orderCounts = ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'planned' => 0];
try {
    $rows = dbFetchAll("SELECT status, COUNT(*) AS cnt FROM manufacturing_orders GROUP BY status");
    foreach ($rows as $r) {
        $orderCounts['total'] += $r['cnt'];
        if (isset($orderCounts[$r['status']])) {
            $orderCounts[$r['status']] = $r['cnt'];
        }
    }
} catch (Exception $e) {}

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col">
    <h2><i class="bi bi-database-gear"></i> デモデータ管理</h2>
    <p class="text-muted mb-0">プレゼン・動作確認用のサンプルデータを一括投入します。</p>
  </div>
</div>

<?= getFlashHtml() ?>

<div class="alert <?= $demoMode === '1' ? 'alert-warning' : 'alert-secondary' ?> d-flex align-items-center gap-3 mb-4">
  <i class="bi <?= $demoMode === '1' ? 'bi-play-circle-fill' : 'bi-stop-circle' ?> fs-4"></i>
  <div class="flex-grow-1">
    <strong>デモモード: <?= $demoMode === '1' ? 'ON' : 'OFF' ?></strong>
    &nbsp;|&nbsp;
    製造指示: 全<?= $orderCounts['total'] ?>件
    （完成<strong class="text-success"><?= $orderCounts['completed'] ?></strong>
     ・仕掛中<strong class="text-primary"><?= $orderCounts['in_progress'] ?></strong>
     ・計画<strong class="text-info"><?= $orderCounts['planned'] ?></strong>）
  </div>
</div>

<div class="card border-warning mb-4">
  <div class="card-header bg-warning text-dark fw-bold">
    <i class="bi bi-exclamation-triangle"></i> 注意事項
  </div>
  <div class="card-body">
    <ul class="mb-0">
      <li>「デモデータ読み込み」を実行すると、<strong>既存の製造指示・作業実績データはすべて削除</strong>されます。</li>
      <li>椅子タイプ・社員・ユーザーなどのマスターデータは変更されません。</li>
      <li>コスト設定にサンプル値（給与¥2,800,000 / 管理費¥1,200,000 / 目標50本）が設定されます。</li>
      <li>デモモードが <strong>ON</strong> になり、全ページに警告バナーが表示されます。</li>
    </ul>
  </div>
</div>

<div class="row g-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header fw-bold bg-warning text-dark">
        <i class="bi bi-database-fill-up"></i> デモデータ読み込み
      </div>
      <div class="card-body">
        <p class="text-muted">以下のサンプルデータを作成します：</p>
        <ul class="small mb-3">
          <li>完成済み受注: <strong>10件</strong>（過去3ヶ月〜今月・月別推移グラフに反映）</li>
          <li>仕掛中: <strong>5件</strong>（工程途中まで完了・遅延アラートあり）</li>
          <li>計画中: <strong>4件</strong>（未着手）</li>
          <li>キャンセル: <strong>1件</strong></li>
          <li>今日の作業ログあり → 稼働状況・本日実績に表示</li>
        </ul>
        <form method="post" action=""
              onsubmit="return confirm('既存の製造データをすべて削除してデモデータを読み込みます。よろしいですか？');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="load_demo">
          <button type="submit" class="btn btn-warning btn-lg w-100">
            <i class="bi bi-database-fill-up"></i> デモデータを読み込む
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header fw-bold">
        <i class="bi bi-trash3"></i> データクリア
      </div>
      <div class="card-body">
        <p class="text-muted">製造指示・作業実績データをすべて削除します。<br>
          マスターデータは保持されます。</p>
        <form method="post" action=""
              onsubmit="return confirm('製造データをすべて削除します。よろしいですか？');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="clear_demo">
          <button type="submit" class="btn btn-outline-danger btn-lg w-100">
            <i class="bi bi-trash3"></i> 製造データをクリア
          </button>
        </form>
        <div class="mt-3">
          <a href="<?= APP_URL ?>/admin_settings.php#demo"
             class="btn btn-outline-secondary w-100">
            <i class="bi bi-arrow-left"></i> 設定画面に戻る
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
