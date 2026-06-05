<?php
// =====================================================
// 個人評価ページ
// 目的: 月別作業者スコアの確認・計算・コメント入力
// 接続テーブル: monthly_worker_scores, employees, work_logs
// 権限: process_leader以上（自分の評価は全員参照可）
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';
require_once __DIR__ . '/../app/evaluation_service.php';

requireLogin();
$pageTitle = '個人評価';

$targetMonth = $_GET['month'] ?? date('Y-m');
$user        = getCurrentUser();

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = postStr('action');

    if ($postAction === 'calc_all' && isLeader()) {
        // 全社員のスコアを再計算
        $employees = dbFetchAll(
            "SELECT id FROM employees WHERE is_active = 1 AND employment_status = 'active'"
        );
        foreach ($employees as $e) {
            calcAndSaveMonthlyScore($e['id'], $targetMonth);
        }
        setFlash("{$targetMonth} の全社員スコアを再計算しました。");
    }

    if ($postAction === 'save_comment' && isLeader()) {
        $empId   = postInt('employee_id');
        $comment = postStr('comment');
        dbExecute(
            "UPDATE monthly_worker_scores SET manager_comment = ?
             WHERE employee_id = ? AND target_month = ?",
            [$comment, $empId, $targetMonth]
        );
        setFlash('コメントを保存しました。');
    }

    header('Location: ' . APP_URL . "/evaluations.php?month={$targetMonth}");
    exit;
}

// 評価一覧取得
$evalList = getEvaluationList($targetMonth);

require __DIR__ . '/parts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h2><i class="bi bi-star"></i> 個人評価</h2>
  <div class="d-flex gap-2">
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="month" name="month" class="form-control form-control-sm" value="<?= h($targetMonth) ?>">
      <button type="submit" class="btn btn-sm btn-primary">表示</button>
    </form>
    <?php if (isLeader()): ?>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="calc_all">
      <button type="submit" class="btn btn-sm btn-warning"
              onclick="return confirm('全社員のスコアを再計算しますか？')">
        <i class="bi bi-arrow-clockwise"></i> 再計算
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- 評価一覧 -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-dark">
          <tr>
            <th>社員</th><th>部署</th><th>役職</th>
            <th class="text-center">効率<br><small>35%</small></th>
            <th class="text-center">品質<br><small>30%</small></th>
            <th class="text-center">安定<br><small>15%</small></th>
            <th class="text-center">難易度<br><small>10%</small></th>
            <th class="text-center">改善<br><small>10%</small></th>
            <th class="text-center">総合点</th>
            <th>コメント</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($evalList as $ev): ?>
          <?php
          $total = (float)($ev['total_score'] ?? 0);
          $totalClass = $total >= 90 ? 'success' : ($total >= 70 ? 'primary' : ($total >= 50 ? 'warning' : 'danger'));
          $isMe = $ev['id'] == $user['employee_id'];
          ?>
          <tr class="<?= $isMe ? 'table-light fw-bold' : '' ?>">
            <td>
              <?= h($ev['name']) ?>
              <?php if ($isMe): ?><span class="badge bg-info ms-1">自分</span><?php endif; ?>
            </td>
            <td><small><?= h($ev['dept_name'] ?? '―') ?></small></td>
            <td><small><?= h($ev['position_name'] ?? '―') ?></small></td>
            <td class="text-center"><?= $ev['efficiency_score'] ? round($ev['efficiency_score'], 1) : '―' ?></td>
            <td class="text-center"><?= $ev['quality_score']    ? round($ev['quality_score'], 1)    : '―' ?></td>
            <td class="text-center"><?= $ev['stability_score']  ? round($ev['stability_score'], 1)  : '―' ?></td>
            <td class="text-center"><?= $ev['difficulty_score'] ? round($ev['difficulty_score'], 1) : '―' ?></td>
            <td class="text-center"><?= $ev['improvement_score']? round($ev['improvement_score'], 1): '―' ?></td>
            <td class="text-center">
              <?php if ($total > 0): ?>
                <span class="badge bg-<?= $totalClass ?> fs-6"><?= round($total, 1) ?></span>
              <?php else: ?>
                <span class="text-muted">未計算</span>
              <?php endif; ?>
            </td>
            <td><small class="text-muted"><?= h(mb_substr($ev['manager_comment'] ?? '', 0, 30)) ?></small></td>
            <td>
              <?php if (isLeader()): ?>
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                      data-bs-target="#commentModal<?= $ev['id'] ?>">コメント</button>
              <?php endif; ?>
            </td>
          </tr>

          <!-- コメントモーダル -->
          <?php if (isLeader()): ?>
          <div class="modal fade" id="commentModal<?= $ev['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><?= h($ev['name']) ?> へのコメント</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <form method="post">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="save_comment">
                  <input type="hidden" name="employee_id" value="<?= $ev['id'] ?>">
                  <div class="modal-body">
                    <textarea name="comment" class="form-control" rows="4"
                    ><?= h($ev['manager_comment'] ?? '') ?></textarea>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- 評価基準の説明 -->
<div class="card mt-3">
  <div class="card-header">評価基準</div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th>評価軸</th><th>比重</th><th>算出方法</th></tr>
          <tr><td>作業効率</td><td>35%</td><td>標準時間÷実績時間×100 の平均</td></tr>
          <tr><td>品質</td><td>30%</td><td>(完了数−不良数−手直し×0.5)÷完了数×100</td></tr>
          <tr><td>安定性</td><td>15%</td><td>達成率のバラつき（標準偏差）逆算</td></tr>
          <tr><td>難易度</td><td>10%</td><td>担当椅子タイプの難易度加重平均</td></tr>
          <tr><td>改善貢献</td><td>10%</td><td>改善アクションの件数×20点（上限100）</td></tr>
        </table>
      </div>
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th>達成率</th><th>判定</th></tr>
          <tr><td>120%以上</td><td><span class="badge bg-success">かなり早い</span></td></tr>
          <tr><td>100〜119%</td><td><span class="badge bg-primary">早い</span></td></tr>
          <tr><td>90〜99%</td><td><span class="badge bg-info">標準</span></td></tr>
          <tr><td>75〜89%</td><td><span class="badge bg-warning">遅れ気味</span></td></tr>
          <tr><td>74%以下</td><td><span class="badge bg-danger">大幅遅れ</span></td></tr>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
