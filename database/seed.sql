-- =====================================================
-- 椅子製造 工程管理システム 初期データ
-- =====================================================

SET NAMES utf8mb4;

-- =====================================================
-- 部署マスター初期データ
-- =====================================================
INSERT IGNORE INTO departments (dept_code, dept_name, display_order) VALUES
('MFG',  '製造部',     1),
('QC',   '品質管理部',  2),
('MGMT', '管理部',     3);

-- =====================================================
-- 課マスター初期データ
-- =====================================================
INSERT IGNORE INTO sections (department_id, section_code, section_name, display_order) VALUES
(1, 'CUT',    '裁断課',   1),
(1, 'SEW',    '縫製課',   2),
(1, 'UPH',    '張り込み課', 3),
(1, 'PACK',   '梱包課',   4),
(2, 'INSP',   '検品課',   1),
(3, 'ADMIN',  '総務課',   1);

-- =====================================================
-- 役職マスター初期データ
-- =====================================================
INSERT IGNORE INTO positions (position_code, position_name, rank_level, display_order) VALUES
('PRES',    '社長',       10, 1),
('DEPT_MGR','部長',        8, 2),
('SECT_MGR','課長',        6, 3),
('LEADER',  '班長',        4, 4),
('SENIOR',  'ベテラン作業員', 2, 5),
('WORKER',  '作業員',      1, 6);

-- =====================================================
-- 社員初期データ（テスト用）
-- =====================================================
INSERT IGNORE INTO employees
    (employee_code, name, name_kana, email, joined_date, employment_status, department_id, position_id)
VALUES
('EMP-001', '大津 一郎',   'オオツ イチロウ', 'ichiro@otsu-furniture.com',   '2010-04-01', 'active', 3, 1),
('EMP-002', '田中 健二',   'タナカ ケンジ',   'kenji@otsu-furniture.com',    '2012-06-01', 'active', 1, 2),
('EMP-003', '山田 太郎',   'ヤマダ タロウ',   'taro@otsu-furniture.com',     '2015-04-01', 'active', 1, 4),
('EMP-004', '佐藤 花子',   'サトウ ハナコ',   'hanako@otsu-furniture.com',   '2018-04-01', 'active', 1, 6),
('EMP-005', '鈴木 次郎',   'スズキ ジロウ',   'jiro@otsu-furniture.com',     '2019-10-01', 'active', 1, 6),
('EMP-006', '高橋 三郎',   'タカハシ サブロウ','saburo@otsu-furniture.com',   '2020-04-01', 'active', 2, 3),
('EMP-007', '伊藤 美咲',   'イトウ ミサキ',   'misaki@otsu-furniture.com',   '2021-04-01', 'active', 1, 6);

-- =====================================================
-- ユーザー初期データ
-- パスワードはすべて "password123" のbcryptハッシュ
-- 本番環境では必ず変更すること
-- =====================================================
INSERT IGNORE INTO users (employee_id, login_id, password_hash, role) VALUES
(1, 'president',     '$2y$12$.btR48KIN3s.s.MkqcsGTuJSTKfv1aR79N0nySycKdRizLQbdttkO', 'president'),
(2, 'admin',         '$2y$12$.btR48KIN3s.s.MkqcsGTuJSTKfv1aR79N0nySycKdRizLQbdttkO', 'admin'),
(3, 'yamada',        '$2y$12$.btR48KIN3s.s.MkqcsGTuJSTKfv1aR79N0nySycKdRizLQbdttkO', 'process_leader'),
(4, 'sato',          '$2y$12$.btR48KIN3s.s.MkqcsGTuJSTKfv1aR79N0nySycKdRizLQbdttkO', 'worker'),
(5, 'suzuki',        '$2y$12$.btR48KIN3s.s.MkqcsGTuJSTKfv1aR79N0nySycKdRizLQbdttkO', 'worker'),
(7, 'ito',           '$2y$12$.btR48KIN3s.s.MkqcsGTuJSTKfv1aR79N0nySycKdRizLQbdttkO', 'worker');

-- =====================================================
-- システム設定初期値
-- =====================================================
INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
('totp_required', '0', 'ログイン時にAuthenticator認証を必須にする（1:必須 / 0:任意）');

-- =====================================================
-- 工程マスター初期データ
-- =====================================================
INSERT IGNORE INTO processes (process_code, process_name, display_order) VALUES
('CUT',    '裁断',       10),
('SEW',    '縫製',       20),
('FOAM',   'ウレタン下地', 30),
('FRAME',  'フレーム組立', 40),
('UPH',    '張り込み',    50),
('INSP',   '検品',       60),
('PACK',   '梱包',       70),
('PARTS',  '部品加工',    5);

-- =====================================================
-- 椅子タイプ親グループ初期データ
-- =====================================================
INSERT IGNORE INTO chair_type_groups (group_code, group_name, memo) VALUES
('CHAIR-A', '事務用回転椅子',         '標準的な事務用チェア'),
('CHAIR-B', 'ダイニングチェア',        '4本脚の食卓椅子'),
('CHAIR-C', 'スタッキングチェア',      '積み重ね可能なチェア'),
('SOFA-A',  '1人掛けソファ',          '単体ソファ'),
('BENCH-A', 'ベンチシート',           '連続シートタイプ');

-- =====================================================
-- 椅子タイプ初期データ
-- =====================================================
-- 基本形
INSERT IGNORE INTO chair_types
    (chair_type_group_id, chair_type_code, chair_type_name, version_no, is_base_type, base_quantity, shape_summary)
VALUES
(1, 'CHAIR-A',    '事務用回転椅子 基本形',   0, 1, 10, '標準スペック。肘なし、布張り、キャスター付き'),
(2, 'CHAIR-B',    'ダイニングチェア 基本形',  0, 1, 10, '4本脚、木製フレーム、ファブリック張り'),
(3, 'CHAIR-C',    'スタッキングチェア 基本形', 0, 1, 20, '積み重ね可能、金属フレーム、布張り');

-- バージョン（差分あり）
INSERT IGNORE INTO chair_types
    (chair_type_group_id, chair_type_code, chair_type_name, version_no, is_base_type, base_chair_type_id, base_quantity, shape_summary, difference_summary)
VALUES
(1, 'CHAIR-A-01', '事務用回転椅子 肘付き', 1, 0,
    (SELECT id FROM chair_types WHERE chair_type_code = 'CHAIR-A'),
    10, '肘付きタイプ', '肘付きのため張り込み工程に追加時間'),
(1, 'CHAIR-A-02', '事務用回転椅子 柄合わせ', 2, 0,
    (SELECT id FROM chair_types WHERE chair_type_code = 'CHAIR-A'),
    10, '柄物生地使用', '柄合わせのため裁断・縫製に追加時間');

-- =====================================================
-- 工程標準時間初期データ（CHAIR-A 基本形）
-- =====================================================
INSERT IGNORE INTO chair_type_process_standards
    (chair_type_id, process_id, base_quantity, setup_minutes, base_work_minutes, allowance_rate, allowance_minutes, allowance_reason, standard_workers, difficulty_level, display_order)
SELECT
    ct.id, p.id,
    10, setup, work, rate, allow_min, reason, workers, diff, seq
FROM chair_types ct
CROSS JOIN (
    SELECT 'CUT'   as pc, 15  as setup, 80  as work, 5.0 as rate, 5  as allow_min, '疲労度考慮' as reason, 2 as workers, 2 as diff, 10 as seq UNION ALL
    SELECT 'SEW',          20,          120, 8.0,     10, '疲労度・難易度考慮', 2, 3, 20 UNION ALL
    SELECT 'FOAM',         10,          60,  5.0,     5,  '材料準備時間', 1, 2, 30 UNION ALL
    SELECT 'FRAME',        15,          90,  5.0,     5,  '組立精度確認', 2, 3, 40 UNION ALL
    SELECT 'UPH',          20,          150, 8.0,     10, '張り込み難易度', 2, 4, 50 UNION ALL
    SELECT 'INSP',         10,          40,  3.0,     3,  '確認作業', 1, 2, 60 UNION ALL
    SELECT 'PACK',         10,          50,  3.0,     3,  '梱包材準備', 1, 1, 70
) AS std
JOIN processes p ON p.process_code = std.pc
WHERE ct.chair_type_code = 'CHAIR-A';

-- =====================================================
-- 差分工程初期データ（CHAIR-A-01 肘付き）
-- =====================================================
INSERT IGNORE INTO chair_type_process_adjustments
    (chair_type_id, process_id, adjustment_type, adjustment_name, adjustment_minutes, applies_per, reason)
SELECT
    ct.id, p.id, 'add', '肘付き加算（張り込み）', 15, 'unit', '肘部分の張り込みに追加作業が必要'
FROM chair_types ct, processes p
WHERE ct.chair_type_code = 'CHAIR-A-01' AND p.process_code = 'UPH';

-- CHAIR-A-02 柄合わせ
INSERT IGNORE INTO chair_type_process_adjustments
    (chair_type_id, process_id, adjustment_type, adjustment_name, adjustment_minutes, applies_per, reason)
SELECT ct.id, p.id, 'add', '柄合わせ加算（裁断）', 10, 'unit', '柄の方向合わせ作業'
FROM chair_types ct, processes p
WHERE ct.chair_type_code = 'CHAIR-A-02' AND p.process_code = 'CUT';

INSERT IGNORE INTO chair_type_process_adjustments
    (chair_type_id, process_id, adjustment_type, adjustment_name, adjustment_minutes, applies_per, reason)
SELECT ct.id, p.id, 'add', '柄合わせ加算（縫製）', 12, 'unit', '縫製時の柄合わせ確認'
FROM chair_types ct, processes p
WHERE ct.chair_type_code = 'CHAIR-A-02' AND p.process_code = 'SEW';

-- =====================================================
-- 社長の言葉初期データ
-- =====================================================
INSERT IGNORE INTO president_words (display_no, speaker_name, message) VALUES
(1,  '社長', '現場は数字で語れ。数字は嘘をつかない。'),
(2,  '社長', '早さだけではなく、正確さまで含めてプロである。'),
(3,  '補完室', '標準化は人を縛るためではなく、人を助けるためにある。'),
(4,  '社長', '問題を隠すな。問題を見つけた者が英雄だ。'),
(5,  '社長', '改善は一日一歩。継続こそが力だ。'),
(6,  '補完室', '段取り八分、仕事二分。準備が結果を決める。'),
(7,  '社長', '品質は妥協しない。それがオーツーファーニチャーのブランドだ。'),
(8,  '補完室', '遅れに気づいたらすぐ報告。対策できるのは早い段階だけだ。'),
(9,  '社長', '現場の声が一番の情報源。机の上だけで考えるな。'),
(10, '補完室', 'チームワークなくして品質なし。');
