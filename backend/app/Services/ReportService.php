<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use Lib\Crypto;
use Lib\Logger;
use Lib\Pdf;
use Lib\QrCode;
use Lib\Settings;

/**
 * Report generation: tabular datasets for the screen and for Excel/CSV export,
 * plus PDF documents (visit report, loan statement, recovery receipt) carrying
 * a QR code that resolves to a public verification page.
 *
 * Every generated PDF is registered in `report_documents` with a SHA-256 of its
 * bytes, so the QR page can confirm the printout is genuine.
 */
final class ReportService
{
    /** Report definitions available in the Reports module. */
    public const TYPES = [
        'bc_wise'       => 'BC Agent performance',
        'branch_wise'   => 'Branch performance',
        'district_wise' => 'District summary',
        'village_wise'  => 'Village summary',
        'npa'           => 'NPA accounts',
        'recovery'      => 'Recovery register',
        'visit'         => 'Visit register',
        'gps'           => 'GPS verification',
        'photo'         => 'Photo evidence',
        'attendance'    => 'Attendance register',
        'followup'      => 'Follow-up pipeline',
    ];

    /**
     * Build a report dataset.
     *
     * @param array{from:string,to:string,branch_id:int,bc_id:int} $filters
     * @return array{title:string,headers:list<string>,aligns:list<string>,formats:list<string>,rows:list<list<mixed>>,totals:array<string,mixed>}
     */
    public function build(string $type, array $filters): array
    {
        $from = $filters['from'] . ' 00:00:00';
        $to = $filters['to'] . ' 23:59:59';

        switch ($type) {
            case 'bc_wise':
                return $this->bcWise($filters, $from, $to);
            case 'branch_wise':
                return $this->branchWise($filters, $from, $to);
            case 'district_wise':
                return $this->groupedGeography('district', $filters, $from, $to);
            case 'village_wise':
                return $this->groupedGeography('village', $filters, $from, $to);
            case 'npa':
                return $this->npa($filters);
            case 'recovery':
                return $this->recoveryRegister($filters, $from, $to);
            case 'visit':
                return $this->visitRegister($filters, $from, $to);
            case 'gps':
                return $this->gpsVerification($filters, $from, $to);
            case 'photo':
                return $this->photoEvidence($filters, $from, $to);
            case 'attendance':
                return $this->attendanceRegister($filters, $from, $to);
            case 'followup':
                return $this->followUpPipeline($filters);
            default:
                throw new \InvalidArgumentException('Unknown report type: ' . $type);
        }
    }

    // ------------------------------------------------------------------
    // Report builders
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function bcWise(array $filters, string $from, string $to): array
    {
        [$scope, $params] = Auth::scopeSql('b.branch_id', 'b.id');
        $where = 'b.status = "active"' . $scope;

        if ($filters['branch_id'] > 0) {
            $where .= ' AND b.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if ($filters['bc_id'] > 0) {
            $where .= ' AND b.id = ?';
            $params[] = $filters['bc_id'];
        }

        $rows = Database::all(
            'SELECT b.bc_code, u.full_name, br.name AS branch_name, b.monthly_target,
                    (SELECT COUNT(*) FROM loans l WHERE l.bc_id = b.id AND l.status = "active") AS accounts,
                    (SELECT COALESCE(SUM(l.outstanding_amount),0) FROM loans l WHERE l.bc_id = b.id AND l.status = "active") AS outstanding,
                    (SELECT COUNT(*) FROM visits v WHERE v.bc_id = b.id AND v.visited_at BETWEEN ? AND ?) AS visits,
                    (SELECT COUNT(DISTINCT v.loan_id) FROM visits v WHERE v.bc_id = b.id AND v.visited_at BETWEEN ? AND ?) AS accounts_visited,
                    (SELECT COALESCE(SUM(r.amount),0) FROM recoveries r WHERE r.bc_id = b.id AND r.collected_at BETWEEN ? AND ? AND r.status <> "rejected") AS recovered,
                    (SELECT COUNT(*) FROM follow_ups f WHERE f.bc_id = b.id AND f.status = "pending") AS pending_followups
             FROM bc_agents b
             JOIN users u ON u.id = b.user_id
             LEFT JOIN branches br ON br.id = b.branch_id
             WHERE ' . $where . '
             ORDER BY recovered DESC',
            array_merge([$from, $to, $from, $to, $from, $to], $params)
        );

        $data = [];
        $totals = ['accounts' => 0, 'visits' => 0, 'recovered' => 0.0, 'outstanding' => 0.0];

        foreach ($rows as $row) {
            $accounts = (int) $row['accounts'];
            $visited = (int) $row['accounts_visited'];
            $target = (float) $row['monthly_target'];
            $recovered = (float) $row['recovered'];

            $data[] = [
                $row['bc_code'],
                $row['full_name'],
                $row['branch_name'] ?? '-',
                $accounts,
                $visited,
                $accounts > 0 ? round(($visited / $accounts) * 100, 1) : 0.0,
                round((float) $row['outstanding'], 2),
                (int) $row['visits'],
                round($recovered, 2),
                round($target, 2),
                $target > 0 ? round(($recovered / $target) * 100, 1) : 0.0,
                (int) $row['pending_followups'],
            ];

            $totals['accounts'] += $accounts;
            $totals['visits'] += (int) $row['visits'];
            $totals['recovered'] += $recovered;
            $totals['outstanding'] += (float) $row['outstanding'];
        }

        return [
            'title'   => 'BC Agent performance',
            'headers' => ['BC Code', 'Agent', 'Branch', 'Accounts', 'Visited', 'Coverage %',
                          'Outstanding', 'Visits', 'Recovered', 'Target', 'Target %', 'Pending F/U'],
            'aligns'  => ['L', 'L', 'L', 'R', 'R', 'R', 'R', 'R', 'R', 'R', 'R', 'R'],
            'formats' => ['text', 'text', 'text', 'number', 'number', 'number', 'money',
                          'number', 'money', 'money', 'number', 'number'],
            'rows'    => $data,
            'totals'  => $totals,
        ];
    }

    /** @return array<string,mixed> */
    private function branchWise(array $filters, string $from, string $to): array
    {
        [$scope, $params] = Auth::scopeSql('br.id');
        $where = 'br.status = "active"' . $scope;

        if ($filters['branch_id'] > 0) {
            $where .= ' AND br.id = ?';
            $params[] = $filters['branch_id'];
        }

        $rows = Database::all(
            'SELECT br.code, br.name, br.district,
                    (SELECT COUNT(*) FROM bc_agents b WHERE b.branch_id = br.id AND b.status = "active") AS bc_count,
                    (SELECT COUNT(*) FROM loans l WHERE l.branch_id = br.id AND l.status = "active") AS accounts,
                    (SELECT COALESCE(SUM(l.outstanding_amount),0) FROM loans l WHERE l.branch_id = br.id AND l.status = "active") AS outstanding,
                    (SELECT COALESCE(SUM(l.overdue_amount),0) FROM loans l WHERE l.branch_id = br.id AND l.status = "active") AS overdue,
                    (SELECT COUNT(*) FROM loans l WHERE l.branch_id = br.id AND l.status = "active"
                        AND l.asset_class IN ("SS","DF1","DF2","DF3","LOSS")) AS npa,
                    (SELECT COUNT(*) FROM visits v WHERE v.branch_id = br.id AND v.visited_at BETWEEN ? AND ?) AS visits,
                    (SELECT COALESCE(SUM(r.amount),0) FROM recoveries r WHERE r.branch_id = br.id
                        AND r.collected_at BETWEEN ? AND ? AND r.status <> "rejected") AS recovered
             FROM branches br
             WHERE ' . $where . '
             ORDER BY recovered DESC',
            array_merge([$from, $to, $from, $to], $params)
        );

        $data = [];
        $totals = ['accounts' => 0, 'outstanding' => 0.0, 'recovered' => 0.0, 'npa' => 0];

        foreach ($rows as $row) {
            $accounts = (int) $row['accounts'];
            $outstanding = (float) $row['outstanding'];
            $recovered = (float) $row['recovered'];

            $data[] = [
                $row['code'],
                $row['name'],
                $row['district'] ?? '-',
                (int) $row['bc_count'],
                $accounts,
                round($outstanding, 2),
                round((float) $row['overdue'], 2),
                (int) $row['npa'],
                $accounts > 0 ? round(((int) $row['npa'] / $accounts) * 100, 1) : 0.0,
                (int) $row['visits'],
                round($recovered, 2),
            ];

            $totals['accounts'] += $accounts;
            $totals['outstanding'] += $outstanding;
            $totals['recovered'] += $recovered;
            $totals['npa'] += (int) $row['npa'];
        }

        return [
            'title'   => 'Branch performance',
            'headers' => ['Code', 'Branch', 'District', 'BC', 'Accounts', 'Outstanding',
                          'Overdue', 'NPA', 'NPA %', 'Visits', 'Recovered'],
            'aligns'  => ['L', 'L', 'L', 'R', 'R', 'R', 'R', 'R', 'R', 'R', 'R'],
            'formats' => ['text', 'text', 'text', 'number', 'number', 'money', 'money',
                          'number', 'number', 'number', 'money'],
            'rows'    => $data,
            'totals'  => $totals,
        ];
    }

    /** @return array<string,mixed> */
    private function groupedGeography(string $column, array $filters, string $from, string $to): array
    {
        $column = $column === 'village' ? 'village' : 'district';
        [$scope, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');

        $where = 'l.status = "active" AND c.' . $column . ' IS NOT NULL AND c.' . $column . ' <> ""' . $scope;
        if ($filters['branch_id'] > 0) {
            $where .= ' AND l.branch_id = ?';
            $params[] = $filters['branch_id'];
        }

        $rows = Database::all(
            'SELECT c.' . $column . ' AS label,
                    COUNT(DISTINCT c.id) AS customers,
                    COUNT(l.id) AS accounts,
                    COALESCE(SUM(l.outstanding_amount),0) AS outstanding,
                    COALESCE(SUM(l.overdue_amount),0) AS overdue,
                    SUM(CASE WHEN l.asset_class IN ("SS","DF1","DF2","DF3","LOSS") THEN 1 ELSE 0 END) AS npa
             FROM loans l
             JOIN customers c ON c.id = l.customer_id
             WHERE ' . $where . '
             GROUP BY c.' . $column . '
             ORDER BY overdue DESC
             LIMIT 500',
            $params
        );

        $labels = array_column($rows, 'label');
        $recoveryMap = [];
        if ($labels !== []) {
            $placeholders = implode(',', array_fill(0, count($labels), '?'));
            $recoveryRows = Database::all(
                'SELECT c.' . $column . ' AS label, COALESCE(SUM(r.amount),0) AS recovered
                 FROM recoveries r
                 JOIN customers c ON c.id = r.customer_id
                 WHERE r.collected_at BETWEEN ? AND ? AND r.status <> "rejected"
                   AND c.' . $column . ' IN (' . $placeholders . ')
                 GROUP BY c.' . $column,
                array_merge([$from, $to], $labels)
            );
            foreach ($recoveryRows as $row) {
                $recoveryMap[(string) $row['label']] = (float) $row['recovered'];
            }
        }

        $data = [];
        $totals = ['accounts' => 0, 'outstanding' => 0.0, 'recovered' => 0.0];

        foreach ($rows as $row) {
            $label = (string) $row['label'];
            $recovered = $recoveryMap[$label] ?? 0.0;

            $data[] = [
                $label,
                (int) $row['customers'],
                (int) $row['accounts'],
                round((float) $row['outstanding'], 2),
                round((float) $row['overdue'], 2),
                (int) $row['npa'],
                round($recovered, 2),
            ];

            $totals['accounts'] += (int) $row['accounts'];
            $totals['outstanding'] += (float) $row['outstanding'];
            $totals['recovered'] += $recovered;
        }

        return [
            'title'   => ucfirst($column) . ' summary',
            'headers' => [ucfirst($column), 'Customers', 'Accounts', 'Outstanding', 'Overdue', 'NPA', 'Recovered'],
            'aligns'  => ['L', 'R', 'R', 'R', 'R', 'R', 'R'],
            'formats' => ['text', 'number', 'number', 'money', 'money', 'number', 'money'],
            'rows'    => $data,
            'totals'  => $totals,
        ];
    }

    /** @return array<string,mixed> */
    private function npa(array $filters): array
    {
        [$scope, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');
        $where = 'l.status = "active" AND l.asset_class IN ("SS","DF1","DF2","DF3","LOSS")' . $scope;

        if ($filters['branch_id'] > 0) {
            $where .= ' AND l.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if ($filters['bc_id'] > 0) {
            $where .= ' AND l.bc_id = ?';
            $params[] = $filters['bc_id'];
        }

        $rows = Database::all(
            'SELECT l.account_number, c.full_name, c.village, br.name AS branch_name,
                    bc.bc_code, l.asset_class, l.dpd, l.npa_date,
                    l.outstanding_amount, l.overdue_amount, l.total_recovered,
                    l.last_visit_at, l.recovery_status, l.risk_score, l.risk_band
             FROM loans l
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN branches br ON br.id = l.branch_id
             LEFT JOIN bc_agents bc ON bc.id = l.bc_id
             WHERE ' . $where . '
             ORDER BY l.outstanding_amount DESC
             LIMIT 5000',
            $params
        );

        $data = [];
        $totals = ['accounts' => count($rows), 'outstanding' => 0.0, 'overdue' => 0.0];

        foreach ($rows as $row) {
            $data[] = [
                $row['account_number'],
                $row['full_name'],
                $row['village'] ?? '-',
                $row['branch_name'] ?? '-',
                $row['bc_code'] ?? 'unallocated',
                $row['asset_class'],
                (int) $row['dpd'],
                $row['npa_date'] ?? '-',
                round((float) $row['outstanding_amount'], 2),
                round((float) $row['overdue_amount'], 2),
                round((float) $row['total_recovered'], 2),
                $row['last_visit_at'] === null ? 'never' : date('d-m-Y', strtotime((string) $row['last_visit_at'])),
                str_replace('_', ' ', (string) $row['recovery_status']),
                $row['risk_band'] ?? '-',
            ];
            $totals['outstanding'] += (float) $row['outstanding_amount'];
            $totals['overdue'] += (float) $row['overdue_amount'];
        }

        return [
            'title'   => 'NPA accounts',
            'headers' => ['Account', 'Customer', 'Village', 'Branch', 'BC', 'Class', 'DPD', 'NPA date',
                          'Outstanding', 'Overdue', 'Recovered', 'Last visit', 'Status', 'Risk'],
            'aligns'  => ['L', 'L', 'L', 'L', 'L', 'L', 'R', 'L', 'R', 'R', 'R', 'L', 'L', 'L'],
            'formats' => ['text', 'text', 'text', 'text', 'text', 'text', 'number', 'date',
                          'money', 'money', 'money', 'date', 'text', 'text'],
            'rows'    => $data,
            'totals'  => $totals,
        ];
    }

    /** @return array<string,mixed> */
    private function recoveryRegister(array $filters, string $from, string $to): array
    {
        [$scope, $params] = Auth::scopeSql('r.branch_id', 'r.bc_id');
        $where = 'r.collected_at BETWEEN ? AND ?' . $scope;
        $params = array_merge([$from, $to], $params);

        if ($filters['branch_id'] > 0) {
            $where .= ' AND r.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if ($filters['bc_id'] > 0) {
            $where .= ' AND r.bc_id = ?';
            $params[] = $filters['bc_id'];
        }

        $rows = Database::all(
            'SELECT r.receipt_number, r.collected_at, l.account_number, c.full_name, c.village,
                    br.name AS branch_name, bc.bc_code, r.amount, r.payment_mode,
                    r.txn_reference, r.status, r.verified_at
             FROM recoveries r
             JOIN loans l ON l.id = r.loan_id
             JOIN customers c ON c.id = r.customer_id
             LEFT JOIN branches br ON br.id = r.branch_id
             LEFT JOIN bc_agents bc ON bc.id = r.bc_id
             WHERE ' . $where . '
             ORDER BY r.collected_at DESC
             LIMIT 10000',
            $params
        );

        $data = [];
        $totals = ['count' => count($rows), 'amount' => 0.0, 'verified' => 0.0, 'pending' => 0.0];

        foreach ($rows as $row) {
            $amount = (float) $row['amount'];
            $data[] = [
                $row['receipt_number'],
                date('d-m-Y H:i', strtotime((string) $row['collected_at'])),
                $row['account_number'],
                $row['full_name'],
                $row['village'] ?? '-',
                $row['branch_name'] ?? '-',
                $row['bc_code'] ?? '-',
                round($amount, 2),
                strtoupper((string) $row['payment_mode']),
                $row['txn_reference'] ?? '-',
                ucfirst((string) $row['status']),
            ];

            if ($row['status'] !== 'rejected') {
                $totals['amount'] += $amount;
            }
            if ($row['status'] === 'verified') {
                $totals['verified'] += $amount;
            }
            if ($row['status'] === 'pending') {
                $totals['pending'] += $amount;
            }
        }

        return [
            'title'   => 'Recovery register',
            'headers' => ['Receipt', 'Collected at', 'Account', 'Customer', 'Village', 'Branch',
                          'BC', 'Amount', 'Mode', 'Reference', 'Status'],
            'aligns'  => ['L', 'L', 'L', 'L', 'L', 'L', 'L', 'R', 'L', 'L', 'L'],
            'formats' => ['text', 'text', 'text', 'text', 'text', 'text', 'text', 'money', 'text', 'text', 'text'],
            'rows'    => $data,
            'totals'  => $totals,
        ];
    }

    /** @return array<string,mixed> */
    private function visitRegister(array $filters, string $from, string $to): array
    {
        [$scope, $params] = Auth::scopeSql('v.branch_id', 'v.bc_id');
        $where = 'v.visited_at BETWEEN ? AND ?' . $scope;
        $params = array_merge([$from, $to], $params);

        if ($filters['branch_id'] > 0) {
            $where .= ' AND v.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if ($filters['bc_id'] > 0) {
            $where .= ' AND v.bc_id = ?';
            $params[] = $filters['bc_id'];
        }

        $rows = Database::all(
            'SELECT v.visited_at, l.account_number, c.full_name, c.village, bc.bc_code,
                    v.visit_status, v.customer_available, v.house_locked, v.recovery_possibility,
                    v.promise_amount, v.promise_date, v.collected_amount,
                    v.latitude, v.longitude, v.distance_from_customer_m, v.remarks,
                    (SELECT COUNT(*) FROM visit_photos p WHERE p.visit_id = v.id) AS photos
             FROM visits v
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             LEFT JOIN bc_agents bc ON bc.id = v.bc_id
             WHERE ' . $where . '
             ORDER BY v.visited_at DESC
             LIMIT 10000',
            $params
        );

        $data = [];
        $totals = ['visits' => count($rows), 'promise' => 0.0, 'collected' => 0.0];

        foreach ($rows as $row) {
            $data[] = [
                date('d-m-Y H:i', strtotime((string) $row['visited_at'])),
                $row['account_number'],
                $row['full_name'],
                $row['village'] ?? '-',
                $row['bc_code'] ?? '-',
                str_replace('_', ' ', (string) $row['visit_status']),
                (int) $row['customer_available'] === 1 ? 'Yes' : 'No',
                (int) $row['house_locked'] === 1 ? 'Yes' : 'No',
                $row['recovery_possibility'] ?? '-',
                round((float) $row['promise_amount'], 2),
                $row['promise_date'] ?? '-',
                round((float) $row['collected_amount'], 2),
                (int) $row['photos'],
                $row['distance_from_customer_m'] === null ? '-' : (int) $row['distance_from_customer_m'],
            ];
            $totals['promise'] += (float) $row['promise_amount'];
            $totals['collected'] += (float) $row['collected_amount'];
        }

        return [
            'title'   => 'Visit register',
            'headers' => ['Visited at', 'Account', 'Customer', 'Village', 'BC', 'Status',
                          'Available', 'Locked', 'Possibility', 'Promise', 'Promise date',
                          'Collected', 'Photos', 'Distance (m)'],
            'aligns'  => ['L', 'L', 'L', 'L', 'L', 'L', 'C', 'C', 'L', 'R', 'L', 'R', 'R', 'R'],
            'formats' => ['text', 'text', 'text', 'text', 'text', 'text', 'text', 'text',
                          'text', 'money', 'date', 'money', 'number', 'number'],
            'rows'    => $data,
            'totals'  => $totals,
        ];
    }

    /** @return array<string,mixed> */
    private function gpsVerification(array $filters, string $from, string $to): array
    {
        [$scope, $params] = Auth::scopeSql('v.branch_id', 'v.bc_id');
        $where = 'v.visited_at BETWEEN ? AND ?' . $scope;
        $params = array_merge([$from, $to], $params);

        $rows = Database::all(
            'SELECT v.visited_at, l.account_number, c.full_name, bc.bc_code,
                    v.latitude, v.longitude, v.accuracy_m, v.is_mock_location,
                    v.distance_from_customer_m, c.latitude AS clat, c.longitude AS clng
             FROM visits v
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             LEFT JOIN bc_agents bc ON bc.id = v.bc_id
             WHERE ' . $where . '
             ORDER BY v.visited_at DESC
             LIMIT 10000',
            $params
        );

        $data = [];
        $suspicious = 0;

        foreach ($rows as $row) {
            $distance = $row['distance_from_customer_m'] === null ? null : (int) $row['distance_from_customer_m'];
            $flag = 'OK';
            if ((int) $row['is_mock_location'] === 1) {
                $flag = 'MOCK GPS';
                $suspicious++;
            } elseif ($distance !== null && $distance > 1000) {
                $flag = 'FAR (' . $distance . 'm)';
                $suspicious++;
            } elseif ($row['clat'] === null) {
                $flag = 'NO REFERENCE';
            }

            $data[] = [
                date('d-m-Y H:i', strtotime((string) $row['visited_at'])),
                $row['account_number'],
                $row['full_name'],
                $row['bc_code'] ?? '-',
                number_format((float) $row['latitude'], 6),
                number_format((float) $row['longitude'], 6),
                $row['accuracy_m'] === null ? '-' : round((float) $row['accuracy_m'], 1),
                $distance ?? '-',
                $flag,
            ];
        }

        return [
            'title'   => 'GPS verification',
            'headers' => ['Visited at', 'Account', 'Customer', 'BC', 'Latitude', 'Longitude',
                          'Accuracy (m)', 'Distance (m)', 'Verdict'],
            'aligns'  => ['L', 'L', 'L', 'L', 'R', 'R', 'R', 'R', 'L'],
            'formats' => ['text', 'text', 'text', 'text', 'text', 'text', 'number', 'number', 'text'],
            'rows'    => $data,
            'totals'  => ['visits' => count($rows), 'flagged' => $suspicious],
        ];
    }

    /** @return array<string,mixed> */
    private function photoEvidence(array $filters, string $from, string $to): array
    {
        [$scope, $params] = Auth::scopeSql('v.branch_id', 'v.bc_id');
        $where = 'v.visited_at BETWEEN ? AND ?' . $scope;
        $params = array_merge([$from, $to], $params);

        $rows = Database::all(
            'SELECT v.visited_at, l.account_number, c.full_name, bc.bc_code,
                    p.file_path, p.thumb_path, p.size_bytes, p.watermarked, p.file_hash,
                    p.latitude, p.longitude
             FROM visit_photos p
             JOIN visits v ON v.id = p.visit_id
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             LEFT JOIN bc_agents bc ON bc.id = v.bc_id
             WHERE ' . $where . '
             ORDER BY v.visited_at DESC
             LIMIT 5000',
            $params
        );

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                date('d-m-Y H:i', strtotime((string) $row['visited_at'])),
                $row['account_number'],
                $row['full_name'],
                $row['bc_code'] ?? '-',
                (int) $row['watermarked'] === 1 ? 'Yes' : 'No',
                round((int) $row['size_bytes'] / 1024, 1),
                substr((string) $row['file_hash'], 0, 16) . '...',
                Config::baseUrl() . 'uploads/' . ltrim((string) $row['file_path'], '/'),
            ];
        }

        return [
            'title'   => 'Photo evidence',
            'headers' => ['Visited at', 'Account', 'Customer', 'BC', 'Watermarked', 'Size (KB)', 'SHA-256', 'URL'],
            'aligns'  => ['L', 'L', 'L', 'L', 'C', 'R', 'L', 'L'],
            'formats' => ['text', 'text', 'text', 'text', 'text', 'number', 'text', 'text'],
            'rows'    => $data,
            'totals'  => ['photos' => count($rows)],
        ];
    }

    /** @return array<string,mixed> */
    private function attendanceRegister(array $filters, string $from, string $to): array
    {
        [$scope, $params] = Auth::scopeSql('a.branch_id');
        $where = 'a.attendance_date BETWEEN ? AND ?' . $scope;
        $params = array_merge([substr($from, 0, 10), substr($to, 0, 10)], $params);

        if ($filters['branch_id'] > 0) {
            $where .= ' AND a.branch_id = ?';
            $params[] = $filters['branch_id'];
        }

        $rows = Database::all(
            'SELECT a.attendance_date, u.full_name, bc.bc_code, br.name AS branch_name,
                    a.check_in_at, a.check_out_at, a.worked_minutes, a.distance_km,
                    a.status, a.is_outside_geofence
             FROM attendance a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN bc_agents bc ON bc.user_id = u.id
             LEFT JOIN branches br ON br.id = a.branch_id
             WHERE ' . $where . '
             ORDER BY a.attendance_date DESC, u.full_name
             LIMIT 10000',
            $params
        );

        $data = [];
        $totals = ['records' => count($rows), 'minutes' => 0, 'distance' => 0.0];

        foreach ($rows as $row) {
            $minutes = (int) $row['worked_minutes'];
            $data[] = [
                date('d-m-Y', strtotime((string) $row['attendance_date'])),
                $row['full_name'],
                $row['bc_code'] ?? '-',
                $row['branch_name'] ?? '-',
                $row['check_in_at'] === null ? '-' : date('h:i A', strtotime((string) $row['check_in_at'])),
                $row['check_out_at'] === null ? '-' : date('h:i A', strtotime((string) $row['check_out_at'])),
                sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60),
                round((float) $row['distance_km'], 2),
                str_replace('_', ' ', (string) $row['status']),
                (int) $row['is_outside_geofence'] === 1 ? 'Outside' : 'Inside',
            ];
            $totals['minutes'] += $minutes;
            $totals['distance'] += (float) $row['distance_km'];
        }

        return [
            'title'   => 'Attendance register',
            'headers' => ['Date', 'Agent', 'BC', 'Branch', 'Check in', 'Check out',
                          'Worked', 'Distance (km)', 'Status', 'Geofence'],
            'aligns'  => ['L', 'L', 'L', 'L', 'L', 'L', 'R', 'R', 'L', 'L'],
            'formats' => ['date', 'text', 'text', 'text', 'text', 'text', 'text', 'number', 'text', 'text'],
            'rows'    => $data,
            'totals'  => $totals,
        ];
    }

    /** @return array<string,mixed> */
    private function followUpPipeline(array $filters): array
    {
        [$scope, $params] = Auth::scopeSql('l.branch_id', 'l.bc_id');
        $where = 'f.status = "pending"' . $scope;

        if ($filters['branch_id'] > 0) {
            $where .= ' AND l.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if ($filters['bc_id'] > 0) {
            $where .= ' AND f.bc_id = ?';
            $params[] = $filters['bc_id'];
        }

        $rows = Database::all(
            'SELECT f.due_date, l.account_number, c.full_name, c.village, bc.bc_code,
                    f.promise_amount, l.outstanding_amount, l.overdue_amount, f.channel,
                    f.reminder_sent_at, f.message
             FROM follow_ups f
             JOIN loans l ON l.id = f.loan_id
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN bc_agents bc ON bc.id = f.bc_id
             WHERE ' . $where . '
             ORDER BY f.due_date ASC
             LIMIT 10000',
            $params
        );

        $today = date('Y-m-d');
        $data = [];
        $totals = ['count' => count($rows), 'promise' => 0.0, 'overdue_count' => 0];

        foreach ($rows as $row) {
            $isOverdue = (string) $row['due_date'] < $today;
            $data[] = [
                date('d-m-Y', strtotime((string) $row['due_date'])),
                $isOverdue ? 'OVERDUE' : 'Upcoming',
                $row['account_number'],
                $row['full_name'],
                $row['village'] ?? '-',
                $row['bc_code'] ?? '-',
                round((float) $row['promise_amount'], 2),
                round((float) $row['outstanding_amount'], 2),
                $row['reminder_sent_at'] === null ? 'No' : 'Yes',
            ];
            $totals['promise'] += (float) $row['promise_amount'];
            if ($isOverdue) {
                $totals['overdue_count']++;
            }
        }

        return [
            'title'   => 'Follow-up pipeline',
            'headers' => ['Due date', 'State', 'Account', 'Customer', 'Village', 'BC',
                          'Promise', 'Outstanding', 'Reminder sent'],
            'aligns'  => ['L', 'L', 'L', 'L', 'L', 'L', 'R', 'R', 'C'],
            'formats' => ['date', 'text', 'text', 'text', 'text', 'text', 'money', 'money', 'text'],
            'rows'    => $data,
            'totals'  => $totals,
        ];
    }

    // ------------------------------------------------------------------
    // PDF documents
    // ------------------------------------------------------------------

    /**
     * Visit report PDF with photos and a verification QR.
     *
     * @return array{ok:bool,message:string,pdf:string,filename:string,doc_uid:string}
     */
    public function visitReportPdf(int $visitId): array
    {
        $visit = Database::first(
            'SELECT v.*, l.account_number, l.outstanding_amount, l.overdue_amount, l.asset_class, l.dpd,
                    c.full_name, c.guardian_name, c.village, c.district, c.address_line, c.mobile_last4,
                    c.occupation AS customer_occupation,
                    br.name AS branch_name, br.code AS branch_code,
                    bc.bc_code, u.full_name AS agent_name
             FROM visits v
             JOIN loans l ON l.id = v.loan_id
             JOIN customers c ON c.id = v.customer_id
             LEFT JOIN branches br ON br.id = v.branch_id
             LEFT JOIN bc_agents bc ON bc.id = v.bc_id
             LEFT JOIN users u ON u.id = v.user_id
             WHERE v.id = ? LIMIT 1',
            [$visitId]
        );

        if ($visit === null) {
            return ['ok' => false, 'message' => 'That visit no longer exists.', 'pdf' => '', 'filename' => '', 'doc_uid' => ''];
        }

        $photos = Database::all(
            'SELECT file_path, captured_at, latitude, longitude FROM visit_photos WHERE visit_id = ? ORDER BY id',
            [$visitId]
        );

        $docUid = 'VR-' . date('Ymd', strtotime((string) $visit['visited_at'])) . '-' . strtoupper(bin2hex(random_bytes(4)));

        try {
            $pdf = $this->newDocument('Visit Report ' . $visit['account_number']);

            $this->documentHeader($pdf, 'FIELD VISIT REPORT', $docUid);

            // ---- QR (vector, so it stays crisp at any print size) --------
            $verifyUrl = Config::baseUrl() . 'verify/' . $docUid;
            $pdf->qrCode(QrCode::encode($verifyUrl), $pdf->pageWidth() - 46, 16, 30);
            $pdf->setFont('', 6.5);
            $pdf->setTextColor(110, 116, 124);
            $pdf->cell($pdf->pageWidth() - 46, 49, 30, 'Scan to verify', 'C');
            $pdf->setTextColor(0, 0, 0);

            $pdf->setY(50);

            // ---- account + customer -------------------------------------
            $columnWidth = ($pdf->contentWidth() - 8) / 2;
            $leftY = $pdf->y();

            $pdf->setFont('B', 10);
            $pdf->text($pdf->marginLeft(), $pdf->y() + 4, 'Borrower');
            $pdf->setY($pdf->y() + 7);
            $pdf->keyValue($pdf->marginLeft(), $columnWidth, 'Name', (string) $visit['full_name']);
            $pdf->keyValue($pdf->marginLeft(), $columnWidth, 'Guardian', (string) ($visit['guardian_name'] ?? '-'));
            $pdf->keyValue($pdf->marginLeft(), $columnWidth, 'Mobile', $visit['mobile_last4'] === null ? '-' : '******' . $visit['mobile_last4']);
            $pdf->keyValue($pdf->marginLeft(), $columnWidth, 'Village', (string) ($visit['village'] ?? '-'));
            $pdf->keyValue($pdf->marginLeft(), $columnWidth, 'District', (string) ($visit['district'] ?? '-'));
            $pdf->keyValue($pdf->marginLeft(), $columnWidth, 'Occupation', (string) ($visit['occupation'] ?? $visit['customer_occupation'] ?? '-'));
            $leftEnd = $pdf->y();

            $rightX = $pdf->marginLeft() + $columnWidth + 8;
            $pdf->setY($leftY);
            $pdf->setFont('B', 10);
            $pdf->text($rightX, $pdf->y() + 4, 'Account');
            $pdf->setY($pdf->y() + 7);
            $pdf->keyValue($rightX, $columnWidth, 'Account no.', (string) $visit['account_number']);
            $pdf->keyValue($rightX, $columnWidth, 'Branch', (string) ($visit['branch_name'] ?? '-'));
            $pdf->keyValue($rightX, $columnWidth, 'Asset class', $visit['asset_class'] . ' (' . (int) $visit['dpd'] . ' DPD)');
            $pdf->keyValue($rightX, $columnWidth, 'Outstanding', 'Rs. ' . number_format((float) $visit['outstanding_amount'], 2));
            $pdf->keyValue($rightX, $columnWidth, 'Overdue', 'Rs. ' . number_format((float) $visit['overdue_amount'], 2));
            $pdf->keyValue($rightX, $columnWidth, 'BC agent', trim((string) ($visit['agent_name'] ?? '-') . ' ' . (string) ($visit['bc_code'] ?? '')));

            $pdf->setY(max($leftEnd, $pdf->y()) + 4);

            // ---- visit findings ------------------------------------------
            $this->sectionTitle($pdf, 'Visit findings');
            $full = $pdf->contentWidth();
            $pdf->keyValue($pdf->marginLeft(), $full, 'Visited at', date('d-m-Y h:i A', strtotime((string) $visit['visited_at'])));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Status', ucwords(str_replace('_', ' ', (string) $visit['visit_status'])));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Customer available', (int) $visit['customer_available'] === 1 ? 'Yes' : 'No');
            $pdf->keyValue($pdf->marginLeft(), $full, 'House locked', (int) $visit['house_locked'] === 1 ? 'Yes' : 'No');
            $pdf->keyValue($pdf->marginLeft(), $full, 'Met person', (string) ($visit['met_person'] ?? '-')
                . ($visit['met_relation'] !== null ? ' (' . $visit['met_relation'] . ')' : ''));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Recovery possibility', ucfirst((string) ($visit['recovery_possibility'] ?? '-')));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Promise', (float) $visit['promise_amount'] > 0
                ? 'Rs. ' . number_format((float) $visit['promise_amount'], 2)
                    . ' by ' . ($visit['promise_date'] !== null ? date('d-m-Y', strtotime((string) $visit['promise_date'])) : '-')
                : 'None');
            $pdf->keyValue($pdf->marginLeft(), $full, 'Collected on visit', 'Rs. ' . number_format((float) $visit['collected_amount'], 2));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Remarks', (string) ($visit['remarks'] ?? '-'));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Recommendation', (string) ($visit['recommendation'] ?? '-'));

            // ---- GPS ------------------------------------------------------
            $pdf->moveY(2);
            $this->sectionTitle($pdf, 'GPS verification');
            $pdf->keyValue($pdf->marginLeft(), $full, 'Coordinates',
                number_format((float) $visit['latitude'], 6) . ', ' . number_format((float) $visit['longitude'], 6));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Accuracy',
                $visit['accuracy_m'] === null ? '-' : round((float) $visit['accuracy_m'], 1) . ' m');
            $pdf->keyValue($pdf->marginLeft(), $full, 'Distance from customer',
                $visit['distance_from_customer_m'] === null ? 'not available' : $visit['distance_from_customer_m'] . ' m');
            $pdf->keyValue($pdf->marginLeft(), $full, 'Mock location',
                (int) $visit['is_mock_location'] === 1 ? 'DETECTED' : 'Not detected');
            $pdf->keyValue($pdf->marginLeft(), $full, 'Device', (string) ($visit['device_id'] ?? '-'));

            // ---- photos ---------------------------------------------------
            if ($photos !== []) {
                $pdf->moveY(3);
                $pdf->ensureSpace(70);
                $this->sectionTitle($pdf, 'Photo evidence (watermarked at capture)');

                $x = $pdf->marginLeft();
                $photoWidth = ($pdf->contentWidth() - 6) / 2;
                $rowTop = $pdf->y();
                $index = 0;

                foreach ($photos as $photo) {
                    $absolute = PhotoStorageService::absolute($photo['file_path']);
                    if ($absolute === null) {
                        continue;
                    }

                    if ($index > 0 && $index % 2 === 0) {
                        $pdf->setY($rowTop + 58);
                        $pdf->ensureSpace(62);
                        $rowTop = $pdf->y();
                        $x = $pdf->marginLeft();
                    }

                    $pdf->image($absolute, $x, $rowTop, $photoWidth, 54);
                    $x += $photoWidth + 6;
                    $index++;
                }

                $pdf->setY($rowTop + 58);
            }

            // ---- signature ------------------------------------------------
            if (!empty($visit['signature_path'])) {
                $signature = PhotoStorageService::absolute($visit['signature_path']);
                if ($signature !== null) {
                    $pdf->ensureSpace(34);
                    $this->sectionTitle($pdf, 'Customer / witness signature');
                    $pdf->image($signature, $pdf->marginLeft(), $pdf->y(), 70, 24);
                    $pdf->moveY(28);
                }
            }

            $pdf->ensureSpace(24);
            $pdf->moveY(6);
            $pdf->setFont('', 8);
            $pdf->setTextColor(110, 116, 124);
            $pdf->write(
                'This report was generated from GPS-verified field data. Verify authenticity at '
                . $verifyUrl . ' or by scanning the QR code. Document ID ' . $docUid . '.',
                4.2
            );

            $output = $pdf->output();
        } catch (\Throwable $e) {
            Logger::error('Visit PDF failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => 'The PDF could not be generated: ' . $e->getMessage(),
                'pdf' => '', 'filename' => '', 'doc_uid' => '',
            ];
        }

        $this->registerDocument($docUid, 'visit_report', 'visit', $visitId, $output);

        return [
            'ok' => true,
            'message' => '',
            'pdf' => $output,
            'filename' => 'visit-report-' . $visit['account_number'] . '-' . $docUid . '.pdf',
            'doc_uid' => $docUid,
        ];
    }

    /**
     * @return array{ok:bool,message:string,pdf:string,filename:string,doc_uid:string}
     */
    public function loanStatementPdf(int $loanId): array
    {
        $loan = Database::first(
            'SELECT l.*, c.full_name, c.guardian_name, c.village, c.district, c.mobile_last4,
                    br.name AS branch_name, bc.bc_code, u.full_name AS bc_name
             FROM loans l
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN branches br ON br.id = l.branch_id
             LEFT JOIN bc_agents bc ON bc.id = l.bc_id
             LEFT JOIN users u ON u.id = bc.user_id
             WHERE l.id = ? LIMIT 1',
            [$loanId]
        );

        if ($loan === null) {
            return ['ok' => false, 'message' => 'That account no longer exists.', 'pdf' => '', 'filename' => '', 'doc_uid' => ''];
        }

        $docUid = 'LS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        try {
            $pdf = $this->newDocument('Loan statement ' . $loan['account_number']);
            $this->documentHeader($pdf, 'LOAN RECOVERY STATEMENT', $docUid);

            $verifyUrl = Config::baseUrl() . 'verify/' . $docUid;
            $pdf->qrCode(QrCode::encode($verifyUrl), $pdf->pageWidth() - 46, 16, 30);
            $pdf->setY(50);

            $full = $pdf->contentWidth();
            $this->sectionTitle($pdf, 'Account');
            $pdf->keyValue($pdf->marginLeft(), $full, 'Account number', (string) $loan['account_number']);
            $pdf->keyValue($pdf->marginLeft(), $full, 'Borrower', (string) $loan['full_name']
                . ($loan['guardian_name'] !== null ? ' S/o, W/o ' . $loan['guardian_name'] : ''));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Village / district',
                (string) ($loan['village'] ?? '-') . ' / ' . (string) ($loan['district'] ?? '-'));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Branch', (string) ($loan['branch_name'] ?? '-'));
            $pdf->keyValue($pdf->marginLeft(), $full, 'BC agent',
                trim((string) ($loan['bc_name'] ?? 'unallocated') . ' ' . (string) ($loan['bc_code'] ?? '')));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Product', (string) ($loan['product_name'] ?? '-'));

            $pdf->moveY(2);
            $this->sectionTitle($pdf, 'Balances');
            foreach ([
                'Sanctioned'        => $loan['sanction_amount'],
                'Disbursed'         => $loan['disbursed_amount'],
                'Outstanding'       => $loan['outstanding_amount'],
                'Overdue'           => $loan['overdue_amount'],
                'Principal overdue' => $loan['principal_overdue'],
                'Interest overdue'  => $loan['interest_overdue'],
                'Instalment (EMI)'  => $loan['emi_amount'],
                'Total recovered'   => $loan['total_recovered'],
            ] as $label => $amount) {
                $pdf->keyValue($pdf->marginLeft(), $full, $label, 'Rs. ' . number_format((float) $amount, 2));
            }

            $pdf->keyValue($pdf->marginLeft(), $full, 'Asset classification',
                (string) $loan['asset_class'] . ' (' . (int) $loan['dpd'] . ' days past due)');
            $pdf->keyValue($pdf->marginLeft(), $full, 'Recovery status',
                ucwords(str_replace('_', ' ', (string) $loan['recovery_status'])));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Visits so far', (string) (int) $loan['visit_count']);
            $pdf->keyValue($pdf->marginLeft(), $full, 'Last visit',
                $loan['last_visit_at'] === null ? 'never' : date('d-m-Y h:i A', strtotime((string) $loan['last_visit_at'])));

            // ---- visit history table --------------------------------------
            $visits = Database::all(
                'SELECT v.visited_at, v.visit_status, v.promise_amount, v.promise_date,
                        v.collected_amount, v.remarks, u.full_name AS agent
                 FROM visits v LEFT JOIN users u ON u.id = v.user_id
                 WHERE v.loan_id = ? ORDER BY v.visited_at DESC LIMIT 200',
                [$loanId]
            );

            if ($visits !== []) {
                $pdf->moveY(4);
                $this->sectionTitle($pdf, 'Visit history');
                $rows = array_map(static function (array $v): array {
                    return [
                        date('d-m-Y', strtotime((string) $v['visited_at'])),
                        ucwords(str_replace('_', ' ', (string) $v['visit_status'])),
                        number_format((float) $v['promise_amount'], 2),
                        $v['promise_date'] === null ? '-' : date('d-m-Y', strtotime((string) $v['promise_date'])),
                        number_format((float) $v['collected_amount'], 2),
                        (string) ($v['agent'] ?? '-'),
                    ];
                }, $visits);

                $pdf->table(
                    ['Date', 'Status', 'Promise', 'Promise date', 'Collected', 'Agent'],
                    $rows,
                    [24, 30, 26, 26, 26, 50],
                    ['L', 'L', 'R', 'L', 'R', 'L']
                );
            }

            // ---- recovery history table -----------------------------------
            $recoveries = Database::all(
                'SELECT receipt_number, collected_at, amount, payment_mode, txn_reference, status
                 FROM recoveries WHERE loan_id = ? ORDER BY collected_at DESC LIMIT 200',
                [$loanId]
            );

            if ($recoveries !== []) {
                $pdf->moveY(4);
                $this->sectionTitle($pdf, 'Recovery history');
                $rows = array_map(static function (array $r): array {
                    return [
                        (string) $r['receipt_number'],
                        date('d-m-Y', strtotime((string) $r['collected_at'])),
                        number_format((float) $r['amount'], 2),
                        strtoupper((string) $r['payment_mode']),
                        (string) ($r['txn_reference'] ?? '-'),
                        ucfirst((string) $r['status']),
                    ];
                }, $recoveries);

                $pdf->table(
                    ['Receipt', 'Date', 'Amount', 'Mode', 'Reference', 'Status'],
                    $rows,
                    [38, 24, 26, 22, 44, 28],
                    ['L', 'L', 'R', 'L', 'L', 'L']
                );
            }

            $output = $pdf->output();
        } catch (\Throwable $e) {
            Logger::error('Loan statement PDF failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => 'The statement could not be generated: ' . $e->getMessage(),
                'pdf' => '', 'filename' => '', 'doc_uid' => '',
            ];
        }

        $this->registerDocument($docUid, 'loan_statement', 'loan', $loanId, $output);

        return [
            'ok' => true, 'message' => '', 'pdf' => $output,
            'filename' => 'statement-' . $loan['account_number'] . '-' . $docUid . '.pdf',
            'doc_uid' => $docUid,
        ];
    }

    /**
     * @return array{ok:bool,message:string,pdf:string,filename:string,doc_uid:string}
     */
    public function receiptPdf(int $recoveryId): array
    {
        $recovery = Database::first(
            'SELECT r.*, l.account_number, c.full_name, c.village, c.mobile_last4,
                    br.name AS branch_name, bc.bc_code, u.full_name AS agent_name
             FROM recoveries r
             JOIN loans l ON l.id = r.loan_id
             JOIN customers c ON c.id = r.customer_id
             LEFT JOIN branches br ON br.id = r.branch_id
             LEFT JOIN bc_agents bc ON bc.id = r.bc_id
             LEFT JOIN users u ON u.id = bc.user_id
             WHERE r.id = ? LIMIT 1',
            [$recoveryId]
        );

        if ($recovery === null) {
            return ['ok' => false, 'message' => 'That receipt no longer exists.', 'pdf' => '', 'filename' => '', 'doc_uid' => ''];
        }

        $docUid = 'RCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        try {
            // A5 is the practical size for a field receipt.
            $pdf = new Pdf('P', 'A5');
            $pdf->setTitle('Receipt ' . $recovery['receipt_number']);
            $pdf->setAuthor(Settings::getString('company.organisation', 'LRMS'));
            $pdf->setMargins(12, 12, 12, 14);
            $pdf->addPage();

            $this->documentHeader($pdf, 'RECOVERY RECEIPT', $docUid, 8);

            $verifyUrl = Config::baseUrl() . 'verify/' . $docUid;
            $pdf->qrCode(QrCode::encode($verifyUrl), $pdf->pageWidth() - 36, 14, 24);
            $pdf->setY(44);

            $full = $pdf->contentWidth();
            $pdf->keyValue($pdf->marginLeft(), $full, 'Receipt number', (string) $recovery['receipt_number']);
            $pdf->keyValue($pdf->marginLeft(), $full, 'Date', date('d-m-Y h:i A', strtotime((string) $recovery['collected_at'])));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Account number', (string) $recovery['account_number']);
            $pdf->keyValue($pdf->marginLeft(), $full, 'Received from', (string) $recovery['full_name']);
            $pdf->keyValue($pdf->marginLeft(), $full, 'Village', (string) ($recovery['village'] ?? '-'));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Branch', (string) ($recovery['branch_name'] ?? '-'));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Collected by',
                trim((string) ($recovery['agent_name'] ?? '-') . ' ' . (string) ($recovery['bc_code'] ?? '')));
            $pdf->keyValue($pdf->marginLeft(), $full, 'Mode', strtoupper((string) $recovery['payment_mode']));
            if (!empty($recovery['txn_reference'])) {
                $pdf->keyValue($pdf->marginLeft(), $full, 'Reference', (string) $recovery['txn_reference']);
            }
            $pdf->keyValue($pdf->marginLeft(), $full, 'Status', ucfirst((string) $recovery['status']));

            $pdf->moveY(4);
            $pdf->filledRect($pdf->marginLeft(), $pdf->y(), $full, 16, 240, 247, 243);
            $pdf->setFont('B', 14);
            $pdf->setTextColor(13, 92, 70);
            $pdf->cell($pdf->marginLeft(), $pdf->y() + 11, $full,
                'Rs. ' . number_format((float) $recovery['amount'], 2), 'C');
            $pdf->setTextColor(0, 0, 0);
            $pdf->moveY(22);

            $pdf->setFont('', 7.5);
            $pdf->setTextColor(110, 116, 124);
            $pdf->write(
                'Pending receipts are subject to verification by the branch. Verify this receipt at '
                . $verifyUrl . ' or by scanning the QR code.',
                3.8
            );

            $output = $pdf->output();
        } catch (\Throwable $e) {
            Logger::error('Receipt PDF failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => 'The receipt could not be generated: ' . $e->getMessage(),
                'pdf' => '', 'filename' => '', 'doc_uid' => '',
            ];
        }

        $this->registerDocument($docUid, 'recovery_receipt', 'recovery', $recoveryId, $output);

        return [
            'ok' => true, 'message' => '', 'pdf' => $output,
            'filename' => 'receipt-' . $recovery['receipt_number'] . '.pdf',
            'doc_uid' => $docUid,
        ];
    }

    /**
     * A tabular report as a PDF (landscape, repeating headers).
     *
     * @param array{title:string,headers:list<string>,aligns:list<string>,rows:list<list<mixed>>,totals:array<string,mixed>} $report
     */
    public function tabularPdf(array $report, string $subtitle): string
    {
        $docUid = 'RPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        $pdf = new Pdf('L', 'A4');
        $pdf->setTitle($report['title']);
        $pdf->setAuthor(Settings::getString('company.organisation', 'LRMS'));
        $pdf->setMargins(10, 10, 10, 14);
        $pdf->setFooter(function (Pdf $p, int $page) use ($docUid): void {
            $p->setFont('', 7);
            $p->setTextColor(120, 126, 134);
            $p->text($p->marginLeft(), $p->pageHeight() - 7,
                'LRMS ' . $docUid . ' - generated ' . date('d-m-Y H:i'));
            $p->cell($p->marginLeft(), $p->pageHeight() - 7, $p->contentWidth(), 'Page ' . $page, 'R');
        });
        $pdf->addPage();

        $this->documentHeader($pdf, strtoupper($report['title']), $docUid);
        $pdf->setY(34);
        $pdf->setFont('', 8.5);
        $pdf->setTextColor(90, 96, 104);
        $pdf->text($pdf->marginLeft(), $pdf->y(), $subtitle);
        $pdf->setTextColor(0, 0, 0);
        $pdf->setY($pdf->y() + 4);

        // Distribute the available width across the columns.
        $columnCount = max(1, count($report['headers']));
        $available = $pdf->contentWidth();
        $widths = array_fill(0, $columnCount, $available / $columnCount);

        $pdf->table($report['headers'], $report['rows'], $widths, $report['aligns'], 5.6, 7.2);

        $pdf->moveY(4);
        $pdf->setFont('B', 8);
        $summary = [];
        foreach ($report['totals'] as $label => $value) {
            $summary[] = ucwords(str_replace('_', ' ', (string) $label)) . ': '
                . (is_float($value) ? number_format($value, 2) : (string) $value);
        }
        $pdf->write(implode('   |   ', $summary), 4.4);

        $output = $pdf->output();
        $this->registerDocument($docUid, 'tabular_report', 'report', $report['title'], $output);

        return $output;
    }

    // ------------------------------------------------------------------
    // Shared PDF chrome
    // ------------------------------------------------------------------

    private function newDocument(string $title): Pdf
    {
        $pdf = new Pdf('P', 'A4');
        $pdf->setTitle($title);
        $pdf->setAuthor(Settings::getString('company.organisation', 'LRMS'));
        $pdf->setMargins(14, 14, 14, 16);
        $pdf->setFooter(function (Pdf $p, int $page): void {
            $p->setFont('', 7);
            $p->setTextColor(120, 126, 134);
            $p->text($p->marginLeft(), $p->pageHeight() - 8,
                Settings::getString('company.app_name', 'LRMS') . ' - generated ' . date('d-m-Y H:i'));
            $p->cell($p->marginLeft(), $p->pageHeight() - 8, $p->contentWidth(), 'Page ' . $page, 'R');
        });
        $pdf->addPage();

        return $pdf;
    }

    private function documentHeader(Pdf $pdf, string $heading, string $docUid, float $logoSize = 12): void
    {
        $organisation = Settings::getString('company.organisation', '');
        $appName = Settings::getString('company.app_name', 'LRMS');

        // Brand band. The app logo stands in for the bank logo when none is set.
        $pdf->filledRect(0, 0, $pdf->pageWidth(), 3, 13, 92, 70);

        $x = $pdf->marginLeft();
        $logo = $this->logoPath();
        if ($logo !== null && $pdf->image($logo, $x, 10, $logoSize, $logoSize)) {
            $x += $logoSize + 4;
        }

        $pdf->setFont('B', 13);
        $pdf->setTextColor(13, 92, 70);
        $pdf->text($x, 16, $organisation !== '' ? $organisation : $appName);

        $pdf->setFont('', 8);
        $pdf->setTextColor(90, 96, 104);
        $pdf->text($x, 21, $organisation !== '' ? $appName . ' - Loan Recovery Management System' : 'Loan Recovery Management System');

        $pdf->setFont('B', 10.5);
        $pdf->setTextColor(20, 22, 26);
        $pdf->text($pdf->marginLeft(), 30, $heading);

        $pdf->setFont('', 7);
        $pdf->setTextColor(120, 126, 134);
        $pdf->text($pdf->marginLeft(), 34.5, 'Document ID ' . $docUid);

        $pdf->setDrawColor(220, 226, 230);
        $pdf->line($pdf->marginLeft(), 37, $pdf->pageWidth() - $pdf->marginLeft(), 37);

        $pdf->setTextColor(0, 0, 0);
    }

    private function sectionTitle(Pdf $pdf, string $title): void
    {
        $pdf->ensureSpace(12);
        $pdf->setFont('B', 9.5);
        $pdf->setTextColor(13, 92, 70);
        $pdf->text($pdf->marginLeft(), $pdf->y() + 4, $title);
        $pdf->setDrawColor(220, 226, 230);
        $pdf->line($pdf->marginLeft(), $pdf->y() + 5.6, $pdf->pageWidth() - $pdf->marginLeft(), $pdf->y() + 5.6);
        $pdf->setTextColor(0, 0, 0);
        $pdf->setFont('', 9);
        $pdf->moveY(8);
    }

    /** Prefer a PNG/JPG logo if one was uploaded, else the bundled SVG cannot be drawn. */
    private function logoPath(): ?string
    {
        foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'uploads/logo.png'] as $candidate) {
            $absolute = BASE_PATH . '/' . $candidate;
            if (is_file($absolute)) {
                return $absolute;
            }
        }
        // The SVG placeholder cannot be embedded by the minimal PDF writer;
        // the coloured brand band above stands in for it.
        return null;
    }

    private function registerDocument(string $docUid, string $type, string $entityType, int|string $entityId, string $content): void
    {
        try {
            Database::insert('report_documents', [
                'doc_uid'      => $docUid,
                'report_type'  => $type,
                'entity_type'  => $entityType,
                'entity_id'    => (string) $entityId,
                'params_json'  => json_encode(['generated_at' => date('c')]),
                'content_hash' => hash('sha256', $content),
                'generated_by' => Auth::id(),
            ]);

            Audit::log('report.generated', $entityType, $entityId,
                $type . ' PDF generated (' . $docUid . ')');
        } catch (\Throwable $e) {
            // The PDF is still valid; only the verification record failed.
            Logger::warning('report_documents insert failed: ' . $e->getMessage());
        }
    }
}
