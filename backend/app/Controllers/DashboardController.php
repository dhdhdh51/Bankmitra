<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Services\DashboardService;
use Lib\Settings;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $service = new DashboardService();

        $stats = Auth::isBcAgent() && Auth::bcId() !== null
            ? $service->forBcAgent((int) Auth::bcId(), (int) Auth::id())
            : $service->forSupervisor((int) Auth::id());

        $this->view('dashboard/index', [
            'pageTitle'     => 'Dashboard',
            'stats'         => $stats,
            'missingConfig' => Settings::missingConfiguration(),
            'bcRanking'     => Auth::isBcAgent() ? [] : $service->bcRanking(8),
            'branchRanking' => Auth::isBcAgent() ? [] : $service->branchRanking(8),
            'recentVisits'  => $this->recentVisits(),
            'pendingUsers'  => Auth::can('users.view')
                ? Database::all(
                    'SELECT u.id, u.full_name, u.employee_code, u.created_at, r.name AS role_name, b.name AS branch_name
                     FROM users u JOIN roles r ON r.id = u.role_id
                     LEFT JOIN branches b ON b.id = u.branch_id
                     WHERE u.status = "pending" ORDER BY u.created_at DESC LIMIT 5'
                )
                : [],
            'cronStatus'    => $this->cronStatus(),
        ]);
    }

    /** JSON feed for the Chart.js graphs. */
    public function charts(): void
    {
        $service = new DashboardService();

        Response::ok([
            'trend'       => $service->trend(14),
            'asset_split' => $service->assetClassSplit(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function recentVisits(): array
    {
        [$scope, $params] = Auth::scopeSql('v.branch_id', 'v.bc_id');

        return Database::all(
            'SELECT v.id, v.visited_at, v.visit_status, v.promise_amount, v.collected_amount,
                    l.account_number, c.full_name, c.village, u.full_name AS agent_name
             FROM visits v
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             LEFT JOIN users u ON u.id = v.user_id
             WHERE 1 = 1' . $scope . '
             ORDER BY v.visited_at DESC LIMIT 8',
            $params
        );
    }

    /**
     * Surface whether the cPanel cron job is actually firing. Without this the
     * reminders/backup jobs can silently stop and nobody notices.
     *
     * @return array{configured:bool,last_run:?string,status:?string,stale:bool}
     */
    private function cronStatus(): array
    {
        $row = Database::first('SELECT job, started_at, status FROM cron_runs ORDER BY id DESC LIMIT 1');

        if ($row === null) {
            return ['configured' => false, 'last_run' => null, 'status' => null, 'stale' => true];
        }

        $lastRun = (string) $row['started_at'];
        $stale = strtotime($lastRun) < time() - 36 * 3600;

        return [
            'configured' => true,
            'last_run'   => $lastRun,
            'status'     => (string) $row['status'],
            'stale'      => $stale,
        ];
    }
}
