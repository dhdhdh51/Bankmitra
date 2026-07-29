<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Services\RecoveryService;

/**
 * Cash / transfer / UPI collection capture and history.
 */
final class RecoveryApiController extends ApiController
{
    private const MODES = ['cash', 'transfer', 'upi', 'cheque', 'dd', 'other'];

    /** POST /recoveries */
    public function store(): void
    {
        $this->requireBcAgent();

        $validator = new Validator($this->request->all());
        $validator->required('loan_id')->integer('loan_id')
            ->required('amount')->numeric('amount')->min('amount', 1)
            ->required('collected_at')->date('collected_at')
            ->inList('payment_mode', self::MODES)
            ->maxLen('txn_reference', 120)
            ->maxLen('receipt_number', 50);

        if ($validator->fails()) {
            Response::fail($validator->firstError(), 'validation_failed', 422, $validator->errors());
            return;
        }

        $recoveryUid = $this->request->str('recovery_uid');
        if ($recoveryUid === '' || preg_match('/^[0-9a-f-]{16,40}$/i', $recoveryUid) !== 1) {
            Response::fail(
                'recovery_uid is missing or malformed. The app must generate it once per receipt '
                    . 'and reuse it on every retry, otherwise a retry would double-count the money.',
                'validation_failed',
                422
            );
            return;
        }

        $result = (new RecoveryService())->create([
            'recovery_uid'   => $recoveryUid,
            'loan_id'        => $this->request->int('loan_id'),
            'visit_id'       => $this->request->int('visit_id', 0) > 0 ? $this->request->int('visit_id') : null,
            'bc_id'          => $this->bcId(),
            'user_id'        => $this->userId(),
            'amount'         => round($this->request->float('amount'), 2),
            'payment_mode'   => $this->request->str('payment_mode', 'cash'),
            'txn_reference'  => $this->nullable('txn_reference'),
            'bank_name'      => $this->nullable('bank_name'),
            'receipt_number' => $this->nullable('receipt_number'),
            'collected_at'   => date('Y-m-d H:i:s', strtotime($this->request->str('collected_at')) ?: time()),
            'latitude'       => $this->request->str('latitude') !== '' ? $this->request->float('latitude') : null,
            'longitude'      => $this->request->str('longitude') !== '' ? $this->request->float('longitude') : null,
            'remarks'        => $this->nullable('remarks'),
        ], $this->request->file('receipt_photo'), (string) ($this->user['full_name'] ?? 'BC Agent'));

        if (!$result['ok']) {
            $status = match ($result['code']) {
                'not_found' => 404,
                'forbidden' => 403,
                'server_error' => 500,
                default => 422,
            };
            Response::fail($result['message'], $result['code'], $status);
            return;
        }

        Response::ok([
            'recovery_id'            => $result['recovery_id'],
            'receipt_number'         => $result['receipt_number'],
            'status'                 => 'pending',
            'duplicate'              => $result['duplicate'],
            'loan_outstanding_after' => $result['outstanding_after'],
            'warnings'               => $result['warnings'],
        ], $result['message']);
    }

    /** GET /recoveries */
    public function index(): void
    {
        $pagination = $this->pagination();

        [$scopeSql, $params] = Auth::scopeSql('r.branch_id', 'r.bc_id');
        $where = '1 = 1' . $scopeSql;

        $date = $this->request->str('date');
        if ($date !== '') {
            $where .= ' AND DATE(r.collected_at) = ?';
            $params[] = date('Y-m-d', strtotime($date) ?: time());
        }

        $status = $this->request->str('status');
        if (in_array($status, ['pending', 'verified', 'rejected', 'reversed'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }

        $total = (int) Database::value('SELECT COUNT(*) FROM recoveries r WHERE ' . $where, $params, 0);

        $rows = Database::all(
            'SELECT r.id, r.receipt_number, r.amount, r.payment_mode, r.txn_reference,
                    r.collected_at, r.status, r.reject_reason,
                    l.account_number, c.full_name
             FROM recoveries r
             JOIN loans l ON l.id = r.loan_id
             JOIN customers c ON c.id = r.customer_id
             WHERE ' . $where . '
             ORDER BY r.collected_at DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $summary = Database::first(
            'SELECT COALESCE(SUM(CASE WHEN r.status <> "rejected" THEN r.amount ELSE 0 END),0) AS total_amount,
                    COALESCE(SUM(CASE WHEN r.status = "pending" THEN r.amount ELSE 0 END),0) AS pending_amount
             FROM recoveries r WHERE ' . $where,
            $params
        ) ?? ['total_amount' => 0, 'pending_amount' => 0];

        $data = array_map(static function (array $row): array {
            return [
                'id'             => (int) $row['id'],
                'receipt_number' => (string) $row['receipt_number'],
                'account_number' => (string) $row['account_number'],
                'customer_name'  => (string) $row['full_name'],
                'amount'         => round((float) $row['amount'], 2),
                'payment_mode'   => (string) $row['payment_mode'],
                'txn_reference'  => $row['txn_reference'],
                'collected_at'   => $row['collected_at'],
                'status'         => (string) $row['status'],
                'reject_reason'  => $row['reject_reason'],
            ];
        }, $rows);

        Response::ok($data, 'OK', array_merge($this->meta($total, $pagination), [
            'total_amount'   => round((float) $summary['total_amount'], 2),
            'pending_amount' => round((float) $summary['pending_amount'], 2),
        ]));
    }

    private function nullable(string $key): ?string
    {
        $value = $this->request->str($key);
        return $value === '' ? null : $value;
    }
}
