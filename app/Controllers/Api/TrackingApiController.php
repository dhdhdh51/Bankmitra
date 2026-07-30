<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Database;
use App\Core\Response;
use Lib\Logger;
use Lib\Settings;

/**
 * POST /tracking/ping
 *
 * Plain GPS polling instead of a paid live-tracking SDK. The app batches pings
 * so a day in a no-signal village still ends up on the map.
 */
final class TrackingApiController extends ApiController
{
    private const MAX_BATCH = 200;

    public function store(): void
    {
        $pings = $this->request->input('pings');

        // Accept a single ping posted as flat fields too, which makes the
        // endpoint easy to test with curl.
        if (!is_array($pings)) {
            $single = [
                'latitude'    => $this->request->float('latitude'),
                'longitude'   => $this->request->float('longitude'),
                'accuracy_m'  => $this->request->float('accuracy_m'),
                'speed_kmph'  => $this->request->float('speed_kmph'),
                'battery_pct' => $this->request->int('battery_pct'),
                'is_mock'     => $this->request->bool('is_mock'),
                'recorded_at' => $this->request->str('recorded_at'),
            ];
            $pings = [$single];
        }

        if ($pings === []) {
            Response::fail('No GPS pings were supplied.', 'validation_failed', 422);
            return;
        }

        if (count($pings) > self::MAX_BATCH) {
            Response::fail(
                'Too many pings in one request (max ' . self::MAX_BATCH . '). Send them in smaller batches.',
                'validation_failed',
                422
            );
            return;
        }

        $blockMock = Settings::getBool('security.block_mock_gps', true);

        $accepted = 0;
        $rejected = 0;
        /** @var list<string> $reasons */
        $reasons = [];

        foreach ($pings as $index => $ping) {
            if (!is_array($ping)) {
                $rejected++;
                continue;
            }

            $lat = isset($ping['latitude']) && is_numeric($ping['latitude']) ? (float) $ping['latitude'] : null;
            $lng = isset($ping['longitude']) && is_numeric($ping['longitude']) ? (float) $ping['longitude'] : null;

            if ($lat === null || $lng === null
                || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180
                || (abs($lat) < 0.0001 && abs($lng) < 0.0001)) {
                $rejected++;
                if (count($reasons) < 5) {
                    $reasons[] = 'Ping ' . ($index + 1) . ': coordinates missing or out of range.';
                }
                continue;
            }

            $isMock = !empty($ping['is_mock']);
            if ($isMock && $blockMock) {
                $rejected++;
                if (count($reasons) < 5) {
                    $reasons[] = 'Ping ' . ($index + 1) . ': mock location rejected.';
                }
                continue;
            }

            $recordedAt = isset($ping['recorded_at']) && is_string($ping['recorded_at'])
                ? (strtotime($ping['recorded_at']) ?: time())
                : time();

            // Do not accept a timestamp from the future (clock tampering) or
            // more than 7 days old (stale queue).
            if ($recordedAt > time() + 900 || $recordedAt < time() - 7 * 86400) {
                $recordedAt = time();
            }

            try {
                Database::insert('gps_pings', [
                    'user_id'     => $this->userId(),
                    'latitude'    => $lat,
                    'longitude'   => $lng,
                    'accuracy_m'  => isset($ping['accuracy_m']) && is_numeric($ping['accuracy_m'])
                        ? min(99999.99, (float) $ping['accuracy_m']) : null,
                    'speed_kmph'  => isset($ping['speed_kmph']) && is_numeric($ping['speed_kmph'])
                        ? min(9999.99, max(0, (float) $ping['speed_kmph'])) : null,
                    'battery_pct' => isset($ping['battery_pct']) && is_numeric($ping['battery_pct'])
                        ? max(0, min(100, (int) $ping['battery_pct'])) : null,
                    'is_mock'     => $isMock ? 1 : 0,
                    'recorded_at' => date('Y-m-d H:i:s', $recordedAt),
                ]);
                $accepted++;
            } catch (\Throwable $e) {
                $rejected++;
                Logger::warning('GPS ping insert failed: ' . $e->getMessage());
                if (count($reasons) < 5) {
                    $reasons[] = 'Ping ' . ($index + 1) . ': could not be stored.';
                }
            }
        }

        Response::ok([
            'accepted'      => $accepted,
            'rejected'      => $rejected,
            'reasons'       => $reasons,
            'next_interval' => max(60, Settings::getInt('app.gps_ping_interval', 300)),
        ], $accepted > 0 ? 'Location updated.' : 'No pings were accepted.');
    }
}
