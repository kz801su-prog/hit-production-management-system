<?php
// =====================================================
// 社員情報 CSV インポート
// 対応形式: UTF-8 または Shift-JIS の CSV
// 権限: admin以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();
requireRole('admin');
$pageTitle = '社員 CSV インポート';

$result   = [];
$errors   = [];
$imported = 0;
$skipped  = 0;

// 列マッピング（エクスポートと同じ順序）
// 社員コード,氏名,氏名カナ,メール,電話,住所,入社日,退社日,在籍状態,部署,課,役職
define('COL_CODE',      0);
define('COL_NAME',      1);
define('COL_KANA',      2);
define('COL_EMAIL',     3);
define('COL_PHONE',     4);
define('COL_ADDRESS',   5);
define('COL_JOINED',    6);
define('COL_RETIRED',   7);
define('COL_STATUS',    8);
define('COL_DEPT',      9);
define('COL_SECTION',  10);
define('COL_POSITION', 11);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (empty($_FILES['csv_file']['tmp_name'])) {
        setFlash('CSVファイルを選択してください。', 'danger');
        header('Location: ' . APP_URL . '/employee_import.php');
        exit;
    }

    $content = file_get_contents($_FILES['csv_file']['tmp_name']);

    // Shift-JIS を UTF-8 に変換（必要な場合）
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'SJIS-WIN');
    }
    // BOM 除去
    $content = ltrim($content, "\xEF\xBB\xBF");

    $lines = explode("\n", str_replace("\r\n", "\n", str_replace("\r", "\n", $content)));

    // 部署・課・役職のルックアップ
    $deptMap = [];
    foreach (dbFetchAll("SELECT id, dept_name FROM departments") as $r) {
        $deptMap[$r['dept_name']] = $r['id'];
    }
    $sectionMap = [];
    foreach (dbFetchAll("SELECT id, section_name FROM sections") as $r) {
        $sectionMap[$r['section_name']] = $r['id'];
    }
    $positionMap = [];
    foreach (dbFetchAll("SELECT id, position_name FROM positions") as $r) {
        $positionMap[$r['position_name']] = $r['id'];
    }
    $statusMap = ['在籍' => 'active', '休職' => 'leave', '退職' => 'retired',
                  'active' => 'active', 'leave' => 'leave', 'retired' => 'retired'];

    $rowNum = 0;
    foreach ($lines as $line) {
        $rowNum++;
        $line = trim($line);
        if ($line === '') continue;

        // CSV パース
        $cols = str_getcsv($line);

        // ヘッダー行をスキップ
        if ($rowNum === 1 && (($cols[0] ?? '') === '社員コード' || ($cols[0] ?? '') === 'employee_code')) {
            continue;
        }

        $code = trim($cols[COL_CODE] ?? '');
        $name = trim($cols[COL_NAME] ?? '');
        if (!$code || !$name) {
            $errors[] = "行{$rowNum}: 社員コードまたは氏名が空です";
            $skipped++;
            continue;
        }

        $statusRaw = trim($cols[COL_STATUS] ?? '在籍');
        $status    = $statusMap[$statusRaw] ?? 'active';

        $data = [
            'employee_code'     => $code,
            'name'              => $name,
            'name_kana'         => trim($cols[COL_KANA]     ?? ''),
            'email'             => trim($cols[COL_EMAIL]    ?? ''),
            'phone'             => trim($cols[COL_PHONE]    ?? ''),
            'address'           => trim($cols[COL_ADDRESS]  ?? ''),
            'joined_date'       => trim($cols[COL_JOINED]   ?? '') ?: null,
            'retired_date'      => trim($cols[COL_RETIRED]  ?? '') ?: null,
            'employment_status' => $status,
            'department_id'     => $deptMap[trim($cols[COL_DEPT]    ?? '')] ?? null,
            'section_id'        => $sectionMap[trim($cols[COL_SECTION] ?? '')] ?? null,
            'position_id'       => $positionMap[trim($cols[COL_POSITION] ?? '')] ?? null,
        ];

        // 既存チェック（社員コードで判断）
        $existing = dbFetchOne("SELECT id FROM employees WHERE employee_code = ?", [$code]);

        try {
            if ($existing) {
                // 更新
                dbExecute(
                    "UPDATE employees SET
                        name=?, name_kana=?, email=?, phone=?, address=?,
                        joined_date=?, retired_date=?, employment_status=?,
                        department_id=?, section_id=?, position_id=?
                     WHERE employee_code=?",
                    [
                        $data['name'], $data['name_kana'], $data['email'],
                        $data['phone'], $data['address'],
                        $data['joined_date'], $data['retired_date'], $data['employment_status'],
                        $data['department_id'], $data['section_id'], $data['position_id'],
                        $code,
                    ]
                );
                // 退職したらユーザーを無効化
                if ($status === 'retired') {
                    dbExecute("UPDATE users SET is_active = 0 WHERE employee_id = ?", [$existing['id']]);
                }
                $result[] = "行{$rowNum}: [{$code}] {$name} を更新";
            } else {
                // 新規登録
                dbExecute(
                    "INSERT INTO employees
                        (employee_code, name, name_kana, email, phone, address,
                         joined_date, retired_date, employment_status,
                         department_id, section_id, position_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                    array_values($data)
                );
                $result[] = "行{$rowNum}: [{$code}] {$name} を新規登録";
            }
            $imported++;
        } catch (PDOException $e) {
            $errors[] = "行{$rowNum}: [{$code}] エラー: " . $e->getMessage();
            $skipped++;
        }
    }

    auditLog('import', 'employees', null, null, ['imported' => $imported, 'skipped' => $skipped]);
}

require __DIR__ . '/parts/header.php';
?>

<div class="row mb-3">
  <div class="col"><h2><i class="bi bi-upload"></i> 社員 CSV インポート</h2></div>
  <div class="col-auto">
    <a href="employees.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> 一覧へ
    </a>
  </div>
</div>

<?= getFlashHtml() ?>

<?php if ($result || $errors): ?>
<div class="card mb-4">
  <div class="card-header fw-bold">
    インポート結果:
    <span class="text-success"><?= $imported ?>件成功</span> /
    <span class="text-danger"><?= $skipped ?>件スキップ</span>
  </div>
  <div class="card-body" style="max-height:300px;overflow-y:auto">
    <?php foreach ($result as $r): ?>
      <div class="text-success small"><i class="bi bi-check"></i> <?= h($r) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
      <div class="text-danger small"><i class="bi bi-x"></i> <?= h($e) ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">CSV ファイルのアップロード</div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <div class="mb-3">
        <label class="form-label">CSV ファイル（UTF-8 または Shift-JIS）</label>
        <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
        <div class="form-text">
          Excel で「名前を付けて保存」→「CSV UTF-8（コンマ区切り）」を選択してください。
        </div>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-upload"></i> インポート実行
      </button>
    </form>
  </div>
</div>

<!-- CSV フォーマット説明 -->
<div class="card mt-4">
  <div class="card-header">CSV フォーマット</div>
  <div class="card-body">
    <p class="small text-muted mb-2">
      1行目はヘッダー行として自動スキップされます。社員コードが既存の場合は上書き更新されます。
    </p>
    <div class="table-responsive">
      <table class="table table-sm table-bordered small">
        <thead class="table-light">
          <tr>
            <th>列</th><th>項目</th><th>必須</th><th>形式・例</th>
          </tr>
        </thead>
        <tbody>
          <tr><td>A</td><td>社員コード</td><td><span class="text-danger">●</span></td><td>EMP-001</td></tr>
          <tr><td>B</td><td>氏名</td><td><span class="text-danger">●</span></td><td>山田 太郎</td></tr>
          <tr><td>C</td><td>氏名カナ</td><td></td><td>ヤマダ タロウ</td></tr>
          <tr><td>D</td><td>メール</td><td></td><td>yamada@example.com</td></tr>
          <tr><td>E</td><td>電話</td><td></td><td>090-0000-0000</td></tr>
          <tr><td>F</td><td>住所</td><td></td><td>大阪府…</td></tr>
          <tr><td>G</td><td>入社日</td><td></td><td>2020-04-01</td></tr>
          <tr><td>H</td><td>退社日</td><td></td><td>2025-03-31（退職の場合）</td></tr>
          <tr><td>I</td><td>在籍状態</td><td></td><td>在籍 / 休職 / 退職</td></tr>
          <tr><td>J</td><td>部署</td><td></td><td>製造部（マスターと一致する名称）</td></tr>
          <tr><td>K</td><td>課</td><td></td><td>裁断課</td></tr>
          <tr><td>L</td><td>役職</td><td></td><td>作業員</td></tr>
        </tbody>
      </table>
    </div>
    <a href="employee_export.php?include_retired=1" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-download"></i> 現在のデータをCSVでダウンロード（テンプレート）
    </a>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
