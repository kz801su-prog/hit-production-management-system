-- =====================================================
-- マイグレーション v3
-- 実行方法: phpMyAdmin の SQL タブで実行（一度だけ）
-- =====================================================

-- manufacturing_orders に顧客名・物件名・受注日を追加
ALTER TABLE manufacturing_orders
  ADD COLUMN customer_name VARCHAR(200) NULL COMMENT '顧客名' AFTER order_no,
  ADD COLUMN project_name  VARCHAR(200) NULL COMMENT '物件名（現場名）' AFTER customer_name,
  ADD COLUMN order_date    DATE         NULL COMMENT '受注日' AFTER project_name;

-- chair_type_process_standards に外注管理カラムを追加
ALTER TABLE chair_type_process_standards
  ADD COLUMN is_outsourced           TINYINT(1)   DEFAULT 0   COMMENT '外注フラグ（1=外注）' AFTER is_active,
  ADD COLUMN outsource_vendor        VARCHAR(200) NULL        COMMENT '外注先名' AFTER is_outsourced,
  ADD COLUMN outsource_lead_days     INT          DEFAULT 0   COMMENT '外注リードタイム（営業日数）' AFTER outsource_vendor,
  ADD COLUMN is_temporarily_excluded TINYINT(1)   DEFAULT 0   COMMENT '一時除外フラグ（1=この工程をスキップ）' AFTER outsource_lead_days,
  ADD COLUMN excluded_reason         VARCHAR(500) NULL        COMMENT '一時除外の理由' AFTER is_temporarily_excluded;
