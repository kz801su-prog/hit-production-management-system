<?php
// =====================================================
// ユーザーマニュアル
// 目的: システムの操作方法を図解付きで説明する
// 権限: worker以上（全ユーザー参照可）
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';

requireLogin();

$pageTitle  = 'ユーザーマニュアル';
$user       = getCurrentUser();

// 登録済み工程を取得（バーコードセクションのフローチャートに使用）
$allProcesses = dbFetchAll(
    "SELECT process_code, process_name FROM processes WHERE is_active = 1 ORDER BY display_order"
);

require __DIR__ . '/parts/header.php';
?>

<!-- =====================================================
     ページ全体レイアウト（TOCサイドバー + 本文）
     ===================================================== -->
<div class="manual-layout row g-0">

  <!-- ─── サイドバー目次 ─── -->
  <aside class="col-lg-3 col-xl-2 d-none d-lg-block">
    <div class="manual-toc-wrap sticky-top pt-2" style="top:66px; max-height:calc(100vh - 80px); overflow-y:auto;">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white py-2">
          <i class="bi bi-list-ul"></i> 目次
        </div>
        <div class="list-group list-group-flush toc-list" id="tocList">
          <a href="#sec-overview"   class="list-group-item list-group-item-action toc-item py-2">1. システム概要</a>
          <a href="#sec-roles"      class="list-group-item list-group-item-action toc-item py-2">2. 権限・ログイン</a>
          <a href="#sec-dashboard"  class="list-group-item list-group-item-action toc-item py-2">3. ダッシュボード</a>
          <a href="#sec-chairtype"  class="list-group-item list-group-item-action toc-item py-2">4. 製品タイプ管理</a>
          <a href="#sec-standards"  class="list-group-item list-group-item-action toc-item py-2">5. 工程標準時間</a>
          <a href="#sec-orders"     class="list-group-item list-group-item-action toc-item py-2">6. 作業指示・発行</a>
          <a href="#sec-barcodes"   class="list-group-item list-group-item-action toc-item py-2 toc-highlight">
            <i class="bi bi-upc-scan"></i> 7. バーコードシステム
          </a>
          <a href="#sec-bc-print"   class="list-group-item list-group-item-action toc-item toc-sub py-1">　└ バーコード印刷</a>
          <a href="#sec-bc-station" class="list-group-item list-group-item-action toc-item toc-sub py-1">　└ スキャンステーション</a>
          <a href="#sec-bc-quality" class="list-group-item list-group-item-action toc-item toc-sub py-1">　└ 品質評価入力</a>
          <a href="#sec-progress"   class="list-group-item list-group-item-action toc-item py-2">8. 進捗ボード</a>
          <a href="#sec-eval"       class="list-group-item list-group-item-action toc-item py-2">9. 個人評価</a>
          <a href="#sec-eval-carte" class="list-group-item list-group-item-action toc-item toc-sub py-1">　└ 個人カルテ</a>
          <a href="#sec-simulator"  class="list-group-item list-group-item-action toc-item py-2">10. 人員シミュレーター</a>
          <a href="#sec-admin"      class="list-group-item list-group-item-action toc-item py-2">11. 管理者向け</a>
          <a href="#sec-special-bc" class="list-group-item list-group-item-action toc-item py-2">12. 特殊バーコード一覧</a>
        </div>
      </div>
    </div>
  </aside>

  <!-- ─── 本文エリア ─── -->
  <main class="col-lg-9 col-xl-10 manual-body px-3 px-lg-4">

    <!-- タイトル -->
    <div class="manual-title-bar text-center py-4 mb-4 rounded-3">
      <div class="display-6 fw-bold"><i class="bi bi-book-half text-primary"></i> ユーザーマニュアル</div>
      <div class="text-muted mt-1">オーツーファーニチャー 椅子製造 工程管理システム</div>
      <div class="mt-2">
        <span class="badge bg-secondary">Ver. 2.0</span>
        <span class="badge bg-light text-dark ms-1"><?= date('Y年m月') ?></span>
      </div>
    </div>

    <!-- ================================================
         1. システム概要
         ================================================ -->
    <section id="sec-overview" class="manual-section">
      <h2 class="section-heading"><span class="section-no">1</span> システム概要</h2>

      <div class="overview-diagram card border-primary mb-4">
        <div class="card-body">
          <div class="row g-3 text-center">
            <div class="col-6 col-md-3">
              <div class="ov-box ov-blue">
                <i class="bi bi-archive fs-2"></i>
                <div class="fw-bold mt-1">製品タイプ</div>
                <div class="ov-desc">椅子の種類と<br>工程を登録</div>
              </div>
            </div>
            <div class="col-auto d-none d-md-flex align-items-center">
              <i class="bi bi-arrow-right fs-2 text-muted"></i>
            </div>
            <div class="col-6 col-md-3">
              <div class="ov-box ov-green">
                <i class="bi bi-clipboard-check fs-2"></i>
                <div class="fw-bold mt-1">作業指示</div>
                <div class="ov-desc">受注に合わせ<br>作業を発行</div>
              </div>
            </div>
            <div class="col-auto d-none d-md-flex align-items-center">
              <i class="bi bi-arrow-right fs-2 text-muted"></i>
            </div>
            <div class="col-6 col-md-3">
              <div class="ov-box ov-orange">
                <i class="bi bi-upc-scan fs-2"></i>
                <div class="fw-bold mt-1">バーコード</div>
                <div class="ov-desc">スキャンで<br>作業記録</div>
              </div>
            </div>
            <div class="col-auto d-none d-md-flex align-items-center">
              <i class="bi bi-arrow-right fs-2 text-muted"></i>
            </div>
            <div class="col-6 col-md-3">
              <div class="ov-box ov-purple">
                <i class="bi bi-star fs-2"></i>
                <div class="fw-bold mt-1">評価・分析</div>
                <div class="ov-desc">実績から<br>評価を自動計算</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="alert alert-info d-flex gap-2">
        <i class="bi bi-lightbulb-fill fs-4 flex-shrink-0 text-warning"></i>
        <div>
          このシステムは、椅子の製造工程をバーコードで管理し、
          <strong>各作業者の実績時間・品質を自動集計</strong>します。
          上司は品質グレード（S/A/B/C/D）を入力するだけで
          個人評価に即時反映されます。
        </div>
      </div>
    </section>

    <!-- ================================================
         2. 権限・ログイン
         ================================================ -->
    <section id="sec-roles" class="manual-section">
      <h2 class="section-heading"><span class="section-no">2</span> 権限レベルとログイン</h2>

      <div class="row g-3 mb-3">
        <?php
        $roles = [
          ['role'=>'president',     'label'=>'社長',       'icon'=>'bi-award-fill',         'color'=>'danger',  'can'=>'全機能 + 社長の言葉'],
          ['role'=>'admin',         'label'=>'管理者',     'icon'=>'bi-shield-fill',         'color'=>'dark',    'can'=>'全機能 + 社員/ユーザー管理'],
          ['role'=>'factory_manager','label'=>'工場長',    'icon'=>'bi-building-fill',       'color'=>'primary', 'can'=>'製品・指示・評価・分析'],
          ['role'=>'process_leader','label'=>'班長',       'icon'=>'bi-person-badge-fill',   'color'=>'success', 'can'=>'作業開始・終了・品質評価'],
          ['role'=>'worker',        'label'=>'作業員',     'icon'=>'bi-person-fill',         'color'=>'secondary','can'=>'バーコードスキャン・作業記録'],
        ];
        foreach ($roles as $r):
        ?>
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="card border-<?= $r['color'] ?> h-100">
            <div class="card-body py-2 d-flex gap-2 align-items-start">
              <i class="bi <?= $r['icon'] ?> fs-4 text-<?= $r['color'] ?> flex-shrink-0 mt-1"></i>
              <div>
                <div class="fw-bold"><?= $r['label'] ?></div>
                <div class="small text-muted"><?= $r['can'] ?></div>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="how-to-box">
        <h5 class="how-to-title"><i class="bi bi-box-arrow-in-right"></i> ログイン手順</h5>
        <ol class="step-list">
          <li>ブラウザでシステムのURLにアクセスする</li>
          <li>ログインIDとパスワードを入力して <kbd>ログイン</kbd> をクリック</li>
          <li>二段階認証が有効な場合はAuthenticatorアプリのコードも入力する</li>
        </ol>
        <div class="alert alert-warning py-2 mt-2 mb-0">
          <i class="bi bi-exclamation-triangle"></i>
          初期パスワードは <code>password123</code> です。初回ログイン後に必ず変更してください。
        </div>
      </div>
    </section>

    <!-- ================================================
         3. ダッシュボード
         ================================================ -->
    <section id="sec-dashboard" class="manual-section">
      <h2 class="section-heading"><span class="section-no">3</span> ダッシュボード</h2>

      <p>ログイン後に最初に表示される画面です。権限ごとに表示される情報が異なります。</p>

      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <div class="dash-widget">
            <i class="bi bi-exclamation-circle-fill text-danger"></i>
            <div class="dash-widget-label">緊急・遅延中の作業</div>
            <div class="dash-widget-sub">優先度が高い順に表示</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="dash-widget">
            <i class="bi bi-graph-up-arrow text-primary"></i>
            <div class="dash-widget-label">本日の進捗サマリー</div>
            <div class="dash-widget-sub">完了・進行中・未着手の件数</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="dash-widget">
            <i class="bi bi-chat-quote-fill text-warning"></i>
            <div class="dash-widget-label">社長の言葉</div>
            <div class="dash-widget-sub">毎回ランダムに表示</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ================================================
         4. 製品タイプ管理
         ================================================ -->
    <section id="sec-chairtype" class="manual-section">
      <h2 class="section-heading"><span class="section-no">4</span> 製品タイプ管理</h2>

      <p>製造する椅子の種類（モデル）を登録・管理します。</p>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <div class="how-to-box h-100">
            <h5 class="how-to-title"><i class="bi bi-plus-circle"></i> 新規登録</h5>
            <ol class="step-list">
              <li>「製品タイプ」メニュー → 「新規登録」をクリック</li>
              <li>グループ・コード・名前を入力して保存</li>
              <li>保存後に「工程標準時間を管理」から工程を追加する</li>
            </ol>
          </div>
        </div>
        <div class="col-md-6">
          <div class="how-to-box h-100">
            <h5 class="how-to-title"><i class="bi bi-diagram-3"></i> 工程フローチャート</h5>
            <p class="small">製品タイプ編集画面の下部に<strong>工程フローチャート</strong>が自動表示されます。</p>
            <div class="mini-flowchart d-flex align-items-center flex-wrap gap-1">
              <div class="mfc-box mfc-normal">裁断</div>
              <div class="mfc-arrow">→</div>
              <div class="mfc-box mfc-normal">縫製</div>
              <div class="mfc-arrow">→</div>
              <div class="mfc-box mfc-out">外注<br><small>張り込み</small></div>
              <div class="mfc-arrow">→</div>
              <div class="mfc-box mfc-excl"><s>検品</s><br><small>除外中</small></div>
            </div>
            <div class="mt-2 d-flex gap-3 small text-muted flex-wrap">
              <span><span class="legend-dot" style="background:#dbeafe;border:2px solid #2563eb"></span> 通常</span>
              <span><span class="legend-dot" style="background:#fffbf0;border:2px solid #d97706"></span> 外注</span>
              <span><span class="legend-dot" style="background:#fff0f0;border:2px solid #dc2626; opacity:.7"></span> 除外中</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ================================================
         5. 工程標準時間
         ================================================ -->
    <section id="sec-standards" class="manual-section">
      <h2 class="section-heading"><span class="section-no">5</span> 工程標準時間設定</h2>

      <p>製品タイプごとに、各工程の標準作業時間を設定します。ここで設定した時間が評価の基準になります。</p>

      <div class="table-responsive mb-3">
        <table class="table table-bordered table-sm">
          <thead class="table-dark text-center">
            <tr>
              <th>項目</th><th>説明</th><th>例</th>
            </tr>
          </thead>
          <tbody>
            <tr><td><strong>段取り時間</strong></td><td>作業準備のための固定時間（数量に関係なく一定）</td><td>5分</td></tr>
            <tr><td><strong>正味作業時間</strong></td><td>基準本数分の実際の作業時間</td><td>30分（5本分）</td></tr>
            <tr><td><strong>アローアンス率</strong></td><td>疲労・休憩を考慮した割増率</td><td>8%</td></tr>
            <tr><td><strong>難易度</strong></td><td>1（易）〜5（難）で評価計算に使用</td><td>3</td></tr>
            <tr><td><strong>標準人数</strong></td><td>この工程に必要な標準的な作業者数</td><td>2人</td></tr>
          </tbody>
        </table>
      </div>

      <div class="alert alert-primary py-2">
        <i class="bi bi-info-circle"></i>
        <strong>標準時間の計算式：</strong>
        段取り時間 + 正味作業時間 × (製造数 ÷ 基準本数) + アローアンス時間
      </div>
    </section>

    <!-- ================================================
         6. 作業指示・バーコード発行
         ================================================ -->
    <section id="sec-orders" class="manual-section">
      <h2 class="section-heading"><span class="section-no">6</span> 作業指示の作成とバーコード発行</h2>

      <div class="row g-4">
        <div class="col-md-7">
          <div class="how-to-box">
            <h5 class="how-to-title"><i class="bi bi-clipboard-plus"></i> 作業指示の作成手順</h5>
            <ol class="step-list">
              <li>「作業指示」メニュー → 「新規作成」をクリック</li>
              <li>製品タイプ・受注日・数量・納期・優先度を入力して保存</li>
              <li><strong>作業番号（WO-YYYY-XXXX）</strong>が自動発番される</li>
              <li>「バーコード印刷」ボタンをクリックしてバーコードを印刷</li>
              <li>印刷したバーコードを現場に持っていき、作業開始時にスキャン</li>
            </ol>
          </div>
        </div>
        <div class="col-md-5">
          <!-- 作業番号バーコードのイメージ -->
          <div class="bc-sample-card text-center">
            <div class="small text-muted mb-1">CHAIR-A — 事務用回転椅子（標準型）</div>
            <div class="bc-sample-bars"></div>
            <div class="fw-bold mt-1">WO-2026-0042</div>
            <div class="row text-start small mt-1 px-2">
              <div class="col-6 text-muted">受注日: 2026/06/10</div>
              <div class="col-6 text-muted">納期: 2026/07/01</div>
              <div class="col-6 text-muted">数量: 10本</div>
              <div class="col-6"><span class="badge bg-danger">緊急</span></div>
            </div>
          </div>
          <div class="text-center small text-muted mt-1">▲ 作業番号バーコードの例</div>
        </div>
      </div>
    </section>

    <!-- ================================================
         7. バーコードシステム（メインセクション）
         ================================================ -->
    <section id="sec-barcodes" class="manual-section">
      <h2 class="section-heading section-heading-main">
        <span class="section-no">7</span>
        <i class="bi bi-upc-scan"></i> バーコードシステム
      </h2>

      <!-- ┌──────────────────────────────────────────────────────┐
           │  バーコードシステム全体ヘッダー（工程フロー＋スキャン順） │
           └──────────────────────────────────────────────────────┘ -->
      <div class="barcode-system-header card shadow mb-4">
        <div class="card-header bg-gradient text-white py-3"
             style="background: linear-gradient(135deg,#1e3a5f,#0d6efd);">
          <h4 class="mb-0 fw-bold">
            <i class="bi bi-diagram-2-fill"></i>
            バーコードスキャンで「工程→人→時間」を自動記録
          </h4>
          <div class="small opacity-75 mt-1">3種類のバーコードを順番に読み込むだけで作業開始・終了・評価を管理</div>
        </div>
        <div class="card-body p-3">

          <!-- 製造工程フロー -->
          <div class="mb-3">
            <div class="flowchart-section-label">
              <i class="bi bi-gear-fill text-primary"></i>
              <strong>製造工程（工程標準時間管理で登録した順）</strong>
            </div>
            <div class="process-flow-header d-flex align-items-center flex-wrap gap-1 mt-2 overflow-auto pb-2">
              <?php if (empty($allProcesses)): ?>
              <div class="text-muted small">（工程が登録されると表示されます）</div>
              <?php else: ?>
              <?php foreach ($allProcesses as $i => $p): ?>
                <?php if ($i > 0): ?>
                <div class="pfh-arrow"><i class="bi bi-arrow-right-short fs-4"></i></div>
                <?php endif; ?>
                <div class="pfh-box">
                  <div class="pfh-num"><?= sprintf('%02d', $i+1) ?></div>
                  <div class="pfh-name"><?= h($p['process_name']) ?></div>
                  <div class="pfh-code"><?= h($p['process_code']) ?></div>
                </div>
              <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <hr class="my-2">

          <!-- スキャン手順フロー -->
          <div class="mb-2">
            <div class="flowchart-section-label">
              <i class="bi bi-upc-scan text-success"></i>
              <strong>各工程の作業開始スキャン手順（毎回この順番で読み取り）</strong>
            </div>
            <div class="scan-flow-header d-flex align-items-center flex-wrap gap-2 mt-2">
              <div class="sfh-step sfh-order">
                <div class="sfh-num">①</div>
                <div class="sfh-icon"><i class="bi bi-clipboard2-check-fill"></i></div>
                <div class="sfh-label">作業番号</div>
                <div class="sfh-sub">WO-YYYY-XXXX</div>
              </div>
              <div class="sfh-arrow">→</div>
              <div class="sfh-step sfh-process">
                <div class="sfh-num">②</div>
                <div class="sfh-icon"><i class="bi bi-gear-fill"></i></div>
                <div class="sfh-label">工程バーコード</div>
                <div class="sfh-sub">裁断・縫製 等</div>
              </div>
              <div class="sfh-arrow">→</div>
              <div class="sfh-step sfh-worker">
                <div class="sfh-num">③</div>
                <div class="sfh-icon"><i class="bi bi-person-badge-fill"></i></div>
                <div class="sfh-label">社員バーコード</div>
                <div class="sfh-sub">EMP-XXX</div>
              </div>
              <div class="sfh-arrow">→</div>
              <div class="sfh-result">
                <i class="bi bi-play-circle-fill fs-3 text-success"></i>
                <div class="sfh-label fw-bold text-success">作業開始!</div>
                <div class="sfh-sub">時間計測スタート</div>
              </div>
              <div class="sfh-arrow ms-3 text-danger">⋯終了時⋯</div>
              <div class="sfh-step sfh-finish">
                <div class="sfh-num">④</div>
                <div class="sfh-icon"><i class="bi bi-stop-circle-fill"></i></div>
                <div class="sfh-label">FINISHコード</div>
                <div class="sfh-sub">または 終了ボタン</div>
              </div>
              <div class="sfh-arrow">→</div>
              <div class="sfh-step sfh-worker">
                <div class="sfh-num">⑤</div>
                <div class="sfh-icon"><i class="bi bi-person-badge-fill"></i></div>
                <div class="sfh-label">社員バーコード</div>
                <div class="sfh-sub">終了する作業者</div>
              </div>
              <div class="sfh-arrow">→</div>
              <div class="sfh-step sfh-quality">
                <div class="sfh-num">⑥</div>
                <div class="sfh-icon"><i class="bi bi-star-fill"></i></div>
                <div class="sfh-label">品質グレード</div>
                <div class="sfh-sub">S / A / B / C / D</div>
              </div>
              <div class="sfh-arrow">→</div>
              <div class="sfh-result sfh-result-end">
                <i class="bi bi-check-circle-fill fs-3 text-danger"></i>
                <div class="sfh-label fw-bold text-danger">作業終了!</div>
                <div class="sfh-sub">評価に即時反映</div>
              </div>
            </div>
          </div>

          <!-- 注記 -->
          <div class="alert alert-light border mb-0 mt-2 py-2 small">
            <i class="bi bi-info-circle text-primary"></i>
            <strong>次の工程へ</strong>：作業終了後、同じ<strong>作業番号バーコード</strong>を再度スキャンし、
            次の工程バーコードを読んで次の作業者で開始します。
            工程が変わっても <strong>①作業番号 → ②工程 → ③社員</strong> の順番は変わりません。
          </div>
        </div>
      </div>

      <!-- ─── 7-1. バーコード印刷 ─── -->
      <section id="sec-bc-print">
        <h3 class="subsection-heading"><i class="bi bi-printer"></i> 7-1. バーコードの種類と印刷</h3>

        <div class="row g-3 mb-3">
          <!-- 3種類のバーコード説明 -->
          <div class="col-md-4">
            <div class="bc-type-card bc-type-order">
              <div class="bc-type-num">①</div>
              <i class="bi bi-clipboard2-check-fill bc-type-icon"></i>
              <div class="bc-type-title">作業番号バーコード</div>
              <hr class="my-2">
              <ul class="bc-type-list">
                <li>作業指示作成時に自動発番</li>
                <li>作業指示一覧 →「印刷」から出力</li>
                <li>製品タイプ・数量・納期が印字</li>
                <li><strong>現場に1枚</strong>持っていく</li>
              </ul>
              <div class="bc-type-badge">班長以上が印刷可</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="bc-type-card bc-type-process">
              <div class="bc-type-num">②</div>
              <i class="bi bi-gear-fill bc-type-icon"></i>
              <div class="bc-type-title">工程バーコード</div>
              <hr class="my-2">
              <ul class="bc-type-list">
                <li>裁断・縫製・張り込みなど</li>
                <li>「工程バーコード印刷」で出力</li>
                <li>各作業エリアに1枚掲示</li>
                <li><strong>一度印刷したら貼り付け</strong>ておく</li>
              </ul>
              <div class="bc-type-badge">班長以上が印刷可</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="bc-type-card bc-type-worker">
              <div class="bc-type-num">③</div>
              <i class="bi bi-person-badge-fill bc-type-icon"></i>
              <div class="bc-type-title">社員コードバーコード</div>
              <hr class="my-2">
              <ul class="bc-type-list">
                <li>全作業者に1枚ずつ配付</li>
                <li>「社員コードバーコード印刷」で出力</li>
                <li>社員証として常に携帯</li>
                <li>作業開始・終了の両方で使用</li>
              </ul>
              <div class="bc-type-badge">管理者が印刷可</div>
            </div>
          </div>
        </div>

        <div class="how-to-box">
          <h5 class="how-to-title"><i class="bi bi-printer"></i> バーコード印刷の手順</h5>
          <div class="row g-3">
            <div class="col-md-6">
              <strong class="text-primary">工程バーコード</strong>
              <ol class="step-list mt-1">
                <li>メニュー「作業指示」→「工程バーコード印刷」</li>
                <li>登録済み全工程のバーコードが一覧表示</li>
                <li>「印刷」ボタンで印刷 → 各エリアに掲示</li>
              </ol>
            </div>
            <div class="col-md-6">
              <strong class="text-success">社員コードバーコード</strong>
              <ol class="step-list mt-1">
                <li>メニュー「作業指示」→「社員コードバーコード印刷」</li>
                <li>部署でフィルタリング可能</li>
                <li>「印刷」ボタンで印刷 → 各社員に配付</li>
              </ol>
            </div>
          </div>
        </div>
      </section>

      <!-- ─── 7-2. スキャンステーション ─── -->
      <section id="sec-bc-station" class="mt-4">
        <h3 class="subsection-heading"><i class="bi bi-upc-scan"></i> 7-2. スキャンステーション操作手順</h3>

        <p>タブレットやPCにバーコードリーダーを接続し、<strong>「バーコードスキャンステーション」</strong>ページを開きます。</p>

        <!-- 開始フロー詳細 -->
        <h5 class="text-success mt-3"><i class="bi bi-play-circle-fill"></i> 作業開始の手順</h5>
        <div class="detailed-flow mb-3">
          <div class="df-step">
            <div class="df-step-num bg-success">STEP 1</div>
            <div class="df-step-body">
              <div class="df-step-title">作業番号バーコードをスキャン</div>
              <div class="df-step-desc">
                作業指示票の<strong>WO-YYYY-XXXX</strong>バーコードをリーダーで読み取ります。<br>
                読み取り成功：受注情報（製品タイプ・納期・数量）が画面に表示されます。
              </div>
              <div class="df-step-tip">
                <i class="bi bi-hand-index-thumb"></i>
                バーコードリーダーはEnterキーを自動送信するため、スキャンするだけで次に進みます。
              </div>
            </div>
          </div>
          <div class="df-connector"><i class="bi bi-arrow-down-short fs-2 text-muted"></i></div>

          <div class="df-step">
            <div class="df-step-num bg-primary">STEP 2</div>
            <div class="df-step-body">
              <div class="df-step-title">工程バーコードをスキャン</div>
              <div class="df-step-desc">
                作業エリアに掲示している<strong>工程バーコード</strong>（裁断・縫製 等）を読み取ります。<br>
                読み取り成功：工程名が表示されます。すでに完了している工程はスキャンできません。
              </div>
              <?php if (!empty($allProcesses)): ?>
              <div class="process-code-list d-flex flex-wrap gap-1 mt-2">
                <?php foreach ($allProcesses as $p): ?>
                <span class="badge bg-primary"><?= h($p['process_name']) ?> <code class="text-light"><?= h($p['process_code']) ?></code></span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="df-connector"><i class="bi bi-arrow-down-short fs-2 text-muted"></i></div>

          <div class="df-step">
            <div class="df-step-num bg-dark">STEP 3</div>
            <div class="df-step-body">
              <div class="df-step-title">社員バーコードをスキャン → 作業開始！</div>
              <div class="df-step-desc">
                作業者が<strong>自分の社員コードバーコード</strong>を読み取ります。<br>
                スキャン直後に<code>work_logs</code>に記録され、<strong>時間の計測が始まります</strong>。
              </div>
              <div class="df-step-success">
                <i class="bi bi-check-circle-fill text-success"></i>
                「〇〇さんの作業を開始しました。」と表示されればOKです。
              </div>
            </div>
          </div>
        </div>

        <!-- 終了フロー詳細 -->
        <h5 class="text-danger mt-4"><i class="bi bi-stop-circle-fill"></i> 作業終了の手順</h5>
        <div class="detailed-flow mb-3">
          <div class="df-step">
            <div class="df-step-num bg-danger">STEP 1</div>
            <div class="df-step-body">
              <div class="df-step-title">「終了」モードに切り替え</div>
              <div class="df-step-desc">
                画面右上の <span class="badge bg-danger"><i class="bi bi-stop-circle-fill"></i> 終了</span> ボタンをクリック<br>
                または <strong>「FINISH」バーコード</strong>をスキャンすると自動で終了モードになります。
              </div>
              <div class="df-step-tip">
                <i class="bi bi-sticky"></i>
                「FINISH」と印字したバーコードを現場に1枚用意しておくと便利です。
              </div>
            </div>
          </div>
          <div class="df-connector"><i class="bi bi-arrow-down-short fs-2 text-muted"></i></div>

          <div class="df-step">
            <div class="df-step-num bg-warning text-dark">STEP 2</div>
            <div class="df-step-body">
              <div class="df-step-title">社員バーコードをスキャン</div>
              <div class="df-step-desc">
                終了する作業者の社員バーコードを読み取ります。<br>
                その社員が<strong>現在進行中の全作業</strong>が一覧表示されます。
              </div>
            </div>
          </div>
          <div class="df-connector"><i class="bi bi-arrow-down-short fs-2 text-muted"></i></div>

          <div class="df-step">
            <div class="df-step-num" style="background:#6f42c1;">STEP 3</div>
            <div class="df-step-body">
              <div class="df-step-title">品質グレードを選択して「終了」</div>
              <div class="df-step-desc">
                上司が出来栄えを<strong>S/A/B/C/D</strong>のいずれかで選択します（任意）。<br>
                「この工程を終了する」ボタンをクリックすると、実績時間が自動計算されます。
              </div>
              <div class="quality-grade-demo d-flex gap-2 mt-2 flex-wrap">
                <?php
                $grades = [
                  'S'=>['success','最高品質'],
                  'A'=>['primary','良好'],
                  'B'=>['info','標準'],
                  'C'=>['warning','要改善'],
                  'D'=>['danger','不良'],
                ];
                foreach ($grades as $g => [$col, $desc]):
                ?>
                <div class="grade-demo-btn text-white bg-<?= $col ?>">
                  <div class="fw-bold fs-4"><?= $g ?></div>
                  <div style="font-size:.7rem"><?= $desc ?></div>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="df-step-success mt-2">
                <i class="bi bi-graph-up text-primary"></i>
                品質グレード入力後、<strong>その場で月次個人評価に反映</strong>されます。
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ─── 7-3. 品質評価 ─── -->
      <section id="sec-bc-quality" class="mt-4">
        <h3 class="subsection-heading"><i class="bi bi-star-fill text-warning"></i> 7-3. 品質グレードと評価反映</h3>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="table-responsive">
              <table class="table table-bordered table-sm text-center">
                <thead class="table-dark"><tr><th>グレード</th><th>内容</th><th>点数換算</th></tr></thead>
                <tbody>
                  <tr><td><span class="badge bg-success fs-6">S</span></td><td>最高品質・ミスなし</td><td>100点</td></tr>
                  <tr><td><span class="badge bg-primary fs-6">A</span></td><td>良好・軽微な問題のみ</td><td>80点</td></tr>
                  <tr><td><span class="badge bg-info fs-6">B</span></td><td>標準・問題なし</td><td>60点</td></tr>
                  <tr><td><span class="badge bg-warning fs-6">C</span></td><td>要改善・手直し必要</td><td>40点</td></tr>
                  <tr><td><span class="badge bg-danger fs-6">D</span></td><td>不良品・再作業</td><td>20点</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card border-info h-100">
              <div class="card-header bg-info text-white fw-bold">
                <i class="bi bi-lightning-fill"></i> 即時反映の仕組み
              </div>
              <div class="card-body">
                <div class="eval-flow d-flex flex-column gap-2">
                  <div class="eval-step eval-grade">
                    <i class="bi bi-star-fill text-warning"></i> 上司がS/A/B/C/Dを入力
                  </div>
                  <div class="text-center"><i class="bi bi-arrow-down text-muted"></i></div>
                  <div class="eval-step eval-calc">
                    <i class="bi bi-calculator text-primary"></i> 月次スコアを自動再計算
                  </div>
                  <div class="text-center"><i class="bi bi-arrow-down text-muted"></i></div>
                  <div class="eval-step eval-result">
                    <i class="bi bi-person-check text-success"></i> 個人評価ページに反映
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </section>

    <!-- ================================================
         8. 進捗ボード
         ================================================ -->
    <section id="sec-progress" class="manual-section">
      <h2 class="section-heading"><span class="section-no">8</span> 進捗ボード・ガントチャート</h2>

      <div class="row g-3">
        <div class="col-md-6">
          <div class="how-to-box">
            <h5 class="how-to-title"><i class="bi bi-kanban"></i> 進捗ボード</h5>
            <p>全作業指示の工程状況をマトリックス形式で一覧確認できます。</p>
            <div class="status-legend d-flex flex-wrap gap-2">
              <span class="badge bg-secondary">未着手</span>
              <span class="badge bg-primary">作業中</span>
              <span class="badge bg-success">完了</span>
              <span class="badge bg-danger">遅延</span>
              <span class="badge bg-warning text-dark">保留</span>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="how-to-box">
            <h5 class="how-to-title"><i class="bi bi-bar-chart-steps"></i> ガントチャート</h5>
            <p>作業指示の予定・実績をガントチャートで可視化します。</p>
            <ul class="small mb-0">
              <li>青色バー：予定期間</li>
              <li>緑色バー：実績（完了）</li>
              <li>赤色バー：遅延・超過</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- ================================================
         9. 個人評価
         ================================================ -->
    <section id="sec-eval" class="manual-section">
      <h2 class="section-heading"><span class="section-no">9</span> 個人評価</h2>

      <p>月ごとに作業実績から5つの軸で自動計算されます。「再計算」ボタンを押すといつでも最新データで更新できます。画面には <strong>月次評価</strong> と <strong>個人カルテ</strong> の2つのタブがあります。</p>

      <div class="table-responsive mb-3">
        <table class="table table-bordered table-sm">
          <thead class="table-primary text-center"><tr>
            <th>評価軸</th><th>比重</th><th>算出方法</th>
          </tr></thead>
          <tbody>
            <tr><td><i class="bi bi-speedometer2 text-success"></i> <strong>作業効率</strong></td><td class="text-center">35%</td><td>標準時間 ÷ 実績時間 × 100 の平均</td></tr>
            <tr><td><i class="bi bi-star text-warning"></i> <strong>品質</strong></td><td class="text-center">30%</td><td>S/A/B/C/Dグレードの平均点（未入力は不良数から計算）</td></tr>
            <tr><td><i class="bi bi-graph-up text-primary"></i> <strong>安定性</strong></td><td class="text-center">15%</td><td>達成率のバラつきが少ないほど高得点</td></tr>
            <tr><td><i class="bi bi-bar-chart text-info"></i> <strong>難易度</strong></td><td class="text-center">10%</td><td>担当した製品の難易度加重平均</td></tr>
            <tr><td><i class="bi bi-tools text-secondary"></i> <strong>改善貢献</strong></td><td class="text-center">10%</td><td>改善アクションの件数（1件20点、上限100点）</td></tr>
          </tbody>
        </table>
      </div>

      <div class="alert alert-success py-2">
        <i class="bi bi-info-circle"></i>
        品質グレード（S/A/B/C/D）が入力されると、その時点で<strong>即座に品質点が更新</strong>されます。
        月次の「再計算」を待つ必要はありません。
      </div>

      <!-- 個人カルテ -->
      <h3 id="sec-eval-carte" class="subsection-heading mt-4"><i class="bi bi-person-vcard"></i> 9-1. 個人カルテ</h3>
      <p>「個人カルテ」タブに切り替えると、<strong>社員ごとの年間パフォーマンス</strong>を一画面で確認できます。</p>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <div class="how-to-box h-100">
            <h5 class="how-to-title"><i class="bi bi-search"></i> 表示手順</h5>
            <ol class="step-list">
              <li>「個人評価」メニューを開く</li>
              <li>「<i class="bi bi-person-vcard"></i> 個人カルテ」タブをクリック</li>
              <li>対象社員・対象年度を選択して「カルテ表示」</li>
            </ol>
            <div class="alert alert-light border py-2 mt-2 mb-0 small">
              <i class="bi bi-lock"></i>
              自分自身のカルテはすべての権限で閲覧可。他社員は工程リーダー以上が参照可。
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-header bg-primary text-white">
              <i class="bi bi-bar-chart-line"></i> カルテに表示される情報
            </div>
            <ul class="list-group list-group-flush small">
              <li class="list-group-item"><i class="bi bi-person text-primary"></i> <strong>プロフィール</strong>：所属・役職・入社日</li>
              <li class="list-group-item"><i class="bi bi-123 text-success"></i> <strong>年間サマリー</strong>：評価回数・合計点・平均点・最高点月</li>
              <li class="list-group-item"><i class="bi bi-graph-up text-warning"></i> <strong>月次推移グラフ</strong>：年間の総合点折れ線グラフ</li>
              <li class="list-group-item"><i class="bi bi-table text-info"></i> <strong>年度別能力評価</strong>：年度ごとの評価点・コメント一覧</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- ================================================
         10. 人員シミュレーター
         ================================================ -->
    <section id="sec-simulator" class="manual-section">
      <h2 class="section-heading"><span class="section-no">10</span> 人員シミュレーター</h2>

      <p>製品タイプ・数量・作業者数・1日の稼働時間を入力すると、完成までの所要日数を試算できます。人員配置の検討に活用します。</p>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <div class="how-to-box h-100">
            <h5 class="how-to-title"><i class="bi bi-calculator"></i> 基本の試算手順</h5>
            <ol class="step-list">
              <li>「分析・評価」→「人員シミュレーター」をクリック</li>
              <li>製品タイプ・数量・作業者数・1日の稼働時間を入力</li>
              <li>「試算する」ボタンをクリック</li>
              <li>工程別の所要時間と合計日数を確認</li>
            </ol>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card h-100 border-success">
            <div class="card-header bg-success text-white fw-bold">
              <i class="bi bi-people-fill"></i> チーム構成シミュレーション（評価ベース）
            </div>
            <div class="card-body small">
              <p class="mb-2">評価データが存在する場合、<strong>評価基準月</strong>を選択すると個人評価スコアを使ったチーム試算ができます。</p>
              <div class="d-flex flex-column gap-2">
                <div class="p-2 rounded bg-success-subtle">
                  <i class="bi bi-trophy-fill text-success"></i>
                  <strong>最速かつ最高品質の構成</strong><br>
                  <span class="text-muted">総合点上位の社員を優先。推定所要時間・日数・メンバー名を表示</span>
                </div>
                <div class="p-2 rounded bg-danger-subtle">
                  <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                  <strong>反対条件の構成</strong><br>
                  <span class="text-muted">総合点下位のチーム。サポート・教育計画の立案に活用</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="alert alert-info py-2">
        <i class="bi bi-lightbulb"></i>
        評価基準月に評価データがない場合はチーム構成試算は表示されません。先に「個人評価」で月次スコアを計算してください。
      </div>
    </section>

    <!-- ================================================
         11. 管理者向け
         ================================================ -->
    <section id="sec-admin" class="manual-section">
      <h2 class="section-heading"><span class="section-no">11</span> 管理者向け機能</h2>

      <div class="row g-3">
        <div class="col-md-6">
          <div class="how-to-box">
            <h5 class="how-to-title"><i class="bi bi-people"></i> 社員管理</h5>
            <ul class="step-list">
              <li>社員コード・氏名・所属・役職を登録</li>
              <li>退職者は「退職」ステータスに変更（データは保持）</li>
              <li>異動履歴は自動記録</li>
            </ul>
          </div>
        </div>
        <div class="col-md-6">
          <div class="how-to-box">
            <h5 class="how-to-title"><i class="bi bi-person-gear"></i> ユーザー管理</h5>
            <ul class="step-list">
              <li>ログインIDと権限ロールを設定</li>
              <li>パスワードリセット可能</li>
              <li>二段階認証の有効化</li>
            </ul>
          </div>
        </div>
        <div class="col-md-6">
          <div class="how-to-box">
            <h5 class="how-to-title"><i class="bi bi-database"></i> バックアップ</h5>
            <ul class="step-list">
              <li>手動バックアップでDBのSQLダンプを取得</li>
              <li>30日間のバックアップ履歴を保持</li>
            </ul>
          </div>
        </div>
        <div class="col-md-6">
          <div class="how-to-box">
            <h5 class="how-to-title"><i class="bi bi-chat-quote"></i> 社長の言葉</h5>
            <ul class="step-list">
              <li>ログイン画面・ダッシュボードに表示するメッセージを管理</li>
              <li>複数登録でランダム表示</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- ================================================
         11. 特殊バーコード一覧
         ================================================ -->
    <section id="sec-special-bc" class="manual-section">
      <h2 class="section-heading"><span class="section-no">12</span> 特殊バーコード一覧</h2>

      <p>以下のコードをバーコードスキャンステーションに入力（スキャン）すると特殊操作ができます。</p>

      <div class="table-responsive">
        <table class="table table-bordered">
          <thead class="table-dark text-center"><tr>
            <th style="width:200px">スキャンするコード</th><th>動作</th><th>用途</th>
          </tr></thead>
          <tbody>
            <tr>
              <td class="text-center"><code class="fs-5 bc-code">FINISH</code></td>
              <td>終了モードに切り替え</td>
              <td>作業を終わらせるとき最初にスキャン</td>
            </tr>
            <tr>
              <td class="text-center"><code class="fs-5 bc-code">START</code></td>
              <td>開始モードに戻る</td>
              <td>誤って終了モードに入ったとき</td>
            </tr>
            <tr>
              <td class="text-center"><code class="fs-5 bc-code">RESET</code></td>
              <td>最初からやり直し</td>
              <td>誤スキャンをやり直したいとき</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="alert alert-info">
        <i class="bi bi-lightbulb"></i>
        <strong>ヒント：</strong>
        「FINISH」「RESET」などの文字をCodeバーコードに変換して印刷し、
        スキャンステーション横に貼り付けておくと現場で便利です。
        <a href="process_barcodes.php">工程バーコード印刷</a>ページから印刷できます。
      </div>
    </section>

    <!-- フッターナビ -->
    <div class="text-center py-4 border-top mt-4">
      <a href="dashboard.php" class="btn btn-primary me-2">
        <i class="bi bi-speedometer2"></i> ダッシュボードへ
      </a>
      <a href="barcode_station.php" class="btn btn-success me-2">
        <i class="bi bi-upc-scan"></i> スキャンステーションへ
      </a>
      <a href="#" onclick="window.scrollTo(0,0)" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-up"></i> 先頭へ戻る
      </a>
    </div>

  </main><!-- /manual-body -->
</div><!-- /manual-layout -->

<style>
/* ────────────────────────────────────────────────
   マニュアル全体レイアウト
   ──────────────────────────────────────────────── */
.manual-body { font-size: .95rem; }
.manual-section { margin-bottom: 3rem; padding-top: .5rem; }
.manual-title-bar { background: linear-gradient(135deg,#f0f7ff,#e8f5e9); border: 1px solid #dee2e6; }

/* 目次 */
.toc-list .toc-item { font-size: .85rem; }
.toc-list .toc-highlight { background: #e8f5e9; font-weight: bold; color: #0d6efd; }
.toc-list .toc-sub { font-size: .8rem; }

/* セクション見出し */
.section-heading {
  font-size: 1.5rem; font-weight: 800;
  border-left: 5px solid #0d6efd;
  padding: .4rem .8rem;
  margin-bottom: 1rem;
  background: #f8f9fa;
  border-radius: 0 6px 6px 0;
}
.section-heading-main { border-color: #198754; background: #f0fdf4; }
.section-no {
  display: inline-block; width: 2rem; height: 2rem; line-height: 2rem;
  text-align: center; background: #0d6efd; color: #fff;
  border-radius: 50%; font-size: .9rem; margin-right: .5rem;
}
.section-heading-main .section-no { background: #198754; }
.subsection-heading {
  font-size: 1.2rem; font-weight: 700;
  border-bottom: 2px solid #dee2e6;
  padding-bottom: .4rem; margin-bottom: 1rem;
}

/* ────────────────────────────────────────────────
   概要ダイアグラム
   ──────────────────────────────────────────────── */
.ov-box { border-radius: 12px; padding: 16px 8px; }
.ov-blue   { background:#dbeafe; border:2px solid #2563eb; color:#1e3a5f; }
.ov-green  { background:#dcfce7; border:2px solid #16a34a; color:#14532d; }
.ov-orange { background:#ffedd5; border:2px solid #ea580c; color:#7c2d12; }
.ov-purple { background:#ede9fe; border:2px solid #7c3aed; color:#3b0764; }
.ov-desc   { font-size:.8rem; color:inherit; opacity:.8; margin-top:4px; }

/* ────────────────────────────────────────────────
   作業方法ボックス
   ──────────────────────────────────────────────── */
.how-to-box {
  background: #f8f9fa; border-left: 4px solid #0d6efd;
  border-radius: 0 8px 8px 0; padding: 1rem;
}
.how-to-title { font-size: 1rem; font-weight: 700; margin-bottom: .5rem; color: #1e3a5f; }
.step-list { padding-left: 1.3rem; margin-bottom: 0; }
.step-list li { margin-bottom: .25rem; }

/* ────────────────────────────────────────────────
   作業番号バーコードサンプル
   ──────────────────────────────────────────────── */
.bc-sample-card {
  border: 2px solid #212529; border-radius: 8px; padding: 12px;
  background: #fff; box-shadow: 2px 2px 6px rgba(0,0,0,.15);
}
.bc-sample-bars {
  height: 60px; background: repeating-linear-gradient(
    90deg,
    #000 0px, #000 2px, #fff 2px, #fff 4px,
    #000 4px, #000 7px, #fff 7px, #fff 9px,
    #000 9px, #000 10px, #fff 10px, #fff 13px
  );
  margin: 6px 0; border-radius: 2px;
}

/* ────────────────────────────────────────────────
   バーコードシステムヘッダー（製造工程フロー）
   ──────────────────────────────────────────────── */
.flowchart-section-label { font-size: .9rem; margin-bottom: 4px; }

/* 製造工程フロー */
.process-flow-header { min-width: max-content; }
.pfh-box {
  border: 2px solid #0d6efd; border-radius: 8px; background: #dbeafe;
  padding: 6px 10px; text-align: center; min-width: 80px;
}
.pfh-num  { font-size: .65rem; color: #555; }
.pfh-name { font-size: .85rem; font-weight: bold; color: #1e3a5f; }
.pfh-code { font-size: .65rem; color: #666; font-family: monospace; }
.pfh-arrow { color: #999; }

/* スキャン手順フロー */
.scan-flow-header { flex-wrap: wrap; }
.sfh-step {
  border: 2px solid #dee2e6; border-radius: 8px; background: #fff;
  padding: 8px 10px; text-align: center; min-width: 100px;
}
.sfh-step.sfh-order   { border-color:#198754; background:#dcfce7; }
.sfh-step.sfh-process { border-color:#0d6efd; background:#dbeafe; }
.sfh-step.sfh-worker  { border-color:#6f42c1; background:#ede9fe; }
.sfh-step.sfh-finish  { border-color:#dc3545; background:#fee2e2; }
.sfh-step.sfh-quality { border-color:#fd7e14; background:#ffedd5; }
.sfh-num   { font-size: .75rem; font-weight: bold; color: #555; }
.sfh-icon  { font-size: 1.4rem; }
.sfh-label { font-size: .8rem; font-weight: bold; }
.sfh-sub   { font-size: .65rem; color: #666; }
.sfh-result { text-align: center; }
.sfh-result-end { }
.sfh-arrow { font-size: 1.3rem; color: #999; align-self: center; }

/* ────────────────────────────────────────────────
   3種類バーコードカード
   ──────────────────────────────────────────────── */
.bc-type-card {
  border: 2px solid #dee2e6; border-radius: 10px;
  padding: 16px; height: 100%; position: relative;
}
.bc-type-order   { border-color: #198754; background: #f0fdf4; }
.bc-type-process { border-color: #0d6efd; background: #eff6ff; }
.bc-type-worker  { border-color: #7c3aed; background: #f5f3ff; }
.bc-type-num {
  position: absolute; top: -14px; left: 14px;
  width: 28px; height: 28px; line-height: 28px; text-align: center;
  background: #212529; color: #fff; border-radius: 50%; font-weight: bold;
}
.bc-type-icon    { font-size: 2rem; display: block; margin: 4px 0; }
.bc-type-title   { font-weight: 800; font-size: 1rem; margin-bottom: 4px; }
.bc-type-list    { font-size: .85rem; padding-left: 1.2rem; margin-bottom: 8px; }
.bc-type-list li { margin-bottom: 3px; }
.bc-type-badge   {
  font-size: .7rem; background: #f1f5f9; color: #555;
  border-radius: 4px; padding: 2px 6px; display: inline-block;
}

/* ────────────────────────────────────────────────
   詳細ステップフロー
   ──────────────────────────────────────────────── */
.detailed-flow { border-left: 3px solid #dee2e6; padding-left: 1rem; margin-left: .5rem; }
.df-step { display: flex; gap: .8rem; margin-bottom: .5rem; }
.df-step-num {
  color: #fff; border-radius: 6px; padding: 4px 8px;
  font-size: .75rem; font-weight: bold; white-space: nowrap;
  align-self: flex-start; margin-top: 2px;
}
.df-step-body { flex: 1; background: #f8f9fa; border-radius: 8px; padding: .75rem; }
.df-step-title { font-weight: 700; font-size: .95rem; margin-bottom: .25rem; }
.df-step-desc  { font-size: .88rem; color: #444; }
.df-step-tip   { font-size: .8rem; background: #fffbf0; border: 1px solid #fde68a; border-radius: 4px; padding: 4px 8px; margin-top: .5rem; }
.df-step-success { font-size: .85rem; color: #166534; background: #dcfce7; border-radius: 4px; padding: 4px 8px; margin-top: .5rem; }
.df-connector { text-align: center; margin: -4px 0; }

/* ────────────────────────────────────────────────
   品質グレードデモ
   ──────────────────────────────────────────────── */
.grade-demo-btn {
  width: 60px; height: 60px; border-radius: 8px;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
}

/* ────────────────────────────────────────────────
   ダッシュボードウィジェットデモ
   ──────────────────────────────────────────────── */
.dash-widget {
  background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px;
  padding: 1rem; text-align: center;
}
.dash-widget > i { font-size: 2rem; display: block; margin-bottom: .5rem; }
.dash-widget-label { font-weight: bold; font-size: .9rem; }
.dash-widget-sub   { font-size: .8rem; color: #666; }

/* ────────────────────────────────────────────────
   ミニフローチャート
   ──────────────────────────────────────────────── */
.mini-flowchart { overflow-x: auto; padding-bottom: 4px; }
.mfc-box { border-radius: 6px; padding: 4px 8px; font-size: .8rem; text-align: center; min-width: 60px; }
.mfc-normal { border: 2px solid #2563eb; background: #dbeafe; }
.mfc-out    { border: 2px solid #d97706; background: #fffbf0; }
.mfc-excl   { border: 2px dashed #dc2626; background: #fff0f0; opacity: .7; }
.mfc-arrow  { color: #999; font-size: 1rem; align-self: center; }
.legend-dot { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 3px; }

/* ────────────────────────────────────────────────
   評価フロー
   ──────────────────────────────────────────────── */
.eval-step {
  border-radius: 8px; padding: 8px 12px; font-size: .88rem;
}
.eval-grade  { background: #fffbf0; border: 1px solid #fde68a; }
.eval-calc   { background: #eff6ff; border: 1px solid #93c5fd; }
.eval-result { background: #dcfce7; border: 1px solid #86efac; }

/* ────────────────────────────────────────────────
   特殊バーコードコード表示
   ──────────────────────────────────────────────── */
.bc-code {
  background: #212529; color: #f8f9fa;
  border-radius: 4px; padding: 2px 10px;
  letter-spacing: .1em;
}

/* ────────────────────────────────────────────────
   スクロールスパイ（アクティブ目次ハイライト）
   ──────────────────────────────────────────────── */
.toc-item.active {
  background: #e8f5e9 !important;
  color: #0d6efd !important;
  font-weight: bold;
  border-left: 3px solid #0d6efd;
}

@media (max-width: 991px) {
  .scan-flow-header { gap: .5rem; }
  .sfh-step { min-width: 80px; }
  .sfh-sub  { display: none; }
}
</style>

<?php
$extraJs = <<<'JS'
// スクロールスパイで目次のアクティブリンクをハイライト
(function() {
    const sections = document.querySelectorAll('section[id], div[id^="sec-"]');
    const tocLinks  = document.querySelectorAll('.toc-item');
    if (!sections.length || !tocLinks.length) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            tocLinks.forEach(a => a.classList.remove('active'));
            const link = document.querySelector('.toc-item[href="#' + entry.target.id + '"]');
            if (link) link.classList.add('active');
        });
    }, { rootMargin: '-20% 0px -70% 0px' });

    sections.forEach(s => observer.observe(s));
})();
JS;
require __DIR__ . '/parts/footer.php';
?>
