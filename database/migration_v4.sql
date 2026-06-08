-- =====================================================
-- マイグレーション v4
-- 実行方法: phpMyAdmin の SQL タブで実行（一度だけ）
-- =====================================================

-- 職能ランクテーブル（社員×工程）
CREATE TABLE IF NOT EXISTS employee_skill_ranks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT '社員ID',
    process_id  INT NOT NULL COMMENT '工程ID',
    rank_level  INT DEFAULT 1 COMMENT '職能ランク 1=見習い 2=補助 3=一般 4=熟練 5=マスター',
    memo        TEXT NULL COMMENT '備考',
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id INT NULL,
    UNIQUE KEY uq_emp_proc (employee_id, process_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (process_id)  REFERENCES processes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='社員×工程の職能ランク';

-- 社員編集履歴テーブル
CREATE TABLE IF NOT EXISTS employee_edit_logs (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    employee_id        INT NOT NULL COMMENT '対象社員ID',
    field_name         VARCHAR(100) NOT NULL COMMENT '変更フィールド名',
    old_value          TEXT NULL COMMENT '変更前の値',
    new_value          TEXT NULL COMMENT '変更後の値',
    changed_by_user_id INT NULL COMMENT '変更者ユーザーID',
    changed_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='社員情報の編集履歴';

-- employees に retired_date がまだ無い場合のみ追加（すでにある場合はスキップ）
ALTER TABLE employees
    MODIFY COLUMN employment_status ENUM('active','leave','retired') DEFAULT 'active';

-- コスト設定を system_settings に追加
INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
('monthly_salary_total',      '0', '月間給与総額（円）'),
('monthly_overhead_cost',     '0', '月間管理費（円）'),
('cost_target_month',         '',  'コスト計算対象月（YYYY-MM 空欄=当月）'),
('monthly_production_target', '0', '月間生産目標本数（0=自動計算）');
