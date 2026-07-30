<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuthService;

/**
 * The signed-in agent's own profile and device registration.
 */
final class MeApiController extends ApiController
{
    /** GET /me */
    public function show(): void
    {
        Response::ok([
            'user'   => $this->userBlock($this->user ?? []),
            'config' => $this->configBlock(),
        ]);
    }

    /** POST /me/fcm-token */
    public function saveFcmToken(): void
    {
        $token = $this->request->str('fcm_token');
        if ($token === '') {
            Response::fail('The FCM token is missing.', 'validation_failed', 422);
            return;
        }

        $deviceId = $this->deviceId();
        if ($deviceId === '') {
            Response::fail('The device id is missing.', 'validation_failed', 422);
            return;
        }

        $existing = Database::first(
            'SELECT id FROM user_devices WHERE user_id = ? AND device_id = ? LIMIT 1',
            [$this->userId(), $deviceId]
        );

        if ($existing === null) {
            Database::insert('user_devices', [
                'user_id'     => $this->userId(),
                'device_id'   => $deviceId,
                'app_version' => $this->appVersion() !== '' ? $this->appVersion() : null,
                'fcm_token'   => $token,
                'status'      => 'active',
            ]);
        } else {
            Database::update('user_devices', [
                'fcm_token'   => $token,
                'app_version' => $this->appVersion() !== '' ? $this->appVersion() : null,
                'status'      => 'active',
            ], ['id' => (int) $existing['id']]);
        }

        // The token itself is a credential-like value, so it is never logged.
        Audit::api('device.fcm_registered', 'user', $this->userId(), 'Push token registered');

        Response::ok(null, 'Push notifications registered.');
    }

    /** POST /me/password */
    public function changePassword(): void
    {
        $validator = new Validator($this->request->all());
        $validator->required('new_password')->strongPassword('new_password');
        if ($validator->fails()) {
            Response::fail($validator->firstError(), 'validation_failed', 422, $validator->errors());
            return;
        }

        $current = (string) $this->request->input('current_password', '');
        $existingHash = (string) ($this->user['password_hash'] ?? '');

        // A user who has never set a password (OTP-only) may set one without
        // providing the current value.
        if ($existingHash !== '') {
            if ($current === '') {
                Response::fail('Enter your current password.', 'validation_failed', 422, [
                    'current_password' => 'Enter your current password.',
                ]);
                return;
            }
            if (!password_verify($current, $existingHash)) {
                Response::fail('Your current password is incorrect.', 'validation_failed', 422, [
                    'current_password' => 'Your current password is incorrect.',
                ]);
                return;
            }
        }

        Database::update('users', [
            'password_hash'        => password_hash((string) $this->request->input('new_password', ''), PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ], ['id' => $this->userId()]);

        // Keep the current session, kill every other one.
        $service = new AuthService();
        $service->revokeAllTokens($this->userId(), 'password changed');
        $newToken = $service->issueToken(
            $this->userId(),
            $this->deviceId(),
            $this->request->str('device_model'),
            $this->appVersion(),
            $this->request->ip()
        );

        Audit::api('password.changed', 'user', $this->userId(), 'Password changed from the app');

        Response::ok([
            'token'      => $newToken['token'],
            'expires_at' => $newToken['expires_at'],
        ], 'Password updated. Other devices have been signed out.');
    }
}
