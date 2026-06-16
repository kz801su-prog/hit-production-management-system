<?php
// =====================================================
// 課題管理（カイゼン記録）
// 目的: チーム・個人の課題を登録・週次報告・上司承認でクローズ
// 接続テーブル: improvement_issues, issue_weekly_reports
// 権限: worker以上（作成・報告）、manager以上（クローズ承認）
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
$pageTitle = '課題管理（カイゼン記録）';
$userId    = (int)$_SESSION['user_id'];

// =====================================================
// POST処理
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = postStr('action');

    // --- 課題 新規登録 ---
    if ($act === 'create_issue') {
        $issueNo = 'ISSUE-' . date('Y') . '-' . str_pad(
            ((int)(dbFetchOne("SELECT COUNT(*) AS c FROM improvement_issues WHERE issue_no LIKE ?",
                              ['ISSUE-' . date('Y') . '-%'])['c'] ?? 0)) + 1,
            3, '0', STR_PAD_LEFT
        );
        dbExecute(
            "INSERT INTO improvement_issues
                (issue_no, issue_type, employee_id, dept_id, title, description,
                 target_metric, baseline_value, target_value, identified_by_user_id, identified_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [
                $issueNo,
                postStr('issue_type'),
                postInt('employee_id') ?: null,
                postInt('dept_id')     ?: null,
                trim(postStr('title')),
                trim(postStr('description')) ?: null,
                trim(postStr('target_metric')) ?: null,
                postStr('baseline_value') !== '' ? (float)postStr('baseline_value') : null,
                postStr('target_value')   !== '' ? (float)postStr('target_value')   : null,
                $userId,
                postStr('identified_at') ?: date('Y-m-d'),
            ]
        );
        setFlash('課題「' . h(trim(postStr('title'))) . '」を登録しました。');
        header('Location: ' . APP_URL . '/improvement_issues.php');
        exit;
    }

    // --- 週次進捗報告 ---
    if ($act === 'add_report') {
        $issueId    = postInt('issue_id');
        $reportWeek = postStr('report_week');   // monday of week
        $curVal     = postStr('current_value');
        $note       = trim(postStr('progress_note'));

        // ステータスを in_progress に更新
        dbExecute(
            "UPDATE improvement_issues SET status='in_progress' WHERE id=? AND status='open'",
            [$issueId]
        );
        dbExecute(
            "INSERT INTO issue_weekly_reports (issue_id, reported_by_user_id, report_week, current_value, progress_note)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                reported_by_user_id=VALUES(reported_by_user_id),
                current_value=VALUES(current_value),
                progress_note=VALUES(progress_note)",
            [$issueId, $userId, $reportWeek, $curVal !== '' ? (float)$curVal : null, $note]
        );
        setFlash('週次報告を保存しました。');
        header('Location: ' . APP_URL . '/improvement_issues.php?view=' . $issueId);
        exit;
    }

    // --- 課題クローズ（manager以上） ---
    if ($act === 'resolve' && isManager()) {
        $issueId = postInt('issue_id');
        $note    = trim(postStr('resolution_note'));
        dbExecute(
            "UPDATE improvement_issues
             SET status='resolved', resolved_at=NOW(), resolved_by_user_id=?, resolution_note=?
             WHERE id=?",
            [$userId, $note ?: null, $issueId]
        );
        auditLog('resolve', 'improvement_issues', $issueId);
        setFlash('課題をクローズしました。', 'success');
        header('Location: ' . APP_URL . '/improvement_issues.php');
        exit;
    }

    // --- ステータス変更（in_progress → open 差し戻し、manager以上） ---
    if ($act === 'reopen' && isManager()) {
        $issueId = postInt('issue_id');
        dbExecute("UPDATE improvement_issues SET status='open', resolved_at=NULL WHERE id=?", [$issueId]);
        setFlash('課題を再オープンしました。', 'warning');
        header('Location: ' . APP_URL . '/improvement_issues.php?view=' . $issueId);
        exit;
    }

    header('Location: ' . APP_URL . '/improvement_issues.php');
    exit;
}

// =====================================================
// データ取得
// =====================================================
$viewId     = getInt('view');  // 詳細表示する課題ID
$filterType = $_GET['type']   ?? '';
$filterStat = $_GET['status'] ?? 'active';  // active = open+in_progress

// 社員一覧・部署一覧（フォーム用）
$employees   = dbFetchAll("SELECT id, name, employee_code FROM employees WHERE is_active=1 ORDER BY name");
$departments = dbFetchAll("SELECT id, dept_name FROM departments WHERE is_active=1 ORDER BY display_order");

// 課題一覧
$issueSql = "SELECT ii.*,
               e.name AS emp_name, e.employee_code,
               d.dept_name,
               u.login_id AS created_by_name,
               (SELECT COUNT(*) FROM issue_weekly_reports wr WHERE wr.issue_id = ii.id) AS report_count,
               (SELECT report_week FROM issue_weekly_reports wr WHERE wr.issue_id = ii.id ORDER BY report_week DESC LIMIT 1) AS last_report_week
             FROM improvement_issues ii
             LEFT JOIN employees e ON ii.employee_id = e.id
             LEFT JOIN departments d ON ii.dept_id = d.id
             LEFT JOIN users u ON ii.identified_by_user_id = u.id
             WHERE 1=1";
$issueParams = [];
if ($filterType)                    { $issueSql .= " AND ii.issue_type=?"; $issueParams[] = $filterType; }
if ($filterStat === 'active')       { $issueSql .= " AND ii.status IN ('open','in_progress')"; }
elseif ($filterStat === 'resolved') { $issueSql .= " AND ii.status='resolved'"; }
$issueSql .= " ORDER BY FIELD(ii.status,'in_progress','open','resolved'), ii.identified_at DESC";
$issues = dbFetchAll($issueSql, $issueParams);

// 詳細表示中の課題
$viewIssue   = null;
$viewReports = [];
if ($viewId) {
    $viewIssue = dbFetchOne(
        "SELECT ii.*, e.name AS emp_name, d.dept_name, u.login_id AS created_by_name,
                ru.login_id AS resolved_by_name
         FROM improvement_issues ii
         LEFT JOIN employees e ON ii.employee_id = e.id
         LEFT JOIN departments d ON ii.dept_id = d.id
         LEFT JOIN users u ON ii.identified_by_user_id = u.id
         LEFT JOIN users ru ON ii.resolved_by_user_id = ru.id
         WHERE ii.id=?", [$viewId]
    );
    $viewReports = dbFetchAll(
        "SELECT wr.*, u.login_id AS reporter_name
         FROM issue_weekly_reports wr
         JOIN users u ON wr.reported_by_user_id = u.id
         WHERE wr.issue_id=? ORDER BY wr.report_week DESC",
        [$viewId]
    );
}

// 今週の月曜日
$monday = date('Y-m-d', strtotime('monday this week'));

$statusMap = ['open'=>['label'=>'未着手','class'=>'secondary'], 'in_progress'=>['label'=>'対応中','class'=>'warning'], 'resolved'=>['label'=>'完了','class'=>'success']];
$typeMap   = ['team'=>['label'=>'チーム課題','class'=>'info'], 'individual'=>['label'=>'個人課題','class'=>'primary']];

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3 align-items-center">
  <div class="col">
    <h2><i class="bi bi-tools"></i> 課題管理（カイゼン記録）</h2>
  </div>
  <div class="col-auto">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
      <i class="bi bi-plus-circle"></i> 課題を登録
    </button>
  </div>
</div>

<!-- フィルタ -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
      <select name="type" class="form-select form-select-sm" style="width:140px">
        <option value="">全タイプ</option>
        <option value="team"       <?= $filterType==='team'       ? 'selected':'' ?>>チーム課題</option>
        <option value="individual" <?= $filterType==='individual' ? 'selected':'' ?>>個人課題</option>
      </select>
      <select name="status" class="form-select form-select-sm" style="width:130px">
        <option value="active"   <?= $filterStat==='active'   ? 'selected':'' ?>>未着手＋対応中</option>
        <option value="resolved" <?= $filterStat==='resolved' ? 'selected':'' ?>>完了済み</option>
        <option value=""         <?= $filterStat===''         ? 'selected':'' ?>>全て</option>
      </select>
      <button type="submit" class="btn btn-sm btn-primary">絞込</button>
      <a href="improvement_issues.php" class="btn btn-sm btn-outline-secondary">リセット</a>
      <span class="ms-auto text-muted small"><?= count($issues) ?>件</span>
    </form>
  </div>
</div>

<div class="row g-3">
  <!-- 課題一覧 -->
  <div class="col-lg-<?= $viewIssue ? '5' : '12' ?>">
    <?php if (empty($issues)): ?>
      <div class="alert alert-info">該当する課題がありません。</div>
    <?php else: ?>
    <div class="list-group">
    <?php foreach ($issues as $iss): ?>
      <?php $st = $statusMap[$iss['status']]; $tp = $typeMap[$iss['issue_type']]; ?>
      <a href="improvement_issues.php?view=<?= $iss['id'] ?>&type=<?= h($filterType) ?>&status=<?= h($filterStat) ?>"
         class="list-group-item list-group-item-action <?= $viewId==$iss['id'] ? 'active' : '' ?>">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="badge bg-<?= $tp['class'] ?> me-1"><?= $tp['label'] ?></span>
            <span class="badge bg-<?= $st['class'] ?>"><?= $st['label'] ?></span>
            <span class="ms-2 fw-bold"><?= h($iss['issue_no']) ?></span>
          </div>
          <small class="text-<?= $viewId==$iss['id'] ? 'light' : 'muted' ?>"><?= h($iss['identified_at']) ?></small>
        </div>
        <div class="mt-1"><?= h($iss['title']) ?></div>
        <div class="small <?= $viewId==$iss['id'] ? 'text-light opacity-75' : 'text-muted' ?>">
          <?php if ($iss['issue_type']==='individual'): ?>
            <i class="bi bi-person"></i> <?= h($iss['emp_name'] ?? '―') ?>
          <?php else: ?>
            <i class="bi bi-building"></i> <?= h($iss['dept_name'] ?? '―') ?>
          <?php endif; ?>
          &nbsp;|&nbsp;
          <i class="bi bi-bar-chart"></i> 週次報告: <?= $iss['report_count'] ?>回
          <?php if ($iss['last_report_week']): ?>
            （最終: <?= h($iss['last_report_week']) ?>週）
          <?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- 詳細パネル -->
  <?php if ($viewIssue): ?>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>
          <span class="badge bg-<?= $typeMap[$viewIssue['issue_type']]['class'] ?>"><?= $typeMap[$viewIssue['issue_type']]['label'] ?></span>
          <span class="badge bg-<?= $statusMap[$viewIssue['status']]['class'] ?> ms-1"><?= $statusMap[$viewIssue['status']]['label'] ?></span>
          <strong class="ms-2"><?= h($viewIssue['issue_no']) ?></strong>
        </span>
        <?php if ($viewIssue['status'] !== 'resolved' && isManager()): ?>
        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#resolveModal">
          <i class="bi bi-check-circle"></i> 承認してクローズ
        </button>
        <?php elseif ($viewIssue['status'] === 'resolved' && isManager()): ?>
        <form method="post" class="d-inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reopen">
          <input type="hidden" name="issue_id" value="<?= $viewIssue['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-warning"
                  onclick="return confirm('課題を再オープンしますか？')">再オープン</button>
        </form>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <h5><?= h($viewIssue['title']) ?></h5>

        <div class="row g-2 small mb-3">
          <div class="col-6">
            <span class="text-muted">対象:</span>
            <?php if ($viewIssue['issue_type']==='individual'): ?>
              <?= h($viewIssue['emp_name'] ?? '未指定') ?>
            <?php else: ?>
              <?= h($viewIssue['dept_name'] ?? '未指定') ?>（部署）
            <?php endif; ?>
          </div>
          <div class="col-6">
            <span class="text-muted">登録日:</span> <?= h($viewIssue['identified_at']) ?>
          </div>
          <?php if ($viewIssue['target_metric']): ?>
          <div class="col-6">
            <span class="text-muted">改善指標:</span> <?= h($viewIssue['target_metric']) ?>
          </div>
          <div class="col-6">
            <span class="text-muted">現状値→目標値:</span>
            <?= $viewIssue['baseline_value'] ?? '―' ?> → <?= $viewIssue['target_value'] ?? '―' ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($viewIssue['description']): ?>
        <div class="mb-3 p-2 bg-light rounded small"><?= nl2br(h($viewIssue['description'])) ?></div>
        <?php endif; ?>

        <?php if ($viewIssue['status'] === 'resolved'): ?>
        <div class="alert alert-success mb-3">
          <i class="bi bi-check-circle"></i>
          <strong>解決済み</strong> — <?= h($viewIssue['resolved_by_name']) ?> が <?= formatDatetime($viewIssue['resolved_at']) ?> に承認<br>
          <?php if ($viewIssue['resolution_note']): ?><small><?= nl2br(h($viewIssue['resolution_note'])) ?></small><?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 週次報告一覧 -->
        <h6 class="border-bottom pb-1 mb-2"><i class="bi bi-calendar-week"></i> 週次進捗報告</h6>
        <?php if (empty($viewReports)): ?>
          <p class="text-muted small">まだ報告がありません。</p>
        <?php else: ?>
          <div class="timeline mb-3">
          <?php foreach ($viewReports as $rp): ?>
            <div class="d-flex gap-2 mb-2">
              <div class="flex-shrink-0 text-center" style="width:80px">
                <span class="badge bg-light text-dark border small"><?= h($rp['report_week']) ?>週</span>
              </div>
              <div class="flex-grow-1 border rounded p-2 small">
                <?php if ($rp['current_value'] !== null): ?>
                  <span class="badge bg-info me-1">現状値: <?= h($rp['current_value']) ?></span>
                <?php endif; ?>
                <span class="text-muted"><?= h($rp['reporter_name']) ?></span><br>
                <?= nl2br(h($rp['progress_note'])) ?>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- 週次報告入力（未クローズのみ） -->
        <?php if ($viewIssue['status'] !== 'resolved'): ?>
        <h6 class="border-bottom pb-1 mb-2"><i class="bi bi-plus-circle"></i> 今週の報告を追加</h6>
        <form method="post" class="row g-2">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_report">
          <input type="hidden" name="issue_id" value="<?= $viewIssue['id'] ?>">
          <div class="col-md-4">
            <label class="form-label small">対象週（月曜日）</label>
            <input type="date" name="report_week" class="form-control form-control-sm"
                   value="<?= $monday ?>">
          </div>
          <?php if ($viewIssue['target_metric']): ?>
          <div class="col-md-3">
            <label class="form-label small">現状値</label>
            <input type="number" name="current_value" class="form-control form-control-sm"
                   step="0.01" placeholder="<?= h($viewIssue['target_metric']) ?>">
          </div>
          <?php else: ?>
          <input type="hidden" name="current_value" value="">
          <?php endif; ?>
          <div class="col-12">
            <label class="form-label small">今週の活動・気付き <span class="text-danger">*</span></label>
            <textarea name="progress_note" class="form-control form-control-sm" rows="2"
                      placeholder="今週取り組んだこと、変化、気付いた点など" required></textarea>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-sm btn-primary">
              <i class="bi bi-send"></i> 報告を送信
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- 課題登録モーダル -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_issue">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="bi bi-plus-circle"></i> 課題を登録</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <!-- タイプ -->
            <div class="col-md-4">
              <label class="form-label fw-bold">課題タイプ <span class="text-danger">*</span></label>
              <select name="issue_type" id="issueType" class="form-select" required>
                <option value="team">チーム課題</option>
                <option value="individual">個人課題</option>
              </select>
            </div>

            <!-- チーム課題の場合：部署 -->
            <div class="col-md-4" id="deptField">
              <label class="form-label fw-bold">対象部署</label>
              <select name="dept_id" class="form-select">
                <option value="">― 選択 ―</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= h($d['dept_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 個人課題の場合：社員 -->
            <div class="col-md-4 d-none" id="empField">
              <label class="form-label fw-bold">対象社員</label>
              <select name="employee_id" class="form-select">
                <option value="">― 選択 ―</option>
                <?php foreach ($employees as $e): ?>
                  <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['employee_code']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-bold">課題特定日 <span class="text-danger">*</span></label>
              <input type="date" name="identified_at" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <!-- タイトル -->
            <div class="col-12">
              <label class="form-label fw-bold">課題タイトル <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control"
                     placeholder="例: 裁断工程の不良率が3%を超えている" required>
            </div>

            <!-- 詳細 -->
            <div class="col-12">
              <label class="form-label">詳細・背景</label>
              <textarea name="description" class="form-control" rows="3"
                        placeholder="いつから、どのような状況か、考えられる原因など"></textarea>
            </div>

            <!-- 改善指標 -->
            <div class="col-md-4">
              <label class="form-label">改善指標</label>
              <input type="text" name="target_metric" class="form-control form-control-sm"
                     placeholder="例: 不良率(%)、達成率(%)">
            </div>
            <div class="col-md-4">
              <label class="form-label">現状値</label>
              <input type="number" name="baseline_value" class="form-control form-control-sm"
                     step="0.01" placeholder="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label">目標値</label>
              <input type="number" name="target_value" class="form-control form-control-sm"
                     step="0.01" placeholder="0.00">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save"></i> 登録する
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- クローズ承認モーダル -->
<?php if ($viewIssue && $viewIssue['status'] !== 'resolved' && isManager()): ?>
<div class="modal fade" id="resolveModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="resolve">
        <input type="hidden" name="issue_id" value="<?= $viewIssue['id'] ?>">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="bi bi-check-circle"></i> 課題をクローズ</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="fw-bold"><?= h($viewIssue['title']) ?></p>
          <div class="mb-3">
            <label class="form-label">解決の概要（任意）</label>
            <textarea name="resolution_note" class="form-control" rows="3"
                      placeholder="どのように改善されたか、最終的な結果など"></textarea>
          </div>
          <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle"></i>
            クローズすると改善貢献スコアに反映されます。本当に解決しましたか？
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg"></i> 承認してクローズ
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = <<<JS
// 課題タイプ切り替え
document.getElementById('issueType').addEventListener('change', function() {
    const isIndiv = this.value === 'individual';
    document.getElementById('deptField').classList.toggle('d-none',  isIndiv);
    document.getElementById('empField').classList.toggle('d-none',  !isIndiv);
});
JS;
require __DIR__ . '/parts/footer.php'; ?>
