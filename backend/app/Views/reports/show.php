<?php

/**
 * @var string $type
 * @var array{title:string,headers:list<string>,aligns:list<string>,formats:list<string>,rows:list<list<mixed>>,totals:array<string,mixed>} $report
 * @var array{from:string,to:string,branch_id:int,bc_id:int} $filters
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $agents
 * @var array<string,string> $types
 */

use App\Core\Auth;
use App\Core\View;

$query = http_build_query([
    'from' => $filters['from'],
    'to' => $filters['to'],
    'branch_id' => $filters['branch_id'] ?: '',
    'bc_id' => $filters['bc_id'] ?: '',
]);
?>

<div class="lrms-page-head">
    <div>
        <h1><?= View::e($report['title']) ?></h1>
        <div class="lrms-page-sub">
            <?= View::e(View::date($filters['from'])) ?> to <?= View::e(View::date($filters['to'])) ?>
            &middot; <?= number_format(count($report['rows'])) ?> row(s)
        </div>
    </div>
    <div class="ms-auto d-flex flex-wrap gap-2">
        <?php if (Auth::can('reports.export')): ?>
            <a class="btn btn-sm btn-outline-success"
               href="<?= View::e(View::url('reports/' . $type . '/export?format=xlsx&' . $query)) ?>">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= View::e(View::url('reports/' . $type . '/export?format=csv&' . $query)) ?>">
                <i class="bi bi-filetype-csv me-1"></i>CSV
            </a>
            <a class="btn btn-sm btn-outline-danger"
               href="<?= View::e(View::url('reports/' . $type . '/export?format=pdf&' . $query)) ?>">
                <i class="bi bi-file-earmark-pdf me-1"></i>PDF
            </a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= View::e(View::url('reports')) ?>">
            <i class="bi bi-arrow-left me-1"></i>All reports
        </a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body pb-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label" for="report_type">Report</label>
                <select class="form-select form-select-sm" id="report_type"
                        onchange="window.location.href=<?= json_encode(View::url('reports/')) ?>+this.value">
                    <?php foreach ($types as $key => $label): ?>
                        <option value="<?= View::e($key) ?>" <?= $key === $type ? 'selected' : '' ?>>
                            <?= View::e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="from">From</label>
                <input type="date" class="form-control form-control-sm" id="from" name="from"
                       value="<?= View::e($filters['from']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="to">To</label>
                <input type="date" class="form-control form-control-sm" id="to" name="to"
                       value="<?= View::e($filters['to']) ?>">
            </div>
            <?php if (!Auth::hasRole(Auth::ROLE_BRANCH_MANAGER) && !Auth::isBcAgent()): ?>
                <div class="col-md-2">
                    <label class="form-label" for="branch_id">Branch</label>
                    <select class="form-select form-select-sm" id="branch_id" name="branch_id">
                        <option value="">All</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>" <?= $filters['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>>
                                <?= View::e($branch['code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if (!Auth::isBcAgent()): ?>
                <div class="col-md-2">
                    <label class="form-label" for="bc_id">BC agent</label>
                    <select class="form-select form-select-sm" id="bc_id" name="bc_id">
                        <option value="">All</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= (int) $agent['id'] ?>" <?= $filters['bc_id'] === (int) $agent['id'] ? 'selected' : '' ?>>
                                <?= View::e($agent['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-1">
                <button class="btn btn-sm btn-success w-100" type="submit">Run</button>
            </div>
        </form>
    </div>
</div>

<?php if ($report['totals'] !== []): ?>
    <div class="row g-2 mb-3">
        <?php foreach ($report['totals'] as $label => $value): ?>
            <div class="col-6 col-lg-3">
                <div class="lrms-tile">
                    <div class="min-w-0">
                        <div class="lrms-tile-value">
                            <?= is_float($value)
                                ? View::e(View::moneyShort($value))
                                : View::e(number_format((float) $value)) ?>
                        </div>
                        <div class="lrms-tile-label"><?= View::e(View::label((string) $label)) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <?php if ($report['rows'] === []): ?>
        <div class="lrms-empty">
            <i class="bi bi-inbox"></i>
            No data for this period. Try widening the date range.
        </div>
    <?php else: ?>
        <div class="table-responsive" style="max-height:70vh">
            <table class="table table-lrms table-hover table-sm align-middle">
                <thead class="sticky-top">
                <tr>
                    <?php foreach ($report['headers'] as $index => $header): ?>
                        <th class="<?= ($report['aligns'][$index] ?? 'L') === 'R' ? 'lrms-num' : '' ?>">
                            <?= View::e($header) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                    <tr>
                        <?php foreach (array_values($row) as $index => $cell): ?>
                            <?php
                            $align = $report['aligns'][$index] ?? 'L';
                            $format = $report['formats'][$index] ?? 'text';
                            $class = $align === 'R' ? 'lrms-num' : ($align === 'C' ? 'text-center' : '');
                            ?>
                            <td class="<?= $class ?> small">
                                <?php if ($format === 'money' && is_numeric($cell)): ?>
                                    <?= View::e(View::money($cell, false)) ?>
                                <?php elseif ($format === 'number' && is_numeric($cell)): ?>
                                    <?= View::e(number_format((float) $cell, (float) $cell === floor((float) $cell) ? 0 : 1)) ?>
                                <?php else: ?>
                                    <?= View::e((string) $cell) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="text-muted small px-3 py-2 border-top">
            <?= number_format(count($report['rows'])) ?> row(s) shown.
            Large reports are capped - narrow the date range or export for the full dataset.
        </div>
    <?php endif; ?>
</div>
