<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-only view of the current HTTP request.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $body;
    /** @var array<string,mixed> */
    private array $json;

    public function __construct()
    {
        $this->query = $_GET;
        $this->body  = $_POST;
        $this->json  = [];

        if (str_contains(strtolower($this->header('Content-Type') ?? ''), 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->json = $decoded;
                }
            }
        }
    }

    public function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Allow HTML forms to emulate PUT/DELETE via a hidden _method field.
        if ($method === 'POST' && isset($this->body['_method'])) {
            $override = strtoupper((string) $this->body['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $method;
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Path relative to the installation root, no leading/trailing slash.
     * Works with the .htaccess rewrite (?_route=) and with PATH_INFO.
     */
    public function path(): string
    {
        $route = $_GET['_route'] ?? null;
        if (is_string($route) && $route !== '') {
            return trim($route, '/');
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $uri = (string) parse_url($uri, PHP_URL_PATH);

        $script = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        if ($script !== '' && $script !== '/' && str_starts_with($uri, $script)) {
            $uri = substr($uri, strlen($script));
        }

        return trim($uri, '/');
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        // Content-Type / Content-Length are not HTTP_ prefixed.
        $alt = strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$alt])) {
            return (string) $_SERVER[$alt];
        }
        // Some LiteSpeed/Apache setups hide Authorization unless asked nicely.
        if (strtolower($name) === 'authorization' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $hk => $hv) {
                if (strtolower($hk) === 'authorization') {
                    return (string) $hv;
                }
            }
        }
        return null;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m) === 1) {
            return trim($m[1]);
        }
        // Fallback header for hosts that strip Authorization entirely.
        $alt = $this->header('X-Auth-Token');
        return $alt !== null && $alt !== '' ? $alt : null;
    }

    /** Query string parameter. */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** POST body / JSON body parameter (JSON wins when both exist). */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key, null);
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->json);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $candidate = trim(explode(',', (string) $_SERVER[$key])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
        return '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    /**
     * Normalise a multi-file upload (name="photos[]") into a flat list.
     * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public function files(string $key): array
    {
        $f = $_FILES[$key] ?? null;
        if (!is_array($f)) {
            return [];
        }
        if (!is_array($f['name'])) {
            return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }

        $out = [];
        foreach (array_keys($f['name']) as $i) {
            if ((int) $f['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string) $f['name'][$i],
                'type'     => (string) $f['type'][$i],
                'tmp_name' => (string) $f['tmp_name'][$i],
                'error'    => (int) $f['error'][$i],
                'size'     => (int) $f['size'][$i],
            ];
        }
        return $out;
    }
}
