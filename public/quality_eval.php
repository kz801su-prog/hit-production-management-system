<?php
// =====================================================
// 品質評価入力
// 目的: 作業指示完了後に責任者がルーブリックで品質評価を入力
// 接続テーブル: order_quality_evaluations, eval_criteria
// 権限: process_leader以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('process_leader');

$orderId   = getInt('order_id');
$pageTitle = '品質評価入力';

$order = $orderId
    ? dbFetchOne(
        "SELECT mo.*, ct.chair_type_code, ct.chair_type_name
         FROM manufacturing_orders mo
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         WHERE mo.id = ?",
        [$orderId]
    )
    : null;

if (!$order) {
    setFlash('作業指示が見つかりません。', 'danger');
    header('Location: ' . APP_URL . '/orders.php');
    exit;
}

// 既存評価
$existing = dbFetchOne(
    "SELECT e.*, u.login_id AS evaluator_name
     FROM order_quality_evaluations e
     JOIN users u ON e.evaluator_user_id = u.id
     WHERE e.manufacturing_order_id = ?",
    [$orderId]
);

// ルーブリック取得
$rubric = dbFetchAll(
    "SELECT * FROM eval_criteria WHERE category='quality' ORDER BY score DESC"
);

// 作業に携わった社員一覧（work_logs から）
$workers = dbFetchAll(
    "SELECT DISTINCT e.id, e.name, e.employee_code, d.dept_name
     FROM work_logs wl
     JOIN employees e ON wl.employee_id = e.id
     LEFT JOIN departments d ON e.department_id = d.id
     WHERE wl.manufacturing_order_id = ?
     ORDER BY d.dept_name, e.name",
    [$orderId]
);

// POST: 評価保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $score    = max(1, min(5, postInt('quality_score')));
    $defects  = max(0, postInt('defect_count'));
    $reworks  = max(0, postInt('rework_count'));
    $comment  = trim(postStr('comment'));

    dbExecute(
        "INSERT INTO order_quality_evaluations
             (manufacturing_order_id, evaluator_user_id, quality_score, defect_count, rework_count, comment, evaluated_at)
         VALUES (?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
             evaluator_user_id=VALUES(evaluator_user_id),
             quality_score=VALUES(quality_score),
             defect_count=VALUES(defect_count),
             rework_count=VALUES(rework_count),
             comment=VALUES(comment),
             evaluated_at=NOW()",
        [$orderId, $_SESSION['user_id'], $score, $defects, $reworks, $comment ?: null]
    );
    auditLog('upsert', 'order_quality_evaluations', $orderId);
    setFlash('品質評価を保存しました。');
    header('Location: ' . APP_URL . '/orders.php');
    exit;
}

require __DIR__ . '/parts/header.php';

$scoreColors = [5=>'success', 4=>'primary', 3=>'secondary', 2=>'warning', 1=>'danger'];
$currentScore = $existing['quality_score'] ?? 0;
?>

<div class="row mb-3 align-items-center">
  <div class="col">
    <h2><i class="bi bi-star-fill text-warning"></i> 品質評価入力</h2>
  </div>
  <div class="col-auto">
    <a href="orders.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> 一覧へ戻る
    </a>
  </div>
</div>

<!-- 作業指示サマリー -->
<div class="card mb-3 border-info">
  <div class="card-body py-2">
    <div class="row g-2 small">
      <div class="col-6 col-md-3">
        <span class="text-muted">作業番号:</span><br>
        <strong><?= h($order['order_no']) ?></strong>
      </div>
      <div class="col-6 col-md-3">
        <span class="text-muted">製品タイプ:</span><br>
        <?= h($order['chair_type_code']) ?> <?= h($order['chair_type_name']) ?>
      </div>
      <div class="col-6 col-md-3">
        <span class="text-muted">数量:</span><br>
        <?= h($order['quantity']) ?>本
      </div>
      <div class="col-6 col-md-3">
        <span class="text-muted">納期:</span><br>
        <?= formatDate($order['due_date']) ?>
      </div>
    </div>
  </div>
</div>

<?php if ($existing): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle"></i>
  既存の評価があります（評価者: <?= h($existing['evaluator_name']) ?>、評価日時: <?= formatDatetime($existing['evaluated_at']) ?>）。
  上書き保存できます。
</div>
<?php endif; ?>

<!-- 作業者一覧 -->
<?php if ($workers): ?>
<div class="card mb-3">
  <div class="card-header bg-light">この作業指示に携わった作業者</div>
  <div class="card-body py-2">
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($workers as $w): ?>
        <span class="badge bg-secondary fs-6"><?= h($w['name']) ?> <small class="opacity-75"><?= h($w['dept_name'] ?? '') ?></small></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <!-- ルーブリック -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header bg-primary text-white fw-bold">
        <i class="bi bi-list-check"></i> 品質評価基準
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th class="text-center" style="width:60px">点数</th><th>ラベル</th><th>基準</th></tr>
          </thead>
          <tbody>
          <?php foreach ($rubric as $r): ?>
            <tr class="<?= $currentScore == $r['score'] ? 'table-warning fw-bold' : '' ?>">
              <td class="text-center">
                <span class="badge bg-<?= $scoreColors[$r['score']] ?> fs-6"><?= $r['score'] ?></span>
              </td>
              <td class="fw-bold"><?= h($r['label']) ?></td>
              <td class="small"><?= nl2br(h($r['description'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 評価入力フォーム -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header bg-warning text-dark fw-bold">
        <i class="bi bi-pencil-square"></i> 評価を入力
      </div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?>

          <!-- スコア選択 -->
          <div class="mb-4">
            <label class="form-label fw-bold">品質スコア <span class="text-danger">*</span></label>
            <div class="d-flex gap-2 flex-wrap" id="scoreButtons">
              <?php foreach (array_reverse($rubric) as $r): ?>
                <?php $sel = $currentScore == $r['score']; ?>
                <label class="score-btn-label">
                  <input type="radio" name="quality_score" value="<?= $r['score'] ?>"
                         class="d-none score-radio" <?= $sel ? 'checked' : '' ?> required>
                  <div class="score-btn border rounded p-3 text-center <?= $sel ? 'score-btn-active border-primary' : '' ?>"
                       style="width:90px; cursor:pointer">
                    <div class="fs-3 fw-bold text-<?= $scoreColors[$r['score']] ?>"><?= $r['score'] ?></div>
                    <div class="small fw-bold"><?= h($r['label']) ?></div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
            <div id="scoreDesc" class="mt-2 text-muted small"></div>
          </div>

          <!-- 数値入力 -->
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">不良数</label>
              <div class="input-group">
                <input type="number" name="defect_count" class="form-control"
                       value="<?= $existing['defect_count'] ?? 0 ?>" min="0">
                <span class="input-group-text">件</span>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label">手直し数</label>
              <div class="input-group">
                <input type="number" name="rework_count" class="form-control"
                       value="<?= $existing['rework_count'] ?? 0 ?>" min="0">
                <span class="input-group-text">件</span>
              </div>
            </div>
          </div>

          <!-- コメント -->
          <div class="mb-3">
            <label class="form-label">評価コメント（任意）</label>
            <textarea name="comment" class="form-control" rows="3"
                      placeholder="気付いた点・改善の提案など"><?= h($existing['comment'] ?? '') ?></textarea>
          </div>

          <button type="submit" class="btn btn-primary w-100 btn-lg">
            <i class="bi bi-save"></i> 品質評価を保存
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php
// ルーブリックをJSに渡す
$rubricJson = json_encode(array_column($rubric, null, 'score'), JSON_UNESCAPED_UNICODE);
$extraJs = <<<JS
const rubric = $rubricJson;

// スコアボタンのトグル
document.querySelectorAll('.score-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.score-btn').forEach(b => b.classList.remove('score-btn-active','border-primary'));
        this.closest('.score-btn-label').querySelector('.score-btn').classList.add('score-btn-active','border-primary');
        const r = rubric[this.value];
        document.getElementById('scoreDesc').textContent = r ? r.description : '';
    });
    // 初期表示
    if (radio.checked) {
        const r = rubric[radio.value];
        if (r) document.getElementById('scoreDesc').textContent = r.description;
    }
});

// ラベルクリックでラジオ選択
document.querySelectorAll('.score-btn-label').forEach(function(label) {
    label.querySelector('.score-btn').addEventListener('click', function() {
        label.querySelector('.score-radio').click();
    });
});
JS;

require __DIR__ . '/parts/footer.php'; ?>
