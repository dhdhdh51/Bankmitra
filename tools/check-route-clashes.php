<?php

declare(strict_types=1);

/**
 * Guard against a whole class of 404s that only appear on a real web server.
 * ---------------------------------------------------------------------------
 * backend/.htaccess sends everything to the front controller EXCEPT paths that
 * already exist on disk:
 *
 *     RewriteCond %{REQUEST_FILENAME} -f [OR]
 *     RewriteCond %{REQUEST_FILENAME} -d
 *     RewriteRule ^ - [L]
 *
 * That rule has to stay, otherwise assets and uploaded photos would be routed
 * through PHP. The consequence is that any route whose FIRST path segment
 * matches a real file or directory in the web root is unreachable: the web
 * server serves the file, or refuses the directory listing, and PHP never runs.
 *
 * This bit us once - the Excel upload page was routed at "uploads", which is
 * also the physical folder that visit photos are served from, so the page
 * returned 404 on the live host while working fine under `php -S` (the dev
 * server has no .htaccess and no such rule).
 *
 * Run:  php tools/check-route-clashes.php
 * Exits non-zero if a clash exists, so it can go in CI.
 */

$repoRoot = dirname(__DIR__);
$frontController = $repoRoot . '/backend/index.php';
$apiFrontController = $repoRoot . '/backend/api/v1/index.php';
$webRoot = $repoRoot . '/backend';

if (!is_file($frontController)) {
    fwrite(STDERR, "Cannot find backend/index.php\n");
    exit(2);
}

/** @return list<string> first path segment of every declared route */
function routeSegments(string $file): array
{
    $src = (string) file_get_contents($file);
    preg_match_all(
        '/\$router->(?:get|post|put|patch|delete|any)\(\s*[\'"]([^\'"]*)[\'"]/',
        $src,
        $m
    );

    $segments = [];
    foreach ($m[1] as $route) {
        $route = ltrim($route, '/');
        if ($route === '') {
            continue; // the "" route is index.php itself, always reachable
        }
        $segments[explode('/', $route)[0]] = true;
    }

    $out = array_keys($segments);
    sort($out);
    return $out;
}

/** @return array<string,string> name => "dir"|"file" */
function webRootEntries(string $dir): array
{
    $entries = [];
    foreach ((array) scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $entries[$e] = is_dir($dir . '/' . $e) ? 'dir' : 'file';
    }

    // These exist in the deploy/public_html layout even though they live
    // outside backend/ on the source branch.
    $entries['database'] = 'dir';
    $entries['READ-ME-FIRST.md'] = 'file';

    return $entries;
}

$physical = webRootEntries($webRoot);
$clashes = [];
$checked = 0;

foreach ([$frontController, $apiFrontController] as $file) {
    if (!is_file($file)) {
        continue;
    }
    $label = substr($file, strlen($repoRoot) + 1);

    // The API is mounted under api/v1/ by its own rewrite rule, so its route
    // segments are namespaced and cannot clash with the web root.
    $isApi = str_contains($label, '/api/');

    foreach (routeSegments($file) as $segment) {
        $checked++;
        if (!$isApi && isset($physical[$segment])) {
            $clashes[] = sprintf(
                '%s: route "%s" collides with the %s "%s" in the web root',
                $label,
                $segment,
                $physical[$segment],
                $segment
            );
        }
    }
}

echo "Checked {$checked} route segment(s) against " . count($physical)
    . " web-root entries.\n";

if ($clashes !== []) {
    echo "\nFAIL - unreachable route(s):\n";
    foreach ($clashes as $c) {
        echo '  - ' . $c . "\n";
    }
    echo "\nRename the route. Do not remove the -f/-d RewriteCond.\n";
    exit(1);
}

echo "OK - no route collides with a real file or directory.\n";
exit(0);
