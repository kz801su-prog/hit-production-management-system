-- =====================================================
-- マイグレーション v8
-- 内容（全て IF NOT EXISTS のため重複実行は安全）:
--   1. eval_criteria             — 評価基準ルーブリック＋初期データ
--   2. order_quality_evaluations — 作業指示品質評価
--   3. improvement_issues        — 課題管理（カイゼン記録）
--   4. issue_weekly_reports      — 週次進捗報告
--   5. eval_score_adjustments    — 社長による評価加算減算（新規機能）
--
-- 実行方法: Xserver phpMyAdmin → kz801xs_seisankanri → SQLタブで実行
-- ※ CREATE TABLE IF NOT EXISTS を使用しているため、既存テーブルはスキップ
-- =====================================================

-- ─────────────────────────────────────────────────────────────────
-- 1. 評価基準ルーブリック
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS eval_criteria (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    category    ENUM('quality','stability','difficulty','improvement') NOT NULL
                    COMMENT '評価カテゴリ',
    score       TINYINT NOT NULL    COMMENT '段階 1〜5',
    label       VARCHAR(50) NOT NULL COMMENT '段階ラベル（例: 優秀）',
    description TEXT NOT NULL       COMMENT '誰でも判断できる具体的な基準',
    sort_order  INT DEFAULT 0,
    updated_by  INT NULL,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ec (category, score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='評価基準ルーブリック';

-- デフォルト評価基準を投入（INSERT IGNORE: 既存行はスキップ）
INSERT IGNORE INTO eval_criteria (category, score, label, description, sort_order) VALUES
('quality', 5, '優秀',        '不良・手直しゼロ。顧客または次工程から称賛あり。',                               1),
('quality', 4, '良好',        '不良・手直しゼロ。仕上げ・検査基準を完全クリア。',                               2),
('quality', 3, '標準',        '軽微な手直し 1〜2 件。指摘後すぐに修正。',                                      3),
('quality', 2, '要改善',      '手直し 3 件以上または客先・後工程から指摘を受けた。',                            4),
('quality', 1, '不可',        '重大不良・製品やり直し・クレームが発生した。',                                   5),
('stability', 5, '非常に安定', '月間達成率のばらつき（標準偏差）が ±5% 未満。',                               1),
('stability', 4, '安定',       '月間達成率のばらつきが ±10% 未満。',                                         2),
('stability', 3, '普通',       '月間達成率のばらつきが ±15% 未満。',                                         3),
('stability', 2, '不安定',     '月間達成率のばらつきが ±20% 未満。',                                         4),
('stability', 1, '大きく不安定','月間達成率のばらつきが ±20% 以上。',                                         5),
('difficulty', 5, 'レベル5',  '最高難度の工程（特殊技能・熟練が必要）を主担当。',                               1),
('difficulty', 4, 'レベル4',  '高難度工程を主担当。',                                                         2),
('difficulty', 3, 'レベル3',  '中程度の難度工程を担当。',                                                      3),
('difficulty', 2, 'レベル2',  '比較的簡単な工程を主担当。',                                                    4),
('difficulty', 1, 'レベル1',  '標準的・単純工程のみ担当。',                                                    5),
('improvement', 5, '特別貢献', '月内に課題をクローズ 4 件以上。またはチームへの波及効果が大きい改善を主導。',   1),
('improvement', 4, '積極貢献', '月内に課題をクローズ 3 件。',                                                  2),
('improvement', 3, '標準',     '月内に課題をクローズ 2 件。',                                                  3),
('improvement', 2, '少ない',   '月内に課題をクローズ 1 件。',                                                  4),
('improvement', 1, '貢献なし', '当月クローズ 0 件。',                                                          5);

-- ─────────────────────────────────────────────────────────────────
-- 2. 作業指示品質評価
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS order_quality_evaluations (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_id INT NOT NULL UNIQUE    COMMENT '作業指示ID（1指示1評価）',
    evaluator_user_id      INT NOT NULL           COMMENT '評価者（責任者）',
    quality_score          TINYINT NOT NULL       COMMENT '品質スコア 1〜5',
    defect_count           INT DEFAULT 0          COMMENT '不良数',
    rework_count           INT DEFAULT 0          COMMENT '手直し数',
    comment                TEXT NULL,
    evaluated_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_oqe_order     FOREIGN KEY (manufacturing_order_id) REFERENCES manufacturing_orders(id),
    CONSTRAINT fk_oqe_evaluator FOREIGN KEY (evaluator_user_id)      REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作業指示品質評価';

-- ─────────────────────────────────────────────────────────────────
-- 3. 課題管理（カイゼン記録）
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS improvement_issues (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    issue_no               VARCHAR(20) NOT NULL UNIQUE COMMENT '課題番号 ISSUE-2026-001',
    issue_type             ENUM('team','individual') NOT NULL,
    employee_id            INT NULL               COMMENT '個人課題の対象社員',
    dept_id                INT NULL               COMMENT 'チーム課題の対象部署',
    title                  VARCHAR(200) NOT NULL,
    description            TEXT NULL,
    target_metric          VARCHAR(100) NULL      COMMENT '改善指標（例: 不良率）',
    baseline_value         DECIMAL(10,2) NULL     COMMENT '現状値（登録時）',
    target_value           DECIMAL(10,2) NULL     COMMENT '目標値',
    identified_by_user_id  INT NOT NULL,
    identified_at          DATE NOT NULL,
    status                 ENUM('open','in_progress','resolved') DEFAULT 'open',
    resolved_at            DATETIME NULL,
    resolved_by_user_id    INT NULL,
    resolution_note        TEXT NULL,
    created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ii_employee   FOREIGN KEY (employee_id)           REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_ii_dept       FOREIGN KEY (dept_id)               REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_ii_identified FOREIGN KEY (identified_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_ii_resolved   FOREIGN KEY (resolved_by_user_id)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='課題管理（カイゼン記録）';

-- ─────────────────────────────────────────────────────────────────
-- 4. 週次進捗報告
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS issue_weekly_reports (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    issue_id             INT NOT NULL,
    reported_by_user_id  INT NOT NULL,
    report_week          DATE NOT NULL     COMMENT '週の月曜日の日付',
    current_value        DECIMAL(10,2) NULL COMMENT '今週の現状値',
    progress_note        TEXT NOT NULL,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_issue_week (issue_id, report_week),
    CONSTRAINT fk_iwr_issue    FOREIGN KEY (issue_id)            REFERENCES improvement_issues(id) ON DELETE CASCADE,
    CONSTRAINT fk_iwr_reporter FOREIGN KEY (reported_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='課題週次進捗報告';

-- ─────────────────────────────────────────────────────────────────
-- 5. 社長による評価加算減算（新規機能）
--    total_score = 自動計算値 + SUM(points)  ※0〜150点にクランプ
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS eval_score_adjustments (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    employee_id        INT NOT NULL          COMMENT '対象社員ID',
    target_month       CHAR(7) NOT NULL      COMMENT '対象月（YYYY-MM形式）',
    points             DECIMAL(6,2) NOT NULL COMMENT '加算は正値、減算は負値',
    reason             VARCHAR(255) NOT NULL COMMENT '加減算の理由',
    created_by_user_id INT NOT NULL          COMMENT '登録者ユーザーID（社長）',
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_esa_employee FOREIGN KEY (employee_id)        REFERENCES employees(id),
    CONSTRAINT fk_esa_user     FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='社長による月次評価加算減算';
