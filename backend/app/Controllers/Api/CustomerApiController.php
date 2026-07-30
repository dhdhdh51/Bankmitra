<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\PhotoStorageService;
use Lib\Crypto;

/**
 * The agent's allocated accounts and one account's full profile.
 */
final class CustomerApiController extends ApiController
{
    /** GET /customers */
    public function index(): void
    {
        $pagination = $this->pagination();
        $search = $this->request->str('search');
        $village = $this->request->str('village');
        $status = $this->request->str('status');

        [$scopeSql, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');
        $where = 'l.status = "active"' . $scopeSql;

        if ($search !== '') {
            // Encrypted mobile is matched through its blind index, everything
            // else with a LIKE. This is why customers.mobile_hash exists.
            $mobileHash = Crypto::blindIndex($search, 'mobile');
            $where .= ' AND (l.account_number LIKE ? OR c.cif_number LIKE ? OR c.full_name LIKE ?'
                . ' OR c.village LIKE ? OR c.mobile_hash = ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $mobileHash);
        }

        if ($village !== '') {
            $where .= ' AND c.village = ?';
            $params[] = $village;
        }

        if ($status !== '') {
            $where .= ' AND l.recovery_status = ?';
            $params[] = $status;
        }

        $total = (int) Database::value(
            'SELECT COUNT(*) FROM loans l JOIN customers c ON c.id = l.customer_id WHERE ' . $where,
            $params,
            0
        );

        $rows = Database::all(
            'SELECT l.id AS loan_id, l.account_number, l.outstanding_amount, l.overdue_amount,
                    l.asset_class, l.dpd, l.recovery_status, l.last_visit_at, l.visit_count,
                    l.next_followup_date, l.emi_amount, l.product_name, l.risk_score, l.risk_band,
                    c.id AS customer_id, c.cif_number, c.full_name, c.guardian_name,
                    c.mobile_last4, c.village, c.district, c.address_line,
                    c.latitude, c.longitude, c.occupation
             FROM loans l
             JOIN customers c ON c.id = l.customer_id
             WHERE ' . $where . '
             ORDER BY (l.last_visit_at IS NULL) DESC, l.overdue_amount DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $data = array_map(static function (array $row): array {
            return [
                'loan_id'            => (int) $row['loan_id'],
                'account_number'     => (string) $row['account_number'],
                'customer_id'        => (int) $row['customer_id'],
                'cif_number'         => (string) $row['cif_number'],
                'full_name'          => (string) $row['full_name'],
                'guardian_name'      => $row['guardian_name'],
                'mobile_masked'      => $row['mobile_last4'] === null ? '-' : '******' . $row['mobile_last4'],
                'village'            => $row['village'],
                'district'           => $row['district'],
                'address_line'       => $row['address_line'],
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
        }, $rows);

        Response::ok($data, 'OK', $this->meta($total, $pagination));
    }

    /** GET /loans/{id} */
    public function show(array $args): void
    {
        $loanId = (int) ($args['id'] ?? 0);

        [$scopeSql, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');
        array_unshift($params, $loanId);

        $loan = Database::first(
            'SELECT l.*, c.*, l.id AS loan_id, c.id AS customer_id,
                    br.name AS branch_name, br.code AS branch_code,
                    bc.bc_code, bu.full_name AS bc_name
             FROM loans l
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN branches br ON br.id = l.branch_id
             LEFT JOIN bc_agents bc ON bc.id = l.bc_id
             LEFT JOIN users bu ON bu.id = bc.user_id
             WHERE l.id = ?' . $scopeSql . ' LIMIT 1',
            $params
        );

        if ($loan === null) {
            Response::fail('That account was not found, or it is not allocated to you.', 'not_found', 404);
            return;
        }

        $visits = Database::all(
            'SELECT v.id, v.visit_uid, v.visit_date, v.visited_at, v.visit_status,
                    v.promise_amount, v.promise_date, v.collected_amount, v.remarks,
                    v.latitude, v.longitude, v.distance_from_customer_m,
                    (SELECT COUNT(*) FROM visit_photos p WHERE p.visit_id = v.id) AS photo_count,
                    (SELECT p.thumb_path FROM visit_photos p WHERE p.visit_id = v.id ORDER BY p.id LIMIT 1) AS thumb
             FROM visits v WHERE v.loan_id = ? ORDER BY v.visited_at DESC LIMIT 20',
            [$loanId]
        );

        $recoveries = Database::all(
            'SELECT id, receipt_number, amount, payment_mode, txn_reference,
                    collected_at, status, reject_reason
             FROM recoveries WHERE loan_id = ? ORDER BY collected_at DESC LIMIT 20',
            [$loanId]
        );

        $followUps = Database::all(
            'SELECT id, due_date, channel, promise_amount, message, status
             FROM follow_ups WHERE loan_id = ? AND status = "pending" ORDER BY due_date LIMIT 20',
            [$loanId]
        );

        Response::ok([
            'loan' => [
                'id'                 => (int) $loan['loan_id'],
                // Also expose it under the same name the list endpoint uses.
                // The app flattens loan + customer into one lookup, so a bare
                // "id" is ambiguous - customer.id used to win and the loan id was
                // lost, which left the visit form with loan_id 0 and the message
                // "this visit has no loan attached".
                'loan_id'            => (int) $loan['loan_id'],
                'account_number'     => (string) $loan['account_number'],
                'product_name'       => $loan['product_name'],
                'scheme_code'        => $loan['scheme_code'],
                'sanction_amount'    => round((float) $loan['sanction_amount'], 2),
                'disbursed_amount'   => round((float) $loan['disbursed_amount'], 2),
                'outstanding_amount' => round((float) $loan['outstanding_amount'], 2),
                'overdue_amount'     => round((float) $loan['overdue_amount'], 2),
                'principal_overdue'  => round((float) $loan['principal_overdue'], 2),
                'interest_overdue'   => round((float) $loan['interest_overdue'], 2),
                'emi_amount'         => round((float) $loan['emi_amount'], 2),
                'total_recovered'    => round((float) $loan['total_recovered'], 2),
                'disbursement_date'  => $loan['disbursement_date'],
                'maturity_date'      => $loan['maturity_date'],
                'npa_date'           => $loan['npa_date'],
                'asset_class'        => (string) $loan['asset_class'],
                'dpd'                => (int) $loan['dpd'],
                'last_paid_date'     => $loan['last_paid_date'],
                'last_paid_amount'   => round((float) $loan['last_paid_amount'], 2),
                'recovery_status'    => (string) $loan['recovery_status'],
                'visit_count'        => (int) $loan['visit_count'],
                'last_visit_at'      => $loan['last_visit_at'],
                'next_followup_date' => $loan['next_followup_date'],
                'risk_score'         => $loan['risk_score'] === null ? null : (int) $loan['risk_score'],
                'risk_band'          => $loan['risk_band'],
                'branch_name'        => $loan['branch_name'],
                'branch_code'        => $loan['branch_code'],
                'bc_code'            => $loan['bc_code'],
                'bc_name'            => $loan['bc_name'],
            ],
            'customer' => [
                'id'            => (int) $loan['customer_id'],
                // Unambiguous alias, for the same reason as loan_id above.
                'customer_id'   => (int) $loan['customer_id'],
                'cif_number'    => (string) $loan['cif_number'],
                'full_name'     => (string) $loan['full_name'],
                'guardian_name' => $loan['guardian_name'],
                'gender'        => $loan['gender'],
                // The field agent legitimately needs to phone the borrower, so
                // the mobile is decrypted here (and this call is audited).
                'mobile'        => Crypto::decrypt($loan['mobile_enc'] ?? null),
                'alt_mobile'    => Crypto::decrypt($loan['alt_mobile_enc'] ?? null),
                'aadhaar_last4' => $loan['aadhaar_last4'],
                'address_line'  => $loan['address_line'],
                'village'       => $loan['village'],
                'panchayat'     => $loan['panchayat'],
                'block'         => $loan['block'],
                'district'      => $loan['district'],
                'state'         => $loan['state'],
                'pincode'       => $loan['pincode'],
                'occupation'    => $loan['occupation'],
                'latitude'      => $loan['latitude'] === null ? null : (float) $loan['latitude'],
                'longitude'     => $loan['longitude'] === null ? null : (float) $loan['longitude'],
                'status'        => (string) $loan['status'],
            ],
            'visits' => array_map(static function (array $v): array {
                return [
                    'id'                => (int) $v['id'],
                    'visit_date'        => $v['visit_date'],
                    'visited_at'        => $v['visited_at'],
                    'visit_status'      => $v['visit_status'],
                    'promise_amount'    => round((float) $v['promise_amount'], 2),
                    'promise_date'      => $v['promise_date'],
                    'collected_amount'  => round((float) $v['collected_amount'], 2),
                    'remarks'           => $v['remarks'],
                    'latitude'          => (float) $v['latitude'],
                    'longitude'         => (float) $v['longitude'],
                    'distance_m'        => $v['distance_from_customer_m'] === null ? null : (int) $v['distance_from_customer_m'],
                    'photo_count'       => (int) $v['photo_count'],
                    'thumb_url'         => PhotoStorageService::url($v['thumb']),
                ];
            }, $visits),
            'recoveries' => array_map(static function (array $r): array {
                return [
                    'id'             => (int) $r['id'],
                    'receipt_number' => (string) $r['receipt_number'],
                    'amount'         => round((float) $r['amount'], 2),
                    'payment_mode'   => (string) $r['payment_mode'],
                    'txn_reference'  => $r['txn_reference'],
                    'collected_at'   => $r['collected_at'],
                    'status'         => (string) $r['status'],
                    'reject_reason'  => $r['reject_reason'],
                ];
            }, $recoveries),
            'followups' => $followUps,
        ]);
    }
}
