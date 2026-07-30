<?php

declare(strict_types=1);

namespace Lib;

use App\Core\Database;

/**
 * Central runtime configuration store (the "Settings / Integrations" module).
 *
 * Rules implemented here:
 *  - Nothing is hardcoded: every integration key is read from here.
 *  - Rows flagged is_encrypted are AES-encrypted at rest via Lib\Crypto.
 *  - Rows flagged is_sensitive are NEVER echoed back to the browser; the UI
 *    shows a "configured / not configured" badge instead.
 *  - A missing key returns the supplied default and is reported by
 *    missingConfiguration() so the dashboard can show the warning banner.
 *  - Saving takes effect immediately - no redeploy.
 */
final class Settings
{
    /** @var array<string,array<string,array<string,mixed>>>|null group => key => row */
    private static ?array $cache = null;

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $cache = [];
        try {
            $rows = Database::all(
                'SELECT group_key, setting_key, setting_value, is_encrypted, is_sensitive, value_type, label
                 FROM settings'
            );
        } catch (\Throwable $e) {
            // Settings table missing (fresh install) must not take the app down.
            Logger::warning('Settings table unavailable: ' . $e->getMessage());
            return self::$cache = [];
        }

        foreach ($rows as $row) {
            $cache[$row['group_key']][$row['setting_key']] = $row;
        }

        return self::$cache = $cache;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Read one setting. $path is "group.key" e.g. "smtp.host".
     */
    public static function get(string $path, mixed $default = null): mixed
    {
        [$group, $key] = self::split($path);
        $row = self::load()[$group][$key] ?? null;
        if ($row === null) {
            return $default;
        }

        $raw = $row['setting_value'];
        if ((int) $row['is_encrypted'] === 1) {
            $raw = Crypto::decrypt(is_string($raw) ? $raw : null);
        }

        if ($raw === null || $raw === '') {
            return $default;
        }

        return self::cast((string) $raw, (string) $row['value_type']);
    }

    public static function getString(string $path, string $default = ''): string
    {
        $v = self::get($path, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public static function getInt(string $path, int $default = 0): int
    {
        $v = self::get($path, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function getBool(string $path, bool $default = false): bool
    {
        $v = self::get($path, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    /** True when a value is present and non-empty. */
    public static function has(string $path): bool
    {
        $v = self::get($path);
        return $v !== null && $v !== '' && $v !== [];
    }

    /**
     * Persist one setting. Encryption is decided by the existing row's flag
     * (or by $encrypt when the row does not exist yet).
     */
    public static function set(string $path, mixed $value, ?int $userId = null, ?bool $encrypt = null): void
    {
        [$group, $key] = self::split($path);
        $existing = self::load()[$group][$key] ?? null;

        $shouldEncrypt = $encrypt ?? ($existing !== null && (int) $existing['is_encrypted'] === 1);
        $sensitive = $existing !== null ? (int) $existing['is_sensitive'] : ($shouldEncrypt ? 1 : 0);
        $type = $existing !== null ? (string) $existing['value_type'] : 'string';

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $value = (string) $value;
        }

        $stored = $shouldEncrypt && $value !== '' ? Crypto::encrypt($value) : $value;

        Database::upsert('settings', [
            'group_key'     => $group,
            'setting_key'   => $key,
            'setting_value' => $stored,
            'is_encrypted'  => $shouldEncrypt ? 1 : 0,
            'is_sensitive'  => $sensitive,
            'value_type'    => $type,
            'label'         => $existing['label'] ?? null,
            'updated_by'    => $userId,
        ], ['setting_value', 'is_encrypted', 'is_sensitive', 'updated_by']);

        self::flush();
    }

    /**
     * All rows of a group, with sensitive values replaced by a marker so the
     * form can render "configured" without ever transmitting the secret.
     *
     * @return list<array<string,mixed>>
     */
    public static function group(string $group): array
    {
        $out = [];
        foreach (self::load()[$group] ?? [] as $key => $row) {
            $sensitive = (int) $row['is_sensitive'] === 1;
            $value = self::get($group . '.' . $key, '');
            $out[] = [
                'key'          => $key,
                'label'        => $row['label'] ?? $key,
                'value_type'   => $row['value_type'],
                'is_sensitive' => $sensitive,
                'is_configured' => $value !== null && $value !== '',
                // Sensitive values are intentionally blanked out.
                'value'        => $sensitive ? '' : (is_scalar($value) ? (string) $value : ''),
            ];
        }
        return $out;
    }

    /**
     * Which integrations are unusable because of missing keys.
     * Drives the "Missing Configuration" banner on the dashboard.
     *
     * @return list<array{module:string,label:string,missing:list<string>,url:string}>
     */
    public static function missingConfiguration(): array
    {
        $required = [
            'smtp'     => ['label' => 'Email / SMTP (Email OTP)', 'keys' => ['host', 'port', 'from_email']],
            'sms'      => ['label' => 'SMS Gateway (Mobile OTP)', 'keys' => ['api_url', 'api_key', 'sender_id']],
            'maps'     => ['label' => 'Google Maps',              'keys' => ['api_key']],
            'firebase' => ['label' => 'Firebase Push',            'keys' => ['project_id']],
        ];

        $out = [];
        foreach ($required as $group => $spec) {
            $missing = [];
            foreach ($spec['keys'] as $key) {
                if (!self::has($group . '.' . $key)) {
                    $missing[] = $key;
                }
            }
            if ($missing !== []) {
                $out[] = [
                    'module'  => $group,
                    'label'   => $spec['label'],
                    'missing' => $missing,
                    'url'     => 'settings/' . $group,
                ];
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string} */
    private static function split(string $path): array
    {
        $pos = strpos($path, '.');
        if ($pos === false) {
            return ['app', $path];
        }
        return [substr($path, 0, $pos), substr($path, $pos + 1)];
    }

    private static function cast(string $raw, string $type): mixed
    {
        switch ($type) {
            case 'int':
                return (int) $raw;
            case 'bool':
                return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
            case 'json':
                $decoded = json_decode($raw, true);
                return $decoded === null ? $raw : $decoded;
            default:
                return $raw;
        }
    }
}
