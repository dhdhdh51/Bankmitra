<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use Lib\Logger;
use Lib\Settings;

/**
 * Creating a GPS-verified visit, and the side effects that follow:
 * loan counters, follow-ups, and the risk score refresh hint.
 *
 * The whole thing runs in one transaction, so a failure part-way through never
 * leaves a visit without its photos or a loan with a wrong counter.
 */
final class VisitService
{
    /**
     * @param array{
     *   visit_uid:string, loan_id:int, user_id:int, bc_id:?int,
     *   visited_at:string, latitude:float, longitude:float, accuracy_m:?float,
     *   is_mock_location:bool, visit_status:string, customer_available:bool,
     *   house_locked:bool, met_person:?string, met_relation:?string,
     *   occupation:?string, recovery_possibility:?string, promise_amount:float,
     *   promise_date:?string, recommendation:?string, remarks:?string,
     *   device_id:?string, app_version:?string, sync_source:string
     * } $input
     * @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $photoFiles
     * @param string $signatureBase64
     *
     * @return array{
     *   ok:bool, code:string, message:string, visit_id:?int, duplicate:bool,
     *   photos_saved:int, photo_errors:list<string>, distance:?int,
     *   followup_created:bool
     * }
     */
    public function create(array $input, array $photoFiles, string $signatureBase64, string $agentName): array
    {
        $fail = static fn (string $code, string $message): array => [
            'ok' => false, 'code' => $code, 'message' => $message, 'visit_id' => null,
            'duplicate' => false, 'photos_saved' => 0, 'photo_errors' => [],
            'distance' => null, 'followup_created' => false,
        ];

        // ---- idempotency: the app may retry a queued visit ----------------
        $existing = Database::first(
            'SELECT id FROM visits WHERE visit_uid = ? LIMIT 1',
            [$input['visit_uid']]
        );
        if ($existing !== null) {
            return [
                'ok' => true, 'code' => 'ok',
                'message' => 'This visit was already saved.',
                'visit_id' => (int) $existing['id'], 'duplicate' => true,
                'photos_saved' => (int) Database::value(
                    'SELECT COUNT(*) FROM visit_photos WHERE visit_id = ?',
                    [(int) $existing['id']],
                    0
                ),
                'photo_errors' => [], 'distance' => null, 'followup_created' => false,
            ];
        }

        // ---- the loan must exist and belong to this agent -----------------
        $loan = Database::first(
            'SELECT l.*, c.full_name AS customer_name, c.latitude AS customer_lat, c.longitude AS customer_lng
             FROM loans l
             JOIN customers c ON c.id = l.customer_id
             WHERE l.id = ? LIMIT 1',
            [$input['loan_id']]
        );
        if ($loan === null) {
            return $fail('not_found', 'That loan account no longer exists.');
        }

        if ($input['bc_id'] !== null
            && $loan['bc_id'] !== null
            && (int) $loan['bc_id'] !== $input['bc_id']) {
            return $fail('forbidden', 'This account is not allocated to you.');
        }

        // ---- photo policy --------------------------------------------------
        $minPhotos = max(0, Settings::getInt('app.visit_photo_min', 1));
        if (count($photoFiles) < $minPhotos) {
            return $fail(
                'photo_required',
                $minPhotos === 1
                    ? 'At least one photo is required for a visit.'
                    : 'At least ' . $minPhotos . ' photos are required for a visit.'
            );
        }

        // ---- distance from the customer's recorded location -----------------
        $distance = null;
        if ($loan['customer_lat'] !== null && $loan['customer_lng'] !== null) {
            $distance = self::haversineMetres(
                $input['latitude'],
                $input['longitude'],
                (float) $loan['customer_lat'],
                (float) $loan['customer_lng']
            );

            $maxDistance = Settings::getInt('app.visit_max_distance_m', 500);
            if ($maxDistance > 0 && $distance > $maxDistance) {
                return $fail('gps_required', sprintf(
                    'You appear to be %s km away from this customer\'s recorded location '
                    . '(limit %s m). Move closer, or ask your Branch Manager to correct the '
                    . 'customer coordinates.',
                    number_format($distance / 1000, 2),
                    number_format($maxDistance)
                ));
            }
        }

        // ---- promise consistency -------------------------------------------
        if ($input['visit_status'] === 'promise') {
            if ($input['promise_amount'] <= 0) {
                return $fail('validation_failed', 'Enter the promised amount for a "Promise" visit.');
            }
            if (($input['promise_date'] ?? '') === '') {
                return $fail('validation_failed', 'Enter the promised date for a "Promise" visit.');
            }
        }

        // ---- store the photos BEFORE opening the transaction ---------------
        // Writing files inside a transaction would leave orphans on rollback,
        // so files go first and are cleaned up if the insert fails.
        $storage = new PhotoStorageService();
        $storedPhotos = [];
        $photoErrors = [];

        foreach ($photoFiles as $index => $file) {
            $stored = $storage->storeVisitPhoto($file, [
                'agent'      => $agentName,
                'latitude'   => $input['latitude'],
                'longitude'  => $input['longitude'],
                'accuracy'   => $input['accuracy_m'],
                'capturedAt' => $input['visited_at'],
                'extra'      => 'A/c ' . $loan['account_number'],
            ]);

            if ($stored['ok']) {
                $storedPhotos[] = $stored;
            } else {
                $photoErrors[] = 'Photo ' . ($index + 1) . ': ' . $stored['error'];
            }
        }

        if ($storedPhotos === [] && $minPhotos > 0) {
            return array_merge(
                $fail('photo_required', 'None of the photos could be saved. ' . implode(' ', $photoErrors)),
                ['photo_errors' => $photoErrors]
            );
        }

        $signaturePath = null;
        if ($signatureBase64 !== '') {
            $signature = $storage->storeSignature($signatureBase64, $input['visit_uid']);
            if ($signature['ok']) {
                $signaturePath = $signature['relative'];
            } else {
                $photoErrors[] = 'Signature: ' . $signature['error'];
            }
        }

        // ---- persist --------------------------------------------------------
        try {
            $visitId = Database::transaction(function () use ($input, $loan, $distance, $storedPhotos, $signaturePath): int {
                $visitId = Database::insert('visits', [
                    'visit_uid'            => $input['visit_uid'],
                    'loan_id'              => (int) $loan['id'],
                    'customer_id'          => (int) $loan['customer_id'],
                    'bc_id'                => $input['bc_id'] ?? ($loan['bc_id'] === null ? null : (int) $loan['bc_id']),
                    'user_id'              => $input['user_id'],
                    'branch_id'            => $loan['branch_id'] === null ? null : (int) $loan['branch_id'],
                    'visit_date'           => date('Y-m-d', strtotime($input['visited_at']) ?: time()),
                    'visited_at'           => $input['visited_at'],
                    'latitude'             => $input['latitude'],
                    'longitude'            => $input['longitude'],
                    'accuracy_m'           => $input['accuracy_m'],
                    'is_mock_location'     => $input['is_mock_location'] ? 1 : 0,
                    'distance_from_customer_m' => $distance,
                    'visit_status'         => $input['visit_status'],
                    'customer_available'   => $input['customer_available'] ? 1 : 0,
                    'house_locked'         => $input['house_locked'] ? 1 : 0,
                    'met_person'           => $input['met_person'],
                    'met_relation'         => $input['met_relation'],
                    'occupation'           => $input['occupation'],
                    'recovery_possibility' => $input['recovery_possibility'],
                    'promise_amount'       => $input['promise_amount'],
                    'promise_date'         => ($input['promise_date'] ?? '') !== '' ? $input['promise_date'] : null,
                    'recommendation'       => $input['recommendation'],
                    'remarks'              => $input['remarks'],
                    'signature_path'       => $signaturePath,
                    'device_id'            => $input['device_id'],
                    'app_version'          => $input['app_version'],
                    'sync_source'          => $input['sync_source'],
                    'synced_at'            => date('Y-m-d H:i:s'),
                ]);

                foreach ($storedPhotos as $photo) {
                    Database::insert('visit_photos', [
                        'visit_id'    => $visitId,
                        'photo_uid'   => $photo['photo_uid'],
                        'file_path'   => $photo['relative'],
                        'thumb_path'  => $photo['thumb'] !== '' ? $photo['thumb'] : null,
                        'photo_type'  => 'house',
                        'latitude'    => $input['latitude'],
                        'longitude'   => $input['longitude'],
                        'captured_at' => $input['visited_at'],
                        'watermarked' => 1,
                        'file_hash'   => $photo['hash'],
                        'size_bytes'  => $photo['bytes'],
                        'mime_type'   => 'image/jpeg',
                    ]);
                }

                // Roll the loan's derived state forward.
                $recoveryStatus = self::mapVisitStatusToRecoveryStatus($input['visit_status'], (string) $loan['recovery_status']);
                Database::run(
                    'UPDATE loans
                     SET last_visit_at = ?, visit_count = visit_count + 1,
                         recovery_status = ?,
                         next_followup_date = ?
                     WHERE id = ?',
                    [
                        $input['visited_at'],
                        $recoveryStatus,
                        ($input['promise_date'] ?? '') !== '' ? $input['promise_date'] : $loan['next_followup_date'],
                        (int) $loan['id'],
                    ]
                );

                return $visitId;
            });
        } catch (\Throwable $e) {
            Logger::error('Visit insert failed: ' . $e->getMessage(), ['visit_uid' => $input['visit_uid']]);

            // Remove the files we had already written so nothing is orphaned.
            foreach ($storedPhotos as $photo) {
                @unlink(UPLOAD_PATH . '/' . $photo['relative']);
                if ($photo['thumb'] !== '') {
                    @unlink(UPLOAD_PATH . '/' . $photo['thumb']);
                }
            }
            if ($signaturePath !== null) {
                @unlink(UPLOAD_PATH . '/' . $signaturePath);
            }

            return $fail('server_error', 'The visit could not be saved. Nothing was recorded - please try again.');
        }

        // ---- follow-up ------------------------------------------------------
        $followupCreated = false;
        if (($input['promise_date'] ?? '') !== '') {
            try {
                Database::insert('follow_ups', [
                    'loan_id'        => (int) $loan['id'],
                    'visit_id'       => $visitId,
                    'bc_id'          => $input['bc_id'],
                    'assigned_to'    => $input['user_id'],
                    'due_date'       => $input['promise_date'],
                    'channel'        => 'visit',
                    'promise_amount' => $input['promise_amount'],
                    'message'        => 'Promise follow-up for A/c ' . $loan['account_number'],
                    'status'         => 'pending',
                    'created_by'     => $input['user_id'],
                ]);
                $followupCreated = true;
            } catch (\Throwable $e) {
                // The visit itself is safe; surface the problem but do not fail.
                Logger::warning('Follow-up creation failed: ' . $e->getMessage());
                $photoErrors[] = 'The follow-up reminder could not be created.';
            }
        }

        Audit::api('visit.create', 'visit', $visitId,
            'Visit for A/c ' . $loan['account_number'] . ' (' . $input['visit_status'] . ')');

        return [
            'ok' => true,
            'code' => 'ok',
            'message' => 'Visit saved.',
            'visit_id' => $visitId,
            'duplicate' => false,
            'photos_saved' => count($storedPhotos),
            'photo_errors' => $photoErrors,
            'distance' => $distance,
            'followup_created' => $followupCreated,
        ];
    }

    /**
     * How a visit outcome moves the loan's recovery status.
     * A closed or written-off loan is never reopened by a visit.
     */
    private static function mapVisitStatusToRecoveryStatus(string $visitStatus, string $current): string
    {
        if (in_array($current, ['closed', 'write_off'], true)) {
            return $current;
        }

        return match ($visitStatus) {
            'paid'    => 'partly_paid',
            'promise' => 'promise',
            'ots'     => 'ots',
            'legal'   => 'legal',
            default   => $current === 'open' ? 'in_progress' : $current,
        };
    }

    public static function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
