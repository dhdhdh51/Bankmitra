<?php

/**
 * Router for PHP's built-in web server - LOCAL DEVELOPMENT / TESTING ONLY.
 *
 *   cd backend
 *   php -S 127.0.0.1:8080 dev-server.php
 *   open http://127.0.0.1:8080/
 *
 * On cPanel this file is never used: Apache/LiteSpeed reads .htaccess instead.
 * It exists so you can verify a deployment locally (or in CI) without a web
 * server, because `php -S` ignores .htaccess entirely.
 *
 * It reproduces the two rules that matter:
 *   1. serve real files from disk,
 *   2. send /api/v1/* to api/v1/index.php and everything else to index.php.
 */

declare(strict_types=1);

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ltrim(rawurldecode($uri), '/');
$root = __DIR__;

// ---------------------------------------------------------------------
// Refuse the paths that .htaccess blocks in production, so local testing
// exercises the same restrictions.
// ---------------------------------------------------------------------
foreach (['app/', 'config/', 'lib/', 'cron/', 'storage/'] as $blocked) {
    if ($path === rtrim($blocked, '/') || str_starts_with($path, $blocked)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden (this path is blocked by .htaccess in production too).\n";
        return true;
    }
}

// ---------------------------------------------------------------------
// Serve existing static files directly (assets, uploaded photos).
// ---------------------------------------------------------------------
$candidate = $root . '/' . $path;
if ($path !== '' && is_file($candidate)) {
    // Never execute an uploaded script, matching uploads/.htaccess.
    if (str_starts_with($path, 'uploads/') && preg_match('/\.(php|phtml|pl|py|cgi|sh)$/i', $path) === 1) {
        http_response_code(403);
        echo "403 Forbidden\n";
        return true;
    }
    return false; // let the built-in server stream it
}

// ---------------------------------------------------------------------
// API front controller
// ---------------------------------------------------------------------
if ($path === 'api/v1' || str_starts_with($path, 'api/v1/')) {
    $route = substr($path, strlen('api/v1'));
    $_GET['_route'] = trim($route, '/');
    $_SERVER['SCRIPT_NAME'] = '/api/v1/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/api/v1/index.php';
    require $root . '/api/v1/index.php';
    return true;
}

// ---------------------------------------------------------------------
// Admin panel front controller
// ---------------------------------------------------------------------
$_GET['_route'] = $path;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
return true;
