<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use Lib\Crypto;

/**
 * Customer directory and profile. Search covers account number, CIF, mobile,
 * name, village and branch, as required by the spec.
 */
final class CustomerController extends Controller
{
    protected ?string $permission = 'customers.view';

    public function index(): void
    {
        $pagination = $this->paginate();
        [$scope, $params] = Auth::scopeSql('c.branch_id');

        $where = '1 = 1' . $scope;

        $search = $this->request->str('search');
        if ($search !== '') {
            $mobileHash = Crypto::blindIndex($search, 'mobile');
            $where .= ' AND (c.full_name LIKE ? OR c.cif_number LIKE ? OR c.village LIKE ?'
                . ' OR c.mobile_hash = ? OR c.alt_mobile_hash = ?'
                . ' OR EXISTS (SELECT 1 FROM loans lx WHERE lx.customer_id = c.id AND lx.account_number LIKE ?))';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $mobileHash, $mobileHash, $like);
        }

        $village = $this->request->str('village');
        if ($village !== '') {
            $where .= ' AND c.village = ?';
            $params[] = $village;
        }

        $branchId = $this->request->int('branch_id');
        if ($branchId > 0) {
            $where .= ' AND c.branch_id = ?';
            $params[] = $branchId;
        }

        $total = (int) Database::value('SELECT COUNT(*) FROM customers c WHERE ' . $where, $params, 0);

        $customers = Database::all(
            'SELECT c.id, c.cif_number, c.full_name, c.guardian_name, c.mobile_last4, c.village,
                    c.district, c.occupation, c.status, b.name AS branch_name,
                    (SELECT COUNT(*) FROM loans l WHERE l.customer_id = c.id AND l.status = "active") AS loan_count,
                    (SELECT COALESCE(SUM(l.outstanding_amount),0) FROM loans l WHERE l.customer_id = c.id AND l.status = "active") AS outstanding,
                    (SELECT COALESCE(SUM(l.overdue_amount),0) FROM loans l WHERE l.customer_id = c.id AND l.status = "active") AS overdue
             FROM customers c
             LEFT JOIN branches b ON b.id = c.branch_id
             WHERE ' . $where . '
             ORDER BY overdue DESC, c.full_name
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $this->view('customers/index', [
            'pageTitle' => 'Customers',
            'customers' => $customers,
            'meta'      => $this->paginationMeta($total, $pagination),
            'filters'   => ['search' => $search, 'village' => $village, 'branch_id' => $branchId],
            'branches'  => $this->branchOptions(),
            'villages'  => Database::all(
                'SELECT DISTINCT village FROM customers WHERE village IS NOT NULL AND village <> "" ORDER BY village LIMIT 300'
            ),
        ]);
    }

    public function show(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        [$scope, $params] = Auth::scopeSql('c.branch_id');
        array_unshift($params, $id);

        $customer = Database::first(
            'SELECT c.*, b.name AS branch_name, b.code AS branch_code
             FROM customers c
             LEFT JOIN branches b ON b.id = c.branch_id
             WHERE c.id = ?' . $scope . ' LIMIT 1',
            $params
        );

        if ($customer === null) {
            $this->redirect('customers', 'warning', 'That customer was not found, or is outside your access scope.');
            return;
        }

        // Decrypting PII is a deliberate, audited action.
        $customer['mobile'] = Crypto::decrypt($customer['mobile_enc'] ?? null);
        $customer['alt_mobile'] = Crypto::decrypt($customer['alt_mobile_enc'] ?? null);

        Audit::log('customer.viewed', 'customer', $id,
            'Viewed customer profile (contact details decrypted)');

        $loans = Database::all(
            'SELECT l.*, bc.bc_code, u.full_name AS bc_name
             FROM loans l
             LEFT JOIN bc_agents bc ON bc.id = l.bc_id
             LEFT JOIN users u ON u.id = bc.user_id
             WHERE l.customer_id = ? ORDER BY l.overdue_amount DESC',
            [$id]
        );

        $visits = Database::all(
            'SELECT v.id, v.visited_at, v.visit_status, v.promise_amount, v.promise_date,
                    v.collected_amount, v.remarks, v.latitude, v.longitude,
                    l.account_number, u.full_name AS agent_name,
                    (SELECT p.thumb_path FROM visit_photos p WHERE p.visit_id = v.id ORDER BY p.id LIMIT 1) AS thumb
             FROM visits v
             JOIN loans l ON l.id = v.loan_id
             LEFT JOIN users u ON u.id = v.user_id
             WHERE v.customer_id = ? ORDER BY v.visited_at DESC LIMIT 50',
            [$id]
        );

        $recoveries = Database::all(
            'SELECT r.*, l.account_number FROM recoveries r
             JOIN loans l ON l.id = r.loan_id
             WHERE r.customer_id = ? ORDER BY r.collected_at DESC LIMIT 50',
            [$id]
        );

        $this->view('customers/show', [
            'pageTitle'  => $customer['full_name'],
            'customer'   => $customer,
            'loans'      => $loans,
            'visits'     => $visits,
            'recoveries' => $recoveries,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function branchOptions(): array
    {
        [$scope, $params] = Auth::scopeSql('br.id');
        return Database::all(
            'SELECT br.id, br.code, br.name FROM branches br WHERE br.status = "active"' . $scope . ' ORDER BY br.name',
            $params
        );
    }
}
