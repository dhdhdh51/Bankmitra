<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use Lib\Settings;

/**
 * Live tracking - plain GPS ping polling, no paid live-tracking SDK.
 * The page shows a Google map when a key is configured, and always shows a
 * table so the feature still works without one.
 */
final class TrackingController extends Controller
{
    protected ?string $permission = 'tracking.view';

    public function index(): void
    {
        $this->view('tracking/index', [
            'pageTitle'  => 'Live tracking',
            'agents'     => $this->latestPositions(),
            'mapsKey'    => Settings::getString('maps.api_key', ''),
            'defaultLat' => (float) Settings::getString('maps.default_lat', '25.5941'),
            'defaultLng' => (float) Settings::getString('maps.default_lng', '85.1376'),
            'defaultZoom' => Settings::getInt('maps.default_zoom', 7),
            'pingInterval' => Settings::getInt('app.gps_ping_interval', 300),
        ]);
    }

    /** JSON polled by the map every minute. */
    public function data(): void
    {
        Response::ok(['agents' => $this->latestPositions(), 'server_time' => date('c')]);
    }

    /**
     * The most recent ping per agent within the last 12 hours.
     *
     * @return list<array<string,mixed>>
     */
    private function latestPositions(): array
    {
        [$scope, $params] = Auth::scopeSql('u.branch_id');

        // A correlated subquery keeps this readable and index-friendly on
        // MySQL 5.7, where window functions are unavailable.
        $rows = Database::all(
            'SELECT u.id AS user_id, u.full_name, bc.bc_code, br.name AS branch_name,
                    g.latitude, g.longitude, g.accuracy_m, g.speed_kmph, g.battery_pct,
                    g.recorded_at,
                    (SELECT COUNT(*) FROM visits v WHERE v.user_id = u.id AND v.visit_date = CURRENT_DATE) AS visits_today,
                    a.check_in_at, a.check_out_at
             FROM users u
             LEFT JOIN bc_agents bc ON bc.user_id = u.id
             LEFT JOIN branches br ON br.id = u.branch_id
             LEFT JOIN attendance a ON a.user_id = u.id AND a.attendance_date = CURRENT_DATE
             JOIN gps_pings g ON g.id = (
                 SELECT gp.id FROM gps_pings gp
                 WHERE gp.user_id = u.id AND gp.recorded_at > DATE_SUB(NOW(), INTERVAL 12 HOUR)
                 ORDER BY gp.recorded_at DESC LIMIT 1
             )
             WHERE u.status = "active"' . $scope . '
             ORDER BY g.recorded_at DESC
             LIMIT 500',
            $params
        );

        return array_map(static function (array $row): array {
            $minutesAgo = (int) floor((time() - (int) strtotime((string) $row['recorded_at'])) / 60);

            return [
                'user_id'      => (int) $row['user_id'],
                'name'         => (string) $row['full_name'],
                'bc_code'      => $row['bc_code'],
                'branch_name'  => $row['branch_name'],
                'latitude'     => (float) $row['latitude'],
                'longitude'    => (float) $row['longitude'],
                'accuracy_m'   => $row['accuracy_m'] === null ? null : (float) $row['accuracy_m'],
                'speed_kmph'   => $row['speed_kmph'] === null ? null : (float) $row['speed_kmph'],
                'battery_pct'  => $row['battery_pct'] === null ? null : (int) $row['battery_pct'],
                'recorded_at'  => (string) $row['recorded_at'],
                'minutes_ago'  => $minutesAgo,
                'is_stale'     => $minutesAgo > 60,
                'visits_today' => (int) $row['visits_today'],
                'checked_in'   => $row['check_in_at'] !== null,
                'checked_out'  => $row['check_out_at'] !== null,
            ];
        }, $rows);
    }
}
