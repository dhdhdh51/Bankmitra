<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

/**
 * GET /sync/bootstrap
 *
 * One call that fills the app's local SQLite cache so a BC agent can work all
 * day in a village with no signal. Deliberately capped so the response cannot
 * grow unbounded on a slow 2G connection.
 */
final class SyncApiController extends ApiController
{
    private const MAX_LOANS = 1000;
    private const MAX_FOLLOWUPS = 500;

    public function bootstrap(): void
    {
        [$scopeSql, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');

        $loanCount = (int) Database::value(
            'SELECT COUNT(*) FROM loans l WHERE l.status = "active"' . $scopeSql,
            $params,
            0
        );

        $loans = Database::all(
            'SELECT l.id AS loan_id, l.account_number, l.outstanding_amount, l.overdue_amount,
                    l.emi_amount, l.asset_class, l.dpd, l.recovery_status, l.last_visit_at,
                    l.visit_count, l.next_followup_date, l.product_name, l.risk_score, l.risk_band,
                    c.id AS customer_id, c.cif_number, c.full_name, c.guardian_name,
                    c.mobile_last4, c.address_line, c.village, c.district, c.occupation,
                    c.latitude, c.longitude
             FROM loans l
             JOIN customers c ON c.id = l.customer_id
             WHERE l.status = "active"' . $scopeSql . '
             ORDER BY (l.last_visit_at IS NULL) DESC, l.overdue_amount DESC
             LIMIT ' . self::MAX_LOANS,
            $params
        );

        $followUpWhere = 'f.status = "pending"';
        $followUpParams = [];
        if ($this->bcId() !== null) {
            $followUpWhere .= ' AND (f.bc_id = ? OR f.assigned_to = ?)';
            $followUpParams = [$this->bcId(), $this->userId()];
        } else {
            [$fScope, $fParams] = Auth::scopeSql('l.branch_id', 'l.bc_id');
            $followUpWhere .= $fScope;
            $followUpParams = $fParams;
        }

        $followUps = Database::all(
            'SELECT f.id, f.loan_id, f.due_date, f.channel, f.promise_amount, f.message,
                    l.account_number, c.full_name
             FROM follow_ups f
             JOIN loans l ON l.id = f.loan_id
             JOIN customers c ON c.id = l.customer_id
             WHERE ' . $followUpWhere . '
             ORDER BY f.due_date ASC
             LIMIT ' . self::MAX_FOLLOWUPS,
            $followUpParams
        );

        $mapped = array_map(static function (array $row): array {
            return [
                'loan_id'            => (int) $row['loan_id'],
                'account_number'     => (string) $row['account_number'],
                'customer_id'        => (int) $row['customer_id'],
                'cif_number'         => (string) $row['cif_number'],
                'full_name'          => (string) $row['full_name'],
                'guardian_name'      => $row['guardian_name'],
                'mobile_masked'      => $row['mobile_last4'] === null ? '-' : '******' . $row['mobile_last4'],
                'address_line'       => $row['address_line'],
                'village'            => $row['village'],
                'district'           => $row['district'],
                'occupation'         => $row['occupation'],
                'product_name'       => $row['product_name'],
                'outstanding_amount' => round((float) $row['outstanding_amount'], 2),
                'overdue_amount'     => round((float) $row['overdue_amount'], 2),
                'emi_amount'         => round((float) $row['emi_amount'], 2),
                'asset_class'        => (string) $row['asset_class'],
                'dpd'                => (int) $row['dpd'],
                'recovery_status'    => (string) $row['recovery_status'],
                'last_visit_at'      => $row['last_visit_at'],
                'visit_count'        => (int) $row['visit_count'],
                'next_followup_date' => $row['next_followup_date'],
                'latitude'           => $row['latitude'] === null ? null : (float) $row['latitude'],
                'longitude'          => $row['longitude'] === null ? null : (float) $row['longitude'],
                'risk_score'         => $row['risk_score'] === null ? null : (int) $row['risk_score'],
                'risk_band'          => $row['risk_band'],
            ];
        }, $loans);

        $truncated = $loanCount > self::MAX_LOANS;

        Response::ok([
            'generated_at' => date('Y-m-d H:i:s'),
            'config'       => $this->configBlock(),
            'user'         => $this->userBlock($this->user ?? []),
            'loans'        => $mapped,
            'followups'    => $followUps,
            'counts'       => [
                'loans'     => count($mapped),
                'loans_total' => $loanCount,
                'followups' => count($followUps),
            ],
            'truncated'    => $truncated,
            'notice'       => $truncated
                ? 'Only the ' . self::MAX_LOANS . ' most urgent accounts were cached. Use search to reach the rest while online.'
                : null,
        ], 'Sync complete.');
    }
}
