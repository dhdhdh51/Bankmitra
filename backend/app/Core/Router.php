<?php

declare(strict_types=1);

namespace App\Core;

use Lib\Logger;
use RuntimeException;

/**
 * Minimal path router.
 *
 *   $r->get('customers/{id}', [CustomerController::class, 'show']);
 *
 * Placeholders: {id} matches [^/]+ ; {id:\d+} matches a custom pattern.
 */
final class Router
{
    /** @var array<string,list<array{regex:string,params:list<string>,handler:mixed}>> */
    private array $routes = [];

    private mixed $notFound = null;

    public function get(string $path, mixed $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, mixed $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    /** Register the same handler for GET and POST (typical form pages). */
    public function any(string $path, mixed $handler): void
    {
        $this->add('GET', $path, $handler);
        $this->add('POST', $path, $handler);
    }

    public function fallback(mixed $handler): void
    {
        $this->notFound = $handler;
    }

    private function add(string $method, string $path, mixed $handler): void
    {
        $path = trim($path, '/');

        // Build the regex by quoting ONLY the literal segments. Quoting the
        // whole path first would mangle a custom placeholder pattern such as
        // {id:\d+} (preg_quote escapes the backslash and the plus sign).
        $params = [];
        $regex = '';
        $offset = 0;

        if (preg_match_all(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            $path,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        ) > 0) {
            foreach ($matches as $match) {
                [$placeholder, $position] = $match[0];

                $regex .= preg_quote(substr($path, $offset, $position - $offset), '#');
                $params[] = $match[1][0];
                $regex .= '(' . (isset($match[2]) && $match[2][0] !== '' ? $match[2][0] : '[^/]+') . ')';

                $offset = $position + strlen($placeholder);
            }
        }

        $regex .= preg_quote(substr($path, $offset), '#');

        $this->routes[$method][] = [
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) === 1) {
                array_shift($matches);
                $args = [];
                foreach ($route['params'] as $i => $name) {
                    $args[$name] = $matches[$i] ?? null;
                }
                $this->invoke($route['handler'], $request, $args);
                return;
            }
        }

        // Path exists but with a different verb -> 405 is more helpful than 404.
        foreach ($this->routes as $verb => $routes) {
            if ($verb === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $this->methodNotAllowed($request, $verb);
                    return;
                }
            }
        }

        if ($this->notFound !== null) {
            $this->invoke($this->notFound, $request, []);
            return;
        }

        Response::html('404 - Not Found', 404);
    }

    /** @param array<string,mixed> $args */
    private function invoke(mixed $handler, Request $request, array $args): void
    {
        if (is_callable($handler)) {
            $handler($request, $args);
            return;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            if (!is_string($class) || !class_exists($class)) {
                throw new RuntimeException('Route controller not found: ' . (is_string($class) ? $class : 'n/a'));
            }
            $instance = new $class($request);
            if (!method_exists($instance, (string) $method)) {
                throw new RuntimeException('Route action not found: ' . $class . '::' . (string) $method);
            }
            $instance->{$method}($args);
            return;
        }

        throw new RuntimeException('Invalid route handler.');
    }

    private function methodNotAllowed(Request $request, string $allowed): void
    {
        Logger::warning('405 for ' . $request->method() . ' /' . $request->path());
        if (str_starts_with($request->path(), 'api/') || $request->isAjax()) {
            Response::fail('Method not allowed. Use ' . $allowed . '.', 'method_not_allowed', 405);
            return;
        }
        Response::html('405 - Method Not Allowed (expected ' . htmlspecialchars($allowed) . ')', 405);
    }
}
