<?php
// =====================================================
// TOTP (RFC 6238) ユーティリティ
// Composer なし・外部依存なしのスクラッチ実装
// =====================================================

/**
 * ランダムな TOTP シークレットを生成（Base32）
 */
function totpGenerateSecret(): string {
    return base32Encode(random_bytes(20));
}

/**
 * 6桁コードを検証（前後1タイムステップ許容でクロックズレに対応）
 */
function totpVerify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (strlen($code) !== 6 || !ctype_digit($code)) return false;
    $t = (int)(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (totpCode($secret, $t + $i) === $code) return true;
    }
    return false;
}

/**
 * 指定カウンターに対応する6桁コードを生成
 */
function totpCode(string $secret, int $counter): string {
    $key  = base32Decode($secret);
    $msg  = pack('N*', 0, $counter);           // 64-bit big-endian
    $hash = hash_hmac('sha1', $msg, $key, true);
    $off  = ord($hash[19]) & 0x0f;
    $code = (
        ((ord($hash[$off])     & 0x7f) << 24) |
        ((ord($hash[$off + 1]) & 0xff) << 16) |
        ((ord($hash[$off + 2]) & 0xff) <<  8) |
        ( ord($hash[$off + 3]) & 0xff)
    ) % 1_000_000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

/**
 * Authenticator アプリ用の otpauth:// URL を生成（QRコードのデータ）
 */
function totpOtpauthUrl(string $secret, string $accountName, string $issuer = 'OtsuFurniture'): string {
    return 'otpauth://totp/'
        . rawurlencode($issuer . ':' . $accountName)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * シークレットを AES-256-CBC で暗号化してDB保存用 Base64 文字列を返す
 */
function totpEncrypt(string $plaintext): string {
    $key = substr(hash('sha256', ENCRYPTION_KEY, true), 0, 32);
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

/**
 * DB保存値を復号してシークレット文字列を返す
 */
function totpDecrypt(string $ciphertext): string {
    $key = substr(hash('sha256', ENCRYPTION_KEY, true), 0, 32);
    $raw = base64_decode($ciphertext);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return (string)openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

/**
 * システム設定で「Authenticator必須」になっているか確認
 */
function isTotpRequired(): bool {
    static $cache = null;
    if ($cache === null) {
        $row   = dbFetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'totp_required'");
        $cache = ($row['setting_value'] ?? '0') === '1';
    }
    return $cache;
}

/**
 * 指定ユーザーが TOTP を設定済みか確認
 */
function isTotpEnabled(int $userId): bool {
    $row = dbFetchOne("SELECT mfa_secret_encrypted FROM users WHERE id = ?", [$userId]);
    return !empty($row['mfa_secret_encrypted']);
}

/**
 * 指定ユーザーの TOTP シークレット（復号済み）を返す。未設定なら null
 */
function getTotpSecret(int $userId): ?string {
    $row = dbFetchOne("SELECT mfa_secret_encrypted FROM users WHERE id = ?", [$userId]);
    if (empty($row['mfa_secret_encrypted'])) return null;
    return totpDecrypt($row['mfa_secret_encrypted']);
}

// =====================================================
// Base32 エンコード / デコード（RFC 4648）
// =====================================================

function base32Encode(string $data): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out   = '';
    $bits  = 0;
    $buf   = 0;
    foreach (str_split($data) as $c) {
        $buf   = ($buf << 8) | ord($c);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out  .= $alpha[($buf >> $bits) & 0x1f];
        }
    }
    if ($bits > 0) {
        $out .= $alpha[($buf << (5 - $bits)) & 0x1f];
    }
    return $out;
}

function base32Decode(string $data): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $data  = strtoupper(rtrim($data, '='));
    $out   = '';
    $bits  = 0;
    $buf   = 0;
    foreach (str_split($data) as $c) {
        $pos = strpos($alpha, $c);
        if ($pos === false) continue;
        $buf   = ($buf << 5) | $pos;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out  .= chr(($buf >> $bits) & 0xff);
        }
    }
    return $out;
}
