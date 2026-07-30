<?php

declare(strict_types=1);

namespace App\Core;

use Lib\Crypto;
use Lib\Settings;

/**
 * Identity + authorisation for BOTH channels:
 *  - web  : session based (admin panel)
 *  - api  : bearer token in api_tokens (Android app)
 *
 * Also provides the data-scoping helpers (scopeSql) that make sure a Branch
 * Manager can only ever see their own branch and a BC Agent only their own
 * allocated accounts.
 */
final class Auth
{
    public const ROLE_SUPER_ADMIN     = 'super_admin';
    public const ROLE_REGIONAL_OFFICE = 'regional_office';
    public const ROLE_BRANCH_MANAGER  = 'branch_manager';
    public const ROLE_BC_AGENT        = 'bc_agent';

    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    private static bool $resolved = false;

    // ------------------------------------------------------------------
    // Web session
    // ------------------------------------------------------------------

    public static function loginSession(int $userId): void
    {
        Session::regenerate();
        Session::set('user_id', $userId);
        self::$user = null;
        self::$resolved = false;
    }

    public static function logout(): void
    {
        self::$user = null;
        self::$resolved = true;
        Session::destroy();
    }

    /** Explicitly set the authenticated user (used by the API middleware). */
    public static function setUser(?array $user): void
    {
        self::$user = $user;
        self::$resolved = true;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $id = Session::get('user_id');
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return self::$user = null;
        }

        $user = self::fetchUser((int) $id);
        if ($user === null || $user['status'] !== 'active') {
            Session::forget('user_id');
            return self::$user = null;
        }

        return self::$user = $user;
    }

    /** @return array<string,mixed>|null */
    public static function fetchUser(int $id): ?array
    {
        $row = Database::first(
            'SELECT u.*, r.code AS role_code, r.name AS role_name, r.hierarchy AS role_hierarchy,
                    b.name AS branch_name, b.code AS branch_code,
                    bc.id AS bc_id, bc.bc_code, bc.monthly_target, bc.visit_target
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN branches b  ON b.id = u.branch_id
             LEFT JOIN bc_agents bc ON bc.user_id = u.id
             WHERE u.id = ? LIMIT 1',
            [$id]
        );
        if ($row === null) {
            return null;
        }

        // Decrypt contact details once so views/API can use them directly.
        $row['mobile'] = Crypto::decrypt($row['mobile_enc'] ?? null);
        $row['email']  = Crypto::decrypt($row['email_enc'] ?? null);

        return $row;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u === null ? null : (int) $u['id'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function name(): string
    {
        $u = self::user();
        return $u === null ? 'Guest' : (string) $u['full_name'];
    }

    public static function role(): string
    {
        $u = self::user();
        return $u === null ? '' : (string) $u['role_code'];
    }

    public static function branchId(): ?int
    {
        $u = self::user();
        return ($u === null || $u['branch_id'] === null) ? null : (int) $u['branch_id'];
    }

    public static function regionId(): ?int
    {
        $u = self::user();
        return ($u === null || $u['region_id'] === null) ? null : (int) $u['region_id'];
    }

    public static function bcId(): ?int
    {
        $u = self::user();
        return ($u === null || ($u['bc_id'] ?? null) === null) ? null : (int) $u['bc_id'];
    }

    // ------------------------------------------------------------------
    // Authorisation
    // ------------------------------------------------------------------

    public static function isSuperAdmin(): bool
    {
        return self::role() === self::ROLE_SUPER_ADMIN;
    }

    public static function isBcAgent(): bool
    {
        return self::role() === self::ROLE_BC_AGENT;
    }

    /** @param string|list<string> $roles */
    public static function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];
        return in_array(self::role(), $roles, true);
    }

    /**
     * Super admin implicitly has every permission; everyone else is checked
     * against role_permissions.
     */
    public static function can(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::isSuperAdmin()) {
            return true;
        }

        static $cache = [];
        $roleId = (int) (self::user()['role_id'] ?? 0);
        if (!isset($cache[$roleId])) {
            $rows = Database::all(
                'SELECT permission_key FROM role_permissions WHERE role_id = ? AND allowed = 1',
                [$roleId]
            );
            $cache[$roleId] = array_column($rows, 'permission_key');
        }

        return in_array($permission, $cache[$roleId], true);
    }

    /**
     * SQL fragment restricting a query to what the current user may see.
     *
     * @param string $branchColumn fully-qualified column holding branch_id
     * @param string|null $bcColumn fully-qualified column holding bc_agents.id
     * @return array{0:string,1:array<int,mixed>} [sqlFragment, params]
     */
    public static function scopeSql(string $branchColumn, ?string $bcColumn = null): array
    {
        switch (self::role()) {
            case self::ROLE_SUPER_ADMIN:
            case self::ROLE_REGIONAL_OFFICE:
                // Regional office is read-only but sees everything in its region
                // (or everything at all when no region is assigned).
                $regionId = self::regionId();
                if (self::role() === self::ROLE_REGIONAL_OFFICE && $regionId !== null) {
                    return [
                        " AND {$branchColumn} IN (SELECT id FROM branches WHERE region_id = ?)",
                        [$regionId],
                    ];
                }
                return ['', []];

            case self::ROLE_BRANCH_MANAGER:
                return [" AND {$branchColumn} = ?", [self::branchId() ?? 0]];

            case self::ROLE_BC_AGENT:
                if ($bcColumn !== null) {
                    return [" AND {$bcColumn} = ?", [self::bcId() ?? 0]];
                }
                return [" AND {$branchColumn} = ?", [self::branchId() ?? 0]];

            default:
                // Not authenticated - deny everything rather than leak rows.
                return [' AND 1 = 0', []];
        }
    }

    // ------------------------------------------------------------------
    // Device binding
    // ------------------------------------------------------------------

    /**
     * @return array{ok:bool,message:string}
     */
    public static function checkDeviceBinding(array $user, string $deviceId): array
    {
        if (!Settings::getBool('security.device_binding', true)) {
            return ['ok' => true, 'message' => ''];
        }
        if ($deviceId === '') {
            return ['ok' => false, 'message' => 'Device ID missing. Please update the app.'];
        }

        $bound = $user['device_id'] ?? null;
        if ($bound === null || $bound === '') {
            return ['ok' => true, 'message' => 'first_bind'];
        }
        if (hash_equals((string) $bound, $deviceId)) {
            return ['ok' => true, 'message' => ''];
        }

        return [
            'ok' => false,
            'message' => 'This account is registered on another device. '
                . 'Ask your administrator to reset the device binding.',
        ];
    }

    public static function bindDevice(int $userId, string $deviceId, string $model, string $osVersion, string $appVersion): void
    {
        Database::transaction(static function () use ($userId, $deviceId, $model, $osVersion, $appVersion): void {
            Database::update('users', [
                'device_id'       => $deviceId,
                'device_model'    => $model !== '' ? $model : null,
                'device_bound_at' => date('Y-m-d H:i:s'),
            ], ['id' => $userId]);

            Database::run(
                'UPDATE user_devices SET status = "released", released_at = NOW()
                 WHERE user_id = ? AND device_id <> ? AND status = "active"',
                [$userId, $deviceId]
            );

            $existing = Database::first(
                'SELECT id FROM user_devices WHERE user_id = ? AND device_id = ? LIMIT 1',
                [$userId, $deviceId]
            );

            if ($existing === null) {
                Database::insert('user_devices', [
                    'user_id'     => $userId,
                    'device_id'   => $deviceId,
                    'device_model' => $model !== '' ? $model : null,
                    'os_version'  => $osVersion !== '' ? $osVersion : null,
                    'app_version' => $appVersion !== '' ? $appVersion : null,
                    'status'      => 'active',
                ]);
            } else {
                Database::update('user_devices', [
                    'status'      => 'active',
                    'device_model' => $model !== '' ? $model : null,
                    'os_version'  => $osVersion !== '' ? $osVersion : null,
                    'app_version' => $appVersion !== '' ? $appVersion : null,
                    'released_at' => null,
                ], ['id' => (int) $existing['id']]);
            }
        });
    }
}
