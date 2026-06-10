<?php
// =====================================================
// システム設定（admin以上のみ）
// - Authenticator必須化の ON/OFF
// - 登録申請待ちユーザーの承認
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('admin');
$pageTitle = 'システム設定';

// =====================================================
// POST 処理
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    // TOTP 必須設定の更新
    if ($postAction === 'update_totp_required') {
        $value = postStr('totp_required') === '1' ? '1' : '0';
        dbExecute(
            "INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
             VALUES ('totp_required', ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     updated_by_user_id = VALUES(updated_by_user_id),
                                     updated_at = NOW()",
            [$value, getCurrentUser()['id']]
        );
        auditLog('update_setting', 'system_settings', null, null, ['totp_required' => $value]);
        setFlash('Authenticator設定を' . ($value === '1' ? '必須' : '任意') . 'に変更しました。');
        header('Location: ' . APP_URL . '/admin_settings.php');
        exit;
    }

    // 申請待ちユーザーを承認（有効化）
    if ($postAction === 'approve_user') {
        $userId = postInt('user_id');
        if ($userId) {
            dbExecute("UPDATE users SET is_active = 1 WHERE id = ? AND is_active = 0", [$userId]);
            auditLog('approve_user', 'users', $userId, ['is_active' => 0], ['is_active' => 1]);
            setFlash('ユーザーを承認しました。');
        }
        header('Location: ' . APP_URL . '/admin_settings.php');
        exit;
    }

    // 申請を却下（ユーザー・社員レコードを削除）
    if ($postAction === 'reject_user') {
        $userId = postInt('user_id');
        if ($userId) {
            $user = dbFetchOne("SELECT employee_id FROM users WHERE id = ? AND is_active = 0", [$userId]);
            if ($user) {
                $pdo = getDB();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM users     WHERE id = ?")->execute([$userId]);
                    $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$user['employee_id']]);
                    $pdo->commit();
                    auditLog('reject_user', 'users', $userId);
                    setFlash('登録申請を却下しました。', 'warning');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    setFlash('削除処理中にエラーが発生しました。', 'danger');
                }
            }
        }
        header('Location: ' . APP_URL . '/admin_settings.php');
        exit;
    }

    // デモモード切替（社長・admin のみ）
    if ($postAction === 'toggle_demo' && isPresidentOrAdmin()) {
        $val = postStr('demo_mode') === '1' ? '1' : '0';
        dbExecute(
            "INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
             VALUES ('demo_mode', ?, ?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                                     updated_by_user_id=VALUES(updated_by_user_id),
                                     updated_at=NOW()",
            [$val, getCurrentUser()['id']]
        );
        auditLog('update_setting', 'system_settings', null, null, ['demo_mode' => $val]);
        setFlash('デモモードを' . ($val === '1' ? 'ON' : 'OFF') . 'にしました。');
        header('Location: ' . APP_URL . '/admin_settings.php#demo');
        exit;
    }

    // コスト設定の更新（社長・admin のみ）
    if ($postAction === 'update_costs' && isPresidentOrAdmin()) {
        $costMonth    = postStr('cost_target_month');
        $salaryTotal  = (int)str_replace([',','，'], '', postStr('monthly_salary_total'));
        $overheadCost = (int)str_replace([',','，'], '', postStr('monthly_overhead_cost'));
        $userId = getCurrentUser()['id'];

        $productionTarget = (int)str_replace([',','，'], '', postStr('monthly_production_target'));
        foreach ([
            'cost_target_month'         => $costMonth,
            'monthly_salary_total'      => (string)$salaryTotal,
            'monthly_overhead_cost'     => (string)$overheadCost,
            'monthly_production_target' => (string)$productionTarget,
        ] as $key => $val) {
            dbExecute(
                "INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                                         updated_by_user_id=VALUES(updated_by_user_id),
                                         updated_at=NOW()",
                [$key, $val, $userId]
            );
        }
        auditLog('update_setting', 'system_settings', null, null,
            ['cost_target_month' => $costMonth, 'salary' => $salaryTotal, 'overhead' => $overheadCost]);
        setFlash('コスト設定を保存しました。');
        header('Location: ' . APP_URL . '/admin_settings.php#cost');
        exit;
    }

    // 月次予算インポート（Excel → JSON）
    if ($postAction === 'import_budget' && isPresidentOrAdmin()) {
        $rows = json_decode(postStr('budget_json') ?: '[]', true);
        $uid  = getCurrentUser()['id'];
        $saved = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $ym = trim($row['year_month'] ?? '');
                // YYYY/MM → YYYY-MM、YYYY年M月 → YYYY-MM
                $ym = preg_replace('/[\/年]/', '-', $ym);
                $ym = preg_replace('/月$/', '', $ym);
                // ゼロ埋め: 2024-1 → 2024-01
                if (preg_match('/^(\d{4})-(\d{1,2})$/', $ym, $m)) {
                    $ym = $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
                }
                if (!preg_match('/^\d{4}-\d{2}$/', $ym)) continue;
                $tq  = max(0, (int)preg_replace('/[^\d]/', '', $row['target_qty']       ?? '0'));
                $sal = max(0, (int)preg_replace('/[^\d]/', '', $row['salary_forecast']   ?? '0'));
                $ovh = max(0, (int)preg_replace('/[^\d]/', '', $row['overhead_forecast'] ?? '0'));
                try {
                    dbExecute(
                        "INSERT INTO monthly_budget
                             (`year_month`, target_qty, salary_forecast, overhead_forecast, updated_by_user_id)
                         VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                             target_qty=VALUES(target_qty),
                             salary_forecast=VALUES(salary_forecast),
                             overhead_forecast=VALUES(overhead_forecast),
                             updated_by_user_id=VALUES(updated_by_user_id),
                             updated_at=NOW()",
                        [$ym, $tq, $sal, $ovh, $uid]
                    );
                    $saved++;
                } catch (Exception $e) {}
            }
        }
        setFlash("{$saved}件の予算データを保存しました。");
        header('Location: ' . APP_URL . '/admin_settings.php#budget');
        exit;
    }

    // ダッシュボード表示設定
    if ($postAction === 'save_display' && isPresidentOrAdmin()) {
        $allWidgets = ['daily_chart','monthly_chart','budget_chart','delay_alerts','upcoming_due','dept_status','cost_card','gantt'];
        $on = [];
        foreach ($allWidgets as $w) {
            $on[$w] = isset($_POST['w_' . $w]) ? 1 : 0;
        }
        dbExecute(
            "INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
             VALUES ('dashboard_widgets', ?, ?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                 updated_by_user_id=VALUES(updated_by_user_id), updated_at=NOW()",
            [json_encode($on), getCurrentUser()['id']]
        );
        setFlash('表示設定を保存しました。');
        header('Location: ' . APP_URL . '/admin_settings.php#display');
        exit;
    }
}

// 現在の設定値
$totpRequired = isTotpRequired();

// コスト設定（社長・admin のみ読み込む）
$costSettings = [];
if (isPresidentOrAdmin()) {
    try {
        $rows = dbFetchAll(
            "SELECT setting_key, setting_value FROM system_settings
             WHERE setting_key IN (
                 'cost_target_month','monthly_salary_total','monthly_overhead_cost',
                 'monthly_production_target'
             )"
        );
        foreach ($rows as $r) {
            $costSettings[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Exception $e) {}
}

// 月次予算データ（過去2年）
$monthlyBudgets = [];
if (isPresidentOrAdmin()) {
    try {
        $monthlyBudgets = dbFetchAll(
            "SELECT mb.year_month, mb.target_qty, mb.salary_forecast, mb.overhead_forecast,
                    (mb.salary_forecast + mb.overhead_forecast) AS total_budget,
                    COALESCE((SELECT SUM(quantity) FROM manufacturing_orders mo
                               WHERE mo.status='completed'
                                 AND DATE_FORMAT(mo.updated_at,'%Y-%m')=mb.year_month), 0) AS actual_qty
             FROM monthly_budget mb
             ORDER BY mb.year_month DESC LIMIT 24"
        );
    } catch (Exception $e) {}
}

// ダッシュボード表示設定
$displayWidgets = [];
try {
    $dw = dbFetchOne("SELECT setting_value FROM system_settings WHERE setting_key='dashboard_widgets'")['setting_value'] ?? '';
    $displayWidgets = $dw ? (json_decode($dw, true) ?? []) : [];
} catch (Exception $e) {}

// 承認待ちユーザー一覧（is_active=0）
$pendingUsers = dbFetchAll(
    "SELECT u.id, u.login_id, u.role, u.created_at,
            e.name AS emp_name, d.dept_name
     FROM users u
     JOIN employees e ON u.employee_id = e.id
     LEFT JOIN departments d ON e.department_id = d.id
     WHERE u.is_active = 0
     ORDER BY u.created_at DESC"
);

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col"><h2><i class="bi bi-sliders"></i> システム設定</h2></div>
</div>

<?= getFlashHtml() ?>

<!-- ===== Authenticator 必須設定 ===== -->
<div class="card mb-4">
  <div class="card-header fw-bold">
    <i class="bi bi-shield-lock"></i> Authenticator（二段階認証）設定
  </div>
  <div class="card-body">
    <div class="row align-items-center">
      <div class="col-md-8">
        <p class="mb-1">
          <strong>現在の設定:</strong>
          <?php if ($totpRequired): ?>
            <span class="badge bg-danger fs-6"><i class="bi bi-shield-check"></i> Authenticator 必須</span>
          <?php else: ?>
            <span class="badge bg-secondary fs-6"><i class="bi bi-shield"></i> Authenticator 任意</span>
          <?php endif; ?>
        </p>
        <p class="text-muted small mb-0">
          「必須」にすると、すべてのユーザーはログイン時に Authenticator の認証コードを入力する必要があります。<br>
          未設定のユーザーはログイン後すぐに設定ページへ誘導されます。
        </p>
      </div>
      <div class="col-md-4 text-end">
        <form method="post" action=""
              onsubmit="return confirm('Authenticator 設定を変更します。よろしいですか？');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_totp_required">
          <input type="hidden" name="totp_required" value="<?= $totpRequired ? '0' : '1' ?>">
          <button type="submit"
                  class="btn <?= $totpRequired ? 'btn-outline-secondary' : 'btn-danger' ?> btn-lg">
            <?php if ($totpRequired): ?>
              <i class="bi bi-shield-slash"></i> 任意に変更
            <?php else: ?>
              <i class="bi bi-shield-lock"></i> 必須に設定
            <?php endif; ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ===== 登録申請待ちユーザー ===== -->
<div class="card">
  <div class="card-header fw-bold">
    <i class="bi bi-person-check"></i> 登録申請待ちユーザー
    <?php if ($pendingUsers): ?>
      <span class="badge bg-warning text-dark ms-2"><?= count($pendingUsers) ?></span>
    <?php endif; ?>
  </div>
  <div class="card-body <?= !$pendingUsers ? 'p-3' : 'p-0' ?>">
    <?php if (!$pendingUsers): ?>
      <p class="text-muted mb-0"><i class="bi bi-check-circle"></i> 承認待ちのユーザーはいません。</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
          <thead class="table-warning">
            <tr>
              <th>ログインID</th>
              <th>氏名</th>
              <th>所属</th>
              <th>申請日時</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($pendingUsers as $u): ?>
            <tr>
              <td><strong><?= h($u['login_id']) ?></strong></td>
              <td><?= h($u['emp_name']) ?></td>
              <td><?= h($u['dept_name'] ?? '―') ?></td>
              <td><small><?= formatDatetime($u['created_at']) ?></small></td>
              <td>
                <!-- 承認 -->
                <form method="post" action="" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="approve_user">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-check-lg"></i> 承認
                  </button>
                </form>
                <!-- 却下 -->
                <form method="post" action="" class="d-inline ms-1"
                      onsubmit="return confirm('この申請を却下（削除）します。よろしいですか？');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="reject_user">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-x-lg"></i> 却下
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (isPresidentOrAdmin()): ?>
<!-- ===== コスト設定 ===== -->
<div class="card mt-4" id="cost">
  <div class="card-header fw-bold">
    <i class="bi bi-currency-yen"></i> コスト設定
    <span class="badge bg-danger ms-2">社長・Admin 限定</span>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_costs">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">計算対象月</label>
          <input type="month" name="cost_target_month" class="form-control"
                 value="<?= h($costSettings['cost_target_month'] ?? date('Y-m')) ?>">
          <div class="form-text">空白 = 当月</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">月間生産目標本数</label>
          <div class="input-group">
            <input type="number" name="monthly_production_target" class="form-control"
                   value="<?= h($costSettings['monthly_production_target'] ?? '0') ?>"
                   min="0" step="1">
            <span class="input-group-text">本</span>
          </div>
          <div class="form-text">0 = 自動（受注数）</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">月間給与総額（円）</label>
          <div class="input-group">
            <span class="input-group-text">¥</span>
            <input type="number" name="monthly_salary_total" class="form-control"
                   value="<?= h($costSettings['monthly_salary_total'] ?? '0') ?>"
                   min="0" step="1000">
          </div>
          <div class="form-text">全社員の給与・賞与合計</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">月間管理費（円）</label>
          <div class="input-group">
            <span class="input-group-text">¥</span>
            <input type="number" name="monthly_overhead_cost" class="form-control"
                   value="<?= h($costSettings['monthly_overhead_cost'] ?? '0') ?>"
                   min="0" step="1000">
          </div>
          <div class="form-text">光熱費・家賃・設備費など</div>
        </div>
      </div>
      <div class="mt-3">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> コスト設定を保存
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (isPresidentOrAdmin()): ?>
<!-- ===== デモモード設定 ===== -->
<?php
$demoMode = '0';
try {
    $demoMode = dbFetchOne(
        "SELECT setting_value FROM system_settings WHERE setting_key='demo_mode'"
    )['setting_value'] ?? '0';
} catch (Exception $e) {}
?>
<div class="card mt-4" id="demo">
  <div class="card-header fw-bold">
    <i class="bi bi-play-circle"></i> デモモード設定
    <span class="badge bg-danger ms-2">社長・Admin 限定</span>
    <?php if ($demoMode === '1'): ?>
      <span class="badge bg-warning text-dark ms-1">現在 ON</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="row align-items-start g-3">
      <div class="col-md-7">
        <p class="mb-2">
          デモモードを <strong>ON</strong> にすると、全ページの上部に
          「デモモード稼働中」バナーが表示されます。<br>
          プレゼンや動作確認の際に利用してください。
        </p>
        <p class="text-muted small mb-0">
          ※ デモデータの読み込み時に自動的に ON になります。
        </p>
      </div>
      <div class="col-md-3">
        <form method="post" action="">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_demo">
          <input type="hidden" name="demo_mode" value="<?= $demoMode === '1' ? '0' : '1' ?>">
          <button type="submit"
                  class="btn <?= $demoMode === '1' ? 'btn-outline-secondary' : 'btn-warning' ?> w-100">
            <?php if ($demoMode === '1'): ?>
              <i class="bi bi-stop-circle"></i> デモモードを OFF にする
            <?php else: ?>
              <i class="bi bi-play-circle"></i> デモモードを ON にする
            <?php endif; ?>
          </button>
        </form>
      </div>
      <div class="col-md-2">
        <a href="<?= APP_URL ?>/demo_load.php" class="btn btn-outline-warning w-100">
          <i class="bi bi-database-gear"></i> デモデータ管理
        </a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (isPresidentOrAdmin()): ?>
<!-- ===== 月次予算設定 ===== -->
<div class="card mt-4" id="budget">
  <div class="card-header fw-bold">
    <i class="bi bi-table"></i> 月次予算設定
    <span class="badge bg-danger ms-2">社長・Admin 限定</span>
  </div>
  <div class="card-body">

    <!-- Excel インポート -->
    <h6 class="fw-bold mb-2"><i class="bi bi-file-earmark-excel text-success"></i> Excelファイルから取り込み</h6>
    <p class="text-muted small mb-2">
      Excel ファイルを選択してください。<br>
      <strong>A列=年月（YYYY-MM / YYYY/MM / YYYY年M月）, B列=目標本数, C列=給与予測（円）, D列=管理費予測（円）</strong><br>
      1行目がヘッダーの場合は自動的にスキップされます。
    </p>

    <div class="mb-3">
      <input type="file" id="budgetFileInput" accept=".xlsx,.xls,.csv" class="form-control" style="max-width:400px">
    </div>

    <!-- プレビューテーブル -->
    <div id="budgetPreview" class="d-none mb-3">
      <p class="fw-bold small mb-1">プレビュー（<span id="budgetRowCount">0</span>件）</p>
      <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0" style="font-size:.85rem">
          <thead class="table-light">
            <tr><th>年月</th><th>目標本数</th><th>給与予測</th><th>管理費予測</th><th>合計予算</th></tr>
          </thead>
          <tbody id="budgetPreviewBody"></tbody>
        </table>
      </div>
    </div>

    <!-- 取り込みフォーム -->
    <form method="post" id="budgetImportForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="import_budget">
      <input type="hidden" name="budget_json" id="budgetJsonInput" value="[]">
      <button type="submit" id="budgetImportBtn" class="btn btn-success d-none">
        <i class="bi bi-cloud-upload"></i> データを取り込む
      </button>
    </form>

    <!-- 既存データ表示 -->
    <?php if (!empty($monthlyBudgets)): ?>
    <hr>
    <h6 class="fw-bold mb-2"><i class="bi bi-list-ul"></i> 登録済み予算データ</h6>
    <div class="table-responsive">
      <table class="table table-sm table-hover" style="font-size:.85rem">
        <thead class="table-light">
          <tr>
            <th>年月</th>
            <th class="text-end">目標本数</th>
            <th class="text-end">実績本数</th>
            <th class="text-end">達成率</th>
            <th class="text-end">給与予測</th>
            <th class="text-end">管理費予測</th>
            <th class="text-end">合計予算</th>
            <th class="text-end">予算単価/本</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($monthlyBudgets as $mb):
          $ach = $mb['target_qty'] > 0 ? round($mb['actual_qty'] / $mb['target_qty'] * 100) : null;
          $unitBudget = $mb['target_qty'] > 0 ? (int)($mb['total_budget'] / $mb['target_qty']) : null;
        ?>
          <tr>
            <td class="fw-bold"><?= h($mb['year_month']) ?></td>
            <td class="text-end"><?= number_format($mb['target_qty']) ?>本</td>
            <td class="text-end <?= $mb['actual_qty'] >= $mb['target_qty'] ? 'text-success fw-bold' : '' ?>">
              <?= number_format($mb['actual_qty']) ?>本
            </td>
            <td class="text-end">
              <?php if ($ach !== null): ?>
                <span class="badge bg-<?= $ach >= 100 ? 'success' : ($ach >= 80 ? 'warning text-dark' : 'danger') ?>">
                  <?= $ach ?>%
                </span>
              <?php else: ?><span class="text-muted">―</span><?php endif; ?>
            </td>
            <td class="text-end text-muted">¥<?= number_format($mb['salary_forecast']) ?></td>
            <td class="text-end text-muted">¥<?= number_format($mb['overhead_forecast']) ?></td>
            <td class="text-end fw-bold">¥<?= number_format($mb['total_budget']) ?></td>
            <td class="text-end"><?= $unitBudget ? '¥'.number_format($unitBudget) : '―' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <p class="text-muted small mb-0 mt-3"><i class="bi bi-info-circle"></i> 予算データが登録されていません。Excelファイルから取り込んでください。</p>
    <?php endif; ?>
  </div>
</div>

<!-- ===== ダッシュボード表示設定 ===== -->
<div class="card mt-4" id="display">
  <div class="card-header fw-bold">
    <i class="bi bi-layout-three-columns"></i> ダッシュボード表示設定
    <span class="badge bg-danger ms-2">社長・Admin 限定</span>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">経営ダッシュボードに表示するウィジェットを選択してください。</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_display">
      <?php
      $widgetLabels = [
          'daily_chart'  => ['日別生産グラフ',         'bi-bar-chart'],
          'monthly_chart'=> ['月別推移グラフ',          'bi-graph-up'],
          'budget_chart' => ['予算対比グラフ',          'bi-bar-chart-line'],
          'delay_alerts' => ['遅延アラート',            'bi-exclamation-triangle'],
          'upcoming_due' => ['納期迫る案件',            'bi-calendar-event'],
          'dept_status'  => ['部門別稼働状況',          'bi-people-fill'],
          'cost_card'    => ['コスト管理カード',        'bi-currency-yen'],
          'gantt'        => ['製造スケジュール（ガント）','bi-bar-chart-steps'],
      ];
      ?>
      <div class="row g-2">
      <?php foreach ($widgetLabels as $key => [$label, $icon]): ?>
        <div class="col-md-3 col-sm-4 col-6">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch"
                   id="w_<?= $key ?>" name="w_<?= $key ?>"
                   <?= (!isset($displayWidgets[$key]) || $displayWidgets[$key]) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="w_<?= $key ?>">
              <i class="bi <?= $icon ?>"></i> <?= $label ?>
            </label>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <div class="mt-3">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-save"></i> 表示設定を保存
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- SheetJS CDN (Excel読み込み) -->
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
(function(){
    const fileInput = document.getElementById('budgetFileInput');
    if (!fileInput) return;

    fileInput.addEventListener('change', function(e){
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(ev){
            let rows = [];
            const ext = file.name.split('.').pop().toLowerCase();

            if (ext === 'csv') {
                // CSV パース
                const text = new TextDecoder('utf-8').decode(new Uint8Array(ev.target.result));
                rows = text.trim().split(/\r?\n/).map(line => line.split(',').map(c => c.replace(/^"|"$/g,'').trim()));
            } else {
                // Excel パース (SheetJS)
                const wb = XLSX.read(new Uint8Array(ev.target.result), {type:'array'});
                const ws = wb.Sheets[wb.SheetNames[0]];
                rows = XLSX.utils.sheet_to_json(ws, {header:1, defval:''});
            }

            // ヘッダー行スキップ判定
            const data = [];
            for (const row of rows) {
                if (!row[0]) continue;
                const ym = String(row[0]).trim();
                // 年月っぽくない（数字で始まらない）行はスキップ
                if (!/^\d/.test(ym)) continue;
                data.push({
                    year_month:        ym,
                    target_qty:        String(row[1] ?? '0'),
                    salary_forecast:   String(row[2] ?? '0'),
                    overhead_forecast: String(row[3] ?? '0'),
                });
            }

            // プレビュー表示
            const tbody = document.getElementById('budgetPreviewBody');
            const preview = document.getElementById('budgetPreview');
            const countEl = document.getElementById('budgetRowCount');
            const importBtn = document.getElementById('budgetImportBtn');
            const jsonInput = document.getElementById('budgetJsonInput');

            tbody.innerHTML = '';
            if (data.length === 0) {
                preview.classList.add('d-none');
                importBtn.classList.add('d-none');
                alert('データが見つかりませんでした。列の順序を確認してください。');
                return;
            }

            data.forEach(function(r){
                const sal = parseInt(r.salary_forecast.replace(/[^\d]/g,'')) || 0;
                const ovh = parseInt(r.overhead_forecast.replace(/[^\d]/g,'')) || 0;
                const tr = document.createElement('tr');
                tr.innerHTML = `<td>${r.year_month}</td>`
                    + `<td class="text-end">${parseInt(r.target_qty)||0}本</td>`
                    + `<td class="text-end">¥${sal.toLocaleString()}</td>`
                    + `<td class="text-end">¥${ovh.toLocaleString()}</td>`
                    + `<td class="text-end fw-bold">¥${(sal+ovh).toLocaleString()}</td>`;
                tbody.appendChild(tr);
            });

            countEl.textContent = data.length;
            preview.classList.remove('d-none');
            importBtn.classList.remove('d-none');
            jsonInput.value = JSON.stringify(data);
        };
        reader.readAsArrayBuffer(file);
    });
})();
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
