<?php

/**
 * @var array<string,mixed> $customer
 * @var list<array<string,mixed>> $loans
 * @var list<array<string,mixed>> $visits
 * @var list<array<string,mixed>> $recoveries
 */

use App\Core\View;
use App\Services\PhotoStorageService;
?>

<div class="lrms-page-head">
    <div>
        <h1><?= View::e($customer['full_name']) ?></h1>
        <div class="lrms-page-sub">
            CIF <?= View::e($customer['cif_number']) ?>
            <?php if (!empty($customer['branch_name'])): ?>
                &middot; <?= View::e($customer['branch_name']) ?>
            <?php endif; ?>
            &middot; <?= View::e($customer['village'] ?? '-') ?>
        </div>
    </div>
    <div class="ms-auto">
        <a class="btn btn-sm btn-outline-secondary" href="<?= View::e(View::url('customers')) ?>">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Profile</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted fw-normal">Guardian</dt>
                    <dd class="col-7"><?= View::e($customer['guardian_name'] ?? '-') ?></dd>

                    <dt class="col-5 text-muted fw-normal">Mobile</dt>
                    <dd class="col-7">
                        <?php if (!empty($customer['mobile'])): ?>
                            <a href="tel:<?= View::e($customer['mobile']) ?>"><?= View::e($customer['mobile']) ?></a>
                        <?php else: ?>-<?php endif; ?>
                    </dd>

                    <?php if (!empty($customer['alt_mobile'])): ?>
                        <dt class="col-5 text-muted fw-normal">Alt. mobile</dt>
                        <dd class="col-7"><a href="tel:<?= View::e($customer['alt_mobile']) ?>"><?= View::e($customer['alt_mobile']) ?></a></dd>
                    <?php endif; ?>

                    <dt class="col-5 text-muted fw-normal">Aadhaar</dt>
                    <dd class="col-7">
                        <?= $customer['aadhaar_last4'] !== null
                            ? 'XXXX XXXX ' . View::e($customer['aadhaar_last4'])
                            : '-' ?>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Occupation</dt>
                    <dd class="col-7"><?= View::e($customer['occupation'] ?? '-') ?></dd>

                    <dt class="col-5 text-muted fw-normal">Address</dt>
                    <dd class="col-7"><?= View::e($customer['address_line'] ?? '-') ?></dd>

                    <dt class="col-5 text-muted fw-normal">Village</dt>
                    <dd class="col-7"><?= View::e($customer['village'] ?? '-') ?></dd>

                    <dt class="col-5 text-muted fw-normal">Block / district</dt>
                    <dd class="col-7">
                        <?= View::e($customer['block'] ?? '-') ?> / <?= View::e($customer['district'] ?? '-') ?>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Pincode</dt>
                    <dd class="col-7"><?= View::e($customer['pincode'] ?? '-') ?></dd>

                    <dt class="col-5 text-muted fw-normal">GPS</dt>
                    <dd class="col-7">
                        <?php if ($customer['latitude'] !== null && $customer['longitude'] !== null): ?>
                            <a target="_blank" rel="noopener"
                               href="https://maps.google.com/?q=<?= View::e((string) $customer['latitude']) ?>,<?= View::e((string) $customer['longitude']) ?>">
                                <?= View::e((string) $customer['latitude']) ?>, <?= View::e((string) $customer['longitude']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-warning-emphasis">not recorded</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Status</dt>
                    <dd class="col-7">
                        <span class="badge badge-soft bg-<?= $customer['status'] === 'active' ? 'success' : 'secondary' ?>">
                            <?= View::e(View::label((string) $customer['status'])) ?>
                        </span>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">Loan accounts (<?= count($loans) ?>)</div>
            <?php if ($loans === []): ?>
                <div class="lrms-empty"><i class="bi bi-journal-x"></i>No loan accounts.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-lrms table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Account</th><th>Product</th><th>Class</th>
                            <th class="lrms-num">Outstanding</th><th class="lrms-num">Overdue</th>
                            <th>BC</th><th>Status</th><th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><code><?= View::e($loan['account_number']) ?></code></td>
                                <td class="small"><?= View::e($loan['product_name'] ?? '-') ?></td>
                                <td>
                                    <span class="badge badge-soft bg-<?= View::e(View::assetClassBadge((string) $loan['asset_class'])) ?>">
                                        <?= View::e($loan['asset_class']) ?>
                                    </span>
                                    <div class="small text-muted"><?= (int) $loan['dpd'] ?> DPD</div>
                                </td>
                                <td class="lrms-num"><?= View::e(View::money($loan['outstanding_amount'], false)) ?></td>
                                <td class="lrms-num text-danger"><?= View::e(View::money($loan['overdue_amount'], false)) ?></td>
                                <td class="small">
                                    <?= View::e($loan['bc_name'] ?? 'unallocated') ?>
                                    <?php if (!empty($loan['bc_code'])): ?>
                                        <div class="small text-muted"><?= View::e($loan['bc_code']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-soft bg-secondary">
                                        <?= View::e(View::label((string) $loan['recovery_status'])) ?>
                                    </span>
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
            <?php endif; ?>
        </div>

        <div class="card mb-3">
            <div class="card-header">Visit history (<?= count($visits) ?>)</div>
            <?php if ($visits === []): ?>
                <div class="lrms-empty"><i class="bi bi-geo"></i>No visits recorded.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-lrms table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Photo</th><th>When</th><th>Account</th><th>Status</th>
                            <th class="lrms-num">Promise</th><th class="lrms-num">Collected</th>
                            <th>Agent</th><th>Remarks</th><th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($visits as $visit): ?>
                            <tr>
                                <td>
                                    <?php $thumb = PhotoStorageService::url($visit['thumb']); ?>
                                    <?php if ($thumb !== null): ?>
                                        <a href="<?= View::e($thumb) ?>" target="_blank" rel="noopener">
                                            <img src="<?= View::e($thumb) ?>" alt="Visit photo" class="lrms-photo-thumb"
                                                 style="width:44px;height:44px" loading="lazy">
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-nowrap"><?= View::e(View::dateTime($visit['visited_at'])) ?></td>
                                <td class="small"><?= View::e($visit['account_number']) ?></td>
                                <td>
                                    <span class="badge badge-soft bg-<?= View::e(View::visitStatusBadge((string) $visit['visit_status'])) ?>">
                                        <?= View::e(View::label((string) $visit['visit_status'])) ?>
                                    </span>
                                </td>
                                <td class="lrms-num"><?= View::e(View::money($visit['promise_amount'], false)) ?></td>
                                <td class="lrms-num"><?= View::e(View::money($visit['collected_amount'], false)) ?></td>
                                <td class="small"><?= View::e($visit['agent_name'] ?? '-') ?></td>
                                <td class="small text-muted" style="max-width:220px">
                                    <?= View::e(mb_strimwidth((string) ($visit['remarks'] ?? ''), 0, 70, '...')) ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= View::e(View::url('visits/' . (int) $visit['id'])) ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">Recovery history (<?= count($recoveries) ?>)</div>
            <?php if ($recoveries === []): ?>
                <div class="lrms-empty"><i class="bi bi-cash-stack"></i>No collections recorded.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-lrms table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Receipt</th><th>Account</th><th class="lrms-num">Amount</th>
                            <th>Mode</th><th>Reference</th><th>When</th><th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recoveries as $recovery): ?>
                            <tr>
                                <td class="small"><code><?= View::e($recovery['receipt_number']) ?></code></td>
                                <td class="small"><?= View::e($recovery['account_number']) ?></td>
                                <td class="lrms-num fw-semibold"><?= View::e(View::money($recovery['amount'], false)) ?></td>
                                <td class="small"><?= View::e(strtoupper((string) $recovery['payment_mode'])) ?></td>
                                <td class="small text-muted"><?= View::e($recovery['txn_reference'] ?? '-') ?></td>
                                <td class="small text-nowrap"><?= View::e(View::dateTime($recovery['collected_at'])) ?></td>
                                <td>
                                    <?php
                                    $tone = ['verified' => 'success', 'pending' => 'warning',
                                             'rejected' => 'danger', 'reversed' => 'dark'][$recovery['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-soft bg-<?= View::e($tone) ?>">
                                        <?= View::e(ucfirst((string) $recovery['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
