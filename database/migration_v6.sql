-- =====================================================
-- マイグレーション v6
-- 工程標準時間テーブルに責任部署列を追加
-- 実行方法: phpMyAdmin の SQL タブで実行（一度だけ）
-- =====================================================

-- dept_id 列は migration_v5.sql で追加済みのため、ここでは外部キー制約のみ追加する
ALTER TABLE chair_type_process_standards
    ADD CONSTRAINT fk_ctps_dept
        FOREIGN KEY (dept_id) REFERENCES departments(id) ON DELETE SET NULL;
