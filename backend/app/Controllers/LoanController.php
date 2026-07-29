<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Services\ReportService;
use Lib\Crypto;

final class LoanController extends Controller
{
    protected ?string $permission = 'loans.view';

    public function index(): void
    {
        $pagination = $this->paginate(50);
        [$scope, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');

        $where = 'l.status = "active"' . $scope;

        $search = $this->request->str('search');
        if ($search !== '') {
            $mobileHash = Crypto::blindIndex($search, 'mobile');
            $where .= ' AND (l.account_number LIKE ? OR c.cif_number LIKE ? OR c.full_name LIKE ?'
                . ' OR c.village LIKE ? OR c.mobile_hash = ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $mobileHash);
        }

        $assetClass = $this->request->str('asset_class');
        if (in_array($assetClass, ['STD', 'SMA0', 'SMA1', 'SMA2', 'SS', 'DF1', 'DF2', 'DF3', 'LOSS'], true)) {
            $where .= ' AND l.asset_class = ?';
            $params[] = $assetClass;
        } elseif ($assetClass === 'NPA') {
            $where .= ' AND l.asset_class IN ("SS","DF1","DF2","DF3","LOSS")';
        }

        $recoveryStatus = $this->request->str('recovery_status');
        if ($recoveryStatus !== '') {
            $where .= ' AND l.recovery_status = ?';
            $params[] = $recoveryStatus;
        }

        $branchId = $this->request->int('branch_id');
        if ($branchId > 0) {
            $where .= ' AND l.branch_id = ?';
            $params[] = $branchId;
        }

        $bcId = $this->request->int('bc_id');
        if ($bcId > 0) {
            $where .= ' AND l.bc_id = ?';
            $params[] = $bcId;
        } elseif ($this->request->str('allocation') === 'unallocated') {
            $where .= ' AND l.bc_id IS NULL';
        }

        $total = (int) Database::value(
            'SELECT COUNT(*) FROM loans l JOIN customers c ON c.id = l.customer_id WHERE ' . $where,
            $params,
            0
        );

        $summary = Database::first(
            'SELECT COALESCE(SUM(l.outstanding_amount),0) AS outstanding,
                    COALESCE(SUM(l.overdue_amount),0) AS overdue,
                    COALESCE(SUM(l.total_recovered),0) AS recovered
             FROM loans l JOIN customers c ON c.id = l.customer_id WHERE ' . $where,
            $params
        ) ?? [];

        $loans = Database::all(
            'SELECT l.*, c.full_name, c.cif_number, c.village, c.mobile_last4,
                    b.name AS branch_name, bc.bc_code, u.full_name AS bc_name
             FROM loans l
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN branches b ON b.id = l.branch_id
             LEFT JOIN bc_agents bc ON bc.id = l.bc_id
             LEFT JOIN users u ON u.id = bc.user_id
             WHERE ' . $where . '
             ORDER BY l.overdue_amount DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $this->view('loans/index', [
            'pageTitle' => 'Loan accounts',
            'loans'     => $loans,
            'meta'      => $this->paginationMeta($total, $pagination),
            'summary'   => $summary,
            'filters'   => [
                'search' => $search, 'asset_class' => $assetClass,
                'recovery_status' => $recoveryStatus, 'branch_id' => $branchId,
                'bc_id' => $bcId, 'allocation' => $this->request->str('allocation'),
            ],
            'branches'  => $this->branchOptions(),
            'agents'    => $this->agentOptions(),
        ]);
    }

    public function show(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        [$scope, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');
        array_unshift($params, $id);

        $loan = Database::first(
            'SELECT l.*, c.full_name, c.cif_number, c.guardian_name, c.village, c.district,
                    c.address_line, c.occupation, c.mobile_enc, c.mobile_last4, c.aadhaar_last4,
                    c.latitude AS customer_lat, c.longitude AS customer_lng, c.id AS customer_id,
                    b.name AS branch_name, b.code AS branch_code,
                    bc.bc_code, bc.id AS bc_row_id, u.full_name AS bc_name
             FROM loans l
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN branches b ON b.id = l.branch_id
             LEFT JOIN bc_agents bc ON bc.id = l.bc_id
             LEFT JOIN users u ON u.id = bc.user_id
             WHERE l.id = ?' . $scope . ' LIMIT 1',
            $params
        );

        if ($loan === null) {
            $this->redirect('loans', 'warning', 'That account was not found, or is outside your access scope.');
            return;
        }

        $loan['mobile'] = Crypto::decrypt($loan['mobile_enc'] ?? null);

        $this->view('loans/show', [
            'pageTitle' => 'A/c ' . $loan['account_number'],
            'loan'      => $loan,
            'visits'    => Database::all(
                'SELECT v.*, u.full_name AS agent_name,
                        (SELECT COUNT(*) FROM visit_photos p WHERE p.visit_id = v.id) AS photo_count,
                        (SELECT p.thumb_path FROM visit_photos p WHERE p.visit_id = v.id ORDER BY p.id LIMIT 1) AS thumb
                 FROM visits v LEFT JOIN users u ON u.id = v.user_id
                 WHERE v.loan_id = ? ORDER BY v.visited_at DESC LIMIT 50',
                [$id]
            ),
            'recoveries' => Database::all(
                'SELECT * FROM recoveries WHERE loan_id = ? ORDER BY collected_at DESC LIMIT 50',
                [$id]
            ),
            'followUps' => Database::all(
                'SELECT * FROM follow_ups WHERE loan_id = ? ORDER BY due_date DESC LIMIT 20',
                [$id]
            ),
            'risk'      => Database::first('SELECT * FROM risk_scores WHERE loan_id = ? LIMIT 1', [$id]),
            'agents'    => $this->agentOptions((int) ($loan['branch_id'] ?? 0)),
        ]);
    }

    /** Manual (re)allocation of one account to a BC agent. */
    public function allocate(array $args): void
    {
        $this->authorize('loans.allocate');
        $this->verifyCsrf();

        $id = (int) ($args['id'] ?? 0);
        $bcId = $this->request->int('bc_id');

        $loan = Database::first('SELECT * FROM loans WHERE id = ? LIMIT 1', [$id]);
        if ($loan === null) {
            $this->back('warning', 'That account does not exist.');
            return;
        }

        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)
            && (int) ($loan['branch_id'] ?? 0) !== Auth::branchId()) {
            $this->back('danger', 'You can only allocate accounts in your own branch.');
            return;
        }

        if ($bcId === 0) {
            Database::update('loans', ['bc_id' => null, 'allocated_at' => null], ['id' => $id]);
            Audit::log('loan.unallocated', 'loan', $id,
                'A/c ' . $loan['account_number'] . ' unallocated', null, null, 'notice');
            $this->back('success', 'Account unallocated.');
            return;
        }

        $agent = Database::first(
            'SELECT b.id, b.bc_code, b.branch_id, u.full_name
             FROM bc_agents b JOIN users u ON u.id = b.user_id
             WHERE b.id = ? AND b.status = "active" LIMIT 1',
            [$bcId]
        );
        if ($agent === null) {
            $this->back('danger', 'That BC agent was not found or is inactive.');
            return;
        }

        Database::update('loans', [
            'bc_id'        => $bcId,
            'allocated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        Audit::log('loan.allocated', 'loan', $id,
            'A/c ' . $loan['account_number'] . ' allocated to ' . $agent['full_name'] . ' (' . $agent['bc_code'] . ')',
            ['bc_id' => $loan['bc_id']], ['bc_id' => $bcId], 'notice');

        $this->back('success', 'Account allocated to ' . $agent['full_name'] . '.');
    }

    /** PDF account statement with a QR verification code. */
    public function statement(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        [$scope, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');
        array_unshift($params, $id);

        $exists = Database::first(
            'SELECT l.id FROM loans l WHERE l.id = ?' . $scope . ' LIMIT 1',
            $params
        );
        if ($exists === null) {
            $this->redirect('loans', 'warning', 'That account is outside your access scope.');
            return;
        }

        $result = (new ReportService())->loanStatementPdf($id);

        if (!$result['ok']) {
            $this->back('danger', $result['message']);
            return;
        }

        Response::inlinePdf($result['pdf'], $result['filename']);
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

    /** @return list<array<string,mixed>> */
    private function agentOptions(int $branchId = 0): array
    {
        [$scope, $params] = Auth::scopeSql('b.branch_id', 'b.id');
        $where = 'b.status = "active"' . $scope;

        if ($branchId > 0) {
            $where .= ' AND b.branch_id = ?';
            $params[] = $branchId;
        }

        return Database::all(
            'SELECT b.id, b.bc_code, u.full_name, br.name AS branch_name,
                    (SELECT COUNT(*) FROM loans l WHERE l.bc_id = b.id AND l.status = "active") AS account_load
             FROM bc_agents b
             JOIN users u ON u.id = b.user_id
             LEFT JOIN branches br ON br.id = b.branch_id
             WHERE ' . $where . '
             ORDER BY u.full_name',
            $params
        );
    }
}
