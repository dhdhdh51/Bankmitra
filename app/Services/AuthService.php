<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use Lib\Crypto;
use Lib\Settings;

/**
 * Everything shared between the web login form and the mobile API:
 * credential checks, lockout, invitation redemption, and bearer tokens.
 */
final class AuthService
{
    /**
     * Find a user by mobile, email or employee code.
     *
     * @return array<string,mixed>|null
     */
    public function findByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        // Mobile
        $mobileHash = Crypto::blindIndex($identifier, 'mobile');
        if ($mobileHash !== null) {
            $row = Database::first('SELECT id FROM users WHERE mobile_hash = ? LIMIT 1', [$mobileHash]);
            if ($row !== null) {
                return Auth::fetchUser((int) $row['id']);
            }
        }

        // Email
        if (str_contains($identifier, '@')) {
            $emailHash = Crypto::blindIndex($identifier, 'email');
            if ($emailHash !== null) {
                $row = Database::first('SELECT id FROM users WHERE email_hash = ? LIMIT 1', [$emailHash]);
                if ($row !== null) {
                    return Auth::fetchUser((int) $row['id']);
                }
            }
        }

        // Employee code (not sensitive, stored in clear)
        $row = Database::first('SELECT id FROM users WHERE employee_code = ? LIMIT 1', [$identifier]);
        return $row === null ? null : Auth::fetchUser((int) $row['id']);
    }

    /**
     * Password authentication with lockout.
     *
     * @return array{ok:bool,code:string,message:string,user:?array<string,mixed>}
     */
    public function attemptPassword(string $identifier, string $password, string $ip, string $channel = 'web'): array
    {
        $identifierHash = Crypto::blindIndex($identifier, str_contains($identifier, '@') ? 'email' : 'mobile');

        $fail = function (string $code, string $message, ?int $userId = null) use ($identifierHash, $ip, $channel): array {
            Database::insert('login_attempts', [
                'identifier_hash' => $identifierHash,
                'ip_address'      => $ip !== '' ? $ip : '0.0.0.0',
                'channel'         => $channel === 'api' ? 'api' : 'web',
                'successful'      => 0,
                'reason'          => substr($code, 0, 80),
            ]);
            if ($userId !== null) {
                $this->registerFailure($userId);
            }
            return ['ok' => false, 'code' => $code, 'message' => $message, 'user' => null];
        };

        // IP-level brute force guard, independent of which account is targeted.
        $maxAttempts = max(3, Settings::getInt('security.max_login_attempts', 5));
        $lockoutMinutes = max(1, Settings::getInt('security.lockout_minutes', 15));

        $ipFailures = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND successful = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$ip !== '' ? $ip : '0.0.0.0', $lockoutMinutes],
            0
        );
        if ($ipFailures >= $maxAttempts * 4) {
            return $fail('rate_limited', 'Too many failed attempts from this network. Please try again in '
                . $lockoutMinutes . ' minutes.');
        }

        $user = $this->findByIdentifier($identifier);
        if ($user === null) {
            // Deliberately vague so the form cannot be used to enumerate users.
            return $fail('unauthenticated', 'Incorrect credentials. Please check and try again.');
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            $minutesLeft = (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60);
            return $fail('rate_limited', 'This account is temporarily locked. Try again in '
                . $minutesLeft . ' minute' . ($minutesLeft === 1 ? '' : 's') . '.', (int) $user['id']);
        }

        if ($user['password_hash'] === null || $user['password_hash'] === '') {
            return $fail('unauthenticated', 'This account does not have a password set. Please sign in with an OTP.');
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return $fail('unauthenticated', 'Incorrect credentials. Please check and try again.', (int) $user['id']);
        }

        $statusCheck = $this->checkStatus($user);
        if (!$statusCheck['ok']) {
            return $fail($statusCheck['code'], $statusCheck['message'], (int) $user['id']);
        }

        // Opportunistically upgrade an old hash to the current algorithm.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => (int) $user['id']]);
        }

        $this->registerSuccess((int) $user['id'], $ip, $identifierHash, $channel);

        return ['ok' => true, 'code' => 'ok', 'message' => 'Signed in successfully.', 'user' => $user];
    }

    /**
     * @param array<string,mixed> $user
     * @return array{ok:bool,code:string,message:string}
     */
    public function checkStatus(array $user): array
    {
        switch ((string) $user['status']) {
            case 'active':
                return ['ok' => true, 'code' => 'ok', 'message' => ''];
            case 'pending':
                return [
                    'ok' => false,
                    'code' => 'account_pending',
                    'message' => 'Your account is waiting for administrator approval. '
                        . 'You will be able to sign in once it is approved.',
                ];
            case 'suspended':
                return [
                    'ok' => false,
                    'code' => 'account_suspended',
                    'message' => 'Your account has been suspended. Please contact your administrator.',
                ];
            default:
                return [
                    'ok' => false,
                    'code' => 'account_suspended',
                    'message' => 'Your account is disabled. Please contact your administrator.',
                ];
        }
    }

    private function registerFailure(int $userId): void
    {
        $maxAttempts = max(3, Settings::getInt('security.max_login_attempts', 5));
        $lockoutMinutes = max(1, Settings::getInt('security.lockout_minutes', 15));

        $row = Database::first('SELECT failed_attempts FROM users WHERE id = ?', [$userId]);
        $attempts = ($row === null ? 0 : (int) $row['failed_attempts']) + 1;

        $update = ['failed_attempts' => $attempts];
        if ($attempts >= $maxAttempts) {
            $update['locked_until'] = date('Y-m-d H:i:s', time() + $lockoutMinutes * 60);
            $update['failed_attempts'] = 0;
            Audit::log('login.locked', 'user', $userId,
                'Account locked after ' . $maxAttempts . ' failed attempts', null, null, 'warning');
        }

        Database::update('users', $update, ['id' => $userId]);
    }

    private function registerSuccess(int $userId, string $ip, ?string $identifierHash, string $channel): void
    {
        Database::update('users', [
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => date('Y-m-d H:i:s'),
            'last_login_ip'   => $ip !== '' ? $ip : null,
        ], ['id' => $userId]);

        Database::insert('login_attempts', [
            'identifier_hash' => $identifierHash,
            'ip_address'      => $ip !== '' ? $ip : '0.0.0.0',
            'channel'         => $channel === 'api' ? 'api' : 'web',
            'successful'      => 1,
            'reason'          => null,
        ]);
    }

    // ------------------------------------------------------------------
    // Bearer tokens (mobile app sessions)
    // ------------------------------------------------------------------

    /**
     * Issue an API token. The plaintext is returned once and only its
     * SHA-256 hash is stored, so a database dump yields no usable sessions.
     *
     * @return array{token:string,expires_at:string}
     */
    public function issueToken(int $userId, string $deviceId, string $deviceModel, string $appVersion, string $ip): array
    {
        $days = max(1, Settings::getInt('security.api_token_days', 30));
        $token = Crypto::randomToken(32);
        $expiresAt = date('Y-m-d H:i:s', time() + $days * 86400);

        // One live token per device: replace any previous one.
        Database::run(
            'UPDATE api_tokens SET revoked_at = NOW(), revoke_reason = "replaced by new login"
             WHERE user_id = ? AND device_id = ? AND revoked_at IS NULL',
            [$userId, $deviceId]
        );

        Database::insert('api_tokens', [
            'user_id'      => $userId,
            'token_hash'   => hash('sha256', $token),
            'device_id'    => $deviceId !== '' ? $deviceId : null,
            'device_model' => $deviceModel !== '' ? $deviceModel : null,
            'app_version'  => $appVersion !== '' ? $appVersion : null,
            'platform'     => 'android',
            'ip_address'   => $ip !== '' ? $ip : null,
            'expires_at'   => $expiresAt,
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Resolve a bearer token to a user, or explain why it failed.
     *
     * @return array{ok:bool,code:string,message:string,user:?array<string,mixed>}
     */
    public function resolveToken(?string $token): array
    {
        if ($token === null || $token === '') {
            return ['ok' => false, 'code' => 'unauthenticated', 'message' => 'Authentication token missing.', 'user' => null];
        }

        $row = Database::first(
            'SELECT * FROM api_tokens WHERE token_hash = ? LIMIT 1',
            [hash('sha256', $token)]
        );

        if ($row === null) {
            return ['ok' => false, 'code' => 'unauthenticated', 'message' => 'Session not recognised. Please sign in again.', 'user' => null];
        }
        if ($row['revoked_at'] !== null) {
            return ['ok' => false, 'code' => 'token_expired', 'message' => 'Your session was ended. Please sign in again.', 'user' => null];
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return ['ok' => false, 'code' => 'token_expired', 'message' => 'Your session has expired. Please sign in again.', 'user' => null];
        }

        $user = Auth::fetchUser((int) $row['user_id']);
        if ($user === null) {
            return ['ok' => false, 'code' => 'unauthenticated', 'message' => 'Account no longer exists.', 'user' => null];
        }

        $status = $this->checkStatus($user);
        if (!$status['ok']) {
            return ['ok' => false, 'code' => $status['code'], 'message' => $status['message'], 'user' => null];
        }

        // Touch last_used_at at most once a minute to avoid a write per request.
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < time() - 60) {
            Database::update('api_tokens', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
        }

        return ['ok' => true, 'code' => 'ok', 'message' => '', 'user' => $user];
    }

    public function revokeToken(string $token, string $reason = 'logout'): void
    {
        Database::run(
            'UPDATE api_tokens SET revoked_at = NOW(), revoke_reason = ? WHERE token_hash = ? AND revoked_at IS NULL',
            [substr($reason, 0, 120), hash('sha256', $token)]
        );
    }

    public function revokeAllTokens(int $userId, string $reason): void
    {
        Database::run(
            'UPDATE api_tokens SET revoked_at = NOW(), revoke_reason = ? WHERE user_id = ? AND revoked_at IS NULL',
            [substr($reason, 0, 120), $userId]
        );
    }

    // ------------------------------------------------------------------
    // Invitation codes
    // ------------------------------------------------------------------

    /**
     * @return array{ok:bool,code:string,message:string,invite:?array<string,mixed>}
     */
    public function validateInvite(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['ok' => false, 'code' => 'validation_failed', 'message' => 'Enter your invitation code.', 'invite' => null];
        }

        $invite = Database::first(
            'SELECT ic.*, r.code AS role_code, r.name AS role_name, b.name AS branch_name
             FROM invitation_codes ic
             JOIN roles r ON r.id = ic.role_id
             LEFT JOIN branches b ON b.id = ic.branch_id
             WHERE ic.code = ? LIMIT 1',
            [$code]
        );

        if ($invite === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'This invitation code does not exist.', 'invite' => null];
        }
        if ($invite['status'] === 'revoked') {
            return ['ok' => false, 'code' => 'forbidden', 'message' => 'This invitation code has been revoked.', 'invite' => null];
        }
        if ($invite['expires_at'] !== null && strtotime((string) $invite['expires_at']) < time()) {
            Database::update('invitation_codes', ['status' => 'expired'], ['id' => (int) $invite['id']]);
            return ['ok' => false, 'code' => 'forbidden', 'message' => 'This invitation code has expired. Ask your administrator for a new one.', 'invite' => null];
        }
        if ((int) $invite['used_count'] >= (int) $invite['max_uses']) {
            Database::update('invitation_codes', ['status' => 'exhausted'], ['id' => (int) $invite['id']]);
            return ['ok' => false, 'code' => 'forbidden', 'message' => 'This invitation code has already been used.', 'invite' => null];
        }

        return ['ok' => true, 'code' => 'ok', 'message' => 'Invitation code is valid.', 'invite' => $invite];
    }

    /**
     * Create a user against a validated invitation.
     *
     * @param array<string,mixed> $invite
     * @param array{full_name:string,mobile:?string,email:?string,password:?string,employee_code:?string,bc_code:?string} $data
     * @return array{ok:bool,code:string,message:string,user_id:?int,pending:bool}
     */
    public function registerWithInvite(array $invite, array $data, string $ip): array
    {
        $mobileHash = Crypto::blindIndex($data['mobile'] ?? null, 'mobile');
        $emailHash = Crypto::blindIndex($data['email'] ?? null, 'email');

        // Email is the account identity and the OTP channel, so it is mandatory.
        // Mobile is optional and only used for SMS reminders.
        if ($emailHash === null) {
            return ['ok' => false, 'code' => 'validation_failed', 'message' => 'An email address is required.', 'user_id' => null, 'pending' => false];
        }

        // Uniqueness (the DB also enforces this, but a clear message is nicer).
        if ($mobileHash !== null && Database::first('SELECT id FROM users WHERE mobile_hash = ?', [$mobileHash]) !== null) {
            return ['ok' => false, 'code' => 'duplicate', 'message' => 'This mobile number is already registered. Try signing in instead.', 'user_id' => null, 'pending' => false];
        }
        if ($emailHash !== null && Database::first('SELECT id FROM users WHERE email_hash = ?', [$emailHash]) !== null) {
            return ['ok' => false, 'code' => 'duplicate', 'message' => 'This email address is already registered. Try signing in instead.', 'user_id' => null, 'pending' => false];
        }

        $requiresApproval = (int) $invite['requires_approval'] === 1;
        $roleCode = (string) $invite['role_code'];

        try {
            $userId = Database::transaction(function () use ($invite, $data, $mobileHash, $emailHash, $requiresApproval, $roleCode, $ip): int {
                $userId = Database::insert('users', [
                    'uuid'              => Crypto::uuid4(),
                    'role_id'           => (int) $invite['role_id'],
                    'region_id'         => $invite['region_id'] === null ? null : (int) $invite['region_id'],
                    'branch_id'         => $invite['branch_id'] === null ? null : (int) $invite['branch_id'],
                    'employee_code'     => ($data['employee_code'] ?? '') !== '' ? $data['employee_code'] : null,
                    'full_name'         => $data['full_name'],
                    'mobile_enc'        => Crypto::encrypt(Crypto::normalise($data['mobile'] ?? null, 'mobile')),
                    'mobile_hash'       => $mobileHash,
                    'mobile_last4'      => Crypto::last4($data['mobile'] ?? null),
                    'email_enc'         => Crypto::encrypt(Crypto::normalise($data['email'] ?? null, 'email')),
                    'email_hash'        => $emailHash,
                    'password_hash'     => ($data['password'] ?? '') !== '' ? password_hash((string) $data['password'], PASSWORD_DEFAULT) : null,
                    'status'            => $requiresApproval ? 'pending' : 'active',
                    'invitation_code_id' => (int) $invite['id'],
                ]);

                // BC agents get their profile row so allocation can find them.
                if ($roleCode === Auth::ROLE_BC_AGENT) {
                    $bcCode = ($data['bc_code'] ?? '') !== ''
                        ? (string) $data['bc_code']
                        : (($data['employee_code'] ?? '') !== '' ? (string) $data['employee_code'] : 'BC' . str_pad((string) $userId, 5, '0', STR_PAD_LEFT));

                    // bc_code is unique; fall back to a suffixed value on clash.
                    if (Database::first('SELECT id FROM bc_agents WHERE bc_code = ?', [$bcCode]) !== null) {
                        $bcCode .= '-' . $userId;
                    }

                    Database::insert('bc_agents', [
                        'user_id'   => $userId,
                        'bc_code'   => $bcCode,
                        'branch_id' => $invite['branch_id'] === null ? null : (int) $invite['branch_id'],
                        'status'    => 'active',
                    ]);
                }

                Database::run(
                    'UPDATE invitation_codes SET used_count = used_count + 1,
                        status = CASE WHEN used_count + 1 >= max_uses THEN "exhausted" ELSE status END
                     WHERE id = ?',
                    [(int) $invite['id']]
                );

                Database::insert('invitation_redemptions', [
                    'code_id'    => (int) $invite['id'],
                    'user_id'    => $userId,
                    'ip_address' => $ip !== '' ? $ip : null,
                ]);

                return $userId;
            });
        } catch (\Throwable $e) {
            \Lib\Logger::error('Registration failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'code' => 'server_error',
                'message' => 'Registration could not be completed. Please try again or contact your administrator.',
                'user_id' => null,
                'pending' => false,
            ];
        }

        Audit::log(
            'user.registered',
            'user',
            $userId,
            'Registered with invitation code ' . $invite['code'],
            null,
            null,
            'notice',
            'api'
        );

        return [
            'ok' => true,
            'code' => 'ok',
            'message' => $requiresApproval
                ? 'Registration submitted. Your account will be active once an administrator approves it.'
                : 'Registration complete. You can start using the app.',
            'user_id' => $userId,
            'pending' => $requiresApproval,
        ];
    }

    /** Generate a unique invitation code of the configured length. */
    public function generateInviteCode(): string
    {
        $length = max(6, min(20, Settings::getInt('invite.code_length', 10)));
        for ($i = 0; $i < 12; $i++) {
            $code = Crypto::randomCode($length);
            if (Database::first('SELECT id FROM invitation_codes WHERE code = ?', [$code]) === null) {
                return $code;
            }
        }
        // Astronomically unlikely; make it deterministic rather than loop forever.
        return Crypto::randomCode($length + 4);
    }
}
