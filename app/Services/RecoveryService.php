<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use Lib\Logger;

/**
 * Recording a collection. Money is involved, so every path either commits
 * fully or reports a clear error - never a silent partial write.
 *
 * A recovery is created as `pending` and must be verified by a Branch Manager
 * (or Super Admin) in the panel before it counts as confirmed. The loan's
 * outstanding balance is reduced immediately so the agent sees the right figure
 * in the field; a later rejection reverses it.
 */
final class RecoveryService
{
    /**
     * @param array{
     *   recovery_uid:string, loan_id:int, visit_id:?int, bc_id:?int, user_id:int,
     *   amount:float, payment_mode:string, txn_reference:?string, bank_name:?string,
     *   receipt_number:?string, collected_at:string, latitude:?float,
     *   longitude:?float, remarks:?string
     * } $input
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $receiptPhoto
     *
     * @return array{
     *   ok:bool, code:string, message:string, recovery_id:?int, duplicate:bool,
     *   receipt_number:string, outstanding_after:float, warnings:list<string>
     * }
     */
    public function create(array $input, ?array $receiptPhoto, string $agentName): array
    {
        $fail = static fn (string $code, string $message): array => [
            'ok' => false, 'code' => $code, 'message' => $message, 'recovery_id' => null,
            'duplicate' => false, 'receipt_number' => '', 'outstanding_after' => 0.0, 'warnings' => [],
        ];

        // ---- idempotency ---------------------------------------------------
        $existing = Database::first(
            'SELECT id, receipt_number, loan_id FROM recoveries WHERE recovery_uid = ? LIMIT 1',
            [$input['recovery_uid']]
        );
        if ($existing !== null) {
            $outstanding = (float) Database::value(
                'SELECT outstanding_amount FROM loans WHERE id = ?',
                [(int) $existing['loan_id']],
                0
            );
            return [
                'ok' => true, 'code' => 'ok',
                'message' => 'This receipt was already recorded.',
                'recovery_id' => (int) $existing['id'], 'duplicate' => true,
                'receipt_number' => (string) $existing['receipt_number'],
                'outstanding_after' => $outstanding, 'warnings' => [],
            ];
        }

        if ($input['amount'] <= 0) {
            return $fail('validation_failed', 'Enter a collection amount greater than zero.');
        }

        if ($input['payment_mode'] !== 'cash' && ($input['txn_reference'] ?? '') === '') {
            return $fail(
                'validation_failed',
                'A transaction reference (UTR / UPI ref / cheque number) is required for '
                    . strtoupper($input['payment_mode']) . ' collections.'
            );
        }

        $loan = Database::first(
            'SELECT l.*, c.full_name AS customer_name FROM loans l
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

        $warnings = [];
        $outstanding = (float) $loan['outstanding_amount'];
        if ($input['amount'] > $outstanding && $outstanding > 0) {
            // Allowed (settlements can exceed the book balance) but flagged.
            $warnings[] = sprintf(
                'The amount is more than the outstanding balance of %s. It has been recorded and flagged for verification.',
                number_format($outstanding, 2)
            );
        }

        // ---- receipt photo (written before the transaction) -----------------
        $receiptPath = null;
        if ($receiptPhoto !== null) {
            $stored = (new PhotoStorageService())->storeVisitPhoto($receiptPhoto, [
                'agent'      => $agentName,
                'latitude'   => $input['latitude'] ?? 0.0,
                'longitude'  => $input['longitude'] ?? 0.0,
                'accuracy'   => null,
                'capturedAt' => $input['collected_at'],
                'extra'      => 'Receipt A/c ' . $loan['account_number'],
            ]);
            if ($stored['ok']) {
                $receiptPath = $stored['relative'];
            } else {
                $warnings[] = 'The receipt photo could not be saved: ' . $stored['error'];
            }
        }

        $receiptNumber = ($input['receipt_number'] ?? '') !== ''
            ? (string) $input['receipt_number']
            : $this->nextReceiptNumber();

        try {
            $recoveryId = Database::transaction(function () use ($input, $loan, $receiptNumber, $receiptPath): int {
                $recoveryId = Database::insert('recoveries', [
                    'recovery_uid'   => $input['recovery_uid'],
                    'receipt_number' => $receiptNumber,
                    'loan_id'        => (int) $loan['id'],
                    'visit_id'       => $input['visit_id'],
                    'customer_id'    => (int) $loan['customer_id'],
                    'bc_id'          => $input['bc_id'] ?? ($loan['bc_id'] === null ? null : (int) $loan['bc_id']),
                    'branch_id'      => $loan['branch_id'] === null ? null : (int) $loan['branch_id'],
                    'amount'         => $input['amount'],
                    'payment_mode'   => $input['payment_mode'],
                    'txn_reference'  => $input['txn_reference'],
                    'bank_name'      => $input['bank_name'],
                    'collected_at'   => $input['collected_at'],
                    'latitude'       => $input['latitude'],
                    'longitude'      => $input['longitude'],
                    'receipt_photo'  => $receiptPath,
                    'status'         => 'pending',
                    'remarks'        => $input['remarks'],
                ]);

                // Reduce the balance now so field figures stay believable.
                Database::run(
                    'UPDATE loans SET
                        total_recovered = total_recovered + ?,
                        outstanding_amount = GREATEST(0, outstanding_amount - ?),
                        overdue_amount = GREATEST(0, overdue_amount - ?),
                        last_paid_date = ?, last_paid_amount = ?,
                        recovery_status = CASE
                            WHEN outstanding_amount - ? <= 0 THEN "closed"
                            ELSE "partly_paid" END
                     WHERE id = ?',
                    [
                        $input['amount'], $input['amount'], $input['amount'],
                        date('Y-m-d', strtotime($input['collected_at']) ?: time()),
                        $input['amount'], $input['amount'], (int) $loan['id'],
                    ]
                );

                if ($input['visit_id'] !== null) {
                    Database::run(
                        'UPDATE visits SET collected_amount = collected_amount + ? WHERE id = ?',
                        [$input['amount'], $input['visit_id']]
                    );
                }

                return $recoveryId;
            });
        } catch (\Throwable $e) {
            Logger::error('Recovery insert failed: ' . $e->getMessage(), ['uid' => $input['recovery_uid']]);
            if ($receiptPath !== null) {
                @unlink(UPLOAD_PATH . '/' . $receiptPath);
            }
            return $fail(
                'server_error',
                'The collection could NOT be saved. No money has been recorded - please retry, '
                    . 'and do not issue the receipt until you see a success message.'
            );
        }

        $outstandingAfter = (float) Database::value(
            'SELECT outstanding_amount FROM loans WHERE id = ?',
            [(int) $loan['id']],
            0
        );

        Audit::api('recovery.create', 'recovery', $recoveryId, sprintf(
            'Collected %s via %s for A/c %s, receipt %s',
            number_format($input['amount'], 2),
            $input['payment_mode'],
            $loan['account_number'],
            $receiptNumber
        ));

        return [
            'ok' => true,
            'code' => 'ok',
            'message' => sprintf(
                'Recovery of Rs.%s recorded. Receipt %s.',
                number_format($input['amount'], 2),
                $receiptNumber
            ),
            'recovery_id' => $recoveryId,
            'duplicate' => false,
            'receipt_number' => $receiptNumber,
            'outstanding_after' => $outstandingAfter,
            'warnings' => $warnings,
        ];
    }

    /**
     * Verify or reject a pending collection (Branch Manager / Super Admin).
     *
     * @return array{ok:bool,message:string}
     */
    public function review(int $recoveryId, int $reviewerId, bool $approve, string $reason = ''): array
    {
        $recovery = Database::first('SELECT * FROM recoveries WHERE id = ? LIMIT 1', [$recoveryId]);
        if ($recovery === null) {
            return ['ok' => false, 'message' => 'That collection record no longer exists.'];
        }
        if ($recovery['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'This collection has already been ' . $recovery['status'] . '.'];
        }

        try {
            Database::transaction(function () use ($recovery, $reviewerId, $approve, $reason): void {
                Database::update('recoveries', [
                    'status'        => $approve ? 'verified' : 'rejected',
                    'verified_by'   => $reviewerId,
                    'verified_at'   => date('Y-m-d H:i:s'),
                    'reject_reason' => $approve ? null : ($reason !== '' ? $reason : 'Rejected by reviewer'),
                ], ['id' => (int) $recovery['id']]);

                // Rejection reverses the provisional balance reduction.
                if (!$approve) {
                    Database::run(
                        'UPDATE loans SET
                            total_recovered = GREATEST(0, total_recovered - ?),
                            outstanding_amount = outstanding_amount + ?,
                            overdue_amount = overdue_amount + ?,
                            recovery_status = CASE WHEN recovery_status = "closed" THEN "in_progress" ELSE recovery_status END
                         WHERE id = ?',
                        [
                            (float) $recovery['amount'],
                            (float) $recovery['amount'],
                            (float) $recovery['amount'],
                            (int) $recovery['loan_id'],
                        ]
                    );

                    if ($recovery['visit_id'] !== null) {
                        Database::run(
                            'UPDATE visits SET collected_amount = GREATEST(0, collected_amount - ?) WHERE id = ?',
                            [(float) $recovery['amount'], (int) $recovery['visit_id']]
                        );
                    }
                }
            });
        } catch (\Throwable $e) {
            Logger::error('Recovery review failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The review could not be saved. Please try again.'];
        }

        Audit::log(
            $approve ? 'recovery.verified' : 'recovery.rejected',
            'recovery',
            $recoveryId,
            'Receipt ' . $recovery['receipt_number'] . ($approve ? ' verified' : ' rejected: ' . $reason),
            null,
            null,
            $approve ? 'info' : 'warning'
        );

        return [
            'ok' => true,
            'message' => $approve
                ? 'Collection verified.'
                : 'Collection rejected and the loan balance has been restored.',
        ];
    }

    /**
     * Sequential, human-readable receipt number: RCP-YYYYMMDD-NNNN.
     * Uniqueness is enforced by the unique index; we retry on the rare clash.
     */
    private function nextReceiptNumber(): string
    {
        $prefix = 'RCP-' . date('Ymd') . '-';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $lastSequence = (int) Database::value(
                'SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_number, ?) AS UNSIGNED)), 0)
                 FROM recoveries WHERE receipt_number LIKE ?',
                [strlen($prefix) + 1, $prefix . '%'],
                0
            );

            $candidate = $prefix . str_pad((string) ($lastSequence + 1 + $attempt), 4, '0', STR_PAD_LEFT);
            if (Database::first('SELECT id FROM recoveries WHERE receipt_number = ?', [$candidate]) === null) {
                return $candidate;
            }
        }

        // Guaranteed-unique fallback.
        return $prefix . strtoupper(bin2hex(random_bytes(3)));
    }
}
