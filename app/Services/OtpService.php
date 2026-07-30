<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Lib\Crypto;
use Lib\Logger;
use Lib\Mailer;
use Lib\Settings;
use Lib\SmsGateway;

/**
 * One-time password issuing and verification.
 *
 * Security properties:
 *  - the OTP is never stored (only password_hash of it) and never logged,
 *  - the identifier is never stored in clear here (only its blind index),
 *  - resend is throttled, attempts are capped,
 *  - a missing SMS/SMTP configuration produces a clear, actionable error
 *    instead of a silent no-op.
 */
final class OtpService
{
    /**
     * Issue and deliver an OTP.
     *
     * @param 'mobile'|'email' $type
     * @param 'login'|'register'|'reset_password'|'device_reset'|'verify' $purpose
     * @return array{
     *     ok:bool, code:string, message:string,
     *     expires_in_seconds:int, resend_after_seconds:int, masked:string
     * }
     */
    public function issue(string $identifier, string $type, string $purpose, ?int $userId = null, string $ip = ''): array
    {
        $normalised = Crypto::normalise($identifier, $type);
        if ($normalised === null) {
            return $this->result(false, 'validation_failed', 'Enter a valid ' . ($type === 'mobile' ? 'mobile number' : 'email address') . '.');
        }

        $hash = Crypto::blindIndex($normalised, $type);
        $expiryMinutes = max(1, Settings::getInt('security.otp_expiry_minutes', 10));
        $resendSeconds = max(15, Settings::getInt('security.otp_resend_seconds', 60));
        $length = Settings::getInt('security.otp_length', 6);
        $masked = $type === 'mobile' ? Crypto::maskMobile($normalised) : Crypto::maskEmail($normalised);

        // ---- throttle -------------------------------------------------
        $recent = Database::first(
            'SELECT created_at FROM otp_requests
             WHERE identifier_hash = ? AND purpose = ?
             ORDER BY id DESC LIMIT 1',
            [$hash, $purpose]
        );

        if ($recent !== null) {
            $elapsed = time() - (int) strtotime((string) $recent['created_at']);
            if ($elapsed < $resendSeconds) {
                $wait = $resendSeconds - $elapsed;
                return array_merge(
                    $this->result(false, 'otp_throttled', 'Please wait ' . $wait . ' seconds before requesting a new OTP.'),
                    ['resend_after_seconds' => $wait, 'masked' => $masked]
                );
            }
        }

        // Cap requests per hour to blunt SMS-pumping abuse.
        $hourly = (int) Database::value(
            'SELECT COUNT(*) FROM otp_requests
             WHERE identifier_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            [$hash],
            0
        );
        if ($hourly >= 10) {
            return $this->result(false, 'rate_limited', 'Too many OTP requests for this account. Please try again after an hour.');
        }

        // ---- check the channel is usable before burning an OTP ---------
        if ($type === 'mobile' && !SmsGateway::isConfigured()) {
            Logger::warning('OTP requested but the SMS gateway is not configured.');
            return $this->result(
                false,
                'otp_delivery_failed',
                'SMS is not configured on the server yet. Ask your administrator to set it up in '
                    . 'Settings > SMS Gateway, or sign in with your email instead.'
            );
        }
        if ($type === 'email' && !Mailer::isConfigured()) {
            Logger::warning('OTP requested but SMTP is not configured.');
            return $this->result(
                false,
                'otp_delivery_failed',
                'Email is not configured on the server yet. Ask your administrator to set it up in '
                    . 'Settings > Email (SMTP), or sign in with your mobile number instead.'
            );
        }

        // ---- generate + persist ----------------------------------------
        $otp = Crypto::randomOtp($length);
        $expiresAt = date('Y-m-d H:i:s', time() + $expiryMinutes * 60);

        // Invalidate any earlier unconsumed OTP for the same purpose.
        Database::run(
            'UPDATE otp_requests SET consumed_at = NOW()
             WHERE identifier_hash = ? AND purpose = ? AND consumed_at IS NULL',
            [$hash, $purpose]
        );

        $otpId = Database::insert('otp_requests', [
            'identifier_hash' => $hash,
            'identifier_type' => $type,
            'purpose'         => $purpose,
            'otp_hash'        => password_hash($otp, PASSWORD_DEFAULT),
            'user_id'         => $userId,
            'attempts'        => 0,
            'max_attempts'    => max(3, Settings::getInt('security.max_login_attempts', 5)),
            'expires_at'      => $expiresAt,
            'ip_address'      => $ip !== '' ? $ip : null,
            'delivery_status' => 'queued',
        ]);

        // ---- deliver ----------------------------------------------------
        if ($type === 'mobile') {
            $delivery = (new SmsGateway())->sendOtp($normalised, $otp, $expiryMinutes);
        } else {
            $delivery = (new Mailer())->sendOtp($normalised, $otp, $expiryMinutes);
        }

        // Wipe the plaintext OTP from memory as soon as it has been handed off.
        $otp = str_repeat("\0", strlen($otp));
        unset($otp);

        Database::update('otp_requests', [
            'delivery_status' => $delivery['sent'] ? 'sent' : 'failed',
            'delivery_error'  => $delivery['sent'] ? null : substr($delivery['error'], 0, 255),
        ], ['id' => $otpId]);

        if (!$delivery['sent']) {
            return $this->result(
                false,
                'otp_delivery_failed',
                'The OTP could not be delivered. ' . $delivery['error']
            );
        }

        return array_merge(
            $this->result(true, 'ok', 'OTP sent to ' . $masked . '.'),
            [
                'expires_in_seconds'   => $expiryMinutes * 60,
                'resend_after_seconds' => $resendSeconds,
                'masked'               => $masked,
            ]
        );
    }

    /**
     * Verify an OTP and consume it on success.
     *
     * @return array{ok:bool, code:string, message:string, user_id:?int}
     */
    public function verify(string $identifier, string $type, string $purpose, string $otp): array
    {
        $normalised = Crypto::normalise($identifier, $type);
        if ($normalised === null || $otp === '') {
            return ['ok' => false, 'code' => 'validation_failed', 'message' => 'Enter the OTP that was sent to you.', 'user_id' => null];
        }

        $hash = Crypto::blindIndex($normalised, $type);

        $row = Database::first(
            'SELECT * FROM otp_requests
             WHERE identifier_hash = ? AND purpose = ? AND consumed_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [$hash, $purpose]
        );

        if ($row === null) {
            return [
                'ok' => false,
                'code' => 'otp_invalid',
                'message' => 'No active OTP found. Please request a new one.',
                'user_id' => null,
            ];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            Database::update('otp_requests', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
            return [
                'ok' => false,
                'code' => 'otp_expired',
                'message' => 'This OTP has expired. Please request a new one.',
                'user_id' => null,
            ];
        }

        if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
            Database::update('otp_requests', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
            return [
                'ok' => false,
                'code' => 'rate_limited',
                'message' => 'Too many incorrect attempts. Please request a new OTP.',
                'user_id' => null,
            ];
        }

        if (!password_verify($otp, (string) $row['otp_hash'])) {
            $attempts = (int) $row['attempts'] + 1;
            Database::update('otp_requests', ['attempts' => $attempts], ['id' => (int) $row['id']]);
            $left = max(0, (int) $row['max_attempts'] - $attempts);

            return [
                'ok' => false,
                'code' => 'otp_invalid',
                'message' => 'The OTP you entered is incorrect.'
                    . ($left > 0 ? ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.' : ' Please request a new one.'),
                'user_id' => null,
            ];
        }

        Database::update('otp_requests', ['consumed_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);

        return [
            'ok' => true,
            'code' => 'ok',
            'message' => 'OTP verified.',
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
        ];
    }

    /** Housekeeping, called by the cron job. */
    public function purgeExpired(int $olderThanDays = 2): int
    {
        return Database::run(
            'DELETE FROM otp_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [max(1, $olderThanDays)]
        )->rowCount();
    }

    /** @return array{ok:bool,code:string,message:string,expires_in_seconds:int,resend_after_seconds:int,masked:string} */
    private function result(bool $ok, string $code, string $message): array
    {
        return [
            'ok' => $ok,
            'code' => $code,
            'message' => $message,
            'expires_in_seconds' => 0,
            'resend_after_seconds' => Settings::getInt('security.otp_resend_seconds', 60),
            'masked' => '',
        ];
    }
}
