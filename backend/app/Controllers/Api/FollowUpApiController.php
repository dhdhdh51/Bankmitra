<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

/**
 * Promise follow-ups and reminders.
 */
final class FollowUpApiController extends ApiController
{
    /** GET /followups */
    public function index(): void
    {
        $pagination = $this->pagination();
        $today = date('Y-m-d');

        $where = 'f.status = "pending"';
        $params = [];

        if ($this->bcId() !== null) {
            $where .= ' AND (f.bc_id = ? OR f.assigned_to = ?)';
            $params[] = $this->bcId();
            $params[] = $this->userId();
        } else {
            [$scopeSql, $scopeParams] = Auth::scopeSql('l.branch_id', 'l.bc_id');
            $where .= $scopeSql;
            $params = array_merge($params, $scopeParams);
        }

        switch ($this->request->str('due', 'all')) {
            case 'today':
                $where .= ' AND f.due_date = ?';
                $params[] = $today;
                break;
            case 'overdue':
                $where .= ' AND f.due_date < ?';
                $params[] = $today;
                break;
            case 'week':
                $where .= ' AND f.due_date BETWEEN ? AND ?';
                $params[] = $today;
                $params[] = date('Y-m-d', strtotime('+7 days'));
                break;
            default:
                break;
        }

        $total = (int) Database::value(
            'SELECT COUNT(*) FROM follow_ups f JOIN loans l ON l.id = f.loan_id WHERE ' . $where,
            $params,
            0
        );

        $rows = Database::all(
            'SELECT f.id, f.due_date, f.channel, f.promise_amount, f.message, f.status,
                    f.reminder_sent_at,
                    l.id AS loan_id, l.account_number, l.outstanding_amount, l.overdue_amount,
                    c.full_name, c.village, c.mobile_last4
             FROM follow_ups f
             JOIN loans l ON l.id = f.loan_id
             JOIN customers c ON c.id = l.customer_id
             WHERE ' . $where . '
             ORDER BY f.due_date ASC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $data = array_map(static function (array $row) use ($today): array {
            return [
                'id'                 => (int) $row['id'],
                'loan_id'            => (int) $row['loan_id'],
                'account_number'     => (string) $row['account_number'],
                'customer_name'      => (string) $row['full_name'],
                'village'            => $row['village'],
                'mobile_masked'      => $row['mobile_last4'] === null ? '-' : '******' . $row['mobile_last4'],
                'due_date'           => $row['due_date'],
                'is_overdue'         => (string) $row['due_date'] < $today,
                'channel'            => (string) $row['channel'],
                'promise_amount'     => round((float) $row['promise_amount'], 2),
                'outstanding_amount' => round((float) $row['outstanding_amount'], 2),
                'overdue_amount'     => round((float) $row['overdue_amount'], 2),
                'message'            => $row['message'],
                'reminder_sent_at'   => $row['reminder_sent_at'],
            ];
        }, $rows);

        Response::ok($data, 'OK', $this->meta($total, $pagination));
    }

    /** POST /followups/{id}/complete */
    public function complete(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);

        $followUp = Database::first(
            'SELECT f.*, l.branch_id FROM follow_ups f
             JOIN loans l ON l.id = f.loan_id
             WHERE f.id = ? LIMIT 1',
            [$id]
        );

        if ($followUp === null) {
            Response::fail('That follow-up no longer exists.', 'not_found', 404);
            return;
        }

        // A BC agent may only close their own follow-ups.
        if ($this->bcId() !== null
            && (int) ($followUp['bc_id'] ?? 0) !== $this->bcId()
            && (int) ($followUp['assigned_to'] ?? 0) !== $this->userId()) {
            Response::fail('This follow-up is not assigned to you.', 'forbidden', 403);
            return;
        }

        if ($followUp['status'] !== 'pending') {
            Response::ok(null, 'This follow-up was already marked as ' . $followUp['status'] . '.');
            return;
        }

        $remarks = $this->request->str('remarks');

        Database::update('follow_ups', [
            'status'       => 'done',
            'completed_at' => date('Y-m-d H:i:s'),
            'message'      => $remarks !== ''
                ? substr(((string) $followUp['message']) . ' | ' . $remarks, 0, 500)
                : $followUp['message'],
        ], ['id' => $id]);

        Audit::api('followup.completed', 'follow_up', $id, $remarks !== '' ? $remarks : 'Marked done');

        Response::ok(null, 'Follow-up marked as done.');
    }
}
