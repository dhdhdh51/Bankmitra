<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Per-session CSRF token. Every state-changing form in the admin panel must
 * include <?= Csrf::field() ?> and every POST controller must call verify().
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::KEY);
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            Session::set(self::KEY, $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function check(?string $candidate): bool
    {
        $expected = Session::get(self::KEY);
        return is_string($expected)
            && is_string($candidate)
            && $candidate !== ''
            && hash_equals($expected, $candidate);
    }

    /**
     * Verify or abort with a clear message (never a blank page).
     */
    public static function verify(Request $request): void
    {
        $token = $request->str('csrf_token');
        if ($token === '') {
            $token = (string) ($request->header('X-CSRF-Token') ?? '');
        }
        if (self::check($token)) {
            return;
        }

        if ($request->isAjax()) {
            Response::fail(
                'Your session expired. Please reload the page and try again.',
                'csrf_invalid',
                419
            );
            exit;
        }

        Session::flash('danger', 'Security check failed (CSRF). Your session may have expired - please try again.');
        Response::redirect(Config::baseUrl() . ($request->path() ?: 'dashboard'));
    }
}
