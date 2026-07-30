<?php

declare(strict_types=1);

namespace Lib;

use App\Core\Database;

/**
 * SMTP client written directly on top of PHP stream sockets.
 *
 * Why not PHPMailer? This project must run on cPanel shared hosting with no
 * Composer, no shell access and no vendor directory. `stream_socket_client`
 * plus `stream_socket_enable_crypto` are available on every host, so we speak
 * SMTP ourselves. Supports AUTH LOGIN / AUTH PLAIN, STARTTLS and implicit SSL.
 *
 * All credentials come from Settings (admin panel), never from code.
 */
final class Mailer
{
    private $socket = null;

    /** @var list<string> transcript for diagnostics (passwords redacted) */
    private array $transcript = [];

    private string $lastError = '';

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** @return list<string> */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /**
     * True when SMTP is configured well enough to attempt a send.
     * A missing configuration is skipped gracefully (never a fatal error).
     */
    public static function isConfigured(): bool
    {
        return Settings::has('smtp.host')
            && Settings::has('smtp.from_email');
    }

    /**
     * Send an email.
     *
     * @param string $to        recipient address
     * @param string $subject   plain subject
     * @param string $htmlBody  HTML body
     * @param string $textBody  optional plain-text alternative
     * @return array{sent:bool,error:string} never throws - the caller decides
     *                                       how loudly to complain
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): array
    {
        $this->lastError = '';
        $this->transcript = [];

        if (!self::isConfigured()) {
            $msg = 'SMTP is not configured. Go to Settings > Email (SMTP) in the admin panel.';
            $this->logMessage('email', $to, $subject, 'skipped', $msg);
            return ['sent' => false, 'error' => $msg];
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $msg = 'Invalid recipient email address.';
            $this->logMessage('email', $to, $subject, 'failed', $msg);
            return ['sent' => false, 'error' => $msg];
        }

        $host       = Settings::getString('smtp.host');
        $port       = Settings::getInt('smtp.port', 587);
        $username   = Settings::getString('smtp.username');
        $password   = Settings::getString('smtp.password');
        $encryption = strtolower(Settings::getString('smtp.encryption', 'tls'));
        $fromEmail  = Settings::getString('smtp.from_email');
        $fromName   = Settings::getString('smtp.from_name', 'LRMS');
        $timeout    = max(5, Settings::getInt('smtp.timeout', 20));

        try {
            $this->connect($host, $port, $encryption, $timeout);
            $this->handshake($encryption, $timeout);

            if ($username !== '' && $password !== '') {
                $this->authenticate($username, $password);
            }

            $this->command('MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
            $this->writeRaw($this->buildMessage($fromEmail, $fromName, $to, $subject, $htmlBody, $textBody));
            $this->command('.', [250]);
            $this->quit();

            $this->logMessage('email', $to, $subject, 'sent', null);
            return ['sent' => true, 'error' => ''];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->closeQuietly();
            Logger::error('SMTP send failed: ' . $e->getMessage(), [
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'transcript' => $this->transcript,
            ]);
            $this->logMessage('email', $to, $subject, 'failed', $e->getMessage());
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    /** Send an OTP email using the standard template. */
    public function sendOtp(string $to, string $otp, int $validMinutes): array
    {
        $appName = Settings::getString('company.app_name', 'LRMS');
        $org = Settings::getString('company.organisation', '');
        $heading = $org !== '' ? $org . ' - ' . $appName : $appName;

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto">'
            . '<div style="background:#0d5c46;color:#fff;padding:18px 22px;border-radius:8px 8px 0 0">'
            . '<h2 style="margin:0;font-size:19px">' . htmlspecialchars($heading, ENT_QUOTES) . '</h2>'
            . '<p style="margin:4px 0 0;font-size:12px;opacity:.85">Loan Recovery Management System</p>'
            . '</div>'
            . '<div style="border:1px solid #e3e6ea;border-top:0;padding:22px;border-radius:0 0 8px 8px">'
            . '<p style="font-size:14px;color:#333">Your one-time password is:</p>'
            . '<p style="font-size:32px;letter-spacing:8px;font-weight:bold;color:#0d5c46;margin:12px 0">'
            . htmlspecialchars($otp, ENT_QUOTES) . '</p>'
            . '<p style="font-size:13px;color:#555">This code is valid for <strong>' . $validMinutes
            . ' minutes</strong>. Do not share it with anyone, including bank staff.</p>'
            . '<p style="font-size:12px;color:#888;margin-top:20px">'
            . 'If you did not request this code, please inform your administrator immediately.</p>'
            . '</div></div>';

        $text = "Your {$appName} OTP is {$otp}. Valid for {$validMinutes} minutes. Do not share it with anyone.";

        return $this->send($to, $appName . ' verification code', $html, $text);
    }

    /**
     * Diagnostic used by the "Test Connection" button in Settings > SMTP.
     * @return array{ok:bool,message:string,transcript:list<string>}
     */
    public function testConnection(): array
    {
        if (!self::isConfigured()) {
            return [
                'ok' => false,
                'message' => 'SMTP host / from address are not filled in yet.',
                'transcript' => [],
            ];
        }

        $encryption = strtolower(Settings::getString('smtp.encryption', 'tls'));
        $timeout = max(5, Settings::getInt('smtp.timeout', 20));

        try {
            $this->connect(Settings::getString('smtp.host'), Settings::getInt('smtp.port', 587), $encryption, $timeout);
            $this->handshake($encryption, $timeout);

            $username = Settings::getString('smtp.username');
            $password = Settings::getString('smtp.password');
            if ($username !== '' && $password !== '') {
                $this->authenticate($username, $password);
                $this->quit();
                return ['ok' => true, 'message' => 'Connected and authenticated successfully.', 'transcript' => $this->transcript];
            }

            $this->quit();
            return [
                'ok' => true,
                'message' => 'Connected successfully (no username/password configured - the server must allow unauthenticated relay).',
                'transcript' => $this->transcript,
            ];
        } catch (\Throwable $e) {
            $this->closeQuietly();
            return ['ok' => false, 'message' => $e->getMessage(), 'transcript' => $this->transcript];
        }
    }

    // ------------------------------------------------------------------
    // SMTP plumbing
    // ------------------------------------------------------------------

    private function connect(string $host, int $port, string $encryption, int $timeout): void
    {
        $prefix = $encryption === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $prefix . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException(sprintf(
                'Could not connect to %s:%d (%s). On shared hosting, outgoing SMTP ports are often '
                . 'blocked - try port 587 with TLS, or 465 with SSL, or use the host\'s own mail server.',
                $host,
                $port,
                $errstr !== '' ? $errstr : ('error ' . $errno)
            ));
        }

        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;

        $this->expect([220]);
    }

    private function handshake(string $encryption, int $timeout): void
    {
        $ehloHost = $_SERVER['HTTP_HOST'] ?? (gethostname() ?: 'localhost');
        $ehloHost = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string) $ehloHost) ?: 'localhost';

        $this->command('EHLO ' . $ehloHost, [250]);

        if ($encryption === 'tls') {
            $this->command('STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if ($ok !== true) {
                throw new \RuntimeException(
                    'STARTTLS negotiation failed. Try encryption "ssl" on port 465, or "none" if the '
                    . 'server is on localhost.'
                );
            }
            // RFC 3207: re-issue EHLO after the TLS upgrade.
            $this->command('EHLO ' . $ehloHost, [250]);
        }

        stream_set_timeout($this->socket, $timeout);
    }

    private function authenticate(string $username, string $password): void
    {
        // Try AUTH LOGIN first (most widely supported), fall back to PLAIN.
        try {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($username), [334], '[username]');
            $this->command(base64_encode($password), [235], '[password]');
            return;
        } catch (\RuntimeException $e) {
            Logger::warning('AUTH LOGIN rejected, retrying with AUTH PLAIN.');
        }

        $credentials = base64_encode("\0" . $username . "\0" . $password);
        $this->command('AUTH PLAIN ' . $credentials, [235], 'AUTH PLAIN [credentials]');
    }

    /**
     * @param list<int> $expected
     * @param string|null $redactedAs what to write into the transcript instead
     */
    private function command(string $line, array $expected, ?string $redactedAs = null): string
    {
        $this->transcript[] = '> ' . ($redactedAs ?? $line);
        $this->writeRaw($line . "\r\n", false);
        return $this->expect($expected);
    }

    private function writeRaw(string $data, bool $log = false): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('SMTP socket is not open.');
        }
        if ($log) {
            $this->transcript[] = '> [message body, ' . strlen($data) . ' bytes]';
        }
        $written = @fwrite($this->socket, $data);
        if ($written === false) {
            throw new \RuntimeException('Writing to the SMTP socket failed (connection dropped).');
        }
    }

    /** @param list<int> $expected */
    private function expect(array $expected): string
    {
        $response = '';
        while (true) {
            $line = @fgets($this->socket, 1024);
            if ($line === false) {
                $meta = $this->socket === null ? [] : stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('SMTP server did not respond in time (timeout).');
                }
                throw new \RuntimeException('SMTP connection closed unexpectedly.');
            }
            $response .= $line;
            // Multi-line replies look like "250-..." ; the last is "250 ..."
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $this->transcript[] = '< ' . trim($response);
        $code = (int) substr(ltrim($response), 0, 3);

        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException('SMTP error ' . $code . ': ' . trim($response));
        }

        return $response;
    }

    private function quit(): void
    {
        try {
            $this->command('QUIT', [221]);
        } catch (\Throwable) {
            // Server hanging up early on QUIT is harmless.
        }
        $this->closeQuietly();
    }

    private function closeQuietly(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function buildMessage(
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody
    ): string {
        $boundary = 'lrms_' . bin2hex(random_bytes(12));
        $eol = "\r\n";

        if ($textBody === '') {
            $textBody = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES, 'UTF-8'));
        }

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $this->messageIdDomain($fromEmail) . '>',
            'MIME-Version: 1.0',
            'X-Mailer: LRMS',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = '--' . $boundary . $eol
            . 'Content-Type: text/plain; charset=UTF-8' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol . $eol
            . chunk_split(base64_encode($textBody), 76, $eol) . $eol
            . '--' . $boundary . $eol
            . 'Content-Type: text/html; charset=UTF-8' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol . $eol
            . chunk_split(base64_encode($htmlBody), 76, $eol) . $eol
            . '--' . $boundary . '--' . $eol;

        // Dot-stuffing (RFC 5321 4.5.2): a lone "." would end DATA early.
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        return implode($eol, $headers) . $eol . $eol . $body;
    }

    private function messageIdDomain(string $fromEmail): string
    {
        $parts = explode('@', $fromEmail);
        return count($parts) === 2 && $parts[1] !== '' ? $parts[1] : 'localhost';
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Delivery audit row. The recipient is masked, never stored in full. */
    private function logMessage(string $channel, string $recipient, string $subject, string $status, ?string $error): void
    {
        try {
            Database::insert('message_logs', [
                'channel'          => $channel,
                'recipient_masked' => Crypto::maskEmail($recipient),
                'recipient_hash'   => Crypto::blindIndex($recipient, 'email'),
                'template'         => 'generic',
                'subject'          => substr($subject, 0, 190),
                'status'           => $status,
                'provider'         => 'smtp:' . Settings::getString('smtp.host'),
                'error'            => $error === null ? null : substr($error, 0, 255),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('message_logs insert failed: ' . $e->getMessage());
        }
    }
}
