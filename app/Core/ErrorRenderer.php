<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Renders an uncaught exception. In production a real bug shows a friendly page
 * with a reference ID and nothing else; the full detail is in storage/logs
 * (rule #9 - errors are never silently swallowed, but never leaked either).
 *
 * A SetupException is different: it is a deployment mistake, its message is
 * authored by us, and the person looking at the screen is the person who has to
 * fix it. Those are shown in full even in production.
 */
final class ErrorRenderer
{
    /**
     * Short code shown to the user AND written to the log, so the two can be
     * correlated. Generate it once per request and pass it to both.
     */
    public static function newReference(): string
    {
        return strtoupper(bin2hex(random_bytes(4)));
    }

    public static function render(Throwable $e, ?string $reference = null): void
    {
        $reference ??= self::newReference();
        $isDev = Config::get('env') === 'development';
        $isApi = str_starts_with(($_SERVER['REQUEST_URI'] ?? ''), '/api/')
            || str_contains(($_SERVER['REQUEST_URI'] ?? ''), '/api/v')
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        $isSetup = $e instanceof SetupException;

        if (!headers_sent()) {
            http_response_code($isSetup ? 503 : 500);
        }

        if ($isApi) {
            self::renderApi($e, $reference, $isDev, $isSetup);
            return;
        }

        $logFile = 'storage/logs/app-' . date('Y-m-d') . '.log';

        if ($isSetup) {
            /** @var SetupException $e */
            echo self::page(
                $e->heading(),
                'warning',
                '<p class="mb-3">' . htmlspecialchars($e->getMessage()) . '</p>'
                . self::stepsHtml($e->steps())
                . '<p class="text-muted small mb-0">This message is only shown for '
                . 'configuration problems, and it will disappear once the step above '
                . 'is done. Technical detail: <code>' . $logFile . '</code> '
                . '(reference <code>' . $reference . '</code>).</p>',
                $isDev ? self::devDetail($e) : ''
            );
            return;
        }

        echo self::page(
            'Something went wrong',
            'danger',
            '<p class="mb-2">The action could not be completed. Nothing was saved.</p>'
            . '<p class="mb-3">Please share this reference with your administrator: '
            . '<code class="fs-6">' . $reference . '</code></p>'
            . '<p class="text-muted small mb-0">Administrators: search for '
            . '<code>' . $reference . '</code> in <code>' . $logFile . '</code> '
            . 'for the full stack trace.</p>',
            $isDev ? self::devDetail($e) : ''
        );
    }

    private static function renderApi(
        Throwable $e,
        string $reference,
        bool $isDev,
        bool $isSetup
    ): void {
        if ($isSetup) {
            /** @var SetupException $e */
            $payload = [
                'success' => false,
                'code'    => 'setup_incomplete',
                'message' => $e->heading() . '. ' . $e->getMessage(),
                'data'    => ['steps' => $e->steps(), 'reference' => $reference],
            ];
            Response::json($payload, 503);
            return;
        }

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
    }

    /** @param list<string> $steps */
    private static function stepsHtml(array $steps): string
    {
        if ($steps === []) {
            return '';
        }
        $items = '';
        foreach ($steps as $step) {
            $items .= '<li class="mb-2">' . htmlspecialchars($step) . '</li>';
        }
        return '<p class="fw-semibold mb-2">How to fix it</p>'
            . '<ol class="ps-3 mb-3">' . $items . '</ol>';
    }

    private static function devDetail(Throwable $e): string
    {
        $html = '<hr><p class="fw-bold mb-1">' . htmlspecialchars(get_class($e)) . '</p>'
            . '<p class="mb-1">' . htmlspecialchars($e->getMessage()) . '</p>'
            . '<p class="text-muted small mb-2">'
            . htmlspecialchars($e->getFile() . ':' . $e->getLine()) . '</p>';

        $previous = $e->getPrevious();
        if ($previous !== null) {
            $html .= '<p class="mb-1 small">Caused by '
                . htmlspecialchars(get_class($previous)) . ': '
                . htmlspecialchars($previous->getMessage()) . '</p>';
        }

        return $html . '<pre class="small bg-light p-2 border rounded" '
            . 'style="max-height:340px;overflow:auto">'
            . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }

    private static function page(
        string $heading,
        string $tone,
        string $body,
        string $detail
    ): string {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($heading) . ' - LRMS</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">'
            . '</head><body class="bg-light"><div class="container py-5" style="max-width:760px">'
            . '<div class="card shadow-sm"><div class="card-body p-4">'
            . '<h1 class="h4 text-' . $tone . ' mb-3">' . htmlspecialchars($heading) . '</h1>'
            . $body
            . $detail
            . '<a class="btn btn-primary mt-3" href="'
            . htmlspecialchars(Config::baseUrl()) . '">Back to LRMS</a>'
            . '</div></div></div></body></html>';
    }
}
