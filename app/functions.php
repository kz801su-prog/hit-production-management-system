<?php
// =====================================================
// 共通ユーティリティ関数
// 目的: アプリ全体で使う汎用ヘルパー関数をまとめる
// 呼び出し元: すべてのページ・サービス
// =====================================================

/**
 * XSS対策付きhtmlspecialchars のショートカット
 */
function h(mixed $str): string {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * 分を「X時間Y分」形式に変換
 */
function formatMinutes(float $minutes): string {
    if ($minutes <= 0) return '0分';
    $h = floor($minutes / 60);
    $m = round($minutes % 60);
    if ($h === 0.0) return "{$m}分";
    return "{$h}時間{$m}分";
}

/**
 * 達成率から判定ラベルと Bootstrap カラークラスを返す
 * @return array ['label' => string, 'class' => string]
 */
function performanceLabel(float $rate): array {
    if ($rate >= PERF_VERY_FAST) return ['label' => 'かなり早い', 'class' => 'success'];
    if ($rate >= PERF_FAST)      return ['label' => '早い',       'class' => 'primary'];
    if ($rate >= PERF_NORMAL)    return ['label' => '標準',       'class' => 'info'];
    if ($rate >= PERF_SLOW)      return ['label' => '遅れ気味',   'class' => 'warning'];
    return ['label' => '大幅遅れ', 'class' => 'danger'];
}

/**
 * 遅延ステータスから Bootstrap カラークラスを返す
 */
function delayStatusClass(string $status): string {
    return match($status) {
        'warning'  => 'warning',
        'delayed'  => 'danger',
        'critical' => 'dark',
        default    => 'success',
    };
}

/**
 * 優先度ラベルと Bootstrap バッジカラーを返す
 * @return array ['label' => string, 'class' => string]
 */
function priorityLabel(string $priority): array {
    return match($priority) {
        'high'   => ['label' => '高',     'class' => 'warning'],
        'urgent' => ['label' => '緊急',   'class' => 'danger'],
        default  => ['label' => '通常',   'class' => 'secondary'],
    };
}

/**
 * 工程状態ラベルと Bootstrap カラークラスを返す
 */
function processStatusLabel(string $status): array {
    return match($status) {
        'in_progress' => ['label' => '作業中', 'class' => 'primary'],
        'completed'   => ['label' => '完了',   'class' => 'success'],
        'delayed'     => ['label' => '遅れ',   'class' => 'danger'],
        'on_hold'     => ['label' => '保留',   'class' => 'warning'],
        default       => ['label' => '未着手', 'class' => 'secondary'],
    ];
}

/**
 * 作業指示状態ラベルを返す
 */
function orderStatusLabel(string $status): array {
    return match($status) {
        'in_progress' => ['label' => '進行中',   'class' => 'primary'],
        'completed'   => ['label' => '完了',     'class' => 'success'],
        'on_hold'     => ['label' => '保留',     'class' => 'warning'],
        'cancelled'   => ['label' => 'キャンセル', 'class' => 'secondary'],
        default       => ['label' => '計画中',   'class' => 'info'],
    ];
}

/**
 * 数値を安全に取得（null / 空文字は 0 にする）
 */
function safeFloat(mixed $val): float {
    return (float)($val ?? 0);
}

/**
 * 日付フォーマット: NULL対応
 */
function formatDate(?string $date, string $format = 'Y年m月d日'): string {
    if (!$date) return '―';
    return date($format, strtotime($date));
}

/**
 * 日時フォーマット: NULL対応
 */
function formatDatetime(?string $dt): string {
    if (!$dt) return '―';
    return date('Y/m/d H:i', strtotime($dt));
}

/**
 * GETパラメータを整数で安全に取得
 */
function getInt(string $key, int $default = 0): int {
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

/**
 * POSTパラメータを文字列で安全に取得
 */
function postStr(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/**
 * POSTパラメータを整数で安全に取得
 */
function postInt(string $key, int $default = 0): int {
    return isset($_POST[$key]) ? (int)$_POST[$key] : $default;
}

/**
 * POSTパラメータを浮動小数点で安全に取得
 */
function postFloat(string $key, float $default = 0.0): float {
    return isset($_POST[$key]) ? (float)$_POST[$key] : $default;
}

/**
 * フラッシュメッセージをセッションに保存
 */
function setFlash(string $message, string $type = 'success'): void {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type']    = $type;
}

/**
 * フラッシュメッセージのHTMLを返してセッションから削除
 */
function getFlashHtml(): string {
    if (empty($_SESSION['flash_message'])) return '';
    $msg  = h($_SESSION['flash_message']);
    $type = h($_SESSION['flash_type'] ?? 'success');
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
    return "<div class=\"alert alert-{$type} alert-dismissible fade show\" role=\"alert\">
        {$msg}
        <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>
    </div>";
}

/**
 * 作業指示番号を自動生成（例: WO-2026-0001）
 */
function generateOrderNo(): string {
    $year  = date('Y');
    $count = (int)(dbFetchOne(
        "SELECT COUNT(*)+1 AS cnt FROM manufacturing_orders WHERE order_no LIKE ?",
        ["WO-{$year}-%"]
    )['cnt'] ?? 1);
    return sprintf('WO-%s-%04d', $year, $count);
}

/**
 * ページタイトルとBreadcrumbを設定するためのグローバル変数へセット
 */
function setPageTitle(string $title): void {
    $GLOBALS['page_title'] = $title;
}
