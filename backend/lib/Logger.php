<?php

declare(strict_types=1);

namespace Lib;

use Throwable;

/**
 * Dead-simple daily rotating file logger.
 *
 * SECURITY: keys named like a secret are masked before writing, so an OTP
 * or an API key can never end up in storage/logs (project rule #7).
 */
final class Logger
{
    private const SECRET_HINTS = [
        'otp', 'password', 'passwd', 'pass', 'secret', 'api_key', 'apikey',
        'token', 'authorization', 'server_key', 'service_account_json',
        'private_key', 'aadhaar', 'pan', 'csrf',
    ];

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function exception(Throwable $e): void
    {
        self::write('ERROR', get_class($e) . ': ' . $e->getMessage(), [
            'file'  => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = defined('STORAGE_PATH') ? STORAGE_PATH . '/logs' : sys_get_temp_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $line = sprintf(
            "[%s] %-5s %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : ' ' . self::encodeContext($context)
        );

        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function encodeContext(array $context): string
    {
        $json = json_encode(self::mask($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '[uncodable context]' : $json;
    }

    /** Recursively replace values whose key looks secret. */
    public static function mask(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            $isSecret = false;
            foreach (self::SECRET_HINTS as $hint) {
                if (str_contains($lower, $hint)) {
                    $isSecret = true;
                    break;
                }
            }
            if ($isSecret) {
                $out[$key] = '***masked***';
            } elseif (is_array($value)) {
                $out[$key] = self::mask($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            } else {
                $out[$key] = '[' . get_debug_type($value) . ']';
            }
        }
        return $out;
    }
}
