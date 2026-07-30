<?php

/**
 * @var list<array<string,mixed>> $recoveries
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var array<string,mixed> $summary
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $agents
 * @var bool $canVerify
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
?>

<div class="lrms-page-head">
    <div>
        <h1>Recoveries</h1>
        <div class="lrms-page-sub">
            Collections captured in the field start as <strong>pending</strong> and reduce the loan
            balance provisionally. Rejecting one restores the balance automatically.
        </div>
    </div>
    <?php if (Auth::can('reports.export')): ?>
        <div class="ms-auto">
            <a class="btn btn-sm btn-outline-success"
               href="<?= View::e(View::url('reports/recovery?from=' . urlencode($filters['from']) . '&to=' . urlencode($filters['to']))) ?>">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Recovery register
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3">
        <div class="lrms-tile tone-teal">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= View::e(View::moneyShort($summary['total_amount'] ?? 0)) ?></div>
                <div class="lrms-tile-label">Collected in period</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="lrms-tile">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= View::e(View::moneyShort($summary['verified_amount'] ?? 0)) ?></div>
                <div class="lrms-tile-label">Verified</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <a class="text-decoration-none" href="?status=pending&amp;from=<?= View::e($filters['from']) ?>&amp;to=<?= View::e($filters['to']) ?>">
            <div class="lrms-tile tone-amber">
                <div class="min-w-0">
                    <div class="lrms-tile-value"><?= View::e(View::moneyShort($summary['pending_amount'] ?? 0)) ?></div>
                    <div class="lrms-tile-label">
                        Awaiting verification (<?= number_format((int) ($summary['pending_count'] ?? 0)) ?>)
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <div class="lrms-tile tone-blue">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= number_format((int) $meta['total']) ?></div>
                <div class="lrms-tile-label">Receipts</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body pb-2">
        <form class="row g-2 align-items-end" method="get">
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
            <div class="col-md-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (['pending', 'verified', 'rejected', 'reversed'] as $status): ?>
                        <option value="<?= View::e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= View::e(ucfirst($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="payment_mode">Mode</label>
                <select class="form-select form-select-sm" id="payment_mode" name="payment_mode">
                    <option value="">All</option>
                    <?php foreach (['cash', 'transfer', 'upi', 'cheque', 'dd', 'other'] as $mode): ?>
                        <option value="<?= View::e($mode) ?>" <?= $filters['payment_mode'] === $mode ? 'selected' : '' ?>>
                            <?= View::e(strtoupper($mode)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="search">Search</label>
                <input type="search" class="form-control form-control-sm" id="search" name="search"
                       value="<?= View::e($filters['search']) ?>" placeholder="Receipt / account / ref">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-success w-100" type="submit">Apply</button>
            </div>
        </form>
    </div>

    <?php if ($recoveries === []): ?>
        <div class="lrms-empty"><i class="bi bi-cash-stack"></i>No collections in this period.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>Receipt</th><th>When</th><th>Account</th><th>Customer</th><th>BC</th>
                    <th class="lrms-num">Amount</th><th>Mode</th><th>Reference</th>
                    <th>Status</th><th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recoveries as $recovery): ?>
                    <?php $id = (int) $recovery['id']; ?>
                    <tr class="<?= $recovery['status'] === 'pending' ? 'table-warning' : '' ?>">
                        <td class="small"><code><?= View::e($recovery['receipt_number']) ?></code></td>
                        <td class="small text-nowrap"><?= View::e(View::dateTime($recovery['collected_at'])) ?></td>
                        <td class="small">
                            <a class="text-decoration-none"
                               href="<?= View::e(View::url('loans/' . (int) $recovery['loan_id'])) ?>">
                                <?= View::e($recovery['account_number']) ?>
                            </a>
                        </td>
                        <td class="small">
                            <?= View::e($recovery['full_name']) ?>
                            <div class="small text-muted"><?= View::e($recovery['village'] ?? '-') ?></div>
                        </td>
                        <td class="small">
                            <?= View::e($recovery['bc_name'] ?? '-') ?>
                            <?php if (!empty($recovery['bc_code'])): ?>
                                <div class="small text-muted"><?= View::e($recovery['bc_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="lrms-num fw-semibold"><?= View::e(View::money($recovery['amount'], false)) ?></td>
                        <td class="small"><?= View::e(strtoupper((string) $recovery['payment_mode'])) ?></td>
                        <td class="small text-muted"><?= View::e($recovery['txn_reference'] ?? '-') ?></td>
                        <td>
                            <?php
                            $tone = ['verified' => 'success', 'pending' => 'warning',
                                     'rejected' => 'danger', 'reversed' => 'dark'][$recovery['status']] ?? 'secondary';
                            ?>
                            <span class="badge badge-soft bg-<?= View::e($tone) ?>">
                                <?= View::e(ucfirst((string) $recovery['status'])) ?>
                            </span>
                            <?php if (!empty($recovery['verified_by_name'])): ?>
                                <div class="small text-muted"><?= View::e($recovery['verified_by_name']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($recovery['reject_reason'])): ?>
                                <div class="small text-danger"><?= View::e($recovery['reject_reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                               href="<?= View::e(View::url('recoveries/' . $id . '/receipt')) ?>" title="Receipt PDF">
                                <i class="bi bi-printer"></i>
                            </a>
                            <?php if ($canVerify && $recovery['status'] === 'pending'): ?>
                                <form method="post" class="d-inline"
                                      action="<?= View::e(View::url('recoveries/' . $id . '/verify')) ?>"
                                      data-confirm="Verify receipt <?= View::e($recovery['receipt_number']) ?> for <?= View::e(View::money($recovery['amount'])) ?>?">
                                    <?= Csrf::field() ?>
                                    <button class="btn btn-sm btn-success" type="submit">Verify</button>
                                </form>
                                <button class="btn btn-sm btn-outline-danger" type="button"
                                        data-bs-toggle="modal" data-bs-target="#rejectModal<?= $id ?>">Reject</button>

                                <div class="modal fade" id="rejectModal<?= $id ?>" tabindex="-1"
                                     aria-labelledby="rejectLabel<?= $id ?>" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <form class="modal-content" method="post"
                                              action="<?= View::e(View::url('recoveries/' . $id . '/reject')) ?>">
                                            <?= Csrf::field() ?>
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="rejectLabel<?= $id ?>">
                                                    Reject receipt <?= View::e($recovery['receipt_number']) ?>
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                        aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body text-start">
                                                <p class="small">
                                                    Rejecting will add <?= View::e(View::money($recovery['amount'])) ?>
                                                    back to the loan balance. The BC agent will see your reason.
                                                </p>
                                                <label class="form-label" for="reason<?= $id ?>">Reason *</label>
                                                <textarea class="form-control" id="reason<?= $id ?>" name="reason"
                                                          rows="3" required maxlength="255"></textarea>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary"
                                                        data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Reject collection</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
