<?php
// =====================================================
// デモデータ管理
// 製造指示サンプルデータの読み込み・クリア
// 社長・admin のみアクセス可
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/order_service.php';
require_once __DIR__ . '/../app/standard_time_service.php';

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
            setFlash('デモデータの読み込み中にエラーが発生しました: ' . $e->getMessage(), 'danger');
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
// デモデータ読み込み
// =====================================================
function loadDemoData(int $userId): void {
    srand(42); // 再現性のある疑似乱数

    // まず既存の製造データをクリア
    clearMfgData();

    // IDマップを取得
    $ctRows = dbFetchAll("SELECT id, chair_type_code FROM chair_types");
    $ct     = array_column($ctRows, 'id', 'chair_type_code');

    $empRows = dbFetchAll(
        "SELECT id, employee_code FROM employees WHERE employment_status='active' ORDER BY id"
    );
    $workerIds = array_values(array_column($empRows, 'id'));
    if (empty($workerIds)) $workerIds = [1];

    // セッション user_id を設定（createOrder が参照）
    $_SESSION['user_id'] = $userId;

    // =====================================================
    // 注文定義
    // [chair_code, qty, customer, project, order_offset, due_offset, priority, final_status]
    // =====================================================
    $defs = [
        // 完成済み（3ヶ月前）
        ['CHAIR-A',    20, 'ホテルリゾート大阪株式会社',   '客室チェア全面入替',         -100, -70, 'normal', 'completed'],
        ['CHAIR-A',    15, '学校法人光陵学園',             '音楽室用チェア整備',         -95,  -63, 'normal', 'completed'],
        // 完成済み（2ヶ月前）
        ['CHAIR-A',    25, '株式会社テクノメディア',       'オフィス移転プロジェクト',   -78,  -50, 'high',   'completed'],
        ['CHAIR-A-01', 18, '有限会社みなと食堂',           '店舗用ダイニングチェア',     -72,  -48, 'normal', 'completed'],
        ['CHAIR-A',    20, '医療法人桜会クリニック',       '待合室用チェア',             -60,  -38, 'normal', 'completed'],
        // 完成済み（1ヶ月前）
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

    $created = [];
    foreach ($defs as $d) {
        [$ctCode, $qty, $customer, $project, $orderOfs, $dueOfs, $priority, $finalStatus] = $d;
        $chairTypeId = $ct[$ctCode] ?? $ct['CHAIR-A'] ?? 1;

        $orderId = createOrder([
            'chair_type_id' => $chairTypeId,
            'quantity'      => $qty,
            'customer_name' => $customer,
            'project_name'  => $project,
            'order_date'    => date('Y-m-d', strtotime("{$orderOfs} days")),
            'due_date'      => date('Y-m-d', strtotime("{$dueOfs} days")),
            'priority'      => $priority,
            'memo'          => '',
        ]);

        $created[] = [
            'id'         => $orderId,
            'status'     => $finalStatus,
            'qty'        => $qty,
            'due_offset' => $dueOfs,
            'ord_offset' => $orderOfs,
        ];
    }

    // ステータス更新（planned 以外）
    foreach ($created as $o) {
        if ($o['status'] !== 'planned') {
            dbExecute(
                "UPDATE manufacturing_orders SET status=? WHERE id=?",
                [$o['status'], $o['id']]
            );
        }
    }

    // キャンセル注文の工程を削除
    foreach ($created as $o) {
        if ($o['status'] === 'cancelled') {
            dbExecute(
                "DELETE FROM manufacturing_order_processes WHERE manufacturing_order_id=?",
                [$o['id']]
            );
        }
    }

    // 完了注文：全工程を完了 + work_logs
    foreach ($created as $o) {
        if ($o['status'] !== 'completed') continue;

        $procs = dbFetchAll(
            "SELECT id, process_id, planned_total_minutes, process_sequence
             FROM manufacturing_order_processes
             WHERE manufacturing_order_id=? ORDER BY process_sequence",
            [$o['id']]
        );
        if (!$procs) continue;

        // 納期日の N 日前から逆算して開始
        $completionTs = strtotime(date('Y-m-d', strtotime("{$o['due_offset']} days")));
        $processTs    = $completionTs - count($procs) * 86400; // 1工程=約1日

        foreach ($procs as $pi => $p) {
            $plannedMin = max(30.0, (float)$p['planned_total_minutes']);
            $ratio      = 0.82 + (rand(0, 30) / 100.0); // 0.82〜1.12
            $actualMin  = (int)round($plannedMin * $ratio);
            $delayMin   = max(0, $actualMin - (int)$plannedMin);

            $startedAt = date('Y-m-d H:i:s', $processTs);
            $endedAt   = date('Y-m-d H:i:s', $processTs + $actualMin * 60);

            dbExecute(
                "UPDATE manufacturing_order_processes SET
                    status='completed', progress_rate=100,
                    actual_minutes=?, actual_start=?, actual_end=?,
                    completed_qty=?, delay_minutes=?
                 WHERE id=?",
                [$actualMin, $startedAt, $endedAt, $o['qty'], $delayMin, $p['id']]
            );

            // 作業者2名分の work_log
            [$w1, $w2] = demoWorkers($workerIds, $pi);
            foreach ([$w1, $w2] as $wi => $empId) {
                $wStart = $processTs + $wi * 300; // 5分ずらし
                $wEnd   = $wStart + $actualMin * 60;
                dbExecute(
                    "INSERT INTO work_logs
                        (manufacturing_order_id, process_id, employee_id,
                         started_at, ended_at, actual_minutes, completed_qty, memo)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [$o['id'], $p['process_id'], $empId,
                     date('Y-m-d H:i:s', $wStart),
                     date('Y-m-d H:i:s', $wEnd),
                     $actualMin, $o['qty'],
                     $wi === 0 ? '' : '補助作業']
                );
            }

            $processTs += $actualMin * 60 + 3600; // 1時間休憩後に次工程
        }
    }

    // 仕掛中注文：途中まで完了 + 1工程が作業中
    // 各注文の「完了済み工程数」の設定
    $ipDoneCount = [4, 3, 5, 3, 1]; // completed工程数（CHAIR-A=7工程）
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
                // 完了済み工程
                $ratio     = 0.85 + (rand(0, 25) / 100.0);
                $actualMin = (int)round($plannedMin * $ratio);
                $startedAt = date('Y-m-d H:i:s', $processTs);
                $endedAt   = date('Y-m-d H:i:s', $processTs + $actualMin * 60);

                dbExecute(
                    "UPDATE manufacturing_order_processes SET
                        status='completed', progress_rate=100,
                        actual_minutes=?, actual_start=?, actual_end=?,
                        completed_qty=?, delay_minutes=GREATEST(0, ?-?)
                     WHERE id=?",
                    [$actualMin, $startedAt, $endedAt, $o['qty'],
                     $actualMin, (int)$plannedMin, $p['id']]
                );

                [$w1, $w2] = demoWorkers($workerIds, $pi);
                foreach ([$w1, $w2] as $wi => $empId) {
                    $wStart = $processTs + $wi * 180;
                    $wEnd   = $wStart + $actualMin * 60;
                    dbExecute(
                        "INSERT INTO work_logs
                            (manufacturing_order_id, process_id, employee_id,
                             started_at, ended_at, actual_minutes, completed_qty)
                         VALUES (?,?,?,?,?,?,?)",
                        [$o['id'], $p['process_id'], $empId,
                         date('Y-m-d H:i:s', $wStart),
                         date('Y-m-d H:i:s', $wEnd),
                         $actualMin, $o['qty']]
                    );
                }

                $processTs += $actualMin * 60 + 3600;

            } elseif ($pi === $doneCount) {
                // 現在作業中の工程（ended_at=NULL）
                $rate      = 40 + rand(0, 40);
                $elapsed   = (int)round($plannedMin * $rate / 100);
                $startedAt = date('Y-m-d H:i:s', strtotime('today 08:30:00'));

                dbExecute(
                    "UPDATE manufacturing_order_processes SET
                        status='in_progress', progress_rate=?,
                        actual_minutes=?, actual_start=?
                     WHERE id=?",
                    [$rate, $elapsed, $startedAt, $p['id']]
                );

                $worker = $workerIds[$pi % count($workerIds)];
                dbExecute(
                    "INSERT INTO work_logs
                        (manufacturing_order_id, process_id, employee_id,
                         started_at, actual_minutes, completed_qty)
                     VALUES (?,?,?,?,?,0)",
                    [$o['id'], $p['process_id'], $worker, $startedAt, $elapsed]
                );
            }
            // 未着手工程は pending のまま
        }
        $ipIdx++;
    }

    // コスト設定のデモ値を設定
    $demoSettings = [
        'monthly_salary_total'      => '2800000',
        'monthly_overhead_cost'     => '1200000',
        'monthly_production_target' => '50',
        'cost_target_month'         => date('Y-m'),
        'demo_mode'                 => '1',
    ];
    foreach ($demoSettings as $key => $val) {
        dbExecute(
            "INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                                     updated_by_user_id=VALUES(updated_by_user_id),
                                     updated_at=NOW()",
            [$key, $val, $userId]
        );
    }

    auditLog('demo_loaded', 'system_settings', null, null,
        ['orders_created' => count($created)]);
}

// =====================================================
// 製造データを全削除（マスターデータは保持）
// =====================================================
function clearMfgData(): void {
    $db = getDB();
    $db->exec("DELETE FROM work_logs");
    $db->exec("DELETE FROM manufacturing_order_processes");
    $db->exec("DELETE FROM manufacturing_orders");
}

// 工程インデックスに応じて作業者2名を返す（ラウンドロビン）
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
// 現在のデモモード
$demoMode = '0';
try {
    $demoMode = dbFetchOne(
        "SELECT setting_value FROM system_settings WHERE setting_key='demo_mode'"
    )['setting_value'] ?? '0';
} catch (Exception $e) {}

// 製造注文数
$orderCount = 0;
try {
    $orderCount = (int)(dbFetchOne("SELECT COUNT(*) AS cnt FROM manufacturing_orders")['cnt'] ?? 0);
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

<!-- 現在の状態 -->
<div class="alert <?= $demoMode === '1' ? 'alert-warning' : 'alert-secondary' ?> d-flex align-items-center mb-4">
  <i class="bi <?= $demoMode === '1' ? 'bi-play-circle-fill' : 'bi-stop-circle' ?> fs-4 me-3"></i>
  <div>
    <strong>デモモード: <?= $demoMode === '1' ? 'ON' : 'OFF' ?></strong>
    &nbsp;|&nbsp;
    現在の製造指示件数: <strong><?= $orderCount ?>件</strong>
  </div>
</div>

<!-- 注意事項 -->
<div class="card border-warning mb-4">
  <div class="card-header bg-warning text-dark fw-bold">
    <i class="bi bi-exclamation-triangle"></i> 注意事項
  </div>
  <div class="card-body">
    <ul class="mb-0">
      <li>「デモデータ読み込み」を実行すると、<strong>既存の製造指示・作業実績データはすべて削除</strong>されます。</li>
      <li>社員・ユーザー・椅子タイプなどのマスターデータは削除されません。</li>
      <li>コスト設定（給与総額・管理費・生産目標）にサンプル値が設定されます。</li>
      <li>デモモード ON 中は全ページの上部に警告バナーが表示されます。</li>
    </ul>
  </div>
</div>

<div class="row g-4">
  <!-- デモデータ読み込み -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header fw-bold bg-warning text-dark">
        <i class="bi bi-database-fill-up"></i> デモデータ読み込み
      </div>
      <div class="card-body">
        <p class="text-muted">以下のサンプルデータを作成します：</p>
        <ul class="small">
          <li>完成済み受注: <strong>10件</strong>（過去3ヶ月〜今月）</li>
          <li>仕掛中: <strong>5件</strong>（工程途中まで完了）</li>
          <li>計画中: <strong>4件</strong>（未着手）</li>
          <li>キャンセル: <strong>1件</strong></li>
          <li>コスト設定: 給与¥2,800,000 / 管理費¥1,200,000 / 目標50本</li>
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

  <!-- データクリア -->
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
