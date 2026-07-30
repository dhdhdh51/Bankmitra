<?php

/**
 * @var list<array<string,mixed>> $loans
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var array<string,mixed> $summary
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $agents
 */

use App\Core\Auth;
use App\Core\View;
?>

<div class="lrms-page-head">
    <div>
        <h1>Loan accounts</h1>
        <div class="lrms-page-sub">Recovery portfolio, filtered to your access scope.</div>
    </div>
    <?php if (Auth::can('reports.export')): ?>
        <div class="ms-auto">
            <a class="btn btn-sm btn-outline-success"
               href="<?= View::e(View::url('reports/npa')) ?>">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>NPA report
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3">
        <div class="lrms-tile">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= number_format((int) $meta['total']) ?></div>
                <div class="lrms-tile-label">Accounts</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="lrms-tile tone-amber">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= View::e(View::moneyShort($summary['outstanding'] ?? 0)) ?></div>
                <div class="lrms-tile-label">Outstanding</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="lrms-tile tone-red">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= View::e(View::moneyShort($summary['overdue'] ?? 0)) ?></div>
                <div class="lrms-tile-label">Overdue</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="lrms-tile tone-teal">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= View::e(View::moneyShort($summary['recovered'] ?? 0)) ?></div>
                <div class="lrms-tile-label">Recovered to date</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body pb-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label" for="search">Search</label>
                <input type="search" class="form-control form-control-sm" id="search" name="search"
                       value="<?= View::e($filters['search']) ?>" placeholder="Account / CIF / name / village">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="asset_class">Classification</label>
                <select class="form-select form-select-sm" id="asset_class" name="asset_class">
                    <option value="">All</option>
                    <option value="NPA" <?= $filters['asset_class'] === 'NPA' ? 'selected' : '' ?>>NPA only</option>
                    <?php foreach (['STD', 'SMA0', 'SMA1', 'SMA2', 'SS', 'DF1', 'DF2', 'DF3', 'LOSS'] as $class): ?>
                        <option value="<?= View::e($class) ?>" <?= $filters['asset_class'] === $class ? 'selected' : '' ?>>
                            <?= View::e($class) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="recovery_status">Recovery status</label>
                <select class="form-select form-select-sm" id="recovery_status" name="recovery_status">
                    <option value="">All</option>
                    <?php foreach (['open', 'in_progress', 'promise', 'partly_paid', 'closed', 'ots', 'legal', 'write_off'] as $status): ?>
                        <option value="<?= View::e($status) ?>" <?= $filters['recovery_status'] === $status ? 'selected' : '' ?>>
                            <?= View::e(View::label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
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
            <div class="col-md-2">
                <label class="form-label" for="bc_id">BC agent</label>
                <select class="form-select form-select-sm" id="bc_id" name="bc_id">
                    <option value="">All</option>
                    <option value="-1" <?= $filters['allocation'] === 'unallocated' ? 'selected' : '' ?> disabled>- - -</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?= (int) $agent['id'] ?>" <?= $filters['bc_id'] === (int) $agent['id'] ? 'selected' : '' ?>>
                            <?= View::e($agent['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-sm btn-success w-100" type="submit"><i class="bi bi-search"></i></button>
            </div>
            <div class="col-12">
                <a class="small text-decoration-none<?= $filters['allocation'] === 'unallocated' ? ' fw-bold' : '' ?>"
                   href="?allocation=unallocated">
                    <i class="bi bi-person-dash me-1"></i>Show unallocated accounts only
                </a>
            </div>
        </form>
    </div>

    <?php if ($loans === []): ?>
        <div class="lrms-empty">
            <i class="bi bi-journal-x"></i>
            No accounts match. Import your portfolio from
            <a href="<?= View::e(View::url('uploads')) ?>">Excel Upload</a>.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>Account</th><th>Customer</th><th>Class</th>
                    <th class="lrms-num">Outstanding</th><th class="lrms-num">Overdue</th>
                    <th>BC</th><th>Last visit</th><th>Status</th><th>Risk</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($loans as $loan): ?>
                    <tr>
                        <td>
                            <a class="text-decoration-none fw-semibold"
                               href="<?= View::e(View::url('loans/' . (int) $loan['id'])) ?>">
                                <?= View::e($loan['account_number']) ?>
                            </a>
                            <div class="small text-muted"><?= View::e($loan['product_name'] ?? '-') ?></div>
                        </td>
                        <td>
                            <?= View::e($loan['full_name']) ?>
                            <div class="small text-muted">
                                <?= View::e($loan['village'] ?? '-') ?>
                                <?php if (!empty($loan['branch_name'])): ?>
                                    &middot; <?= View::e($loan['branch_name']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-soft bg-<?= View::e(View::assetClassBadge((string) $loan['asset_class'])) ?>">
                                <?= View::e($loan['asset_class']) ?>
                            </span>
                            <div class="small text-muted"><?= (int) $loan['dpd'] ?> DPD</div>
                        </td>
                        <td class="lrms-num"><?= View::e(View::money($loan['outstanding_amount'], false)) ?></td>
                        <td class="lrms-num text-danger"><?= View::e(View::money($loan['overdue_amount'], false)) ?></td>
                        <td class="small">
                            <?php if (!empty($loan['bc_name'])): ?>
                                <?= View::e($loan['bc_name']) ?>
                                <div class="small text-muted"><?= View::e($loan['bc_code']) ?></div>
                            <?php else: ?>
                                <span class="badge badge-soft bg-warning">unallocated</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= $loan['last_visit_at'] === null ? 'never' : View::e(View::date($loan['last_visit_at'])) ?>
                            <?php if ((int) $loan['visit_count'] > 0): ?>
                                <div class="small"><?= (int) $loan['visit_count'] ?> visit(s)</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-soft bg-secondary">
                                <?= View::e(View::label((string) $loan['recovery_status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($loan['risk_band'] !== null): ?>
                                <?php
                                $tone = ['low' => 'success', 'medium' => 'warning',
                                         'high' => 'orange', 'critical' => 'danger'][$loan['risk_band']] ?? 'secondary';
                                ?>
                                <span class="badge badge-soft bg-<?= View::e($tone) ?>">
                                    <?= View::e(ucfirst((string) $loan['risk_band'])) ?>
                                    <?= $loan['risk_score'] !== null ? ' ' . (int) $loan['risk_score'] : '' ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= View::e(View::url('loans/' . (int) $loan['id'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
