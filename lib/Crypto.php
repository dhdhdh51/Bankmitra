<?php

declare(strict_types=1);

namespace Lib;

use App\Core\Config;
use App\Core\SetupException;
use RuntimeException;

/**
 * AES-256-CBC encryption + HMAC-SHA256 blind index, using nothing but the
 * openssl and hash extensions (both are enabled on every cPanel host).
 *
 * Storage format for encrypt():   base64( iv[16] || hmac[32] || ciphertext )
 * The HMAC is computed over iv||ciphertext (encrypt-then-MAC) so tampering
 * is detected before we ever attempt to decrypt.
 *
 * blindIndex() gives a deterministic, non-reversible 64-char hex value used
 * for the `*_hash` columns. That is how we can still do an exact-match
 * lookup ("find user with this mobile") on an encrypted column.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-cbc';
    private const IV_LEN = 16;
    private const MAC_LEN = 32;

    private static ?string $key = null;

    /** 32 raw bytes derived from config app_key. */
    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $raw = (string) Config::get('app_key', '');
        if ($raw === '' || $raw === 'CHANGE_ME_64_HEX_CHARS') {
            throw new SetupException(
                'The encryption key (app_key) in config/config.php is still the '
                . 'placeholder value, so LRMS cannot encrypt or read mobile numbers, '
                . 'Aadhaar-type fields or stored API keys. Set it before signing in.',
                'Encryption key is not set',
                [
                    'Generate a key. In cPanel > Terminal run: '
                        . 'php -r "echo bin2hex(random_bytes(32));"'
                        . '  - or create a file key.php containing '
                        . '<?php echo bin2hex(random_bytes(32)); open it in your '
                        . 'browser, copy the 64 characters, then DELETE key.php.',
                    'Edit config/config.php and replace CHANGE_ME_64_HEX_CHARS with '
                        . 'those 64 characters.',
                    'Keep a copy of the key somewhere safe. If you change it after '
                        . 'entering data, everything already encrypted becomes unreadable.',
                ]
            );
        }

        // Accept either 64 hex chars (preferred) or any passphrase.
        if (strlen($raw) === 64 && ctype_xdigit($raw)) {
            $key = hex2bin($raw);
        } else {
            $key = hash('sha256', $raw, true);
        }

        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY could not be derived to 32 bytes.');
        }

        return self::$key = $key;
    }

    /** Separate sub-keys for encryption and for MAC/blind-index. */
    private static function subKey(string $purpose): string
    {
        return hash_hmac('sha256', $purpose, self::key(), true);
    }

    /**
     * Encrypt a value. NULL and '' pass through unchanged so that optional
     * columns stay NULL/empty instead of storing ciphertext of nothing.
     */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }

        $iv = random_bytes(self::IV_LEN);
        $cipher = openssl_encrypt($plain, self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed (openssl_encrypt returned false).');
        }

        $mac = hash_hmac('sha256', $iv . $cipher, self::subKey('mac'), true);

        return base64_encode($iv . $mac . $cipher);
    }

    /**
     * Decrypt a value produced by encrypt().
     * Returns null when the payload is missing, malformed or tampered with -
     * callers should treat null as "unavailable", never as an empty value.
     */
    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return $payload;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::MAC_LEN) {
            Logger::warning('Crypto::decrypt received a malformed payload.');
            return null;
        }

        $iv     = substr($raw, 0, self::IV_LEN);
        $mac    = substr($raw, self::IV_LEN, self::MAC_LEN);
        $cipher = substr($raw, self::IV_LEN + self::MAC_LEN);

        $expected = hash_hmac('sha256', $iv . $cipher, self::subKey('mac'), true);
        if (!hash_equals($expected, $mac)) {
            Logger::warning('Crypto::decrypt MAC mismatch - data tampered or wrong APP_KEY.');
            return null;
        }

        $plain = openssl_decrypt($cipher, self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    /**
     * Deterministic searchable hash of a sensitive value.
     * Normalises first so "+91 98765 43210" and "9876543210" match.
     */
    public static function blindIndex(?string $value, string $type = 'generic'): ?string
    {
        $normalised = self::normalise($value, $type);
        if ($normalised === null) {
            return null;
        }
        return hash_hmac('sha256', $type . ':' . $normalised, self::subKey('index'));
    }

    public static function normalise(?string $value, string $type = 'generic'): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        switch ($type) {
            case 'mobile':
                $digits = preg_replace('/\D+/', '', $value) ?? '';
                // Keep the last 10 digits (drops 0 / 91 / +91 prefixes).
                if (strlen($digits) > 10) {
                    $digits = substr($digits, -10);
                }
                return $digits === '' ? null : $digits;

            case 'email':
                return strtolower($value);

            case 'aadhaar':
                $digits = preg_replace('/\D+/', '', $value) ?? '';
                return $digits === '' ? null : $digits;

            default:
                return $value;
        }
    }

    /** Mask a mobile for display: 9876543210 -> 98****3210 */
    public static function maskMobile(?string $mobile): string
    {
        $n = self::normalise($mobile, 'mobile');
        if ($n === null || strlen($n) < 6) {
            return '-';
        }
        return substr($n, 0, 2) . str_repeat('*', strlen($n) - 6) . substr($n, -4);
    }

    /** Mask an email for display: ramesh@bank.com -> r****h@bank.com */
    public static function maskEmail(?string $email): string
    {
        if ($email === null || !str_contains($email, '@')) {
            return '-';
        }
        [$user, $domain] = explode('@', $email, 2);
        if (strlen($user) <= 2) {
            return str_repeat('*', strlen($user)) . '@' . $domain;
        }
        return $user[0] . str_repeat('*', strlen($user) - 2) . substr($user, -1) . '@' . $domain;
    }

    public static function last4(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }

    /** URL-safe random token. */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Numeric OTP of the requested length, cryptographically random. */
    public static function randomOtp(int $length = 6): string
    {
        $length = max(4, min(8, $length));
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= (string) random_int(0, 9);
        }
        return $otp;
    }

    /** Unambiguous uppercase code for invitations (no O/0/I/1 confusion). */
    public static function randomCode(int $length = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
