<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Services\PhotoStorageService;
use App\Services\VisitService;
use Lib\Crypto;

/**
 * GPS-verified visit submission and history.
 */
final class VisitApiController extends ApiController
{
    private const STATUSES = [
        'visited', 'not_available', 'promise', 'paid', 'ots', 'legal', 'skip', 'untraceable',
    ];

    /** POST /visits (multipart/form-data) */
    public function store(): void
    {
        $this->requireBcAgent();

        $validator = new Validator($this->request->all());
        $validator->required('loan_id')->integer('loan_id')
            ->required('visited_at')->date('visited_at')
            ->inList('visit_status', self::STATUSES)
            ->latitude('latitude')->longitude('longitude')
            ->realCoordinates('latitude', 'longitude')
            ->maxLen('met_person', 150)
            ->maxLen('met_relation', 80)
            ->maxLen('occupation', 120);

        if ($this->request->str('recovery_possibility') !== '') {
            $validator->inList('recovery_possibility', ['high', 'medium', 'low', 'nil']);
        }

        // Central Bank form enums. inList() ignores empty values, so each of
        // these is only checked when the app actually sent something.
        $validator
            ->inList('loan_type', ['ckcc', 'agl', 'dairy', 'shg', 'other'])
            ->inList('account_status', ['npa', 'ckcc_od2', 'krm_ots', 'other'])
            ->inList('contact_status', ['borrower', 'family', 'not_found', 'phone', 'phone_off'])
            ->inList('residence_status', ['same', 'moved'])
            ->inList('income_source', ['agri', 'dairy', 'job', 'business', 'labour', 'other'])
            ->inList('payment_plan', ['interest', 'krm_ots'])
            ->maxLen('loan_type_other', 80)
            ->maxLen('account_status_other', 80)
            ->maxLen('income_source_other', 80)
            ->maxLen('nonpayment_other', 120);

        if ($this->request->str('contact_mobile') !== '') {
            $validator->mobile('contact_mobile');
        }
        if ($this->request->str('promise_date') !== '') {
            $validator->date('promise_date');
        }
        if ($this->request->str('promise_amount') !== '') {
            $validator->numeric('promise_amount')->min('promise_amount', 0);
        }

        if ($validator->fails()) {
            Response::fail($validator->firstError(), 'validation_failed', 422, $validator->errors());
            return;
        }

        // requireGps() enforces the "GPS off = submit blocked" rule and the
        // mock-location rejection, exiting with a clear message if it fails.
        [$latitude, $longitude] = $this->requireGps();

        $visitUid = $this->request->str('visit_uid');
        if ($visitUid === '' || preg_match('/^[0-9a-f-]{16,40}$/i', $visitUid) !== 1) {
            // Never invent one silently: the app must own the idempotency key.
            Response::fail(
                'visit_uid is missing or malformed. The app must generate it once per visit '
                    . 'and reuse it on every retry.',
                'validation_failed',
                422
            );
            return;
        }

        $result = (new VisitService())->create(
            [
                'visit_uid'            => $visitUid,
                'loan_id'              => $this->request->int('loan_id'),
                'user_id'              => $this->userId(),
                'bc_id'                => $this->bcId(),
                'visited_at'           => date('Y-m-d H:i:s', strtotime($this->request->str('visited_at')) ?: time()),
                'latitude'             => $latitude,
                'longitude'            => $longitude,
                'accuracy_m'           => $this->request->str('accuracy_m') !== '' ? $this->request->float('accuracy_m') : null,
                'is_mock_location'     => $this->request->bool('is_mock_location', false),
                'visit_status'         => $this->request->str('visit_status', 'visited'),
                'customer_available'   => $this->request->bool('customer_available', true),
                'house_locked'         => $this->request->bool('house_locked', false),
                'met_person'           => $this->nullable('met_person'),
                'met_relation'         => $this->nullable('met_relation'),
                'occupation'           => $this->nullable('occupation'),
                'recovery_possibility' => $this->nullable('recovery_possibility'),
                'promise_amount'       => $this->request->float('promise_amount', 0.0),
                'promise_date'         => $this->request->str('promise_date'),
                'recommendation'       => $this->nullable('recommendation'),
                'remarks'              => $this->nullable('remarks'),

                // ---- Central Bank BC FIELD VISIT REPORT sections ------------
                // Every one is optional so an older app keeps working; the
                // service drops anything outside the documented code lists.
                'loan_type'            => $this->nullable('loan_type'),
                'loan_type_other'      => $this->nullable('loan_type_other'),
                'account_status'       => $this->nullable('account_status'),
                'account_status_other' => $this->nullable('account_status_other'),
                'rc_issued'            => $this->request->bool('rc_issued', false),
                'contact_status'       => $this->nullable('contact_status'),
                'contact_mobile'       => $this->request->str('contact_mobile'),
                'borrower_alive'       => $this->request->str('borrower_alive') !== ''
                    ? $this->request->bool('borrower_alive', true) : null,
                'residence_status'     => $this->nullable('residence_status'),
                'income_source'        => $this->nullable('income_source'),
                'income_source_other'  => $this->nullable('income_source_other'),
                'willing_to_pay'       => $this->request->str('willing_to_pay') !== ''
                    ? $this->request->bool('willing_to_pay', true) : null,
                'payment_plan'         => $this->nullable('payment_plan'),
                'nonpayment_reasons'   => $this->nullable('nonpayment_reasons'),
                'nonpayment_other'     => $this->nullable('nonpayment_other'),
                'recommendations'      => $this->nullable('recommendations'),

                'device_id'            => $this->deviceId() !== '' ? $this->deviceId() : null,
                'app_version'          => $this->appVersion() !== '' ? $this->appVersion() : null,
                'sync_source'          => $this->request->bool('from_queue', false) ? 'offline_queue' : 'online',
            ],
            $this->request->files('photos'),
            (string) $this->request->input('signature', ''),
            (string) ($this->user['full_name'] ?? 'BC Agent')
                . ($this->user['bc_code'] !== null ? ' (' . $this->user['bc_code'] . ')' : ''),
            (string) $this->request->input('borrower_signature', ''),
            // photo_types[] runs parallel to photos[]: photo_types[0] tags photos[0].
            array_map('strval', (array) $this->request->input('photo_types', []))
        );

        if (!$result['ok']) {
            $status = match ($result['code']) {
                'not_found' => 404,
                'forbidden' => 403,
                'server_error' => 500,
                default => 422,
            };
            Response::json([
                'success' => false,
                'code' => $result['code'],
                'message' => $result['message'],
                'data' => ['photo_errors' => $result['photo_errors']],
            ], $status);
            return;
        }

        Response::ok([
            'visit_id'                 => $result['visit_id'],
            'visit_uid'                => $visitUid,
            'photos_saved'             => $result['photos_saved'],
            'photo_errors'             => $result['photo_errors'],
            'distance_from_customer_m' => $result['distance'],
            'duplicate'                => $result['duplicate'],
            'followup_created'         => $result['followup_created'],
        ], $result['message']);
    }

    /** GET /visits */
    public function index(): void
    {
        $pagination = $this->pagination();

        [$scopeSql, $params] = Auth::scopeSql('v.branch_id', 'v.bc_id');
        $where = '1 = 1' . $scopeSql;

        $date = $this->request->str('date');
        if ($date !== '') {
            $where .= ' AND v.visit_date = ?';
            $params[] = date('Y-m-d', strtotime($date) ?: time());
        }

        $from = $this->request->str('from');
        $to = $this->request->str('to');
        if ($from !== '' && $to !== '') {
            $where .= ' AND v.visit_date BETWEEN ? AND ?';
            $params[] = date('Y-m-d', strtotime($from) ?: time());
            $params[] = date('Y-m-d', strtotime($to) ?: time());
        }

        $status = $this->request->str('status');
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where .= ' AND v.visit_status = ?';
            $params[] = $status;
        }

        $total = (int) Database::value('SELECT COUNT(*) FROM visits v WHERE ' . $where, $params, 0);

        $rows = Database::all(
            'SELECT v.id, v.visit_uid, v.visit_date, v.visited_at, v.visit_status,
                    v.promise_amount, v.promise_date, v.collected_amount, v.remarks,
                    v.latitude, v.longitude, v.distance_from_customer_m,
                    l.account_number, c.full_name, c.village,
                    (SELECT COUNT(*) FROM visit_photos p WHERE p.visit_id = v.id) AS photo_count,
                    (SELECT p.thumb_path FROM visit_photos p WHERE p.visit_id = v.id ORDER BY p.id LIMIT 1) AS thumb
             FROM visits v
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             WHERE ' . $where . '
             ORDER BY v.visited_at DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $data = array_map(static function (array $row): array {
            return [
                'id'               => (int) $row['id'],
                'visit_uid'        => (string) $row['visit_uid'],
                'account_number'   => (string) $row['account_number'],
                'customer_name'    => (string) $row['full_name'],
                'village'          => $row['village'],
                'visit_date'       => $row['visit_date'],
                'visited_at'       => $row['visited_at'],
                'visit_status'     => (string) $row['visit_status'],
                'promise_amount'   => round((float) $row['promise_amount'], 2),
                'promise_date'     => $row['promise_date'],
                'collected_amount' => round((float) $row['collected_amount'], 2),
                'remarks'          => $row['remarks'],
                'latitude'         => (float) $row['latitude'],
                'longitude'        => (float) $row['longitude'],
                'distance_m'       => $row['distance_from_customer_m'] === null ? null : (int) $row['distance_from_customer_m'],
                'photo_count'      => (int) $row['photo_count'],
                'thumb_url'        => PhotoStorageService::url($row['thumb']),
            ];
        }, $rows);

        Response::ok($data, 'OK', $this->meta($total, $pagination));
    }

    private function nullable(string $key): ?string
    {
        $value = $this->request->str($key);
        return $value === '' ? null : $value;
    }
}
