<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use Lib\Crypto;
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
    /**
     * Allowed codes for the tick-box groups on the printed form. Anything the
     * app sends that is not in these lists is dropped rather than stored, so a
     * newer app cannot write junk into an older server.
     */
    private const NONPAYMENT_REASONS = [
        'financial', 'crop_failure', 'cattle_loss', 'illness',
        'unemployment', 'dispute', 'other_bank_loan', 'other',
    ];

    private const RECOMMENDATIONS = [
        'recovery_good', 'followup_needed', 'legal_action',
        'rc_issue', 'krm_ots', 'other',
    ];

    /**
     * Keeps only known codes from a comma separated list, de-duplicated and in
     * the order the form prints them.
     *
     * @param list<string> $allowed
     */
    private static function filterCodes(?string $raw, array $allowed): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $sent = array_map('trim', explode(',', strtolower($raw)));
        $kept = array_values(array_intersect($allowed, $sent));
        return $kept === [] ? null : implode(',', $kept);
    }

    /** Restricts a value to an enum, mapping anything unexpected to null. */
    private static function enumOrNull(?string $value, array $allowed): ?string
    {
        $value = $value === null ? '' : strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : null;
    }

    public function create(
        array $input,
        array $photoFiles,
        string $signatureBase64,
        string $agentName,
        string $borrowerSignatureBase64 = '',
        array $photoTypes = []
    ): array {
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

        // Section 13 of the printed form has TWO signature boxes. The borrower's
        // one gets its own uid suffix so the two files cannot collide.
        $borrowerSignaturePath = null;
        if ($borrowerSignatureBase64 !== '') {
            $borrowerSig = $storage->storeSignature(
                $borrowerSignatureBase64,
                $input['visit_uid'] . '-borrower'
            );
            if ($borrowerSig['ok']) {
                $borrowerSignaturePath = $borrowerSig['relative'];
            } else {
                $photoErrors[] = 'Borrower signature: ' . $borrowerSig['error'];
            }
        }

        // ---- persist --------------------------------------------------------
        try {
            $visitId = Database::transaction(function () use (
                $input,
                $loan,
                $distance,
                $storedPhotos,
                $signaturePath,
                $borrowerSignaturePath,
                $photoTypes
            ): int {
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

                    // ---- Central Bank BC FIELD VISIT REPORT fields ----------
                    // 3. loan type
                    'loan_type'            => self::enumOrNull($input['loan_type'] ?? null, ['ckcc', 'agl', 'dairy', 'shg', 'other']),
                    'loan_type_other'      => $input['loan_type_other'] ?? null,
                    // 4. current account status
                    'account_status'       => self::enumOrNull($input['account_status'] ?? null, ['npa', 'ckcc_od2', 'krm_ots', 'other']),
                    'account_status_other' => $input['account_status_other'] ?? null,
                    'rc_issued'            => !empty($input['rc_issued']) ? 1 : 0,
                    // 5. how contact was made
                    'contact_status'       => self::enumOrNull($input['contact_status'] ?? null, ['borrower', 'family', 'not_found', 'phone', 'phone_off']),
                    // The number actually reached is PII, so it is encrypted the
                    // same way every other mobile in this schema is.
                    'contact_mobile_enc'   => ($input['contact_mobile'] ?? '') !== ''
                        ? Crypto::encrypt(Crypto::normalise((string) $input['contact_mobile'], 'mobile'))
                        : null,
                    'contact_mobile_last4' => ($input['contact_mobile'] ?? '') !== ''
                        ? Crypto::last4((string) $input['contact_mobile'])
                        : null,
                    // 7. physical verification
                    'borrower_alive'       => isset($input['borrower_alive']) && $input['borrower_alive'] !== null
                        ? ($input['borrower_alive'] ? 1 : 0) : null,
                    'residence_status'     => self::enumOrNull($input['residence_status'] ?? null, ['same', 'moved']),
                    'income_source'        => self::enumOrNull($input['income_source'] ?? null, ['agri', 'dairy', 'job', 'business', 'labour', 'other']),
                    'income_source_other'  => $input['income_source_other'] ?? null,
                    // 9. willingness to pay
                    'willing_to_pay'       => isset($input['willing_to_pay']) && $input['willing_to_pay'] !== null
                        ? ($input['willing_to_pay'] ? 1 : 0) : null,
                    'payment_plan'         => self::enumOrNull($input['payment_plan'] ?? null, ['interest', 'krm_ots']),
                    // 10 + 11. tick-box groups
                    'nonpayment_reasons'   => self::filterCodes($input['nonpayment_reasons'] ?? null, self::NONPAYMENT_REASONS),
                    'nonpayment_other'     => $input['nonpayment_other'] ?? null,
                    'recommendations'      => self::filterCodes($input['recommendations'] ?? null, self::RECOMMENDATIONS),
                    // 13. borrower's signature or thumb impression
                    'borrower_signature_path' => $borrowerSignaturePath,

                    'device_id'            => $input['device_id'],
                    'app_version'          => $input['app_version'],
                    'sync_source'          => $input['sync_source'],
                    'synced_at'            => date('Y-m-d H:i:s'),
                ]);

                foreach ($storedPhotos as $photoIndex => $photo) {
                    // Section 12 asks WHICH evidence each photo is. The app tags
                    // every capture; anything unrecognised falls back to 'house'
                    // so an older app keeps working unchanged.
                    $type = strtolower(trim((string) ($photoTypes[$photoIndex] ?? '')));
                    if (!in_array($type, ['house', 'customer', 'document', 'selfie', 'other'], true)) {
                        $type = 'house';
                    }

                    Database::insert('visit_photos', [
                        'visit_id'    => $visitId,
                        'photo_uid'   => $photo['photo_uid'],
                        'file_path'   => $photo['relative'],
                        'thumb_path'  => $photo['thumb'] !== '' ? $photo['thumb'] : null,
                        'photo_type'  => $type,
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
            if ($borrowerSignaturePath !== null) {
                @unlink(UPLOAD_PATH . '/' . $borrowerSignaturePath);
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
