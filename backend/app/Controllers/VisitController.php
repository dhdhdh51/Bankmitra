<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Services\ReportService;

final class VisitController extends Controller
{
    protected ?string $permission = 'visits.view';

    public function index(): void
    {
        $pagination = $this->paginate(50);
        [$scope, $params] = Auth::scopeSql('v.branch_id', 'v.bc_id');

        $where = '1 = 1' . $scope;

        $from = $this->request->str('from', date('Y-m-01'));
        $to = $this->request->str('to', date('Y-m-d'));
        $where .= ' AND v.visit_date BETWEEN ? AND ?';
        $params[] = date('Y-m-d', strtotime($from) ?: time());
        $params[] = date('Y-m-d', strtotime($to) ?: time());

        $status = $this->request->str('status');
        if ($status !== '') {
            $where .= ' AND v.visit_status = ?';
            $params[] = $status;
        }

        $bcId = $this->request->int('bc_id');
        if ($bcId > 0) {
            $where .= ' AND v.bc_id = ?';
            $params[] = $bcId;
        }

        $branchId = $this->request->int('branch_id');
        if ($branchId > 0) {
            $where .= ' AND v.branch_id = ?';
            $params[] = $branchId;
        }

        $search = $this->request->str('search');
        if ($search !== '') {
            $where .= ' AND (l.account_number LIKE ? OR c.full_name LIKE ? OR c.village LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        if ($this->request->str('flag') === 'suspicious') {
            $where .= ' AND (v.is_mock_location = 1 OR v.distance_from_customer_m > 1000)';
        }

        $total = (int) Database::value(
            'SELECT COUNT(*) FROM visits v
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             WHERE ' . $where,
            $params,
            0
        );

        $summary = Database::first(
            'SELECT COALESCE(SUM(v.promise_amount),0) AS promise,
                    COALESCE(SUM(v.collected_amount),0) AS collected,
                    SUM(v.is_mock_location = 1) AS mock_count
             FROM visits v
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             WHERE ' . $where,
            $params
        ) ?? [];

        $visits = Database::all(
            'SELECT v.*, l.account_number, c.full_name, c.village, bc.bc_code,
                    u.full_name AS agent_name,
                    (SELECT COUNT(*) FROM visit_photos p WHERE p.visit_id = v.id) AS photo_count,
                    (SELECT p.thumb_path FROM visit_photos p WHERE p.visit_id = v.id ORDER BY p.id LIMIT 1) AS thumb
             FROM visits v
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             LEFT JOIN bc_agents bc ON bc.id = v.bc_id
             LEFT JOIN users u ON u.id = v.user_id
             WHERE ' . $where . '
             ORDER BY v.visited_at DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $this->view('visits/index', [
            'pageTitle' => 'Visits',
            'visits'    => $visits,
            'meta'      => $this->paginationMeta($total, $pagination),
            'summary'   => $summary,
            'filters'   => [
                'from' => $from, 'to' => $to, 'status' => $status,
                'bc_id' => $bcId, 'branch_id' => $branchId,
                'search' => $search, 'flag' => $this->request->str('flag'),
            ],
            'branches'  => $this->branchOptions(),
            'agents'    => $this->agentOptions(),
        ]);
    }

    public function show(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        [$scope, $params] = Auth::scopeSql('v.branch_id', 'v.bc_id');
        array_unshift($params, $id);

        $visit = Database::first(
            'SELECT v.*, l.account_number, l.outstanding_amount, l.overdue_amount, l.asset_class, l.dpd,
                    l.id AS loan_id, c.id AS customer_id, c.full_name, c.guardian_name, c.village,
                    c.district, c.address_line, c.mobile_last4,
                    c.latitude AS customer_lat, c.longitude AS customer_lng,
                    br.name AS branch_name, bc.bc_code, u.full_name AS agent_name,
                    vu.full_name AS verified_by_name
             FROM visits v
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             LEFT JOIN branches br ON br.id = v.branch_id
             LEFT JOIN bc_agents bc ON bc.id = v.bc_id
             LEFT JOIN users u ON u.id = v.user_id
             LEFT JOIN users vu ON vu.id = v.verified_by
             WHERE v.id = ?' . $scope . ' LIMIT 1',
            $params
        );

        if ($visit === null) {
            $this->redirect('visits', 'warning', 'That visit was not found, or is outside your access scope.');
            return;
        }

        $this->view('visits/show', [
            'pageTitle' => 'Visit - A/c ' . $visit['account_number'],
            'visit'     => $visit,
            'photos'    => Database::all(
                'SELECT * FROM visit_photos WHERE visit_id = ? ORDER BY id',
                [$id]
            ),
            'recoveries' => Database::all(
                'SELECT * FROM recoveries WHERE visit_id = ? ORDER BY collected_at DESC',
                [$id]
            ),
            'mapsKey'   => \Lib\Settings::getString('maps.api_key', ''),
        ]);
    }

    /** Branch Manager marks a visit as verified. */
    public function verify(array $args): void
    {
        $this->authorize('visits.verify');
        $this->verifyCsrf();

        $id = (int) ($args['id'] ?? 0);
        $visit = Database::first('SELECT * FROM visits WHERE id = ? LIMIT 1', [$id]);

        if ($visit === null) {
            $this->back('warning', 'That visit does not exist.');
            return;
        }

        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)
            && (int) ($visit['branch_id'] ?? 0) !== Auth::branchId()) {
            $this->back('danger', 'You can only verify visits in your own branch.');
            return;
        }

        if ($visit['verified_at'] !== null) {
            $this->back('info', 'This visit was already verified.');
            return;
        }

        Database::update('visits', [
            'verified_by' => Auth::id(),
            'verified_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        Audit::log('visit.verified', 'visit', $id, 'Visit verified by supervisor', null, null, 'notice');

        $this->back('success', 'Visit marked as verified.');
    }

    /** PDF visit report with photos and a QR verification code. */
    public function pdf(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        [$scope, $params] = Auth::scopeSql('v.branch_id', 'v.bc_id');
        array_unshift($params, $id);

        $exists = Database::first('SELECT v.id FROM visits v WHERE v.id = ?' . $scope . ' LIMIT 1', $params);
        if ($exists === null) {
            $this->redirect('visits', 'warning', 'That visit is outside your access scope.');
            return;
        }

        $result = (new ReportService())->visitReportPdf($id);

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
    private function agentOptions(): array
    {
        [$scope, $params] = Auth::scopeSql('b.branch_id', 'b.id');
        return Database::all(
            'SELECT b.id, b.bc_code, u.full_name FROM bc_agents b
             JOIN users u ON u.id = b.user_id
             WHERE b.status = "active"' . $scope . ' ORDER BY u.full_name',
            $params
        );
    }
}
