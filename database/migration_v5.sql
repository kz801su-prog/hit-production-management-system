-- =====================================================
-- マイグレーション v5
-- 月次予算テーブル + ダッシュボード表示設定
-- 実行方法: phpMyAdmin の SQL タブで実行（一度だけ）
-- =====================================================

-- 月次予算計画テーブル
CREATE TABLE IF NOT EXISTS monthly_budget (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    `year_month`        CHAR(7)  NOT NULL                   COMMENT 'YYYY-MM 形式',
    target_qty          INT      NOT NULL DEFAULT 0         COMMENT '目標本数',
    salary_forecast     INT      NOT NULL DEFAULT 0         COMMENT '給与予測（円）',
    overhead_forecast   INT      NOT NULL DEFAULT 0         COMMENT '管理費予測（円）',
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id  INT NULL,
    UNIQUE KEY uq_ym (`year_month`),
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='月次予算計画（Excelインポート）';

-- ダッシュボード表示設定キーを追加
INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
(
    'dashboard_widgets',
    '{"daily_chart":1,"monthly_chart":1,"budget_chart":1,"delay_alerts":1,"upcoming_due":1,"dept_status":1,"cost_card":1,"gantt":1}',
    'ダッシュボード表示ウィジェット設定（JSON）'
);
