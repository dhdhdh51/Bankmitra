<?php
/**
 * LRMS bootstrap - included by BOTH front controllers (index.php and
 * api/v1/index.php). No Composer: a tiny PSR-4-ish autoloader does the job.
 */

declare(strict_types=1);

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    http_response_code(500);
    exit('LRMS requires PHP 8.0 or newer. Detected: ' . PHP_VERSION
        . '. In cPanel go to "Select PHP Version" and pick 8.1 or 8.2.');
}

define('LRMS_START', microtime(true));
define('BASE_PATH', dirname(__DIR__));            // .../backend
define('APP_PATH', BASE_PATH . '/app');
define('LIB_PATH', BASE_PATH . '/lib');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', BASE_PATH . '/uploads');

// ---------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    static $map = [
        'App\\Core\\'        => APP_PATH . '/Core/',
        'App\\Controllers\\' => APP_PATH . '/Controllers/',
        'App\\Models\\'      => APP_PATH . '/Models/',
        'App\\Services\\'    => APP_PATH . '/Services/',
        'Lib\\'              => LIB_PATH . '/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = $dir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------
$configFile = CONFIG_PATH . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("LRMS is not configured yet.\n\n"
        . "Copy config/config.sample.php to config/config.php and fill in\n"
        . "your database credentials and APP_KEY.\n");
}

/** @var array<string,mixed> $LRMS_CONFIG */
$LRMS_CONFIG = require $configFile;
App\Core\Config::load($LRMS_CONFIG);

date_default_timezone_set((string) App\Core\Config::get('timezone', 'Asia/Kolkata'));

// ---------------------------------------------------------------------
// Error handling - never leak stack traces to the browser in production,
// but ALWAYS write them to storage/logs (rule #9: no silent failures).
// ---------------------------------------------------------------------
$isDev = App\Core\Config::get('env') === 'development';
ini_set('display_errors', $isDev ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!is_dir(STORAGE_PATH . '/logs')) {
    @mkdir(STORAGE_PATH . '/logs', 0755, true);
}
ini_set('error_log', STORAGE_PATH . '/logs/php_error.log');

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    // One reference, written to the log AND shown on the page, so "reference
    // FE617E25" can actually be found by grepping storage/logs.
    $reference = App\Core\ErrorRenderer::newReference();
    Lib\Logger::exception($e, $reference);
    App\Core\ErrorRenderer::render($e, $reference);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Lib\Logger::error('FATAL: ' . $err['message'], [
            'file' => $err['file'],
            'line' => $err['line'],
        ]);
    }
});
