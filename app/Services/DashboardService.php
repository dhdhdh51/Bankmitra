<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * All dashboard aggregation lives here so the web panel and the mobile API
 * always show the same numbers.
 */
final class DashboardService
{
    /**
     * Figures for one BC agent.
     * @return array<string,mixed>
     */
    public function forBcAgent(int $bcId, int $userId): array
    {
        $today = date('Y-m-d');

        $assigned = (int) Database::value(
            'SELECT COUNT(*) FROM loans WHERE bc_id = ? AND status = "active"',
            [$bcId],
            0
        );

        $visitedToday = (int) Database::value(
            'SELECT COUNT(DISTINCT loan_id) FROM visits WHERE bc_id = ? AND visit_date = ?',
            [$bcId, $today],
            0
        );

        $promise = (int) Database::value(
            'SELECT COUNT(*) FROM loans WHERE bc_id = ? AND recovery_status = "promise" AND status = "active"',
            [$bcId],
            0
        );

        $ots = (int) Database::value(
            'SELECT COUNT(*) FROM loans WHERE bc_id = ? AND recovery_status = "ots" AND status = "active"',
            [$bcId],
            0
        );

        $recoveryToday = (float) Database::value(
            'SELECT COALESCE(SUM(amount),0) FROM recoveries
             WHERE bc_id = ? AND DATE(collected_at) = ? AND status <> "rejected"',
            [$bcId, $today],
            0
        );

        $recoveryMonth = (float) Database::value(
            'SELECT COALESCE(SUM(amount),0) FROM recoveries
             WHERE bc_id = ? AND collected_at >= ? AND status <> "rejected"',
            [$bcId, date('Y-m-01 00:00:00')],
            0
        );

        $target = (float) Database::value(
            'SELECT COALESCE(monthly_target,0) FROM bc_agents WHERE id = ?',
            [$bcId],
            0
        );

        $followupsDue = (int) Database::value(
            'SELECT COUNT(*) FROM follow_ups
             WHERE bc_id = ? AND status = "pending" AND due_date <= ?',
            [$bcId, $today],
            0
        );

        $npa = (int) Database::value(
            'SELECT COUNT(*) FROM loans
             WHERE bc_id = ? AND status = "active" AND asset_class IN ("SS","DF1","DF2","DF3","LOSS")',
            [$bcId],
            0
        );

        $outstanding = (float) Database::value(
            'SELECT COALESCE(SUM(outstanding_amount),0) FROM loans WHERE bc_id = ? AND status = "active"',
            [$bcId],
            0
        );

        return [
            'scope'                => 'bc_agent',
            'assigned_accounts'    => $assigned,
            'visited_today'        => $visitedToday,
            'pending_today'        => max(0, $assigned - $visitedToday),
            'promise_count'        => $promise,
            'ots_count'            => $ots,
            'npa_count'            => $npa,
            'total_outstanding'    => round($outstanding, 2),
            'recovery_today'       => round($recoveryToday, 2),
            'recovery_month'       => round($recoveryMonth, 2),
            'monthly_target'       => round($target, 2),
            'target_achieved_pct'  => $target > 0 ? round(($recoveryMonth / $target) * 100, 2) : 0.0,
            'followups_due'        => $followupsDue,
            'attendance'           => $this->attendanceToday($userId),
        ];
    }

    /**
     * Figures for Branch Manager / Regional Office / Super Admin, scoped by role.
     * @return array<string,mixed>
     */
    public function forSupervisor(int $userId): array
    {
        $today = date('Y-m-d');
        [$scopeSql, $scopeParams] = Auth::scopeSql('l.branch_id');

        $counts = Database::first(
            'SELECT
                COUNT(*) AS total_accounts,
                COALESCE(SUM(l.outstanding_amount),0) AS total_outstanding,
                COALESCE(SUM(l.overdue_amount),0) AS total_overdue,
                COALESCE(SUM(l.total_recovered),0) AS total_recovered,
                SUM(CASE WHEN l.asset_class IN ("SS","DF1","DF2","DF3","LOSS") THEN 1 ELSE 0 END) AS npa_count,
                SUM(CASE WHEN l.recovery_status = "promise" THEN 1 ELSE 0 END) AS promise_count,
                SUM(CASE WHEN l.recovery_status = "ots" THEN 1 ELSE 0 END) AS ots_count
             FROM loans l
             WHERE l.status = "active"' . $scopeSql,
            $scopeParams
        ) ?? [];

        [$visitScope, $visitParams] = Auth::scopeSql('v.branch_id');
        $visitedToday = (int) Database::value(
            'SELECT COUNT(*) FROM visits v WHERE v.visit_date = ?' . $visitScope,
            array_merge([$today], $visitParams),
            0
        );

        [$recoveryScope, $recoveryParams] = Auth::scopeSql('r.branch_id');
        $recoveryToday = (float) Database::value(
            'SELECT COALESCE(SUM(r.amount),0) FROM recoveries r
             WHERE DATE(r.collected_at) = ? AND r.status <> "rejected"' . $recoveryScope,
            array_merge([$today], $recoveryParams),
            0
        );
        $recoveryMonth = (float) Database::value(
            'SELECT COALESCE(SUM(r.amount),0) FROM recoveries r
             WHERE r.collected_at >= ? AND r.status <> "rejected"' . $recoveryScope,
            array_merge([date('Y-m-01 00:00:00')], $recoveryParams),
            0
        );
        $pendingVerification = (int) Database::value(
            'SELECT COUNT(*) FROM recoveries r WHERE r.status = "pending"' . $recoveryScope,
            $recoveryParams,
            0
        );

        [$bcScope, $bcParams] = Auth::scopeSql('b.branch_id');
        $bcCount = (int) Database::value(
            'SELECT COUNT(*) FROM bc_agents b WHERE b.status = "active"' . $bcScope,
            $bcParams,
            0
        );

        [$branchScope, $branchParams] = Auth::scopeSql('br.id');
        $branchCount = (int) Database::value(
            'SELECT COUNT(*) FROM branches br WHERE br.status = "active"' . $branchScope,
            $branchParams,
            0
        );

        $pendingUsers = Auth::can('users.view')
            ? (int) Database::value('SELECT COUNT(*) FROM users WHERE status = "pending"', [], 0)
            : 0;

        $totalAccounts = (int) ($counts['total_accounts'] ?? 0);

        return [
            'scope'                => Auth::role(),
            'assigned_accounts'    => $totalAccounts,
            'total_accounts'       => $totalAccounts,
            'bc_count'             => $bcCount,
            'branch_count'         => $branchCount,
            'visited_today'        => $visitedToday,
            'pending_today'        => max(0, $totalAccounts - $visitedToday),
            'promise_count'        => (int) ($counts['promise_count'] ?? 0),
            'ots_count'            => (int) ($counts['ots_count'] ?? 0),
            'npa_count'            => (int) ($counts['npa_count'] ?? 0),
            'total_outstanding'    => round((float) ($counts['total_outstanding'] ?? 0), 2),
            'total_overdue'        => round((float) ($counts['total_overdue'] ?? 0), 2),
            'total_recovered'      => round((float) ($counts['total_recovered'] ?? 0), 2),
            'recovery_today'       => round($recoveryToday, 2),
            'recovery_month'       => round($recoveryMonth, 2),
            'monthly_target'       => 0.0,
            'target_achieved_pct'  => 0.0,
            'pending_verification' => $pendingVerification,
            'pending_users'        => $pendingUsers,
            'followups_due'        => 0,
            'attendance'           => $this->attendanceToday($userId),
        ];
    }

    /** @return array<string,mixed> */
    public function attendanceToday(int $userId): array
    {
        $row = Database::first(
            'SELECT check_in_at, check_out_at, status FROM attendance
             WHERE user_id = ? AND attendance_date = ? LIMIT 1',
            [$userId, date('Y-m-d')]
        );

        return [
            'checked_in'   => $row !== null && $row['check_in_at'] !== null,
            'check_in_at'  => $row['check_in_at'] ?? null,
            'checked_out'  => $row !== null && $row['check_out_at'] !== null,
            'check_out_at' => $row['check_out_at'] ?? null,
            'status'       => $row['status'] ?? null,
        ];
    }

    /**
     * Last 14 days of visits and recoveries, for the Chart.js line chart.
     * @return array{labels:list<string>,visits:list<int>,recoveries:list<float>}
     */
    public function trend(int $days = 14): array
    {
        $days = max(7, min(90, $days));
        [$visitScope, $visitParams] = Auth::scopeSql('v.branch_id', 'v.bc_id');
        [$recoveryScope, $recoveryParams] = Auth::scopeSql('r.branch_id', 'r.bc_id');

        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        $visitRows = Database::all(
            'SELECT v.visit_date AS d, COUNT(*) AS c FROM visits v
             WHERE v.visit_date >= ?' . $visitScope . ' GROUP BY v.visit_date',
            array_merge([$from], $visitParams)
        );
        $recoveryRows = Database::all(
            'SELECT DATE(r.collected_at) AS d, COALESCE(SUM(r.amount),0) AS s FROM recoveries r
             WHERE r.collected_at >= ? AND r.status <> "rejected"' . $recoveryScope
                . ' GROUP BY DATE(r.collected_at)',
            array_merge([$from . ' 00:00:00'], $recoveryParams)
        );

        $visitMap = [];
        foreach ($visitRows as $row) {
            $visitMap[(string) $row['d']] = (int) $row['c'];
        }
        $recoveryMap = [];
        foreach ($recoveryRows as $row) {
            $recoveryMap[(string) $row['d']] = (float) $row['s'];
        }

        $labels = [];
        $visits = [];
        $recoveries = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime('-' . $i . ' days'));
            $labels[] = date('d M', strtotime($date));
            $visits[] = $visitMap[$date] ?? 0;
            $recoveries[] = round($recoveryMap[$date] ?? 0.0, 2);
        }

        return ['labels' => $labels, 'visits' => $visits, 'recoveries' => $recoveries];
    }

    /**
     * Branch ranking by recovery in the current month.
     * @return list<array<string,mixed>>
     */
    public function branchRanking(int $limit = 10): array
    {
        [$scope, $params] = Auth::scopeSql('br.id');

        return Database::all(
            'SELECT br.id, br.code, br.name,
                    COUNT(DISTINCT l.id) AS accounts,
                    COALESCE(SUM(l.outstanding_amount),0) AS outstanding,
                    (SELECT COALESCE(SUM(r.amount),0) FROM recoveries r
                       WHERE r.branch_id = br.id AND r.collected_at >= ? AND r.status <> "rejected") AS recovered_month
             FROM branches br
             LEFT JOIN loans l ON l.branch_id = br.id AND l.status = "active"
             WHERE br.status = "active"' . $scope . '
             GROUP BY br.id, br.code, br.name
             ORDER BY recovered_month DESC
             LIMIT ' . max(1, min(50, $limit)),
            array_merge([date('Y-m-01 00:00:00')], $params)
        );
    }

    /**
     * BC ranking by recovery in the current month.
     * @return list<array<string,mixed>>
     */
    public function bcRanking(int $limit = 10): array
    {
        [$scope, $params] = Auth::scopeSql('b.branch_id', 'b.id');

        return Database::all(
            'SELECT b.id, b.bc_code, u.full_name, br.name AS branch_name,
                    b.monthly_target,
                    (SELECT COUNT(*) FROM loans l WHERE l.bc_id = b.id AND l.status = "active") AS accounts,
                    (SELECT COUNT(*) FROM visits v WHERE v.bc_id = b.id AND v.visit_date >= ?) AS visits_month,
                    (SELECT COALESCE(SUM(r.amount),0) FROM recoveries r
                       WHERE r.bc_id = b.id AND r.collected_at >= ? AND r.status <> "rejected") AS recovered_month
             FROM bc_agents b
             JOIN users u ON u.id = b.user_id
             LEFT JOIN branches br ON br.id = b.branch_id
             WHERE b.status = "active"' . $scope . '
             ORDER BY recovered_month DESC
             LIMIT ' . max(1, min(50, $limit)),
            array_merge([date('Y-m-01'), date('Y-m-01 00:00:00')], $params)
        );
    }

    /**
     * Asset classification split, for the doughnut chart.
     * @return array{labels:list<string>,values:list<int>}
     */
    public function assetClassSplit(): array
    {
        [$scope, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');

        $rows = Database::all(
            'SELECT l.asset_class, COUNT(*) AS c FROM loans l
             WHERE l.status = "active"' . $scope . ' GROUP BY l.asset_class',
            $params
        );

        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $labels[] = (string) $row['asset_class'];
            $values[] = (int) $row['c'];
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
