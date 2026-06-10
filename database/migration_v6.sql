-- =====================================================
-- マイグレーション v6
-- 工程標準時間テーブルに責任部署列を追加
-- 実行方法: phpMyAdmin の SQL タブで実行（一度だけ）
-- =====================================================

ALTER TABLE chair_type_process_standards
    ADD COLUMN IF NOT EXISTS dept_id INT NULL COMMENT '責任部署ID'
        AFTER process_id,
    ADD CONSTRAINT fk_ctps_dept
        FOREIGN KEY (dept_id) REFERENCES departments(id) ON DELETE SET NULL;
