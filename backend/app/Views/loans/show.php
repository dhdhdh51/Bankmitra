<?php

/**
 * @var array<string,mixed> $loan
 * @var list<array<string,mixed>> $visits
 * @var list<array<string,mixed>> $recoveries
 * @var list<array<string,mixed>> $followUps
 * @var array<string,mixed>|null $risk
 * @var list<array<string,mixed>> $agents
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\PhotoStorageService;
?>

<div class="lrms-page-head">
    <div>
        <h1>A/c <?= View::e($loan['account_number']) ?></h1>
        <div class="lrms-page-sub">
            <?= View::e($loan['full_name']) ?>
            &middot; <?= View::e($loan['village'] ?? '-') ?>
            &middot; <?= View::e($loan['branch_name'] ?? '-') ?>
        </div>
    </div>
    <div class="ms-auto d-flex gap-2">
        <a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener"
           href="<?= View::e(View::url('loans/' . (int) $loan['id'] . '/statement')) ?>">
            <i class="bi bi-file-earmark-pdf me-1"></i>Statement PDF
        </a>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= View::e(View::url('customers/' . (int) $loan['customer_id'])) ?>">
            <i class="bi bi-person me-1"></i>Customer
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">Balances</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <?php foreach ([
                        'Sanctioned' => $loan['sanction_amount'],
                        'Disbursed' => $loan['disbursed_amount'],
                        'Outstanding' => $loan['outstanding_amount'],
                        'Overdue' => $loan['overdue_amount'],
                        'Principal overdue' => $loan['principal_overdue'],
                        'Interest overdue' => $loan['interest_overdue'],
                        'EMI' => $loan['emi_amount'],
                        'Recovered to date' => $loan['total_recovered'],
                    ] as $label => $amount): ?>
                        <dt class="col-6 text-muted fw-normal"><?= View::e($label) ?></dt>
                        <dd class="col-6 lrms-num"><?= View::e(View::money($amount)) ?></dd>
                    <?php endforeach; ?>

                    <dt class="col-6 text-muted fw-normal">Classification</dt>
                    <dd class="col-6">
                        <span class="badge badge-soft bg-<?= View::e(View::assetClassBadge((string) $loan['asset_class'])) ?>">
                            <?= View::e($loan['asset_class']) ?>
                        </span>
                        <?= (int) $loan['dpd'] ?> DPD
                    </dd>

                    <dt class="col-6 text-muted fw-normal">NPA date</dt>
                    <dd class="col-6"><?= View::e(View::date($loan['npa_date'])) ?></dd>

                    <dt class="col-6 text-muted fw-normal">Last payment</dt>
                    <dd class="col-6">
                        <?= View::e(View::date($loan['last_paid_date'])) ?>
                        <?php if ((float) $loan['last_paid_amount'] > 0): ?>
                            (<?= View::e(View::money($loan['last_paid_amount'], false)) ?>)
                        <?php endif; ?>
                    </dd>

                    <dt class="col-6 text-muted fw-normal">Recovery status</dt>
                    <dd class="col-6">
                        <span class="badge badge-soft bg-secondary">
                            <?= View::e(View::label((string) $loan['recovery_status'])) ?>
                        </span>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Allocation</div>
            <div class="card-body">
                <p class="small mb-2">
                    Currently with:
                    <strong><?= View::e($loan['bc_name'] ?? 'unallocated') ?></strong>
                    <?php if (!empty($loan['bc_code'])): ?>
                        <span class="text-muted">(<?= View::e($loan['bc_code']) ?>)</span>
                    <?php endif; ?>
                </p>

                <?php if (Auth::can('loans.allocate')): ?>
                    <form method="post" action="<?= View::e(View::url('loans/' . (int) $loan['id'] . '/allocate')) ?>"
                          class="d-flex gap-2">
                        <?= Csrf::field() ?>
                        <select class="form-select form-select-sm" name="bc_id" aria-label="BC agent">
                            <option value="0">- unallocate -</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?= (int) $agent['id'] ?>"
                                    <?= (int) ($loan['bc_id'] ?? 0) === (int) $agent['id'] ? 'selected' : '' ?>>
                                    <?= View::e($agent['full_name']) ?> (<?= (int) $agent['account_load'] ?> a/cs)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-success text-nowrap" type="submit">Save</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($risk !== null): ?>
            <div class="card mb-3">
                <div class="card-header">Recovery probability (phase-2 scoring)</div>
                <div class="card-body">
                    <?php
                    $tone = ['low' => 'success', 'medium' => 'warning',
                             'high' => 'warning', 'critical' => 'danger'][$risk['band']] ?? 'secondary';
                    ?>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="display-6 mb-0"><?= (int) $risk['score'] ?></div>
                        <div>
                            <span class="badge badge-soft bg-<?= View::e($tone) ?>">
                                <?= View::e(ucfirst((string) $risk['band'])) ?> risk
                            </span>
                            <div class="small text-muted">
                                computed <?= View::e(View::dateTime($risk['computed_at'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($risk['suggestion'])): ?>
                        <p class="small mb-2"><i class="bi bi-lightbulb me-1"></i><?= View::e($risk['suggestion']) ?></p>
                    <?php endif; ?>
                    <?php
                    $factors = json_decode((string) ($risk['factors_json'] ?? '[]'), true);
                    ?>
                    <?php if (is_array($factors) && $factors !== []): ?>
                        <ul class="small text-muted mb-0">
                            <?php foreach ($factors as $factor => $weight): ?>
                                <li><?= View::e(View::label((string) $factor)) ?>: <?= View::e((string) $weight) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Borrower</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted fw-normal">Name</dt>
                    <dd class="col-7"><?= View::e($loan['full_name']) ?></dd>
                    <dt class="col-5 text-muted fw-normal">Guardian</dt>
                    <dd class="col-7"><?= View::e($loan['guardian_name'] ?? '-') ?></dd>
                    <dt class="col-5 text-muted fw-normal">Mobile</dt>
                    <dd class="col-7">
                        <?php if (!empty($loan['mobile'])): ?>
                            <a href="tel:<?= View::e($loan['mobile']) ?>"><?= View::e($loan['mobile']) ?></a>
                        <?php else: ?>-<?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted fw-normal">Address</dt>
                    <dd class="col-7"><?= View::e($loan['address_line'] ?? '-') ?></dd>
                    <dt class="col-5 text-muted fw-normal">Occupation</dt>
                    <dd class="col-7"><?= View::e($loan['occupation'] ?? '-') ?></dd>
                    <dt class="col-5 text-muted fw-normal">Customer GPS</dt>
                    <dd class="col-7">
                        <?php if ($loan['customer_lat'] !== null): ?>
                            <a target="_blank" rel="noopener"
                               href="https://maps.google.com/?q=<?= View::e((string) $loan['customer_lat']) ?>,<?= View::e((string) $loan['customer_lng']) ?>">
                                open in maps
                            </a>
                        <?php else: ?>
                            <span class="text-warning-emphasis">not recorded</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">Visits (<?= count($visits) ?>)</div>
            <?php if ($visits === []): ?>
                <div class="lrms-empty"><i class="bi bi-geo"></i>No visits yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-lrms table-hover align-middle">
                        <thead>
                        <tr><th></th><th>When</th><th>Status</th><th class="lrms-num">Promise</th>
                            <th class="lrms-num">Collected</th><th>Agent</th><th>GPS</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($visits as $visit): ?>
                            <tr>
                                <td>
                                    <?php $thumb = PhotoStorageService::url($visit['thumb']); ?>
                                    <?php if ($thumb !== null): ?>
                                        <img src="<?= View::e($thumb) ?>" alt="" class="lrms-photo-thumb"
                                             style="width:38px;height:38px" loading="lazy">
                                    <?php endif; ?>
                                </td>
                                <td class="small text-nowrap"><?= View::e(View::dateTime($visit['visited_at'])) ?></td>
                                <td>
                                    <span class="badge badge-soft bg-<?= View::e(View::visitStatusBadge((string) $visit['visit_status'])) ?>">
                                        <?= View::e(View::label((string) $visit['visit_status'])) ?>
                                    </span>
                                </td>
                                <td class="lrms-num"><?= View::e(View::money($visit['promise_amount'], false)) ?></td>
                                <td class="lrms-num"><?= View::e(View::money($visit['collected_amount'], false)) ?></td>
                                <td class="small"><?= View::e($visit['agent_name'] ?? '-') ?></td>
                                <td class="small">
                                    <?php if ((int) $visit['is_mock_location'] === 1): ?>
                                        <span class="badge badge-soft bg-danger">MOCK</span>
                                    <?php elseif ($visit['distance_from_customer_m'] !== null): ?>
                                        <?= (int) $visit['distance_from_customer_m'] ?> m
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
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

        <div class="card mb-3">
            <div class="card-header">Recoveries (<?= count($recoveries) ?>)</div>
            <?php if ($recoveries === []): ?>
                <div class="lrms-empty"><i class="bi bi-cash-stack"></i>No collections yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-lrms table-hover align-middle">
                        <thead>
                        <tr><th>Receipt</th><th class="lrms-num">Amount</th><th>Mode</th>
                            <th>Reference</th><th>When</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recoveries as $recovery): ?>
                            <tr>
                                <td class="small"><code><?= View::e($recovery['receipt_number']) ?></code></td>
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
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                                       href="<?= View::e(View::url('recoveries/' . (int) $recovery['id'] . '/receipt')) ?>">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">Follow-ups (<?= count($followUps) ?>)</div>
            <?php if ($followUps === []): ?>
                <div class="lrms-empty"><i class="bi bi-bell-slash"></i>No follow-ups.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-lrms align-middle">
                        <thead>
                        <tr><th>Due</th><th>Channel</th><th class="lrms-num">Promise</th>
                            <th>Status</th><th>Reminder</th><th>Message</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($followUps as $followUp): ?>
                            <tr>
                                <td class="small"><?= View::e(View::date($followUp['due_date'])) ?></td>
                                <td class="small"><?= View::e(ucfirst((string) $followUp['channel'])) ?></td>
                                <td class="lrms-num"><?= View::e(View::money($followUp['promise_amount'], false)) ?></td>
                                <td>
                                    <?php
                                    $tone = ['pending' => 'warning', 'done' => 'success',
                                             'missed' => 'danger', 'cancelled' => 'secondary'][$followUp['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-soft bg-<?= View::e($tone) ?>">
                                        <?= View::e(ucfirst((string) $followUp['status'])) ?>
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    <?= $followUp['reminder_sent_at'] === null ? 'not sent' : View::e(View::dateTime($followUp['reminder_sent_at'])) ?>
                                </td>
                                <td class="small text-muted"><?= View::e($followUp['message'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
