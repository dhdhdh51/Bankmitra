<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain-PHP templating. Views live in app/Views and are rendered inside
 * app/Views/layouts/{layout}.php with $content already escaped by the view.
 */
final class View
{
    /** @var array<string,mixed> Values available to every view. */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], string $layout = 'app'): string
    {
        $content = self::capture($template, $data);

        if ($layout === '') {
            return $content;
        }

        return self::capture('layouts/' . $layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string,mixed> $data */
    public static function capture(string $template, array $data = []): string
    {
        $file = APP_PATH . '/Views/' . str_replace(['..', '\\'], '', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        $vars = array_merge(self::$shared, $data);

        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Escaping / formatting helpers used throughout the templates
    // ------------------------------------------------------------------

    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Indian-format currency, e.g. 1234567.5 -> 12,34,567.50 */
    public static function money(mixed $amount, bool $withSymbol = true): string
    {
        $n = is_numeric($amount) ? (float) $amount : 0.0;
        $negative = $n < 0;
        $n = abs($n);

        $whole = (string) floor($n);
        $decimals = number_format($n - floor($n), 2, '.', '');
        $decimals = substr($decimals, 2);

        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest . ',' . $last3;
        }

        return ($negative ? '-' : '') . ($withSymbol ? '₹' : '') . $whole . '.' . $decimals;
    }

    /** Compact Indian format for dashboard tiles: 1.23 Cr / 4.5 L / 12.3 K */
    public static function moneyShort(mixed $amount): string
    {
        $n = is_numeric($amount) ? (float) $amount : 0.0;
        $sign = $n < 0 ? '-' : '';
        $n = abs($n);

        if ($n >= 10000000) {
            return $sign . '₹' . number_format($n / 10000000, 2) . ' Cr';
        }
        if ($n >= 100000) {
            return $sign . '₹' . number_format($n / 100000, 2) . ' L';
        }
        if ($n >= 1000) {
            return $sign . '₹' . number_format($n / 1000, 1) . ' K';
        }
        return $sign . '₹' . number_format($n, 0);
    }

    public static function date(?string $value, string $format = 'd-m-Y'): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return '-';
        }
        $ts = strtotime($value);
        return $ts === false ? '-' : date($format, $ts);
    }

    public static function dateTime(?string $value): string
    {
        return self::date($value, 'd-m-Y h:i A');
    }

    public static function url(string $path = ''): string
    {
        return Config::baseUrl() . ltrim($path, '/');
    }

    public static function asset(string $path): string
    {
        return Config::baseUrl() . 'assets/' . ltrim($path, '/');
    }

    /** Bootstrap badge class for a visit status. */
    public static function visitStatusBadge(string $status): string
    {
        return [
            'visited'       => 'success',
            'paid'          => 'success',
            'promise'       => 'info',
            'not_available' => 'warning',
            'ots'           => 'primary',
            'legal'         => 'danger',
            'skip'          => 'secondary',
            'untraceable'   => 'dark',
        ][$status] ?? 'secondary';
    }

    public static function assetClassBadge(string $class): string
    {
        if ($class === 'STD') {
            return 'success';
        }
        if (str_starts_with($class, 'SMA')) {
            return 'warning';
        }
        if ($class === 'SS') {
            return 'orange';
        }
        return 'danger';
    }

    public static function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
