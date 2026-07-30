<?php

declare(strict_types=1);

namespace Lib;

use App\Core\Database;

/**
 * Provider-agnostic SMS sender.
 *
 * Instead of hardcoding one vendor's SDK, the admin panel stores a URL
 * template with placeholders. That covers virtually every Indian HTTP SMS
 * gateway (MSG91, TextLocal, Fast2SMS, SMSCountry, Kaleyra, ...).
 *
 * Supported placeholders in `sms.api_url`, `sms.api_key`, `sms.sender_id`:
 *   {api_key} {api_secret} {sender_id} {mobile} {mobile_91} {message}
 *   {message_raw} {dlt_template_id} {otp}
 *
 * Examples of what an admin would paste into Settings > SMS Gateway:
 *
 *  GET style (MSG91-like):
 *   https://api.msg91.com/api/v2/sendsms?authkey={api_key}&mobiles={mobile_91}
 *     &message={message}&sender={sender_id}&route=4&country=91
 *
 *  POST_JSON style:
 *   https://api.example.com/v1/sms
 *   with body template configured as
 *   {"to":"{mobile_91}","from":"{sender_id}","text":"{message_raw}"}
 *
 * cURL is used when available and falls back to file_get_contents with a
 * stream context, because a few shared hosts disable one or the other.
 */
final class SmsGateway
{
    private string $lastError = '';
    private string $lastResponse = '';

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function lastResponse(): string
    {
        return $this->lastResponse;
    }

    public static function isConfigured(): bool
    {
        return Settings::has('sms.api_url') && Settings::has('sms.api_key');
    }

    /**
     * Send an arbitrary SMS.
     *
     * @return array{sent:bool,error:string} never throws
     */
    public function send(string $mobile, string $message, string $template = 'generic'): array
    {
        $this->lastError = '';
        $this->lastResponse = '';

        $normalised = Crypto::normalise($mobile, 'mobile');
        if ($normalised === null || strlen($normalised) !== 10) {
            $msg = 'Invalid mobile number for SMS.';
            $this->log($mobile, $template, 'failed', $msg);
            return ['sent' => false, 'error' => $msg];
        }

        if (!self::isConfigured()) {
            // Graceful skip: the app keeps working, the admin sees a warning.
            $msg = 'SMS gateway is not configured. Go to Settings > SMS Gateway in the admin panel.';
            $this->log($mobile, $template, 'skipped', $msg);
            Logger::warning('SMS skipped - gateway not configured.');
            return ['sent' => false, 'error' => $msg];
        }

        $method = strtoupper(Settings::getString('sms.method', 'GET'));
        $urlTemplate = Settings::getString('sms.api_url');
        $bodyTemplate = Settings::getString('sms.body_template', '');

        $replacements = [
            '{api_key}'          => Settings::getString('sms.api_key'),
            '{api_secret}'       => Settings::getString('sms.api_secret'),
            '{sender_id}'        => Settings::getString('sms.sender_id'),
            '{dlt_template_id}'  => Settings::getString('sms.dlt_template_id'),
            '{mobile}'           => $normalised,
            '{mobile_91}'        => '91' . $normalised,
            '{message}'          => rawurlencode($message),
            '{message_raw}'      => $message,
        ];

        $url = strtr($urlTemplate, $replacements);
        $body = $bodyTemplate === '' ? '' : strtr($bodyTemplate, $replacements);

        try {
            [$status, $response] = $this->request($method, $url, $body);
            $this->lastResponse = substr($response, 0, 500);

            $ok = $status >= 200 && $status < 300 && !$this->looksLikeFailure($response);

            $this->log(
                $mobile,
                $template,
                $ok ? 'sent' : 'failed',
                $ok ? null : ('HTTP ' . $status . ': ' . substr($response, 0, 180))
            );

            if (!$ok) {
                $this->lastError = 'Gateway returned HTTP ' . $status . '. ' . substr($response, 0, 180);
                Logger::error('SMS gateway rejected the request.', [
                    'status'   => $status,
                    // The URL contains the API key, so log only the host.
                    'host'     => parse_url($url, PHP_URL_HOST),
                    'response' => substr($response, 0, 300),
                ]);
                return ['sent' => false, 'error' => $this->lastError];
            }

            return ['sent' => true, 'error' => ''];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Logger::error('SMS send failed: ' . $e->getMessage(), [
                'host' => parse_url($url, PHP_URL_HOST),
            ]);
            $this->log($mobile, $template, 'failed', $e->getMessage());
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    /** Send an OTP using the configured template. */
    public function sendOtp(string $mobile, string $otp, int $validMinutes): array
    {
        $template = Settings::getString(
            'sms.otp_template',
            'Your LRMS OTP is {otp}. Valid for {minutes} minutes. Do not share.'
        );

        $message = strtr($template, [
            '{otp}'      => $otp,
            '{minutes}'  => (string) $validMinutes,
            '{app_name}' => Settings::getString('company.app_name', 'LRMS'),
        ]);

        return $this->send($mobile, $message, 'otp');
    }

    /**
     * @return array{0:int,1:string} [httpStatus, responseBody]
     */
    private function request(string $method, string $url, string $body): array
    {
        if (function_exists('curl_init')) {
            return $this->curlRequest($method, $url, $body);
        }
        return $this->streamRequest($method, $url, $body);
    }

    /** @return array{0:int,1:string} */
    private function curlRequest(string $method, string $url, string $body): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('curl_init() failed.');
        }

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'LRMS/1.0',
        ];

        if ($method === 'POST' || $method === 'POST_JSON') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER] = $method === 'POST_JSON'
                ? ['Content-Type: application/json', 'Accept: application/json']
                : ['Content-Type: application/x-www-form-urlencoded'];
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('SMS request failed: ' . ($err !== '' ? $err : 'unknown cURL error'));
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $response];
    }

    /** @return array{0:int,1:string} */
    private function streamRequest(string $method, string $url, string $body): array
    {
        $headers = "User-Agent: LRMS/1.0\r\n";
        if ($method === 'POST_JSON') {
            $headers .= "Content-Type: application/json\r\n";
        } elseif ($method === 'POST') {
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method === 'GET' ? 'GET' : 'POST',
                'header'        => $headers,
                'content'       => $method === 'GET' ? '' : $body,
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException(
                'SMS request failed. Both cURL and allow_url_fopen appear to be unavailable or the '
                . 'gateway is unreachable from this host.'
            );
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status === 0 ? 200 : $status, (string) $response];
    }

    /**
     * Many gateways return HTTP 200 with an error payload, so look for the
     * usual failure markers as well.
     */
    private function looksLikeFailure(string $response): bool
    {
        $lower = strtolower($response);
        foreach (['"status":"failure"', '"type":"error"', 'invalid api', 'authentication failed',
                  'insufficient', 'invalid_key', '"success":false', 'error_code'] as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function log(string $mobile, string $template, string $status, ?string $error): void
    {
        try {
            Database::insert('message_logs', [
                'channel'          => 'sms',
                'recipient_masked' => Crypto::maskMobile($mobile),
                'recipient_hash'   => Crypto::blindIndex($mobile, 'mobile'),
                'template'         => substr($template, 0, 60),
                'status'           => $status,
                'provider'         => Settings::getString('sms.provider', 'custom'),
                'error'            => $error === null ? null : substr($error, 0, 255),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('message_logs insert failed: ' . $e->getMessage());
        }
    }
}
