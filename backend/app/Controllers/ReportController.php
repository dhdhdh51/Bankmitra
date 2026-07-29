<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Services\ReportService;
use Lib\SpreadsheetWriter;

/**
 * Reports module: on-screen tables plus Excel / CSV / PDF export.
 */
final class ReportController extends Controller
{
    protected ?string $permission = 'reports.view';

    public function index(): void
    {
        $this->view('reports/index', [
            'pageTitle' => 'Reports',
            'types'     => ReportService::TYPES,
        ]);
    }

    public function show(array $args): void
    {
        $type = (string) ($args['type'] ?? '');
        if (!isset(ReportService::TYPES[$type])) {
            $this->redirect('reports', 'warning', 'Unknown report: ' . $type);
            return;
        }

        $filters = $this->filters();

        try {
            $report = (new ReportService())->build($type, $filters);
        } catch (\Throwable $e) {
            \Lib\Logger::error('Report build failed: ' . $e->getMessage(), ['type' => $type]);
            $this->redirect('reports', 'danger',
                'That report could not be built: ' . $e->getMessage());
            return;
        }

        $this->view('reports/show', [
            'pageTitle' => $report['title'],
            'type'      => $type,
            'report'    => $report,
            'filters'   => $filters,
            'branches'  => $this->branchOptions(),
            'agents'    => $this->agentOptions(),
            'types'     => ReportService::TYPES,
        ]);
    }

    public function export(array $args): void
    {
        $this->authorize('reports.export');

        $type = (string) ($args['type'] ?? '');
        if (!isset(ReportService::TYPES[$type])) {
            $this->redirect('reports', 'warning', 'Unknown report: ' . $type);
            return;
        }

        $format = strtolower($this->request->str('format', 'xlsx'));
        $filters = $this->filters();

        try {
            $service = new ReportService();
            $report = $service->build($type, $filters);
        } catch (\Throwable $e) {
            $this->redirect('reports/' . $type, 'danger',
                'The export could not be produced: ' . $e->getMessage());
            return;
        }

        $subtitle = sprintf('Period %s to %s', $filters['from'], $filters['to']);
        $stamp = date('Ymd-Hi');
        $base = 'lrms-' . $type . '-' . $stamp;

        Audit::log('report.exported', 'report', $type,
            strtoupper($format) . ' export, ' . count($report['rows']) . ' rows, ' . $subtitle);

        switch ($format) {
            case 'csv':
                Response::attachment(
                    SpreadsheetWriter::csv($report['headers'], $report['rows']),
                    $base . '.csv',
                    'text/csv; charset=utf-8'
                );
                return;

            case 'pdf':
                Response::attachment(
                    $service->tabularPdf($report, $subtitle),
                    $base . '.pdf',
                    'application/pdf'
                );
                return;

            case 'xlsx':
            default:
                Response::attachment(
                    SpreadsheetWriter::xlsx(
                        $report['headers'],
                        $report['rows'],
                        substr($report['title'], 0, 31),
                        $report['formats']
                    ),
                    $base . '.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                );
                return;
        }
    }

    /** @return array{from:string,to:string,branch_id:int,bc_id:int} */
    private function filters(): array
    {
        $from = $this->request->str('from', date('Y-m-01'));
        $to = $this->request->str('to', date('Y-m-d'));

        $fromTs = strtotime($from) ?: time();
        $toTs = strtotime($to) ?: time();
        if ($fromTs > $toTs) {
            [$fromTs, $toTs] = [$toTs, $fromTs];
        }

        $branchId = $this->request->int('branch_id');
        $bcId = $this->request->int('bc_id');

        // Scope narrowing is enforced in the service too, but pin the obvious
        // cases here so the UI shows what the user is actually allowed to see.
        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)) {
            $branchId = Auth::branchId() ?? 0;
        }
        if (Auth::isBcAgent()) {
            $bcId = Auth::bcId() ?? 0;
        }

        return [
            'from'      => date('Y-m-d', $fromTs),
            'to'        => date('Y-m-d', $toTs),
            'branch_id' => $branchId,
            'bc_id'     => $bcId,
        ];
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
