<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuthService;
use App\Services\OtpService;
use Lib\Crypto;
use Lib\Settings;

/**
 * Authentication endpoints for the Android app.
 *
 * Open signup is disabled by design: registration always goes through an
 * invitation code created in the admin panel.
 */
final class AuthApiController extends ApiController
{
    protected bool $requiresAuth = false;

    // ------------------------------------------------------------------
    // POST /auth/invite/validate
    // ------------------------------------------------------------------
    public function validateInvite(): void
    {
        $result = (new AuthService())->validateInvite($this->request->str('code'));

        if (!$result['ok'] || $result['invite'] === null) {
            Response::fail($result['message'], $result['code'], $result['code'] === 'not_found' ? 404 : 403);
            return;
        }

        $invite = $result['invite'];
        Response::ok([
            'role'              => (string) $invite['role_code'],
            'role_name'         => (string) $invite['role_name'],
            'branch_name'       => $invite['branch_name'],
            'requires_approval' => (int) $invite['requires_approval'] === 1,
            'expires_at'        => $invite['expires_at'],
        ], $result['message']);
    }

    // ------------------------------------------------------------------
    // POST /auth/otp/request
    // ------------------------------------------------------------------
    public function requestOtp(): void
    {
        $identifier = $this->request->str('identifier');
        $type = $this->request->str('identifier_type', 'email');
        $purpose = $this->request->str('purpose', 'login');

        $validator = new Validator($this->request->all());
        $validator->required('identifier')
            ->inList('identifier_type', ['mobile', 'email'])
            ->inList('purpose', ['login', 'register', 'reset_password']);

        if ($type === 'mobile') {
            $validator->mobile('identifier');
        } else {
            $validator->email('identifier');
        }

        if ($validator->fails()) {
            Response::fail($validator->firstError(), 'validation_failed', 422, $validator->errors());
            return;
        }

        $service = new AuthService();
        $user = $service->findByIdentifier($identifier);

        // For login and password reset the account must already exist. Return a
        // generic message either way so the endpoint cannot enumerate users,
        // but do not actually send an SMS for an unknown number.
        if (in_array($purpose, ['login', 'reset_password'], true)) {
            if ($user === null) {
                Response::ok(
                    [
                        'expires_in_seconds'   => Settings::getInt('security.otp_expiry_minutes', 10) * 60,
                        'resend_after_seconds' => Settings::getInt('security.otp_resend_seconds', 60),
                    ],
                    'If this ' . ($type === 'mobile' ? 'number' : 'email address')
                        . ' is registered, an OTP has been sent to it.'
                );
                return;
            }

            $status = $service->checkStatus($user);
            if (!$status['ok']) {
                Response::fail($status['message'], $status['code'], 403);
                return;
            }
        }

        // For registration the identifier must NOT already exist.
        if ($purpose === 'register' && $user !== null) {
            Response::fail(
                'This ' . ($type === 'mobile' ? 'mobile number' : 'email address')
                    . ' is already registered. Please sign in instead.',
                'duplicate',
                409
            );
            return;
        }

        $result = (new OtpService())->issue(
            $identifier,
            $type,
            $purpose,
            $user === null ? null : (int) $user['id'],
            $this->request->ip()
        );

        if (!$result['ok']) {
            $status = match ($result['code']) {
                'otp_throttled', 'rate_limited' => 429,
                'otp_delivery_failed' => 502,
                default => 422,
            };
            Response::json([
                'success' => false,
                'code' => $result['code'],
                'message' => $result['message'],
                'data' => ['retry_after_seconds' => $result['resend_after_seconds']],
            ], $status);
            return;
        }

        Audit::api('otp.requested', 'user', $user === null ? null : (int) $user['id'], 'Purpose: ' . $purpose);

        Response::ok([
            'expires_in_seconds'   => $result['expires_in_seconds'],
            'resend_after_seconds' => $result['resend_after_seconds'],
            'masked'               => $result['masked'],
        ], $result['message']);
    }

    // ------------------------------------------------------------------
    // POST /auth/otp/verify  (login with OTP)
    // ------------------------------------------------------------------
    public function verifyOtp(): void
    {
        $validator = new Validator($this->request->all());
        $validator->required('identifier')->required('otp')
            ->inList('identifier_type', ['mobile', 'email']);
        if ($validator->fails()) {
            Response::fail($validator->firstError(), 'validation_failed', 422, $validator->errors());
            return;
        }

        $identifier = $this->request->str('identifier');
        $type = $this->request->str('identifier_type', 'email');

        $verification = (new OtpService())->verify($identifier, $type, 'login', $this->request->str('otp'));
        if (!$verification['ok']) {
            Response::fail($verification['message'], $verification['code'], $verification['code'] === 'rate_limited' ? 429 : 400);
            return;
        }

        $service = new AuthService();
        $user = $service->findByIdentifier($identifier);
        if ($user === null) {
            Response::fail('Account not found.', 'not_found', 404);
            return;
        }

        $status = $service->checkStatus($user);
        if (!$status['ok']) {
            Response::fail($status['message'], $status['code'], 403);
            return;
        }

        $this->completeLogin($user, 'otp');
    }

    // ------------------------------------------------------------------
    // POST /auth/login  (password)
    // ------------------------------------------------------------------
    public function login(): void
    {
        $validator = new Validator($this->request->all());
        $validator->required('identifier')->required('password');
        if ($validator->fails()) {
            Response::fail($validator->firstError(), 'validation_failed', 422, $validator->errors());
            return;
        }

        $result = (new AuthService())->attemptPassword(
            $this->request->str('identifier'),
            (string) $this->request->input('password', ''),
            $this->request->ip(),
            'api'
        );

        if (!$result['ok'] || $result['user'] === null) {
            $status = match ($result['code']) {
                'rate_limited' => 429,
                'account_pending', 'account_suspended' => 403,
                default => 401,
            };
            Response::fail($result['message'], $result['code'], $status);
            return;
        }

        $this->completeLogin($result['user'], 'password');
    }

    // ------------------------------------------------------------------
    // POST /auth/register
    // ------------------------------------------------------------------
    public function register(): void
    {
        $data = $this->request->all();
        $type = $this->request->str('identifier_type', 'email');

        $validator = new Validator($data);
        $validator->required('invite_code')
            ->required('full_name')->maxLen('full_name', 150)
            ->required('identifier')
            ->required('otp')
            ->inList('identifier_type', ['mobile', 'email'])
            ->maxLen('employee_code', 40);

        if ($type === 'mobile') {
            $validator->mobile('identifier');
        } else {
            $validator->email('identifier');
        }
        if ($this->request->str('email') !== '') {
            $validator->email('email');
        }
        if ((string) $this->request->input('password', '') !== '') {
            $validator->strongPassword('password');
        }

        if ($validator->fails()) {
            Response::fail($validator->firstError(), 'validation_failed', 422, $validator->errors());
            return;
        }

        $service = new AuthService();
        $inviteResult = $service->validateInvite($this->request->str('invite_code'));
        if (!$inviteResult['ok'] || $inviteResult['invite'] === null) {
            Response::fail($inviteResult['message'], $inviteResult['code'], 403);
            return;
        }

        $verification = (new OtpService())->verify(
            $this->request->str('identifier'),
            $type,
            'register',
            $this->request->str('otp')
        );
        if (!$verification['ok']) {
            Response::fail($verification['message'], $verification['code'], 400);
            return;
        }

        $mobile = $type === 'mobile' ? $this->request->str('identifier') : $this->request->str('mobile');
        $email = $type === 'email' ? $this->request->str('identifier') : $this->request->str('email');

        // Email is the account's identity, so it cannot be skipped even when the
        // OTP went to a mobile. Mobile stays optional.
        if ($email === '') {
            Response::fail(
                'An email address is required to create an account.',
                'validation_failed',
                422,
                ['email' => 'Enter your email address.']
            );
            return;
        }

        $result = $service->registerWithInvite($inviteResult['invite'], [
            'full_name'     => $this->request->str('full_name'),
            'mobile'        => $mobile !== '' ? $mobile : null,
            'email'         => $email !== '' ? $email : null,
            'password'      => (string) $this->request->input('password', ''),
            'employee_code' => $this->request->str('employee_code') !== '' ? $this->request->str('employee_code') : null,
            'bc_code'       => $this->request->str('bc_code') !== '' ? $this->request->str('bc_code') : null,
        ], $this->request->ip());

        if (!$result['ok'] || $result['user_id'] === null) {
            Response::fail($result['message'], $result['code'], $result['code'] === 'duplicate' ? 409 : 422);
            return;
        }

        if ($result['pending']) {
            Response::json([
                'success' => false,
                'code' => 'account_pending',
                'message' => $result['message'],
                'data' => ['user_id' => $result['user_id'], 'requires_approval' => true],
            ], 403);
            return;
        }

        $user = Auth::fetchUser($result['user_id']);
        if ($user === null) {
            Response::fail('Registration saved but the account could not be loaded. Please sign in.', 'server_error', 500);
            return;
        }

        $this->completeLogin($user, 'register');
    }

    // ------------------------------------------------------------------
    // POST /auth/password/reset  (forgot password via OTP)
    // ------------------------------------------------------------------
    public function resetPassword(): void
    {
        $validator = new Validator($this->request->all());
        $validator->required('identifier')->required('otp')
            ->required('new_password')->strongPassword('new_password')
            ->inList('identifier_type', ['mobile', 'email']);
        if ($validator->fails()) {
            Response::fail($validator->firstError(), 'validation_failed', 422, $validator->errors());
            return;
        }

        $identifier = $this->request->str('identifier');
        $type = $this->request->str('identifier_type', 'email');

        $verification = (new OtpService())->verify($identifier, $type, 'reset_password', $this->request->str('otp'));
        if (!$verification['ok']) {
            Response::fail($verification['message'], $verification['code'], 400);
            return;
        }

        $service = new AuthService();
        $user = $service->findByIdentifier($identifier);
        if ($user === null) {
            Response::fail('Account not found.', 'not_found', 404);
            return;
        }

        Database::update('users', [
            'password_hash'        => password_hash((string) $this->request->input('new_password', ''), PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'failed_attempts'      => 0,
            'locked_until'         => null,
        ], ['id' => (int) $user['id']]);

        // Any session on any device is now stale.
        $service->revokeAllTokens((int) $user['id'], 'password reset');

        Auth::setUser($user);
        Audit::log('password.reset', 'user', (int) $user['id'], 'Password reset via OTP', null, null, 'notice', 'api');
        Auth::setUser(null);

        Response::ok(null, 'Password updated. Please sign in with your new password.');
    }

    // ------------------------------------------------------------------
    // POST /auth/logout
    // ------------------------------------------------------------------
    public function logout(): void
    {
        $token = $this->request->bearerToken();
        if ($token === null) {
            Response::ok(null, 'Already signed out.');
            return;
        }

        $service = new AuthService();
        $resolved = $service->resolveToken($token);
        if ($resolved['ok'] && $resolved['user'] !== null) {
            Auth::setUser($resolved['user']);
            Audit::api('auth.logout', 'user', (int) $resolved['user']['id'], 'Signed out from the app');
        }

        $service->revokeToken($token, 'logout');
        Response::ok(null, 'Signed out.');
    }

    // ------------------------------------------------------------------
    // Shared: bind the device, issue a token, return the session payload
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $user */
    private function completeLogin(array $user, string $method): void
    {
        $deviceId = $this->deviceId();
        $deviceModel = $this->request->str('device_model');
        $osVersion = $this->request->str('os_version');
        $appVersion = $this->appVersion();

        $binding = Auth::checkDeviceBinding($user, $deviceId);
        if (!$binding['ok']) {
            Auth::setUser($user);
            Audit::security('auth.device_mismatch',
                'Login blocked: user #' . $user['id'] . ' tried a different device', 'warning');
            Auth::setUser(null);
            Response::fail($binding['message'], 'device_mismatch', 403);
            return;
        }

        if ($deviceId !== '') {
            Auth::bindDevice((int) $user['id'], $deviceId, $deviceModel, $osVersion, $appVersion);
        }

        $token = (new AuthService())->issueToken(
            (int) $user['id'],
            $deviceId,
            $deviceModel,
            $appVersion,
            $this->request->ip()
        );

        // Reload so device_bound_at etc. are current.
        $fresh = Auth::fetchUser((int) $user['id']) ?? $user;
        Auth::setUser($fresh);
        Audit::api('auth.login', 'user', (int) $user['id'], 'Signed in via ' . $method);

        Response::ok([
            'token'      => $token['token'],
            'token_type' => 'Bearer',
            'expires_at' => $token['expires_at'],
            'user'       => $this->userBlock($fresh),
            'config'     => $this->configBlock(),
        ], 'Signed in successfully.');
    }
}
