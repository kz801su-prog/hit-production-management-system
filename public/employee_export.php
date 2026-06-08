<?php
// =====================================================
// 社員情報 CSV エクスポート
// UTF-8 BOM付きCSV → Excel で直接開ける
// 権限: admin以上
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';

requireLogin();
requireRole('admin');

$includeRetired = !empty($_GET['include_retired']);

$sql = "SELECT e.employee_code, e.name, e.name_kana, e.email, e.phone, e.address,
               e.joined_date, e.retired_date, e.employment_status,
               d.dept_name, sec.section_name, p.position_name
        FROM employees e
        LEFT JOIN departments d   ON e.department_id = d.id
        LEFT JOIN sections    sec ON e.section_id     = sec.id
        LEFT JOIN positions   p   ON e.position_id    = p.id
        WHERE e.is_active = 1";
if (!$includeRetired) {
    $sql .= " AND e.employment_status != 'retired'";
}
$sql .= " ORDER BY e.employee_code";

$rows = dbFetchAll($sql, []);

$headers = [
    '社員コード','氏名','氏名カナ','メール','電話','住所',
    '入社日','退社日','在籍状態','部署','課','役職',
];

$statusMap = ['active' => '在籍', 'leave' => '休職', 'retired' => '退職'];

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="employees_' . date('Ymd') . '.csv"');
header('Cache-Control: no-cache');

$fp = fopen('php://output', 'w');
// UTF-8 BOM（Excel 用）
fwrite($fp, "\xEF\xBB\xBF");

fputcsv($fp, $headers);
foreach ($rows as $r) {
    fputcsv($fp, [
        $r['employee_code'],
        $r['name'],
        $r['name_kana']       ?? '',
        $r['email']           ?? '',
        $r['phone']           ?? '',
        $r['address']         ?? '',
        $r['joined_date']     ?? '',
        $r['retired_date']    ?? '',
        $statusMap[$r['employment_status']] ?? $r['employment_status'],
        $r['dept_name']       ?? '',
        $r['section_name']    ?? '',
        $r['position_name']   ?? '',
    ]);
}
fclose($fp);
exit;
