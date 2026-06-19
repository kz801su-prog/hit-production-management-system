-- =====================================================
-- マイグレーション: バーコードスキャンステーション対応
-- 実行順: schema.sql → seed.sql → migration_barcodes.sql
-- =====================================================

-- work_logs に品質グレード列を追加（上司によるS/A/B/C/D評価）
ALTER TABLE work_logs
    ADD COLUMN quality_grade ENUM('S','A','B','C','D') NULL
        COMMENT '上司による品質評価グレード（S/A/B/C/D）。終了時に入力可'
    AFTER memo;

-- chair_type_process_standards に担当部署列を追加（未存在の場合）
-- ※ MySQL 5.7 では IF NOT EXISTS が使えないため、エラーが出た場合は無視してください
ALTER TABLE chair_type_process_standards
    ADD COLUMN dept_id INT NULL
        COMMENT '担当部署ID'
    AFTER process_id,
    ADD CONSTRAINT fk_ctps_dept
        FOREIGN KEY (dept_id) REFERENCES departments(id);
