<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Services\RecoveryService;
use App\Services\ReportService;

final class RecoveryController extends Controller
{
    protected ?string $permission = 'recoveries.view';

    public function index(): void
    {
        $pagination = $this->paginate(50);
        [$scope, $params] = Auth::scopeSql('r.branch_id', 'r.bc_id');

        $where = '1 = 1' . $scope;

        $from = $this->request->str('from', date('Y-m-01'));
        $to = $this->request->str('to', date('Y-m-d'));
        $where .= ' AND r.collected_at BETWEEN ? AND ?';
        $params[] = date('Y-m-d 00:00:00', strtotime($from) ?: time());
        $params[] = date('Y-m-d 23:59:59', strtotime($to) ?: time());

        $status = $this->request->str('status');
        if (in_array($status, ['pending', 'verified', 'rejected', 'reversed'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }

        $mode = $this->request->str('payment_mode');
        if (in_array($mode, ['cash', 'transfer', 'upi', 'cheque', 'dd', 'other'], true)) {
            $where .= ' AND r.payment_mode = ?';
            $params[] = $mode;
        }

        $bcId = $this->request->int('bc_id');
        if ($bcId > 0) {
            $where .= ' AND r.bc_id = ?';
            $params[] = $bcId;
        }

        $search = $this->request->str('search');
        if ($search !== '') {
            $where .= ' AND (r.receipt_number LIKE ? OR l.account_number LIKE ? OR c.full_name LIKE ?'
                . ' OR r.txn_reference LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $joins = 'FROM recoveries r
                  JOIN loans l ON l.id = r.loan_id
                  JOIN customers c ON c.id = r.customer_id';

        $total = (int) Database::value('SELECT COUNT(*) ' . $joins . ' WHERE ' . $where, $params, 0);

        $summary = Database::first(
            'SELECT COALESCE(SUM(CASE WHEN r.status <> "rejected" THEN r.amount ELSE 0 END),0) AS total_amount,
                    COALESCE(SUM(CASE WHEN r.status = "pending" THEN r.amount ELSE 0 END),0) AS pending_amount,
                    COALESCE(SUM(CASE WHEN r.status = "verified" THEN r.amount ELSE 0 END),0) AS verified_amount,
                    SUM(r.status = "pending") AS pending_count
             ' . $joins . ' WHERE ' . $where,
            $params
        ) ?? [];

        $recoveries = Database::all(
            'SELECT r.*, l.account_number, c.full_name, c.village, bc.bc_code,
                    u.full_name AS bc_name, vu.full_name AS verified_by_name
             ' . $joins . '
             LEFT JOIN bc_agents bc ON bc.id = r.bc_id
             LEFT JOIN users u ON u.id = bc.user_id
             LEFT JOIN users vu ON vu.id = r.verified_by
             WHERE ' . $where . '
             ORDER BY FIELD(r.status, "pending") DESC, r.collected_at DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $this->view('recoveries/index', [
            'pageTitle'   => 'Recoveries',
            'recoveries'  => $recoveries,
            'meta'        => $this->paginationMeta($total, $pagination),
            'summary'     => $summary,
            'filters'     => [
                'from' => $from, 'to' => $to, 'status' => $status,
                'payment_mode' => $mode, 'bc_id' => $bcId, 'search' => $search,
            ],
            'agents'      => $this->agentOptions(),
            'canVerify'   => Auth::can('recoveries.verify'),
        ]);
    }

    public function verify(array $args): void
    {
        $this->authorize('recoveries.verify');
        $this->verifyCsrf();

        $id = (int) ($args['id'] ?? 0);
        if (!$this->inScope($id)) {
            $this->back('danger', 'That collection is outside your access scope.');
            return;
        }

        $result = (new RecoveryService())->review($id, (int) Auth::id(), true);
        $this->back($result['ok'] ? 'success' : 'warning', $result['message']);
    }

    public function reject(array $args): void
    {
        $this->authorize('recoveries.verify');
        $this->verifyCsrf();

        $id = (int) ($args['id'] ?? 0);
        if (!$this->inScope($id)) {
            $this->back('danger', 'That collection is outside your access scope.');
            return;
        }

        $reason = $this->request->str('reason');
        if ($reason === '') {
            $this->back('danger', 'Give a reason when rejecting a collection - the BC agent will see it.');
            return;
        }

        $result = (new RecoveryService())->review($id, (int) Auth::id(), false, $reason);
        $this->back($result['ok'] ? 'success' : 'warning', $result['message']);
    }

    /** A5 PDF receipt with a QR verification code. */
    public function receipt(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$this->inScope($id)) {
            $this->redirect('recoveries', 'warning', 'That receipt is outside your access scope.');
            return;
        }

        $result = (new ReportService())->receiptPdf($id);
        if (!$result['ok']) {
            $this->back('danger', $result['message']);
            return;
        }

        Response::inlinePdf($result['pdf'], $result['filename']);
    }

    private function inScope(int $recoveryId): bool
    {
        [$scope, $params] = Auth::scopeSql('r.branch_id', 'r.bc_id');
        array_unshift($params, $recoveryId);

        return Database::first(
            'SELECT r.id FROM recoveries r WHERE r.id = ?' . $scope . ' LIMIT 1',
            $params
        ) !== null;
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
