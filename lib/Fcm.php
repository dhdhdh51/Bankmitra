<?php

declare(strict_types=1);

namespace Lib;

use App\Core\Database;

/**
 * Firebase Cloud Messaging sender - optional module.
 *
 * Google retired the legacy `key=SERVER_KEY` endpoint, so the primary path
 * here is FCM HTTP v1, which needs a service-account JSON and a signed JWT.
 * We mint that JWT with openssl_sign (RS256) - no Composer packages needed.
 *
 * If Firebase is not configured, every call returns a "skipped" result and
 * the rest of the application carries on normally.
 */
final class Fcm
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public static function isConfigured(): bool
    {
        return Settings::has('firebase.service_account_json')
            || Settings::has('firebase.server_key');
    }

    /**
     * Push to a single device token.
     *
     * @param array<string,string> $data extra key/value payload for the app
     * @return array{sent:bool,error:string}
     */
    public function sendToToken(string $deviceToken, string $title, string $body, array $data = []): array
    {
        if ($deviceToken === '') {
            return ['sent' => false, 'error' => 'No FCM token for this device.'];
        }

        if (!self::isConfigured()) {
            $msg = 'Firebase is not configured. Go to Settings > Firebase (Push) in the admin panel.';
            $this->log($deviceToken, $title, 'skipped', $msg);
            return ['sent' => false, 'error' => $msg];
        }

        try {
            if (Settings::has('firebase.service_account_json')) {
                return $this->sendV1($deviceToken, $title, $body, $data);
            }
            return $this->sendLegacy($deviceToken, $title, $body, $data);
        } catch (\Throwable $e) {
            Logger::error('FCM send failed: ' . $e->getMessage());
            $this->log($deviceToken, $title, 'failed', $e->getMessage());
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Push to every active device of a user, and always record an in-app
     * notification so nothing is lost when push is unavailable.
     *
     * @param array<string,string> $data
     * @return array{sent:int,failed:int,error:string}
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): array
    {
        $notificationId = Database::insert('notifications', [
            'user_id'   => $userId,
            'title'     => substr($title, 0, 150),
            'body'      => substr($body, 0, 500),
            'data_json' => $data === [] ? null : json_encode($data),
            'channel'   => 'push',
            'status'    => 'queued',
        ]);

        $tokens = Database::all(
            'SELECT fcm_token FROM user_devices
             WHERE user_id = ? AND status = "active" AND fcm_token IS NOT NULL AND fcm_token <> ""',
            [$userId]
        );

        if ($tokens === []) {
            Database::update('notifications', [
                'status' => 'skipped',
                'error'  => 'No registered device token.',
            ], ['id' => $notificationId]);
            return ['sent' => 0, 'failed' => 0, 'error' => 'No registered device token for this user.'];
        }

        $sent = 0;
        $failed = 0;
        $lastError = '';
        foreach ($tokens as $row) {
            $result = $this->sendToToken((string) $row['fcm_token'], $title, $body, $data);
            if ($result['sent']) {
                $sent++;
            } else {
                $failed++;
                $lastError = $result['error'];
            }
        }

        Database::update('notifications', [
            'status'  => $sent > 0 ? 'sent' : 'failed',
            'sent_at' => $sent > 0 ? date('Y-m-d H:i:s') : null,
            'error'   => $sent > 0 ? null : substr($lastError, 0, 255),
        ], ['id' => $notificationId]);

        return ['sent' => $sent, 'failed' => $failed, 'error' => $lastError];
    }

    // ------------------------------------------------------------------
    // FCM HTTP v1
    // ------------------------------------------------------------------

    /** @param array<string,string> $data */
    private function sendV1(string $deviceToken, string $title, string $body, array $data): array
    {
        $raw = Settings::getString('firebase.service_account_json');
        $account = json_decode($raw, true);
        if (!is_array($account) || !isset($account['client_email'], $account['private_key'])) {
            throw new \RuntimeException(
                'Firebase service account JSON is invalid. Paste the whole file contents from '
                . 'Firebase Console > Project Settings > Service accounts > Generate new private key.'
            );
        }

        $projectId = Settings::getString('firebase.project_id', (string) ($account['project_id'] ?? ''));
        if ($projectId === '') {
            throw new \RuntimeException('Firebase project ID is missing.');
        }

        $accessToken = $this->accessToken((string) $account['client_email'], (string) $account['private_key']);

        // FCM v1 requires every data value to be a string.
        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[(string) $k] = (string) $v;
        }

        $payload = [
            'message' => [
                'token'        => $deviceToken,
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => $stringData,
                'android'      => [
                    'priority'     => 'high',
                    'notification' => ['sound' => 'default', 'channel_id' => 'lrms_default'],
                ],
            ],
        ];

        [$status, $response] = $this->httpPost(
            'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send',
            (string) json_encode($payload),
            ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']
        );

        if ($status < 200 || $status >= 300) {
            $this->log($deviceToken, $title, 'failed', 'HTTP ' . $status . ': ' . substr($response, 0, 180));
            return ['sent' => false, 'error' => 'FCM HTTP ' . $status . ': ' . substr($response, 0, 180)];
        }

        $this->log($deviceToken, $title, 'sent', null);
        return ['sent' => true, 'error' => ''];
    }

    /** Mint an OAuth2 access token from the service account (RS256 JWT). */
    private function accessToken(string $clientEmail, string $privateKey): string
    {
        static $cached = null;
        if (is_array($cached) && $cached['expires'] > time() + 60) {
            return $cached['token'];
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $clientEmail,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $signingInput = $this->b64url((string) json_encode($header))
            . '.' . $this->b64url((string) json_encode($claims));

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new \RuntimeException('Firebase private key could not be parsed (check the \\n escaping in the JSON).');
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign the Firebase JWT.');
        }

        $jwt = $signingInput . '.' . $this->b64url($signature);

        [$status, $response] = $this->httpPost(
            self::TOKEN_URL,
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            ['Content-Type: application/x-www-form-urlencoded']
        );

        $decoded = json_decode($response, true);
        if ($status !== 200 || !is_array($decoded) || !isset($decoded['access_token'])) {
            throw new \RuntimeException('Could not obtain a Firebase access token (HTTP ' . $status . ').');
        }

        $cached = [
            'token'   => (string) $decoded['access_token'],
            'expires' => $now + (int) ($decoded['expires_in'] ?? 3600),
        ];

        return $cached['token'];
    }

    // ------------------------------------------------------------------
    // Legacy endpoint (kept for older Firebase projects that still allow it)
    // ------------------------------------------------------------------

    /** @param array<string,string> $data */
    private function sendLegacy(string $deviceToken, string $title, string $body, array $data): array
    {
        $serverKey = Settings::getString('firebase.server_key');
        $payload = [
            'to'           => $deviceToken,
            'priority'     => 'high',
            'notification' => ['title' => $title, 'body' => $body, 'sound' => 'default'],
            'data'         => $data,
        ];

        [$status, $response] = $this->httpPost(
            'https://fcm.googleapis.com/fcm/send',
            (string) json_encode($payload),
            ['Authorization: key=' . $serverKey, 'Content-Type: application/json']
        );

        $ok = $status >= 200 && $status < 300 && !str_contains($response, '"failure":1');
        $this->log($deviceToken, $title, $ok ? 'sent' : 'failed', $ok ? null : substr($response, 0, 180));

        if (!$ok) {
            return [
                'sent'  => false,
                'error' => 'Legacy FCM returned HTTP ' . $status . '. Google has retired this endpoint - '
                    . 'switch to a service account JSON in Settings > Firebase.',
            ];
        }
        return ['sent' => true, 'error' => ''];
    }

    /**
     * @param list<string> $headers
     * @return array{0:int,1:string}
     */
    private function httpPost(string $url, string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('curl_init() failed.');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new \RuntimeException('Push request failed: ' . $err);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$status, (string) $response];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException('Push request failed (cURL and allow_url_fopen both unavailable?).');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return [$status === 0 ? 200 : $status, (string) $response];
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function log(string $token, string $title, string $status, ?string $error): void
    {
        try {
            Database::insert('message_logs', [
                'channel'          => 'push',
                // Never store a full device token in the log table.
                'recipient_masked' => substr($token, 0, 6) . '...' . substr($token, -4),
                'recipient_hash'   => hash('sha256', $token),
                'template'         => 'push',
                'subject'          => substr($title, 0, 190),
                'status'           => $status,
                'provider'         => 'fcm',
                'error'            => $error === null ? null : substr($error, 0, 255),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('message_logs insert failed: ' . $e->getMessage());
        }
    }
}
