<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AuthService;
use Lib\Settings;

/**
 * Base class for every /api/v1 controller.
 *
 * Handles: maintenance mode, force-update, bearer authentication, device
 * binding and the shared "config" block the app needs.
 */
abstract class ApiController
{
    protected Request $request;
    protected AuthService $auth;

    /** Set to false on public endpoints (ping, login, register). */
    protected bool $requiresAuth = true;

    /** Endpoints exempt from maintenance mode so admins can still diagnose. */
    protected bool $allowDuringMaintenance = false;

    /** @var array<string,mixed>|null resolved authenticated user */
    protected ?array $user = null;

    protected ?string $bearerToken = null;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->auth = new AuthService();

        $this->guardMaintenance();
        $this->guardAppVersion();

        if ($this->requiresAuth) {
            $this->authenticate();
        }
    }

    private function guardMaintenance(): void
    {
        if ($this->allowDuringMaintenance) {
            return;
        }
        if (!Settings::getBool('app.maintenance_mode', false)) {
            return;
        }

        Response::json([
            'success' => false,
            'code' => 'maintenance_mode',
            'message' => Settings::getString('app.maintenance_message', 'System under maintenance. Please try later.'),
            'data' => ['message' => Settings::getString('app.maintenance_message', 'System under maintenance. Please try later.')],
        ], 503);
        exit;
    }

    /**
     * Reject app builds below the configured minimum, so a breaking API change
     * never silently corrupts data from an old client.
     */
    private function guardAppVersion(): void
    {
        $minVersion = Settings::getString('app.min_app_version', '');
        if ($minVersion === '') {
            return;
        }

        $clientVersion = (string) ($this->request->header('X-App-Version') ?? $this->request->str('app_version'));
        if ($clientVersion === '') {
            return; // curl / diagnostics
        }

        if (version_compare($clientVersion, $minVersion, '>=')) {
            return;
        }

        Response::json([
            'success' => false,
            'code' => 'update_required',
            'message' => 'This version of the app is no longer supported. Please update to continue.',
            'data' => [
                'min_app_version' => $minVersion,
                'latest_app_version' => Settings::getString('app.app_version', $minVersion),
                'apk_url' => Settings::getString('app.apk_url', ''),
            ],
        ], 426);
        exit;
    }

    private function authenticate(): void
    {
        $this->bearerToken = $this->request->bearerToken();
        $result = $this->auth->resolveToken($this->bearerToken);

        if (!$result['ok'] || $result['user'] === null) {
            Response::fail($result['message'], $result['code'], 401);
            exit;
        }

        $user = $result['user'];

        // Device binding: the token already belongs to one device, but check the
        // header too so a copied token cannot be replayed from elsewhere.
        if (Settings::getBool('security.device_binding', true)) {
            $headerDevice = (string) ($this->request->header('X-Device-Id') ?? '');
            $boundDevice = (string) ($user['device_id'] ?? '');
            if ($headerDevice !== '' && $boundDevice !== '' && !hash_equals($boundDevice, $headerDevice)) {
                Audit::security(
                    'api.device_mismatch',
                    'Token used from a device that is not bound to user #' . $user['id'],
                    'critical'
                );
                Response::fail(
                    'This account is registered on another device. Ask your administrator to reset the device binding.',
                    'device_mismatch',
                    403
                );
                exit;
            }
        }

        $this->user = $user;
        Auth::setUser($user);
    }

    // ------------------------------------------------------------------
    // Helpers for subclasses
    // ------------------------------------------------------------------

    protected function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    protected function bcId(): ?int
    {
        $bcId = $this->user['bc_id'] ?? null;
        return $bcId === null ? null : (int) $bcId;
    }

    protected function branchId(): ?int
    {
        $branchId = $this->user['branch_id'] ?? null;
        return $branchId === null ? null : (int) $branchId;
    }

    protected function deviceId(): string
    {
        $header = (string) ($this->request->header('X-Device-Id') ?? '');
        return $header !== '' ? $header : $this->request->str('device_id');
    }

    protected function appVersion(): string
    {
        $header = (string) ($this->request->header('X-App-Version') ?? '');
        return $header !== '' ? $header : $this->request->str('app_version');
    }

    /** Only BC agents may create field data. */
    protected function requireBcAgent(): void
    {
        if ($this->bcId() !== null) {
            return;
        }
        Response::fail(
            'Only BC Agents can submit field data. Your account is a '
                . (string) ($this->user['role_name'] ?? 'non-field') . '.',
            'forbidden',
            403
        );
        exit;
    }

    /**
     * The runtime configuration block. Note that the Google Maps key is served
     * from here rather than compiled into the APK, which is the whole point of
     * the admin Settings module.
     *
     * @return array<string,mixed>
     */
    protected function configBlock(): array
    {
        return [
            'maps_api_key'              => Settings::getString('maps.api_key', ''),
            'gps_ping_interval_seconds' => max(60, Settings::getInt('app.gps_ping_interval', 300)),
            'visit_photo_min'           => max(0, Settings::getInt('app.visit_photo_min', 1)),
            'visit_max_distance_m'      => max(0, Settings::getInt('app.visit_max_distance_m', 500)),
            'block_mock_gps'            => Settings::getBool('security.block_mock_gps', true),
            'latest_app_version'        => Settings::getString('app.app_version', '1.0.0'),
            'force_update'              => Settings::getBool('app.force_update', false),
            'default_lat'               => (float) Settings::getString('maps.default_lat', '25.5941'),
            'default_lng'               => (float) Settings::getString('maps.default_lng', '85.1376'),
        ];
    }

    /**
     * The public shape of a user, safe to send to the device.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    protected function userBlock(array $user): array
    {
        return [
            'id'                   => (int) $user['id'],
            'uuid'                 => (string) $user['uuid'],
            'full_name'            => (string) $user['full_name'],
            'employee_code'        => $user['employee_code'],
            'role'                 => (string) $user['role_code'],
            'role_name'            => (string) $user['role_name'],
            'mobile_masked'        => \Lib\Crypto::maskMobile($user['mobile'] ?? null),
            'email_masked'         => \Lib\Crypto::maskEmail($user['email'] ?? null),
            'branch_id'            => $user['branch_id'] === null ? null : (int) $user['branch_id'],
            'branch_name'          => $user['branch_name'],
            'bc_id'                => ($user['bc_id'] ?? null) === null ? null : (int) $user['bc_id'],
            'bc_code'              => $user['bc_code'] ?? null,
            'monthly_target'       => isset($user['monthly_target']) ? (float) $user['monthly_target'] : 0.0,
            'photo_url'            => $user['photo_path'] === null
                ? null
                : Config::baseUrl() . 'uploads/' . ltrim((string) $user['photo_path'], '/'),
            'must_change_password' => (int) ($user['must_change_password'] ?? 0) === 1,
        ];
    }

    /**
     * Validate and return pagination bounds.
     * @return array{page:int,perPage:int,offset:int}
     */
    protected function pagination(int $defaultPerPage = 25): array
    {
        $page = max(1, $this->request->int('page', 1));
        $perPage = max(10, min(200, $this->request->int('per_page', $defaultPerPage)));
        return ['page' => $page, 'perPage' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /** @return array{total:int,page:int,perPage:int,pages:int} */
    protected function meta(int $total, array $pagination): array
    {
        return [
            'total'   => $total,
            'page'    => $pagination['page'],
            'perPage' => $pagination['perPage'],
            'pages'   => (int) max(1, ceil($total / max(1, $pagination['perPage']))),
        ];
    }

    /**
     * Reject a request whose GPS payload is unusable.
     * Returns [latitude, longitude] on success, or exits with a clear error.
     *
     * @return array{0:float,1:float}
     */
    protected function requireGps(): array
    {
        $lat = $this->request->float('latitude', 0.0);
        $lng = $this->request->float('longitude', 0.0);

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Response::fail('The GPS coordinates are out of range.', 'gps_required', 422);
            exit;
        }

        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
            Response::fail(
                'GPS location was not captured. Please turn on location/GPS, wait for a fix, and try again.',
                'gps_required',
                422
            );
            exit;
        }

        if ($this->request->bool('is_mock_location', false) && Settings::getBool('security.block_mock_gps', true)) {
            Audit::security('gps.mock_blocked', 'Mock location rejected for user #' . $this->userId(), 'critical');
            Response::fail(
                'A fake/mock GPS app was detected. Turn off mock locations in Developer Options and try again.',
                'mock_location_blocked',
                422
            );
            exit;
        }

        return [$lat, $lng];
    }

    /**
     * Great-circle distance in metres, used to check the agent is actually at
     * the customer's recorded location.
     */
    protected function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
