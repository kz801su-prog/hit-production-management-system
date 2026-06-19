<?php
// =====================================================
// 評価基準マスター編集
// 目的: 品質・安定性・難易度・改善貢献の評価基準ルーブリックを編集
// 接続テーブル: eval_criteria
// 権限: 社長・admin のみ
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('admin');   // admin以上（社長含む）

$pageTitle = '評価基準設定';
$userId    = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? 'save_criteria';

    // ─── 評価基準（ルーブリック）保存 ─── admin + 社長
    if ($act === 'save_criteria') {
        $categories = ['quality','stability','difficulty','improvement'];
        foreach ($categories as $cat) {
            for ($score = 1; $score <= 5; $score++) {
                $label = trim($_POST["label_{$cat}_{$score}"] ?? '');
                $desc  = trim($_POST["desc_{$cat}_{$score}"]  ?? '');
                if ($label === '' && $desc === '') continue;
                dbExecute(
                    "INSERT INTO eval_criteria (category, score, label, description, updated_by)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        label=VALUES(label), description=VALUES(description), updated_by=VALUES(updated_by)",
                    [$cat, $score, $label, $desc, $userId]
                );
            }
        }
        auditLog('update', 'eval_criteria', null);
        setFlash('評価基準を保存しました。');
        header('Location: ' . APP_URL . '/eval_criteria.php?tab=criteria');
        exit;
    }

    // ─── 加算減算 追加 ─── 社長のみ
    if ($act === 'add_adjustment') {
        if (!isPresident()) {
            http_response_code(403); exit('権限がありません');
        }
        $empId  = (int)($_POST['employee_id'] ?? 0);
        $month  = preg_replace('/[^0-9-]/', '', $_POST['target_month'] ?? '');
        $points = (float)($_POST['points'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($empId && $month && $points != 0 && $reason !== '') {
            dbExecute(
                "INSERT INTO eval_score_adjustments (employee_id, target_month, points, reason, created_by_user_id)
                 VALUES (?,?,?,?,?)",
                [$empId, $month, $points, $reason, $userId]
            );
            // スコアを即時再計算
            require_once __DIR__ . '/../app/evaluation_service.php';
            calcAndSaveMonthlyScore($empId, $month);
            auditLog('insert', 'eval_score_adjustments', null);
            setFlash('加算減算を登録しました。（' . sprintf('%+g', $points) . '点）');
        }
        header('Location: ' . APP_URL . '/eval_criteria.php?tab=adjustment');
        exit;
    }

    // ─── 加算減算 削除 ─── 社長のみ
    if ($act === 'del_adjustment') {
        if (!isPresident()) {
            http_response_code(403); exit('権限がありません');
        }
        $adjId = (int)($_POST['adj_id'] ?? 0);
        $row   = dbFetchOne("SELECT employee_id, target_month FROM eval_score_adjustments WHERE id=?", [$adjId]);
        if ($row) {
            dbExecute("DELETE FROM eval_score_adjustments WHERE id=?", [$adjId]);
            require_once __DIR__ . '/../app/evaluation_service.php';
            calcAndSaveMonthlyScore((int)$row['employee_id'], $row['target_month']);
        }
        setFlash('加算減算を削除しました。', 'warning');
        header('Location: ' . APP_URL . '/eval_criteria.php?tab=adjustment');
        exit;
    }
}

// 全基準を取得
$allCriteria = dbFetchAll("SELECT * FROM eval_criteria ORDER BY category, score DESC");
$byCategory  = [];
foreach ($allCriteria as $row) {
    $byCategory[$row['category']][$row['score']] = $row;
}

$categoryLabels = [
    'quality'     => ['label'=>'品質',     'icon'=>'bi-star-fill',        'color'=>'warning'],
    'stability'   => ['label'=>'安定性',   'icon'=>'bi-graph-up-arrow',   'color'=>'info'],
    'difficulty'  => ['label'=>'難易度',   'icon'=>'bi-lightning-fill',   'color'=>'danger'],
    'improvement' => ['label'=>'改善貢献', 'icon'=>'bi-tools',            'color'=>'success'],
];
$scoreColors = [5=>'success', 4=>'primary', 3=>'secondary', 2=>'warning', 1=>'danger'];

// 加算減算リスト（社長のみ）
$adjustments = [];
$employees   = [];
if (isPresident()) {
    $adjustments = dbFetchAll(
        "SELECT esa.*, e.name AS emp_name, e.employee_code, u.login_id AS entered_by
         FROM eval_score_adjustments esa
         JOIN employees e ON esa.employee_id = e.id
         JOIN users u ON esa.created_by_user_id = u.id
         ORDER BY esa.target_month DESC, esa.created_at DESC"
    );
    $employees = dbFetchAll(
        "SELECT id, employee_code, name FROM employees WHERE is_active=1 ORDER BY name"
    );
}

$activeTab = $_GET['tab'] ?? 'criteria';

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3 align-items-center">
  <div class="col">
    <h2><i class="bi bi-sliders"></i> 評価基準設定</h2>
    <p class="text-muted mb-0 small">品質評価・個人評価の設定を行います。</p>
  </div>
  <div class="col-auto">
    <a href="employees.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> 管理へ戻る
    </a>
  </div>
</div>

<!-- メインタブナビ -->
<ul class="nav nav-pills mb-3" id="mainTabs">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'criteria' ? 'active' : '' ?>"
       href="?tab=criteria">
      <i class="bi bi-sliders"></i> オート評価基準
    </a>
  </li>
  <?php if (isPresident()): ?>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'adjustment' ? 'active' : '' ?>"
       href="?tab=adjustment">
      <i class="bi bi-plus-slash-minus"></i> 加算減算
      <span class="badge bg-danger ms-1">社長のみ</span>
    </a>
  </li>
  <?php endif; ?>
</ul>

<!-- =====================================================
     タブ: オート評価基準（admin + 社長）
     ===================================================== -->
<?php if ($activeTab === 'criteria'): ?>
<div class="alert alert-info py-2 small mb-3">
  <i class="bi bi-info-circle"></i>
  <strong>オート評価基準</strong>：各スコア段階の意味を定義します。
  品質グレード（S/A/B/C/D）や安定性・難易度の自動計算に使用されます。
  Admin・社長が編集できます。
</div>

<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save_criteria">

  <!-- カテゴリーサブタブ -->
  <ul class="nav nav-tabs mb-3" id="critTabs">
    <?php $first = true; foreach ($categoryLabels as $cat => $meta): ?>
    <li class="nav-item">
      <button class="nav-link <?= $first ? 'active' : '' ?>"
              type="button" data-bs-toggle="tab" data-bs-target="#tab-<?= $cat ?>">
        <i class="bi <?= $meta['icon'] ?> text-<?= $meta['color'] ?>"></i>
        <?= $meta['label'] ?>
      </button>
    </li>
    <?php $first = false; endforeach; ?>
  </ul>

  <div class="tab-content">
  <?php $first = true; foreach ($categoryLabels as $cat => $meta): ?>
    <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="tab-<?= $cat ?>">
      <div class="card">
        <div class="card-header bg-<?= $meta['color'] ?> <?= $meta['color']==='warning' ? 'text-dark' : 'text-white' ?>">
          <i class="bi <?= $meta['icon'] ?>"></i>
          <?= $meta['label'] ?>の評価基準（5段階）
        </div>
        <div class="card-body p-0">
          <table class="table table-bordered mb-0">
            <thead class="table-dark text-center">
              <tr>
                <th style="width:70px">点数</th>
                <th style="width:150px">ラベル</th>
                <th>誰でも判断できる具体的な基準</th>
              </tr>
            </thead>
            <tbody>
            <?php for ($score = 5; $score >= 1; $score--): ?>
              <?php $row = $byCategory[$cat][$score] ?? []; ?>
              <tr>
                <td class="text-center align-middle">
                  <span class="badge bg-<?= $scoreColors[$score] ?> fs-5"><?= $score ?></span>
                </td>
                <td>
                  <input type="text" name="label_<?= $cat ?>_<?= $score ?>"
                         class="form-control form-control-sm"
                         value="<?= h($row['label'] ?? '') ?>"
                         placeholder="例: 優秀" required>
                </td>
                <td>
                  <textarea name="desc_<?= $cat ?>_<?= $score ?>"
                            class="form-control form-control-sm" rows="2"
                            placeholder="誰でも判断できる客観的な基準を書いてください" required><?= h($row['description'] ?? '') ?></textarea>
                </td>
              </tr>
            <?php endfor; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php $first = false; endforeach; ?>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> 全カテゴリーを保存
    </button>
  </div>
</form>

<?php endif; ?>

<!-- =====================================================
     タブ: 加算減算（社長のみ）
     ===================================================== -->
<?php if ($activeTab === 'adjustment' && isPresident()): ?>
<div class="alert alert-warning py-2 small mb-3">
  <i class="bi bi-shield-lock-fill"></i>
  <strong>社長専用</strong>：社員の月次総合点に直接加算・減算できます。
  入力後、スコアが即時再計算されます。
</div>

<div class="row g-4">
  <!-- 追加フォーム -->
  <div class="col-lg-5">
    <div class="card border-danger">
      <div class="card-header bg-danger text-white fw-bold">
        <i class="bi bi-plus-slash-minus"></i> 加算減算を追加
      </div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_adjustment">
          <div class="mb-3">
            <label class="form-label fw-bold">社員 <span class="text-danger">*</span></label>
            <select name="employee_id" class="form-select" required>
              <option value="">― 選択 ―</option>
              <?php foreach ($employees as $e): ?>
              <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['employee_code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">対象月 <span class="text-danger">*</span></label>
            <input type="month" name="target_month" class="form-control"
                   value="<?= date('Y-m') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">点数（加算は正値、減算は負値） <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="number" name="points" class="form-control"
                     step="0.1" min="-100" max="100"
                     placeholder="例: +10 または -5" required>
              <span class="input-group-text">点</span>
            </div>
            <div class="form-text">総合点への直接加減算。上限150点・下限0点でクランプされます。</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">理由 <span class="text-danger">*</span></label>
            <input type="text" name="reason" class="form-control"
                   placeholder="例: 安全提案受賞による加点" required>
          </div>
          <button type="submit" class="btn btn-danger w-100">
            <i class="bi bi-save"></i> 登録して即時反映
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- 一覧 -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header fw-bold">
        <i class="bi bi-list-ul"></i> 加算減算一覧（全履歴）
        <span class="badge bg-secondary ms-1"><?= count($adjustments) ?>件</span>
      </div>
      <?php if (empty($adjustments)): ?>
      <div class="card-body text-muted">まだ登録がありません。</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-dark">
            <tr>
              <th>社員</th>
              <th>対象月</th>
              <th class="text-end">点数</th>
              <th>理由</th>
              <th>登録者</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($adjustments as $adj): ?>
            <tr>
              <td><?= h($adj['emp_name']) ?><br><small class="text-muted"><?= h($adj['employee_code']) ?></small></td>
              <td><?= h($adj['target_month']) ?></td>
              <td class="text-end fw-bold <?= $adj['points'] >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= sprintf('%+g', $adj['points']) ?>点
              </td>
              <td class="small"><?= h($adj['reason']) ?></td>
              <td class="small text-muted"><?= h($adj['entered_by']) ?></td>
              <td>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('この加算減算を削除しますか？\nスコアが再計算されます。')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="del_adjustment">
                  <input type="hidden" name="adj_id" value="<?= $adj['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger py-0">
                    <i class="bi bi-trash"></i>
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
</div>

<?php endif; ?>

<?php require __DIR__ . '/parts/footer.php'; ?>
