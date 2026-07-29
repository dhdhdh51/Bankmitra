<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Response;
use App\Services\PhotoStorageService;
use App\Services\VisitService;

/**
 * GPS + selfie attendance.
 */
final class AttendanceApiController extends ApiController
{
    /** GET /attendance/today */
    public function today(): void
    {
        $row = Database::first(
            'SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1',
            [$this->userId(), date('Y-m-d')]
        );

        if ($row === null) {
            Response::ok([
                'attendance_date' => date('Y-m-d'),
                'checked_in'      => false,
                'checked_out'     => false,
            ]);
            return;
        }

        Response::ok($this->present($row));
    }

    /** POST /attendance/check-in (multipart) */
    public function checkIn(): void
    {
        [$latitude, $longitude] = $this->requireGps();
        $today = date('Y-m-d');

        $existing = Database::first(
            'SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1',
            [$this->userId(), $today]
        );

        if ($existing !== null && $existing['check_in_at'] !== null) {
            Response::ok(
                $this->present($existing),
                'You already checked in at ' . date('h:i A', strtotime((string) $existing['check_in_at']))
                    . '.'
            );
            return;
        }

        $accuracy = $this->request->str('accuracy_m') !== '' ? $this->request->float('accuracy_m') : null;
        $photoPath = null;
        $warnings = [];

        $selfie = $this->request->file('selfie');
        if ($selfie !== null) {
            $stored = (new PhotoStorageService())->storeAttendancePhoto(
                $selfie,
                (string) ($this->user['full_name'] ?? 'Agent'),
                $latitude,
                $longitude,
                $accuracy,
                'CHECK IN'
            );
            if ($stored['ok']) {
                $photoPath = $stored['relative'];
            } else {
                $warnings[] = 'The selfie could not be saved: ' . $stored['error'];
            }
        }

        // Flag a check-in that happened well outside the branch geofence.
        $outsideGeofence = 0;
        $branch = $this->branchId() === null ? null : Database::first(
            'SELECT latitude, longitude, geofence_m FROM branches WHERE id = ?',
            [$this->branchId()]
        );
        if ($branch !== null && $branch['latitude'] !== null && $branch['longitude'] !== null) {
            $distance = VisitService::haversineMetres(
                $latitude,
                $longitude,
                (float) $branch['latitude'],
                (float) $branch['longitude']
            );
            $limit = max(50, (int) $branch['geofence_m']);
            if ($distance > $limit) {
                $outsideGeofence = 1;
                $warnings[] = sprintf(
                    'You are %s m from the branch (allowed %s m). The check-in was recorded and flagged.',
                    number_format($distance),
                    number_format($limit)
                );
            }
        }

        $data = [
            'user_id'             => $this->userId(),
            'branch_id'           => $this->branchId(),
            'attendance_date'     => $today,
            'check_in_at'         => date('Y-m-d H:i:s'),
            'check_in_lat'        => $latitude,
            'check_in_lng'        => $longitude,
            'check_in_photo'      => $photoPath,
            'status'              => 'present',
            'is_outside_geofence' => $outsideGeofence,
            'remarks'             => $this->request->str('remarks') !== '' ? $this->request->str('remarks') : null,
        ];

        if ($existing === null) {
            Database::insert('attendance', $data);
        } else {
            unset($data['user_id'], $data['attendance_date']);
            Database::update('attendance', $data, ['id' => (int) $existing['id']]);
        }

        Audit::api('attendance.check_in', 'attendance', $this->userId(), 'Checked in');

        $row = Database::first(
            'SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1',
            [$this->userId(), $today]
        );

        Response::ok(
            array_merge($this->present($row ?? []), ['warnings' => $warnings]),
            'Checked in at ' . date('h:i A') . '.'
        );
    }

    /** POST /attendance/check-out (multipart) */
    public function checkOut(): void
    {
        [$latitude, $longitude] = $this->requireGps();
        $today = date('Y-m-d');

        $existing = Database::first(
            'SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1',
            [$this->userId(), $today]
        );

        if ($existing === null || $existing['check_in_at'] === null) {
            Response::fail(
                'You have not checked in today, so there is nothing to check out from.',
                'validation_failed',
                422
            );
            return;
        }

        if ($existing['check_out_at'] !== null) {
            Response::ok(
                $this->present($existing),
                'You already checked out at ' . date('h:i A', strtotime((string) $existing['check_out_at'])) . '.'
            );
            return;
        }

        $photoPath = null;
        $warnings = [];
        $selfie = $this->request->file('selfie');
        if ($selfie !== null) {
            $stored = (new PhotoStorageService())->storeAttendancePhoto(
                $selfie,
                (string) ($this->user['full_name'] ?? 'Agent'),
                $latitude,
                $longitude,
                null,
                'CHECK OUT'
            );
            if ($stored['ok']) {
                $photoPath = $stored['relative'];
            } else {
                $warnings[] = 'The selfie could not be saved: ' . $stored['error'];
            }
        }

        $checkInTime = strtotime((string) $existing['check_in_at']);
        $workedMinutes = $checkInTime === false ? 0 : (int) max(0, round((time() - $checkInTime) / 60));

        // Distance actually travelled today, from the GPS ping trail.
        $distanceKm = $this->travelledKilometres($this->userId(), $today);

        Database::update('attendance', [
            'check_out_at'    => date('Y-m-d H:i:s'),
            'check_out_lat'   => $latitude,
            'check_out_lng'   => $longitude,
            'check_out_photo' => $photoPath,
            'worked_minutes'  => min(65535, $workedMinutes),
            'distance_km'     => $distanceKm,
            'status'          => $workedMinutes < 240 ? 'half_day' : 'present',
        ], ['id' => (int) $existing['id']]);

        Audit::api('attendance.check_out', 'attendance', $this->userId(),
            'Checked out after ' . $workedMinutes . ' minutes');

        $row = Database::first('SELECT * FROM attendance WHERE id = ?', [(int) $existing['id']]);

        Response::ok(
            array_merge($this->present($row ?? []), ['warnings' => $warnings]),
            sprintf('Checked out. You worked %dh %02dm today.', intdiv($workedMinutes, 60), $workedMinutes % 60)
        );
    }

    /** Sum of consecutive ping distances, ignoring obvious GPS jumps. */
    private function travelledKilometres(int $userId, string $date): float
    {
        $pings = Database::all(
            'SELECT latitude, longitude FROM gps_pings
             WHERE user_id = ? AND DATE(recorded_at) = ?
             ORDER BY recorded_at ASC',
            [$userId, $date]
        );

        $metres = 0;
        $previous = null;
        foreach ($pings as $ping) {
            if ($previous !== null) {
                $step = VisitService::haversineMetres(
                    (float) $previous['latitude'],
                    (float) $previous['longitude'],
                    (float) $ping['latitude'],
                    (float) $ping['longitude']
                );
                // A single hop over 20 km between pings is a GPS glitch, not travel.
                if ($step < 20000) {
                    $metres += $step;
                }
            }
            $previous = $ping;
        }

        return round($metres / 1000, 2);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        if ($row === []) {
            return ['attendance_date' => date('Y-m-d'), 'checked_in' => false, 'checked_out' => false];
        }

        return [
            'id'                  => (int) $row['id'],
            'attendance_date'     => $row['attendance_date'],
            'checked_in'          => $row['check_in_at'] !== null,
            'check_in_at'         => $row['check_in_at'],
            'check_in_photo_url'  => PhotoStorageService::url($row['check_in_photo'] ?? null),
            'checked_out'         => $row['check_out_at'] !== null,
            'check_out_at'        => $row['check_out_at'],
            'check_out_photo_url' => PhotoStorageService::url($row['check_out_photo'] ?? null),
            'worked_minutes'      => (int) ($row['worked_minutes'] ?? 0),
            'distance_km'         => round((float) ($row['distance_km'] ?? 0), 2),
            'status'              => $row['status'] ?? null,
            'is_outside_geofence' => (int) ($row['is_outside_geofence'] ?? 0) === 1,
        ];
    }
}
