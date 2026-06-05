<?php
$pageTitle = 'アクセス拒否';
require __DIR__ . '/header.php';
?>
<div class="text-center py-5">
  <h1 class="display-1 text-danger">403</h1>
  <h2>アクセス権限がありません</h2>
  <p class="text-muted">この操作を行う権限がありません。管理者に連絡してください。</p>
  <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-primary">ダッシュボードへ戻る</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
