-- =====================================================
-- 【緊急修正】初期ユーザーのパスワードハッシュ修正
-- seed.sql の誤ったハッシュを "password123" 用に上書きする
-- phpMyAdmin の「SQLタブ」で実行してください
-- =====================================================

UPDATE users
SET password_hash = '$2y$12$.btR48KIN3s.s.MkqcsGTuJSTKfv1aR79N0nySycKdRizLQbdttkO'
WHERE login_id IN ('president', 'admin', 'yamada', 'sato', 'suzuki', 'ito');

-- system_settings テーブルが未作成の場合は schema.sql を先に実行してください
INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
('totp_required', '0', 'ログイン時にAuthenticator認証を必須にする（1:必須 / 0:任意）');
