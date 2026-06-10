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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // eval_criteria の全行を一括更新
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
                [$cat, $score, $label, $desc, $_SESSION['user_id']]
            );
        }
    }
    auditLog('update', 'eval_criteria', null);
    setFlash('評価基準を保存しました。');
    header('Location: ' . APP_URL . '/eval_criteria.php');
    exit;
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

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3 align-items-center">
  <div class="col">
    <h2><i class="bi bi-sliders"></i> 評価基準設定</h2>
    <p class="text-muted mb-0 small">品質評価・個人評価の各段階の基準を設定します。変更は次回の評価から反映されます。</p>
  </div>
  <div class="col-auto">
    <a href="admin_settings.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> 設定一覧へ
    </a>
  </div>
</div>

<form method="post">
  <?= csrfField() ?>

  <!-- カテゴリータブ -->
  <ul class="nav nav-tabs mb-3" id="critTabs">
    <?php foreach ($categoryLabels as $cat => $meta): ?>
    <li class="nav-item">
      <button class="nav-link <?= $cat==='quality' ? 'active' : '' ?>"
              type="button" data-bs-toggle="tab" data-bs-target="#tab-<?= $cat ?>">
        <i class="bi <?= $meta['icon'] ?> text-<?= $meta['color'] ?>"></i>
        <?= $meta['label'] ?>
      </button>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="tab-content">
  <?php foreach ($categoryLabels as $cat => $meta): ?>
    <div class="tab-pane fade <?= $cat==='quality' ? 'show active' : '' ?>" id="tab-<?= $cat ?>">
      <div class="card">
        <div class="card-header bg-<?= $meta['color'] ?> <?= in_array($meta['color'],['warning']) ? 'text-dark' : 'text-white' ?>">
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
  <?php endforeach; ?>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> 全カテゴリーを保存
    </button>
    <a href="admin_settings.php" class="btn btn-outline-secondary">キャンセル</a>
  </div>
</form>

<?php require __DIR__ . '/parts/footer.php'; ?>
