<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Response helpers. All API output goes through json() so the envelope is
 * identical for every endpoint (see docs/API.md).
 */
final class Response
{
    private static bool $sent = false;

    public static function sent(): bool
    {
        return self::$sent;
    }

    /** @param array<string,mixed>|list<mixed> $payload */
    public static function json(array $payload, int $status = 200, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            foreach ($headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::$sent = true;
    }

    /**
     * Successful API envelope.
     * @param mixed $data
     */
    public static function ok(mixed $data = null, string $message = 'OK', array $meta = []): void
    {
        $payload = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        self::json($payload, 200);
    }

    /**
     * Failed API envelope. `code` is a stable machine-readable string the
     * Android app switches on; `message` is shown to the user.
     * @param array<string,string> $errors field => message
     */
    public static function fail(
        string $message,
        string $code = 'error',
        int $status = 400,
        array $errors = []
    ): void {
        $payload = ['success' => false, 'code' => $code, 'message' => $message];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $status);
    }

    public static function redirect(string $url, int $status = 302): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        }
        self::$sent = true;
        echo '<!doctype html><meta http-equiv="refresh" content="0;url='
            . htmlspecialchars($url, ENT_QUOTES) . '">';
        exit;
    }

    public static function html(string $body, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $body;
        self::$sent = true;
    }

    /** Stream a file for download without loading it entirely into memory. */
    public static function download(string $absolutePath, string $filename, string $mime = 'application/octet-stream'): void
    {
        if (!is_file($absolutePath)) {
            self::html('File not found.', 404);
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
            header('Content-Length: ' . (string) filesize($absolutePath));
            header('Cache-Control: private, max-age=0, must-revalidate');
        }
        readfile($absolutePath);
        self::$sent = true;
        exit;
    }

    /** Send an in-memory string as a download (PDF / CSV / XLS). */
    public static function attachment(string $content, string $filename, string $mime): void
    {
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
            header('Content-Length: ' . (string) strlen($content));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }
        echo $content;
        self::$sent = true;
        exit;
    }

    /** Render a PDF inline in the browser (phone-friendly preview). */
    public static function inlinePdf(string $content, string $filename): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
            header('Content-Length: ' . (string) strlen($content));
        }
        echo $content;
        self::$sent = true;
        exit;
    }
}
