-- =====================================================
-- 椅子製造 工程管理・標準時間・進捗・評価管理システム
-- データベーススキーマ 第2版
-- 作成日: 2026-06-05
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- 部署マスター
-- =====================================================
CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dept_code VARCHAR(50) NOT NULL UNIQUE COMMENT '部署コード',
    dept_name VARCHAR(100) NOT NULL COMMENT '部署名',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='部署マスター';

-- =====================================================
-- 課マスター
-- =====================================================
CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL COMMENT '所属部ID',
    section_code VARCHAR(50) NOT NULL UNIQUE COMMENT '課コード',
    section_name VARCHAR(100) NOT NULL COMMENT '課名',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_section_dept FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='課マスター';

-- =====================================================
-- 役職マスター
-- =====================================================
CREATE TABLE IF NOT EXISTS positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    position_code VARCHAR(50) NOT NULL UNIQUE COMMENT '役職コード',
    position_name VARCHAR(100) NOT NULL COMMENT '役職名',
    rank_level INT DEFAULT 0 COMMENT '役職レベル（大きいほど上位）',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='役職マスター';

-- =====================================================
-- 社員マスター（人事ライブラリーの中核）
-- 他のアプリでも共通利用できる設計
-- =====================================================
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(50) NOT NULL UNIQUE COMMENT '全アプリ共通の社員コード',
    name VARCHAR(100) NOT NULL COMMENT '社員名',
    name_kana VARCHAR(100) NULL COMMENT '社員名カナ',
    email VARCHAR(255) NULL COMMENT 'メールアドレス',
    phone VARCHAR(50) NULL COMMENT '電話番号',
    address TEXT NULL COMMENT '住所',
    joined_date DATE NULL COMMENT '入社日',
    retired_date DATE NULL COMMENT '退職日',
    employment_status ENUM('active','retired','leave') DEFAULT 'active' COMMENT '在籍状態',
    department_id INT NULL COMMENT '現在の部ID',
    section_id INT NULL COMMENT '現在の課ID',
    position_id INT NULL COMMENT '現在の役職ID',
    is_active TINYINT(1) DEFAULT 1 COMMENT '論理削除フラグ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_emp_dept FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_emp_section FOREIGN KEY (section_id) REFERENCES sections(id),
    CONSTRAINT fk_emp_position FOREIGN KEY (position_id) REFERENCES positions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='社員マスター。全アプリ共通の人事ライブラリー中核テーブル';

-- =====================================================
-- ログイン・権限管理
-- employeesとは分離して管理する
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT '社員ID。employeesと連携',
    login_id VARCHAR(100) NOT NULL UNIQUE COMMENT 'ログインID',
    password_hash VARCHAR(255) NOT NULL COMMENT 'ハッシュ化済みパスワード',
    role ENUM('president','admin','factory_manager','process_leader','worker') DEFAULT 'worker' COMMENT '権限',
    mfa_secret_encrypted TEXT NULL COMMENT 'Authenticator用の暗号化シークレット',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'ログイン有効状態',
    last_login_at DATETIME NULL COMMENT '最終ログイン日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ログイン・権限管理。1社員につき1ユーザーアカウント';

-- =====================================================
-- 部署・役職異動履歴
-- 変更のたびに必ずINSERTする（UPDATEしない）
-- =====================================================
CREATE TABLE IF NOT EXISTS employee_position_histories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT '社員ID',
    department_id INT NULL COMMENT '部ID',
    section_id INT NULL COMMENT '課ID',
    position_id INT NULL COMMENT '役職ID',
    job_type VARCHAR(100) NULL COMMENT '職種',
    start_date DATE NOT NULL COMMENT '開始日',
    end_date DATE NULL COMMENT '終了日。現在のポジションはNULL',
    memo TEXT NULL COMMENT '履歴メモ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_position_history_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='部署・役職異動履歴。変更のたびにINSERT';

-- =====================================================
-- 年度評価
-- 1社員×1年度で1レコード
-- =====================================================
CREATE TABLE IF NOT EXISTS annual_employee_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT '評価対象社員',
    evaluation_year INT NOT NULL COMMENT '評価年度',
    score DECIMAL(10,2) NULL COMMENT '点数評価',
    grade VARCHAR(20) NULL COMMENT '評価ランク（S,A,B,C,D等）',
    evaluation_comment TEXT NULL COMMENT '評価コメント',
    evaluator_employee_id INT NULL COMMENT '評価者社員ID',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_year (employee_id, evaluation_year),
    CONSTRAINT fk_annual_eval_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_annual_eval_evaluator FOREIGN KEY (evaluator_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='年度評価。社員×年度でユニーク';

-- =====================================================
-- 工程マスター
-- 裁断・縫製・張り込みなど製造工程を定義
-- =====================================================
CREATE TABLE IF NOT EXISTS processes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    process_code VARCHAR(50) NOT NULL UNIQUE COMMENT '工程コード',
    process_name VARCHAR(100) NOT NULL COMMENT '工程名',
    display_order INT DEFAULT 0 COMMENT '表示順',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程マスター';

-- =====================================================
-- 椅子タイプ親グループ
-- 例: CHAIR-A（事務用回転椅子）
-- =====================================================
CREATE TABLE IF NOT EXISTS chair_type_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_code VARCHAR(100) NOT NULL UNIQUE COMMENT '基本椅子タイプコード。例：CHAIR-A',
    group_name VARCHAR(255) NOT NULL COMMENT '基本椅子タイプ名',
    memo TEXT NULL COMMENT '基本タイプ全体の説明',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='椅子タイプ親グループ';

-- =====================================================
-- 椅子タイプ（バージョン管理付き）
-- 重要: 1つでも仕様が違えば必ず新バージョンとして登録する
-- CHAIR-A（基本）→ CHAIR-A-01（肘付き）→ CHAIR-A-02（柄合わせ）
-- =====================================================
CREATE TABLE IF NOT EXISTS chair_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chair_type_group_id INT NOT NULL COMMENT '親グループID',
    chair_type_code VARCHAR(100) NOT NULL UNIQUE COMMENT '例：CHAIR-A、CHAIR-A-01、CHAIR-A-02',
    chair_type_name VARCHAR(255) NOT NULL COMMENT '椅子タイプ名',
    version_no INT DEFAULT 0 COMMENT '基本形は0、差分版は1,2,3...',
    is_base_type TINYINT(1) DEFAULT 0 COMMENT '基本形フラグ。基本形なら1',
    base_chair_type_id INT NULL COMMENT '派生元の基本形ID。基本形自身はNULL',
    base_quantity INT DEFAULT 1 COMMENT '標準時間の基準本数',
    shape_summary TEXT NULL COMMENT '形状・仕様の説明',
    difference_summary TEXT NULL COMMENT '基本形との違いの説明',
    search_text TEXT NULL COMMENT '全文検索用テキスト',
    is_active TINYINT(1) DEFAULT 1 COMMENT '論理削除フラグ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_chair_type_group FOREIGN KEY (chair_type_group_id) REFERENCES chair_type_groups(id),
    CONSTRAINT fk_chair_type_base FOREIGN KEY (base_chair_type_id) REFERENCES chair_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='椅子タイプ。仕様が1つでも違えば-01,-02として別バージョン登録';

-- =====================================================
-- 椅子タイプ別工程標準時間
-- 標準時間 = 段取り + 正味作業時間×数量換算 + アローアンス
-- =====================================================
CREATE TABLE IF NOT EXISTS chair_type_process_standards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chair_type_id INT NOT NULL COMMENT '椅子タイプID',
    process_id INT NOT NULL COMMENT '工程ID',
    base_quantity INT DEFAULT 1 COMMENT 'この標準時間の基準本数',
    setup_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '段取り時間（数量に比例しない固定時間）',
    base_work_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '基本本数分の正味作業時間',
    allowance_rate DECIMAL(5,2) DEFAULT 0 COMMENT 'アローアンス率（8.00なら8%）',
    allowance_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '固定アローアンス時間',
    allowance_reason TEXT NULL COMMENT 'アローアンスを設定した理由',
    standard_workers INT DEFAULT 1 COMMENT '標準作業人数',
    difficulty_level INT DEFAULT 1 COMMENT '難易度1-5（評価計算で使用）',
    can_start_parallel     TINYINT(1)   DEFAULT 1   COMMENT '同時進行可能か（1=可）',
    display_order          INT          DEFAULT 0   COMMENT '工程表示順',
    is_active              TINYINT(1)   DEFAULT 1,
    is_outsourced          TINYINT(1)   DEFAULT 0   COMMENT '外注フラグ（1=外注）',
    outsource_vendor       VARCHAR(200) NULL        COMMENT '外注先名',
    outsource_lead_days    INT          DEFAULT 0   COMMENT '外注リードタイム（営業日数）',
    is_temporarily_excluded TINYINT(1)  DEFAULT 0   COMMENT '一時除外フラグ',
    excluded_reason        VARCHAR(500) NULL        COMMENT '一時除外の理由',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chair_process (chair_type_id, process_id),
    CONSTRAINT fk_ctps_chair_type FOREIGN KEY (chair_type_id) REFERENCES chair_types(id),
    CONSTRAINT fk_ctps_process FOREIGN KEY (process_id) REFERENCES processes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='椅子タイプ別工程標準時間';

-- =====================================================
-- 差分工程管理
-- 基本形との違いを工程単位で加算・減算・置換・追加・削除
-- =====================================================
CREATE TABLE IF NOT EXISTS chair_type_process_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chair_type_id INT NOT NULL COMMENT '差分を持つ椅子タイプID',
    process_id INT NULL COMMENT '対象工程ID。工程追加の場合は追加先工程',
    adjustment_type ENUM('add','subtract','replace','add_process','remove_process') NOT NULL
        COMMENT '加算/減算/置換/工程追加/工程削除',
    adjustment_name VARCHAR(255) NOT NULL COMMENT '差分名（例：肘付き加算、柄合わせ）',
    adjustment_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '加算または減算する時間',
    adjustment_rate DECIMAL(5,2) DEFAULT 0 COMMENT '率で調整する場合の値',
    applies_per ENUM('order','unit','part','meter') DEFAULT 'order'
        COMMENT '適用単位：指示全体/本数単位/パーツ単位/m単位',
    reason TEXT NULL COMMENT 'この調整が必要な理由',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ctpa_chair_type FOREIGN KEY (chair_type_id) REFERENCES chair_types(id),
    CONSTRAINT fk_ctpa_process FOREIGN KEY (process_id) REFERENCES processes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='差分工程管理。基本形との違いを工程単位で管理';

-- =====================================================
-- 椅子タイプ画像・図面
-- 完成写真、図面、作業指示図などを登録
-- =====================================================
CREATE TABLE IF NOT EXISTS chair_type_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chair_type_id INT NOT NULL COMMENT '椅子タイプID',
    media_type ENUM('photo','drawing','instruction','sewing_line','upholstery_point','difference','other')
        DEFAULT 'photo' COMMENT '写真/図面/作業指示図/縫製ライン/張り込みポイント/差分説明/その他',
    file_path VARCHAR(255) NOT NULL COMMENT '保存ファイルパス（uploadsからの相対パス）',
    original_file_name VARCHAR(255) NULL COMMENT '元ファイル名',
    caption VARCHAR(255) NULL COMMENT '画像説明',
    display_order INT DEFAULT 0 COMMENT '表示順',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ctm_chair_type FOREIGN KEY (chair_type_id) REFERENCES chair_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='椅子タイプ画像・図面管理';

-- =====================================================
-- 椅子タイプ検索キーワード
-- タイプコードだけでは探せない場合のための補助検索
-- =====================================================
CREATE TABLE IF NOT EXISTS chair_type_keywords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chair_type_id INT NOT NULL COMMENT '椅子タイプID',
    keyword VARCHAR(100) NOT NULL COMMENT '検索キーワード',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chair_keyword (chair_type_id, keyword),
    CONSTRAINT fk_ctk_chair_type FOREIGN KEY (chair_type_id) REFERENCES chair_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='椅子タイプ検索キーワード';

-- =====================================================
-- 作業指示管理
-- 作成時に椅子タイプ情報をスナップショットとして保存する
-- これにより後からマスターを変更しても過去の指示は守られる
-- =====================================================
CREATE TABLE IF NOT EXISTS manufacturing_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no      VARCHAR(100) NOT NULL UNIQUE COMMENT '作業指示番号（例：WO-2026-001）',
    customer_name VARCHAR(200) NULL COMMENT '顧客名',
    project_name  VARCHAR(200) NULL COMMENT '物件名（現場名）',
    order_date    DATE         NULL COMMENT '受注日',
    chair_type_id INT NOT NULL COMMENT '選択された椅子タイプID',
    quantity INT NOT NULL COMMENT '製造数量',
    due_date DATE NULL COMMENT '納期',
    priority ENUM('normal','high','urgent') DEFAULT 'normal' COMMENT '優先度',
    status ENUM('planned','in_progress','completed','on_hold','cancelled') DEFAULT 'planned' COMMENT '状態',
    chair_type_snapshot JSON NULL COMMENT '作成時点の椅子タイプ情報スナップショット',
    memo TEXT NULL COMMENT '作業指示メモ',
    created_by INT NULL COMMENT '作成者ユーザーID',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mo_chair_type FOREIGN KEY (chair_type_id) REFERENCES chair_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作業指示管理。作成時にスナップショット保存';

-- =====================================================
-- 作業指示別工程進捗（同時並行対応）
-- 複数の作業指示が同時に、かつ各工程も並行して動く前提
-- =====================================================
CREATE TABLE IF NOT EXISTS manufacturing_order_processes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_id INT NOT NULL COMMENT '作業指示ID',
    process_id INT NOT NULL COMMENT '工程ID',
    process_group VARCHAR(50) NULL COMMENT '同時並行工程グループ識別子',
    process_sequence INT DEFAULT 0 COMMENT '表示順（作業順序とは別）',
    can_start_parallel TINYINT(1) DEFAULT 1 COMMENT '前工程完了前に開始できるか',
    planned_setup_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '予定段取り時間',
    planned_work_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '予定正味作業時間',
    planned_adjustment_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '差分加減算後の時間',
    planned_allowance_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '予定アローアンス時間',
    planned_total_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '予定合計標準時間',
    standard_snapshot JSON NULL COMMENT '標準時間・差分・アローアンスのスナップショット',
    actual_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '実績時間（work_logsの集計）',
    planned_start DATETIME NULL COMMENT '予定開始日時',
    planned_end DATETIME NULL COMMENT '予定終了日時',
    actual_start DATETIME NULL COMMENT '実績開始日時',
    actual_end DATETIME NULL COMMENT '実績終了日時',
    progress_rate DECIMAL(5,2) DEFAULT 0 COMMENT '進捗率（0-100）',
    status ENUM('not_started','in_progress','completed','delayed','on_hold') DEFAULT 'not_started' COMMENT '工程状態',
    delay_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '遅れ時間（負=早い）',
    delay_status ENUM('normal','warning','delayed','critical') DEFAULT 'normal' COMMENT '遅延レベル',
    performance_rate DECIMAL(10,2) NULL COMMENT '達成率（標準時間÷実績時間×100）',
    assigned_worker_count INT DEFAULT 1 COMMENT '予定作業人数',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_process (manufacturing_order_id, process_id),
    CONSTRAINT fk_mop_order FOREIGN KEY (manufacturing_order_id) REFERENCES manufacturing_orders(id),
    CONSTRAINT fk_mop_process FOREIGN KEY (process_id) REFERENCES processes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作業指示別工程進捗。同時並行対応';

-- =====================================================
-- 作業実績ログ
-- 開始・終了・中断・数量・不良を記録
-- =====================================================
CREATE TABLE IF NOT EXISTS work_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_id INT NOT NULL COMMENT '作業指示ID',
    process_id INT NOT NULL COMMENT '工程ID',
    employee_id INT NOT NULL COMMENT '作業者社員ID',
    started_at DATETIME NOT NULL COMMENT '作業開始日時',
    ended_at DATETIME NULL COMMENT '作業終了日時（未終了はNULL）',
    break_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '中断・休憩時間（分）',
    actual_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '実作業時間（終了時に自動計算）',
    completed_qty INT DEFAULT 0 COMMENT '完了数量',
    defect_qty INT DEFAULT 0 COMMENT '不良数量',
    rework_qty INT DEFAULT 0 COMMENT '手直し数量',
    memo TEXT NULL COMMENT '作業メモ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_work_order FOREIGN KEY (manufacturing_order_id) REFERENCES manufacturing_orders(id),
    CONSTRAINT fk_work_process FOREIGN KEY (process_id) REFERENCES processes(id),
    CONSTRAINT fk_work_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作業実績ログ';

-- =====================================================
-- 月別作業者評価スコア
-- 効率・品質・安定性・難易度・改善の5軸で評価
-- =====================================================
CREATE TABLE IF NOT EXISTS monthly_worker_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT '社員ID',
    target_month CHAR(7) NOT NULL COMMENT '対象月（YYYY-MM形式）',
    efficiency_score DECIMAL(10,2) DEFAULT 0 COMMENT '効率点（35%比重）',
    quality_score DECIMAL(10,2) DEFAULT 0 COMMENT '品質点（30%比重）',
    stability_score DECIMAL(10,2) DEFAULT 0 COMMENT '安定性点（15%比重）',
    difficulty_score DECIMAL(10,2) DEFAULT 0 COMMENT '難易度点（10%比重）',
    improvement_score DECIMAL(10,2) DEFAULT 0 COMMENT '改善貢献点（10%比重）',
    total_score DECIMAL(10,2) DEFAULT 0 COMMENT '総合点（加重平均）',
    manager_comment TEXT NULL COMMENT '上司コメント',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_worker_month (employee_id, target_month),
    CONSTRAINT fk_monthly_score_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='月別作業者評価スコア';

-- =====================================================
-- 問題点ログ
-- 遅延・品質問題などの記録
-- =====================================================
CREATE TABLE IF NOT EXISTS issue_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_id INT NULL COMMENT '作業指示ID',
    process_id INT NULL COMMENT '工程ID',
    employee_id INT NULL COMMENT '関係社員ID（問題を起こした/報告した）',
    issue_type ENUM('material','previous_process','skill','machine','instruction','quality','other') NOT NULL
        COMMENT '問題区分：資材/前工程/技能/設備/指示/品質/その他',
    issue_detail TEXT NOT NULL COMMENT '問題内容の詳細',
    impact_minutes DECIMAL(10,2) DEFAULT 0 COMMENT '影響時間（分）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_issue_order FOREIGN KEY (manufacturing_order_id) REFERENCES manufacturing_orders(id),
    CONSTRAINT fk_issue_process FOREIGN KEY (process_id) REFERENCES processes(id),
    CONSTRAINT fk_issue_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='問題点ログ';

-- =====================================================
-- 改善アクション
-- issue_logsへの対策を管理
-- =====================================================
CREATE TABLE IF NOT EXISTS improvement_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NULL COMMENT '関連問題ID（issue_logs）',
    improvement_title VARCHAR(255) NOT NULL COMMENT '改善タイトル',
    improvement_detail TEXT NULL COMMENT '改善内容の詳細',
    responsible_employee_id INT NULL COMMENT '責任者社員ID',
    status ENUM('planned','doing','done','cancelled') DEFAULT 'planned' COMMENT '進捗状態',
    expected_effect_minutes DECIMAL(10,2) NULL COMMENT '期待効果時間（分）',
    actual_effect_minutes DECIMAL(10,2) NULL COMMENT '実績効果時間（分）',
    due_date DATE NULL COMMENT '期限',
    completed_at DATETIME NULL COMMENT '完了日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_improvement_issue FOREIGN KEY (issue_id) REFERENCES issue_logs(id),
    CONSTRAINT fk_improvement_employee FOREIGN KEY (responsible_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='改善アクション管理';

-- =====================================================
-- 社長の言葉
-- ログイン時・ダッシュボードに表示
-- =====================================================
CREATE TABLE IF NOT EXISTS president_words (
    id INT AUTO_INCREMENT PRIMARY KEY,
    display_no INT NOT NULL COMMENT '表示番号（重複時は自動で空き番号へ）',
    speaker_name VARCHAR(100) DEFAULT '社長' COMMENT '発言者名',
    message TEXT NOT NULL COMMENT '発言内容',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='社長の言葉';

-- =====================================================
-- バックアップ履歴
-- =====================================================
CREATE TABLE IF NOT EXISTS backup_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_file VARCHAR(255) NOT NULL COMMENT 'バックアップファイル名',
    status ENUM('success','failed') DEFAULT 'success' COMMENT '結果',
    error_message TEXT NULL COMMENT 'エラー内容',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='バックアップ履歴';

-- =====================================================
-- 操作ログ（監査ログ）
-- 誰がいつ何を変更したかを完全記録
-- =====================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT '操作ユーザーID（未ログインはNULL）',
    action VARCHAR(100) NOT NULL COMMENT '操作内容（login/create/update/delete等）',
    target_table VARCHAR(100) NULL COMMENT '対象テーブル名',
    target_id INT NULL COMMENT '対象レコードID',
    before_data JSON NULL COMMENT '変更前データ（JSON）',
    after_data JSON NULL COMMENT '変更後データ（JSON）',
    ip_address VARCHAR(100) NULL COMMENT 'IPアドレス',
    user_agent TEXT NULL COMMENT 'ブラウザ情報',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作履歴（監査ログ）。誰がいつ何を変更したか';

-- =====================================================
-- システム設定
-- =====================================================
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    description VARCHAR(255) NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='システム設定（管理者が変更するアプリ設定値）';

SET FOREIGN_KEY_CHECKS = 1;
