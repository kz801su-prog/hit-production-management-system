<?php
// =====================================================
// 共通ヘッダーパーツ
// 目的: Bootstrap5 + 共通CSSを読み込む。全ページでincludeする。
// 呼び出し元: すべてのpublicページ
// =====================================================
$pageTitle = isset($pageTitle) ? $pageTitle . ' - ' . APP_NAME : APP_NAME;
$user      = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<!-- ナビゲーションバー -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="<?= APP_URL ?>/dashboard.php">
      <i class="bi bi-tools"></i> オーツーファーニチャー 工程管理
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link" href="<?= APP_URL ?>/dashboard.php"><i class="bi bi-speedometer2"></i> ダッシュボード</a>
        </li>
        <!-- 製品タイプライブラリー -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-archive"></i> 製品タイプ
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= APP_URL ?>/chair_types.php">一覧・検索</a></li>
            <?php if (isLeader()): ?>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/chair_type_form.php">新規登録</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/standards.php">工程標準時間</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/adjustments.php">差分工程</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <!-- 作業指示・進捗 -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-clipboard-check"></i> 作業指示
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= APP_URL ?>/orders.php">作業指示一覧</a></li>
            <?php if (isLeader()): ?>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/order_form.php">新規作成</a></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/progress_board.php">進捗ボード</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/gantt.php">ガントチャート</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/barcode_scan.php">
              <i class="bi bi-upc-scan"></i> バーコードスキャン
            </a></li>
            <?php if (isLeader()): ?>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/barcode_print.php">
              <i class="bi bi-printer"></i> バーコード印刷
            </a></li>
            <?php endif; ?>
          </ul>
        </li>
        <!-- 作業実績 -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-play-circle"></i> 作業実績
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= APP_URL ?>/work_start.php">作業開始</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/work_finish.php">作業終了</a></li>
          </ul>
        </li>
        <!-- 分析・評価 -->
        <?php if (isLeader()): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-bar-chart"></i> 分析・評価
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= APP_URL ?>/evaluations.php">個人評価</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/improvement_issues.php">
              <i class="bi bi-tools"></i> 課題管理（カイゼン）
            </a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/improvements.php">改善管理</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/simulator.php">人員シミュレーター</a></li>
            <?php if (isPresidentOrAdmin()): ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/eval_criteria.php">
              <i class="bi bi-sliders"></i> 評価基準設定
            </a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>
        <!-- 管理 -->
        <?php if (isAdmin()): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-gear"></i> 管理
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= APP_URL ?>/employees.php">社員管理</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/users.php">ユーザー管理</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/words.php">社長の言葉</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/backup.php">バックアップ</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
      <!-- ログインユーザー情報 -->
      <ul class="navbar-nav">
        <?php if (isAdmin()): ?>
        <li class="nav-item">
          <a class="nav-link" href="<?= APP_URL ?>/admin_settings.php" title="システム設定">
            <i class="bi bi-sliders"></i>
            <?php
            // 承認待ちユーザー数バッジ
            try {
                $pendingCount = dbFetchOne("SELECT COUNT(*) AS cnt FROM users WHERE is_active = 0")['cnt'] ?? 0;
            } catch (Exception $e) { $pendingCount = 0; }
            if ($pendingCount > 0): ?>
            <span class="badge bg-warning text-dark"><?= $pendingCount ?></span>
            <?php endif; ?>
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle"></i> <?= h($user['name']) ?>
            <span class="badge bg-secondary ms-1"><?= h(roleLabel($user['role'])) ?></span>
            <?php if (!empty($user['mfa_enabled'])): ?>
            <i class="bi bi-shield-check text-success ms-1" title="二段階認証 有効"></i>
            <?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= APP_URL ?>/settings.php">
              <i class="bi bi-gear"></i> マイ設定
            </a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/logout.php">
              <i class="bi bi-box-arrow-right"></i> ログアウト
            </a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<?php
// デモモードバナー
$_demoBanner = false;
try {
    $_demoBanner = (dbFetchOne(
        "SELECT setting_value FROM system_settings WHERE setting_key='demo_mode'"
    )['setting_value'] ?? '0') === '1';
} catch (Exception $e) {}
?>
<div class="container-fluid py-3">
<?php if ($_demoBanner): ?>
<div class="alert alert-warning alert-dismissible d-flex align-items-center py-2 mb-3 rounded-0 border-0 border-bottom border-warning" role="alert" style="margin:-12px -12px 0 -12px; padding-left:1rem;">
  <i class="bi bi-play-circle-fill me-2 fs-5"></i>
  <strong>デモモード稼働中</strong>
  <span class="ms-2 text-muted small">このデータはデモ用サンプルです。</span>
  <?php if (isPresidentOrAdmin()): ?>
  <a href="<?= APP_URL ?>/admin_settings.php#demo" class="ms-3 btn btn-sm btn-outline-warning py-0">
    <i class="bi bi-gear"></i> 設定
  </a>
  <?php endif; ?>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?= getFlashHtml() ?>
