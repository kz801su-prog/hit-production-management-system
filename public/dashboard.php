<?php
// =====================================================
// ダッシュボード ルーター
// 役割に応じて経営者用 / 従業員用を切り替える
// =====================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/logger.php';

requireLogin();

if (isManager()) {
    // president / admin / factory_manager → 経営者向け
    require __DIR__ . '/dashboard_exec.php';
} else {
    // process_leader / worker → 従業員向け
    require __DIR__ . '/dashboard_worker.php';
}
