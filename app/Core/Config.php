<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable access to config/config.php values (dot notation).
 * Application settings that admins can change live in the `settings`
 * table instead - see Lib\Settings.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    /** @param array<string,mixed> $items */
    public static function load(array $items): void
    {
        self::$items = $items;
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            return $default;
        }
        $node = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    /**
     * Absolute base URL of the installation, always with a trailing slash.
     * Auto-detected so the app works in a subfolder (/lrms/) without edits.
     */
    public static function baseUrl(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $configured = (string) self::get('base_url', 'auto');
        if ($configured !== '' && $configured !== 'auto') {
            return $cached = rtrim($configured, '/') . '/';
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        // SCRIPT_NAME is e.g. /lrms/index.php or /lrms/api/v1/index.php
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = str_replace('\\', '/', dirname($script));
        // Strip the api/v1 suffix so baseUrl() is identical for web + api.
        $dir = preg_replace('#/api/v\d+$#', '', $dir) ?? $dir;
        $dir = ($dir === '/' || $dir === '.') ? '' : $dir;

        return $cached = $scheme . '://' . $host . $dir . '/';
    }
}
