<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class AttendanceController extends Controller
{
    protected ?string $permission = 'attendance.view';

    public function index(): void
    {
        $pagination = $this->paginate(50);
        [$scope, $params] = Auth::scopeSql('a.branch_id');

        $where = '1 = 1' . $scope;

        $from = $this->request->str('from', date('Y-m-01'));
        $to = $this->request->str('to', date('Y-m-d'));
        $where .= ' AND a.attendance_date BETWEEN ? AND ?';
        $params[] = date('Y-m-d', strtotime($from) ?: time());
        $params[] = date('Y-m-d', strtotime($to) ?: time());

        // A BC agent only ever sees their own attendance.
        if (Auth::isBcAgent()) {
            $where .= ' AND a.user_id = ?';
            $params[] = Auth::id();
        }

        $userId = $this->request->int('user_id');
        if ($userId > 0 && !Auth::isBcAgent()) {
            $where .= ' AND a.user_id = ?';
            $params[] = $userId;
        }

        $status = $this->request->str('status');
        if (in_array($status, ['present', 'half_day', 'absent', 'leave', 'holiday'], true)) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }

        if ($this->request->str('flag') === 'outside') {
            $where .= ' AND a.is_outside_geofence = 1';
        }

        $total = (int) Database::value('SELECT COUNT(*) FROM attendance a WHERE ' . $where, $params, 0);

        $summary = Database::first(
            'SELECT COUNT(*) AS records,
                    COALESCE(SUM(a.worked_minutes),0) AS minutes,
                    COALESCE(SUM(a.distance_km),0) AS distance,
                    SUM(a.is_outside_geofence = 1) AS outside,
                    SUM(a.check_out_at IS NULL) AS missing_checkout
             FROM attendance a WHERE ' . $where,
            $params
        ) ?? [];

        $records = Database::all(
            'SELECT a.*, u.full_name, bc.bc_code, br.name AS branch_name
             FROM attendance a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN bc_agents bc ON bc.user_id = u.id
             LEFT JOIN branches br ON br.id = a.branch_id
             WHERE ' . $where . '
             ORDER BY a.attendance_date DESC, u.full_name
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $this->view('attendance/index', [
            'pageTitle' => 'Attendance',
            'records'   => $records,
            'meta'      => $this->paginationMeta($total, $pagination),
            'summary'   => $summary,
            'filters'   => [
                'from' => $from, 'to' => $to, 'user_id' => $userId,
                'status' => $status, 'flag' => $this->request->str('flag'),
            ],
            'agents'    => Auth::isBcAgent() ? [] : $this->agentOptions(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function agentOptions(): array
    {
        [$scope, $params] = Auth::scopeSql('u.branch_id');
        return Database::all(
            'SELECT u.id, u.full_name, bc.bc_code FROM users u
             LEFT JOIN bc_agents bc ON bc.user_id = u.id
             WHERE u.status = "active"' . $scope . ' ORDER BY u.full_name',
            $params
        );
    }
}
