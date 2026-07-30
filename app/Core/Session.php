<?php

declare(strict_types=1);

namespace App\Core;

use Lib\Settings;

/**
 * Session wrapper with hardened cookie flags, idle auto-logout and
 * flash messages.
 */
final class Session
{
    private const IDLE_KEY = '_last_activity';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        session_name('LRMSSESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,      // set an SSL cert in cPanel > SSL/TLS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Keep session files inside the app when the host allows it, so a
        // shared /tmp cannot be read by neighbours on the same server.
        $dir = STORAGE_PATH . '/cache/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }

        session_start();
        self::enforceIdleTimeout();
    }

    private static function enforceIdleTimeout(): void
    {
        $minutes = Settings::getInt('security.session_timeout_minutes', 30);
        if ($minutes <= 0) {
            return;
        }

        $last = $_SESSION[self::IDLE_KEY] ?? null;
        if (is_int($last) && (time() - $last) > $minutes * 60) {
            self::destroy();
            session_start();
            self::flash('warning', 'You were logged out automatically after '
                . $minutes . ' minutes of inactivity.');
        }
        $_SESSION[self::IDLE_KEY] = time();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    /** @param 'success'|'danger'|'warning'|'info' $type */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flashes) ? $flashes : [];
    }

    /** Remember submitted form values so a failed form can be repopulated. */
    public static function flashInput(array $input): void
    {
        // Never re-populate secrets.
        unset($input['password'], $input['password_confirm'], $input['otp'], $input['csrf_token']);
        $_SESSION['_old_input'] = $input;
    }

    public static function oldInput(string $key, string $default = ''): string
    {
        $old = $_SESSION['_old_input'][$key] ?? null;
        return is_scalar($old) ? (string) $old : $default;
    }

    public static function clearOldInput(): void
    {
        unset($_SESSION['_old_input']);
    }
}
