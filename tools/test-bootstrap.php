<?php
/**
 * Minimal bootstrap for running services against a local MariaDB in the
 * sandbox. Not part of the deployed application - see .gitignore.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__) . '/backend');
define('APP_PATH', BASE_PATH . '/app');
define('LIB_PATH', BASE_PATH . '/lib');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', BASE_PATH . '/uploads');

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
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

App\Core\Config::load([
    'db' => [
        'name'        => 'lrms',
        'user'        => 'root',
        'pass'        => '',
        'charset'     => 'utf8mb4',
        'unix_socket' => '/projects/sandbox/mysqlrun/mysql.sock',
    ],
    'app_key'  => str_repeat('a1b2c3d4', 8),
    'base_url' => 'http://localhost/',
    'env'      => 'development',
    'timezone' => 'Asia/Kolkata',
    'cron_key' => 'test',
]);

date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', '1');
