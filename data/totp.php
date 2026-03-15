<?php
/**
 * fly-CMS TOTP (Google Authenticator) бібліотека
 * Реалізація RFC 6238 — без зовнішніх залежностей
 */

class TOTP {

    /**
     * Генерує випадковий Base32-секрет (16 символів = 80 біт)
     */
    public static function generateSecret(int $length = 16): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        $random = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[ord($random[$i]) & 31];
        }
        return $secret;
    }

    /**
     * Декодує Base32 → бінарний рядок
     */
    public static function base32Decode(string $secret): string {
        $chars   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret  = strtoupper(preg_replace('/\s+/', '', $secret));
        $output  = '';
        $buffer  = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($secret); $i++) {
            $val = strpos($chars, $secret[$i]);
            if ($val === false) continue;
            $buffer   = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output  .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $output;
    }

    /**
     * Обчислює TOTP-код для заданого часового кроку
     */
    public static function getCode(string $secret, int $timeSlice): string {
        $key = self::base32Decode($secret);
        if (strlen($key) === 0) return '';

        // Пакуємо timeSlice як 64-bit big-endian
        $time = pack('N*', 0) . pack('N*', $timeSlice);

        $hash    = hash_hmac('sha1', $time, $key, true);
        $offset  = ord($hash[19]) & 0x0F;
        $otp     = (
            ((ord($hash[$offset])   & 0x7F) << 24) |
            ((ord($hash[$offset+1]) & 0xFF) << 16) |
            ((ord($hash[$offset+2]) & 0xFF) << 8)  |
            ((ord($hash[$offset+3]) & 0xFF))
        ) % 1000000;

        return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Верифікує введений код.
     * discrepancy=1 означає ±30 сек допуск (рекомендовано)
     */
    public static function verify(string $secret, string $code, int $discrepancy = 1): bool {
        $code = preg_replace('/\s+/', '', $code);
        if (strlen($code) !== 6 || !ctype_digit($code)) return false;

        $timeSlice = (int)floor(time() / 30);
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            if (hash_equals(self::getCode($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Генерує URL для QR-коду (Google Charts API)
     */
    public static function getQRCodeUrl(string $secret, string $accountName, string $issuer = 'fly-CMS'): string {
        $accountName = rawurlencode($accountName);
        $issuer      = rawurlencode($issuer);
        $otpauth     = "otpauth://totp/{$issuer}:{$accountName}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpauth);
    }

    /**
     * Повертає otpauth URI (для manual entry в Google Authenticator)
     */
    public static function getOtpauthUri(string $secret, string $accountName, string $issuer = 'fly-CMS'): string {
        return "otpauth://totp/" . rawurlencode($issuer) . ":" . rawurlencode($accountName)
             . "?secret={$secret}&issuer=" . rawurlencode($issuer);
    }
}
