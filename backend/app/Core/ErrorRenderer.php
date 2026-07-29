<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Renders an uncaught exception. In production the user sees a friendly page
 * with a reference ID; the full detail is in storage/logs (rule #9 - errors
 * are never silently swallowed, but they are also never leaked).
 */
final class ErrorRenderer
{
    public static function render(Throwable $e): void
    {
        $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $isDev = Config::get('env') === 'development';
        $isApi = str_starts_with(($_SERVER['REQUEST_URI'] ?? ''), '/api/')
            || str_contains(($_SERVER['REQUEST_URI'] ?? ''), '/api/v')
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        if (!headers_sent()) {
            http_response_code(500);
        }

        if ($isApi) {
            $payload = [
                'success' => false,
                'code'    => 'server_error',
                'message' => 'An internal error occurred. Reference: ' . $reference,
            ];
            if ($isDev) {
                $payload['debug'] = [
                    'type'    => get_class($e),
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile() . ':' . $e->getLine(),
                ];
            }
            Response::json($payload, 500);
            return;
        }

        $detail = '';
        if ($isDev) {
            $detail = '<hr><p class="fw-bold mb-1">' . htmlspecialchars(get_class($e)) . '</p>'
                . '<p class="mb-1">' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<p class="text-muted small mb-2">' . htmlspecialchars($e->getFile() . ':' . $e->getLine()) . '</p>'
                . '<pre class="small bg-light p-2 border rounded" style="max-height:340px;overflow:auto">'
                . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Something went wrong - LRMS</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">'
            . '</head><body class="bg-light"><div class="container py-5" style="max-width:760px">'
            . '<div class="card shadow-sm"><div class="card-body p-4">'
            . '<h1 class="h4 text-danger mb-3">Something went wrong</h1>'
            . '<p class="mb-2">The action could not be completed. Nothing was saved.</p>'
            . '<p class="mb-3">Please share this reference with your administrator: '
            . '<code class="fs-6">' . $reference . '</code></p>'
            . '<p class="text-muted small mb-0">Administrators: the full stack trace is in '
            . '<code>storage/logs/app-' . date('Y-m-d') . '.log</code>.</p>'
            . $detail
            . '<a class="btn btn-primary mt-3" href="' . htmlspecialchars(Config::baseUrl()) . '">Back to LRMS</a>'
            . '</div></div></div></body></html>';
    }
}
