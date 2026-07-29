<?php

declare(strict_types=1);

namespace App\Core;

use Lib\Logger;

/**
 * Append-only audit trail. Secrets are masked before anything is written
 * (project rule #7: never persist OTPs, passwords or API keys in clear).
 */
final class Audit
{
    /**
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public static function log(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        string $severity = 'info',
        string $channel = 'web'
    ): void {
        try {
            $user = Auth::user();

            Database::insert('audit_logs', [
                'user_id'     => $user === null ? null : (int) $user['id'],
                'actor_name'  => $user === null ? 'system' : (string) $user['full_name'],
                'action'      => substr($action, 0, 80),
                'entity_type' => $entityType === null ? null : substr($entityType, 0, 60),
                'entity_id'   => $entityId === null ? null : substr((string) $entityId, 0, 60),
                'description' => $description === null ? null : substr($description, 0, 255),
                'old_values'  => $old === null ? null : self::encode($old),
                'new_values'  => $new === null ? null : self::encode($new),
                'ip_address'  => self::ip(),
                'user_agent'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'channel'     => in_array($channel, ['web', 'api', 'cron', 'cli'], true) ? $channel : 'web',
                'severity'    => in_array($severity, ['info', 'notice', 'warning', 'critical'], true) ? $severity : 'info',
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the actual operation, but the failure
            // itself has to be visible somewhere.
            Logger::error('Audit write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    /** Convenience wrapper for the API channel. */
    public static function api(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $description = null
    ): void {
        self::log($action, $entityType, $entityId, $description, null, null, 'info', 'api');
    }

    public static function security(string $action, string $description, string $severity = 'warning'): void
    {
        self::log($action, 'security', null, $description, null, null, $severity);
    }

    /**
     * Only the columns that actually changed, so the log stays readable.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;
            if ((string) $previous !== (string) $value) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }
        return [$old, $new];
    }

    private static function encode(array $data): string
    {
        $json = json_encode(Logger::mask($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : substr($json, 0, 60000);
    }

    private static function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $candidate = trim(explode(',', (string) $_SERVER[$key])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
        return '0.0.0.0';
    }
}
