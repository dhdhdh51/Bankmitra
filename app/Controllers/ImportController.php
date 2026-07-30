<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Services\AllocationService;
use App\Services\PhotoStorageService;

/**
 * Excel/CSV upload and the auto-allocation engine.
 */
final class ImportController extends Controller
{
    protected ?string $permission = 'loans.allocate';

    public function index(): void
    {
        $pagination = $this->paginate(20);

        $total = (int) Database::value('SELECT COUNT(*) FROM allocation_batches', [], 0);

        $batches = Database::all(
            'SELECT ab.*, u.full_name AS uploaded_by_name
             FROM allocation_batches ab
             LEFT JOIN users u ON u.id = ab.uploaded_by
             ORDER BY ab.created_at DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset']
        );

        [$scope, $params] = Auth::scopeSql('l.branch_id');

        $this->view('imports/index', [
            'pageTitle'   => 'Excel upload & allocation',
            'batches'     => $batches,
            'meta'        => $this->paginationMeta($total, $pagination),
            'unallocated' => (int) Database::value(
                'SELECT COUNT(*) FROM loans l WHERE l.bc_id IS NULL AND l.status = "active"' . $scope,
                $params,
                0
            ),
            'headers'     => AllocationService::templateHeaders(),
            'limits'      => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size'       => ini_get('post_max_size'),
                'max_execution_time'  => ini_get('max_execution_time'),
                'zip'                 => class_exists(\ZipArchive::class),
            ],
        ]);
    }

    /** POST uploads/import */
    public function import(): void
    {
        if (!$this->request->isPost()) {
            $this->redirect('imports');
            return;
        }

        $this->verifyCsrf();

        $file = $this->request->file('sheet');
        if ($file === null) {
            $this->redirect('imports', 'danger', 'Choose a .xlsx or .csv file to upload.');
            return;
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $this->redirect('imports', 'danger', $this->uploadError((int) $file['error']));
            return;
        }

        $maxBytes = (int) Config::get('max_excel_bytes', 20 * 1024 * 1024);
        if ((int) $file['size'] > $maxBytes) {
            $this->redirect('imports', 'danger', sprintf(
                'The file is %.1f MB which exceeds the %.0f MB limit. Split it into smaller files.',
                (int) $file['size'] / 1048576,
                $maxBytes / 1048576
            ));
            return;
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'csv', 'txt'], true)) {
            $this->redirect('imports', 'danger',
                'Only .xlsx and .csv files are supported. If you have an old .xls file, open it in '
                . 'Excel and use "Save As" -> "Excel Workbook (.xlsx)".');
            return;
        }

        $strategy = $this->request->str('strategy', 'bc_code');

        // A big import can outlive the default limit on shared hosting.
        @set_time_limit(600);

        $result = (new AllocationService())->import(
            (string) $file['tmp_name'],
            (string) $file['name'],
            $strategy,
            (int) Auth::id()
        );

        if (!$result['ok']) {
            $this->redirect('imports', 'danger', $result['message']);
            return;
        }

        $tone = ($result['failed'] > 0 || $result['skipped'] > 0) ? 'warning' : 'success';
        $message = $result['message'];
        if ($result['error_report'] !== null) {
            $message .= ' A CSV of the rejected rows is available from the batch list.';
        }

        $this->redirect('imports', $tone, $message);
    }

    /** Download the .xlsx import template. */
    public function template(): void
    {
        Response::attachment(
            AllocationService::template(),
            'lrms-allocation-template.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /** Download the rejected-rows CSV of a batch. */
    public function errors(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);

        $batch = Database::first('SELECT * FROM allocation_batches WHERE id = ? LIMIT 1', [$id]);
        if ($batch === null || $batch['error_report'] === null) {
            $this->redirect('imports', 'warning', 'No error report is available for that batch.');
            return;
        }

        $absolute = PhotoStorageService::absolute($batch['error_report']);
        if ($absolute === null) {
            $this->redirect('imports', 'warning',
                'The error report file is missing from disk (it may have been cleaned up).');
            return;
        }

        Response::download($absolute, 'rejected-rows-batch-' . $id . '.csv', 'text/csv');
    }

    /** POST uploads/allocate - run equal distribution on demand. */
    public function allocate(): void
    {
        if (!$this->request->isPost()) {
            $this->redirect('imports');
            return;
        }

        $this->verifyCsrf();

        $branchId = $this->request->int('branch_id');
        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)) {
            // A branch manager may only allocate inside their own branch. If they
            // have no branch assigned, branchId used to fall through to 0 and
            // then to null, which distributeEqually() reads as "every branch" -
            // so the guard has to refuse rather than widen the scope.
            $branchId = Auth::branchId() ?? 0;
            if ($branchId <= 0) {
                $this->redirect('imports', 'danger',
                    'Your account is not linked to a branch, so there is nothing to '
                    . 'allocate. Ask a Super Admin to set your branch.');
                return;
            }
        }

        $allocated = (new AllocationService())->distributeEqually($branchId > 0 ? $branchId : null);

        if ($allocated === 0) {
            $this->redirect('imports', 'info',
                'Nothing to allocate. Either every account already has a BC agent, or the '
                . 'branches with unallocated accounts have no active BC agents.');
            return;
        }

        $this->redirect('imports', 'success',
            $allocated . ' account(s) distributed evenly among the active BC agents of each branch.');
    }

    private function uploadError(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The file is larger than the server allows (upload_max_filesize is currently '
                    . ini_get('upload_max_filesize') . '). Raise it in cPanel > MultiPHP INI Editor, '
                    . 'or split the file.';
            case UPLOAD_ERR_PARTIAL:
                return 'The upload was interrupted. Please try again.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was received.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'The server has no temporary upload folder. Please contact your host.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'The server could not write the file to disk. Check that uploads/ is writable (755).';
            default:
                return 'The upload failed (error code ' . $code . ').';
        }
    }
}
