<?php

/**
 * @var list<array<string,mixed>> $batches
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var int $unallocated
 * @var list<string> $headers
 * @var array{upload_max_filesize:string,post_max_size:string,max_execution_time:string,zip:bool} $limits
 */

use App\Core\Csrf;
use App\Core\View;
?>

<div class="lrms-page-head">
    <div>
        <h1>Excel upload &amp; allocation</h1>
        <div class="lrms-page-sub">
            Import recovery accounts and distribute them to BC agents. Re-uploading the same file
            refreshes balances instead of creating duplicates.
        </div>
    </div>
    <div class="ms-auto">
        <a class="btn btn-sm btn-outline-success" href="<?= View::e(View::url('imports/template')) ?>">
            <i class="bi bi-download me-1"></i>Download template
        </a>
    </div>
</div>

<?php if (!$limits['zip']): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        The PHP <code>zip</code> extension is not enabled, so <strong>.xlsx</strong> files cannot be
        read and Excel export is unavailable. Enable it in
        <strong>cPanel &rarr; Select PHP Version &rarr; Extensions</strong>, or upload CSV instead.
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">Import a file</div>
            <form method="post" action="<?= View::e(View::url('imports/import')) ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="sheet">Spreadsheet (.xlsx or .csv) *</label>
                        <input type="file" class="form-control" id="sheet" name="sheet"
                               accept=".xlsx,.csv,text/csv" required>
                        <div class="form-text">
                            Server limits: max upload <?= View::e($limits['upload_max_filesize']) ?>,
                            max POST <?= View::e($limits['post_max_size']) ?>,
                            time limit <?= View::e($limits['max_execution_time']) ?>s.
                            Old <code>.xls</code> files are not supported - use "Save As" &rarr; .xlsx.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="strategy">Allocation strategy</label>
                        <select class="form-select" id="strategy" name="strategy">
                            <option value="bc_code">Use the BC_CODE column in the file (recommended)</option>
                            <option value="equal_branch">Distribute equally among each branch's BC agents</option>
                            <option value="none">Import only - allocate later</option>
                        </select>
                        <div class="form-text">
                            With <strong>BC_CODE</strong>, a code that does not match an active BC agent
                            still imports the account but leaves it unallocated, and the row is listed in
                            the rejected-rows report.
                        </div>
                    </div>

                    <button class="btn btn-success" type="submit">
                        <i class="bi bi-upload me-1"></i>Import
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">Auto allocation</div>
            <div class="card-body">
                <p class="mb-2">
                    <span class="display-6"><?= number_format($unallocated) ?></span>
                    <span class="text-muted">account(s) currently have no BC agent.</span>
                </p>
                <p class="small text-muted">
                    Equal distribution gives the next account to whichever active BC agent in that
                    branch currently has the smallest workload, highest-overdue accounts first.
                </p>
                <form method="post" action="<?= View::e(View::url('imports/allocate')) ?>"
                      data-confirm="Distribute all unallocated accounts equally among branch BC agents?">
                    <?= Csrf::field() ?>
                    <button class="btn btn-outline-success" type="submit" <?= $unallocated === 0 ? 'disabled' : '' ?>>
                        <i class="bi bi-diagram-3 me-1"></i>Distribute equally now
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Recognised columns</div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Header names are matched case-insensitively and common aliases are accepted
                    (e.g. <code>ACCOUNT NO</code>, <code>Outstanding</code>, <code>Arrears</code>).
                    Only <code>ACCOUNT_NUMBER</code> and <code>CUSTOMER_NAME</code> are mandatory.
                </p>
                <div style="max-height:220px;overflow:auto">
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($headers as $header): ?>
                            <code class="small bg-light border rounded px-1"><?= View::e($header) ?></code>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Import history</div>
    <?php if ($batches === []): ?>
        <div class="lrms-empty"><i class="bi bi-file-earmark-x"></i>No imports yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>File</th><th>When</th><th>By</th><th>Strategy</th>
                    <th class="lrms-num">Rows</th><th class="lrms-num">New</th>
                    <th class="lrms-num">Updated</th><th class="lrms-num">Skipped</th>
                    <th class="lrms-num">Failed</th><th class="lrms-num">Allocated</th>
                    <th>Status</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($batches as $batch): ?>
                    <tr>
                        <td class="small" style="max-width:200px">
                            <span class="d-inline-block text-truncate" style="max-width:190px"
                                  title="<?= View::e($batch['file_name']) ?>">
                                <?= View::e($batch['file_name']) ?>
                            </span>
                        </td>
                        <td class="small text-nowrap"><?= View::e(View::dateTime($batch['created_at'])) ?></td>
                        <td class="small"><?= View::e($batch['uploaded_by_name'] ?? 'system') ?></td>
                        <td class="small"><?= View::e(View::label((string) $batch['strategy'])) ?></td>
                        <td class="lrms-num"><?= number_format((int) $batch['total_rows']) ?></td>
                        <td class="lrms-num text-success"><?= number_format((int) $batch['inserted_rows']) ?></td>
                        <td class="lrms-num"><?= number_format((int) $batch['updated_rows']) ?></td>
                        <td class="lrms-num text-warning-emphasis"><?= number_format((int) $batch['skipped_rows']) ?></td>
                        <td class="lrms-num text-danger"><?= number_format((int) $batch['failed_rows']) ?></td>
                        <td class="lrms-num"><?= number_format((int) $batch['allocated_rows']) ?></td>
                        <td>
                            <?php
                            $tone = ['completed' => 'success', 'partial' => 'warning',
                                     'failed' => 'danger', 'processing' => 'info'][$batch['status']] ?? 'secondary';
                            ?>
                            <span class="badge badge-soft bg-<?= View::e($tone) ?>">
                                <?= View::e(ucfirst((string) $batch['status'])) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <?php if (!empty($batch['error_report'])): ?>
                                <a class="btn btn-sm btn-outline-danger"
                                   href="<?= View::e(View::url('imports/' . (int) $batch['id'] . '/errors')) ?>">
                                    <i class="bi bi-download"></i> Rejected rows
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($batch['message'])): ?>
                        <tr class="table-light">
                            <td colspan="12" class="small text-muted">
                                <pre class="mb-0" style="white-space:pre-wrap;font-size:.74rem;max-height:110px;overflow:auto"><?= View::e($batch['message']) ?></pre>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
