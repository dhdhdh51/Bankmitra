<?php

/**
 * @var array<string,mixed> $visit
 * @var list<array<string,mixed>> $photos
 * @var list<array<string,mixed>> $recoveries
 * @var string $mapsKey
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\PhotoStorageService;

$isMock = (int) $visit['is_mock_location'] === 1;
$distance = $visit['distance_from_customer_m'] === null ? null : (int) $visit['distance_from_customer_m'];
?>

<div class="lrms-page-head">
    <div>
        <h1>Visit - A/c <?= View::e($visit['account_number']) ?></h1>
        <div class="lrms-page-sub">
            <?= View::e(View::dateTime($visit['visited_at'])) ?>
            &middot; <?= View::e($visit['agent_name'] ?? '-') ?>
            <?php if (!empty($visit['bc_code'])): ?>(<?= View::e($visit['bc_code']) ?>)<?php endif; ?>
        </div>
    </div>
    <div class="ms-auto d-flex gap-2">
        <a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener"
           href="<?= View::e(View::url('visits/' . (int) $visit['id'] . '/pdf')) ?>">
            <i class="bi bi-file-earmark-pdf me-1"></i>Report PDF
        </a>
        <?php if ($visit['verified_at'] === null && Auth::can('visits.verify')): ?>
            <form method="post" action="<?= View::e(View::url('visits/' . (int) $visit['id'] . '/verify')) ?>"
                  data-confirm="Mark this visit as verified?">
                <?= Csrf::field() ?>
                <button class="btn btn-sm btn-success" type="submit">
                    <i class="bi bi-check2-circle me-1"></i>Verify
                </button>
            </form>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= View::e(View::url('visits')) ?>">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<?php if ($isMock): ?>
    <div class="alert alert-danger">
        <i class="bi bi-shield-exclamation me-1"></i>
        <strong>Mock GPS was reported by the device for this visit.</strong>
        Treat the location as unverified and investigate with the agent.
    </div>
<?php elseif ($distance !== null && $distance > 1000): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        This visit was recorded <?= number_format($distance) ?> m from the customer's stored
        coordinates. Either the customer's GPS position is wrong, or the visit needs checking.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">Findings</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted fw-normal">Status</dt>
                    <dd class="col-7">
                        <span class="badge badge-soft bg-<?= View::e(View::visitStatusBadge((string) $visit['visit_status'])) ?>">
                            <?= View::e(View::label((string) $visit['visit_status'])) ?>
                        </span>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Customer available</dt>
                    <dd class="col-7"><?= (int) $visit['customer_available'] === 1 ? 'Yes' : 'No' ?></dd>

                    <dt class="col-5 text-muted fw-normal">House locked</dt>
                    <dd class="col-7"><?= (int) $visit['house_locked'] === 1 ? 'Yes' : 'No' ?></dd>

                    <dt class="col-5 text-muted fw-normal">Met person</dt>
                    <dd class="col-7">
                        <?= View::e($visit['met_person'] ?? '-') ?>
                        <?php if (!empty($visit['met_relation'])): ?>
                            <span class="text-muted">(<?= View::e($visit['met_relation']) ?>)</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Occupation seen</dt>
                    <dd class="col-7"><?= View::e($visit['occupation'] ?? '-') ?></dd>

                    <dt class="col-5 text-muted fw-normal">Recovery possibility</dt>
                    <dd class="col-7"><?= View::e(ucfirst((string) ($visit['recovery_possibility'] ?? '-'))) ?></dd>

                    <dt class="col-5 text-muted fw-normal">Promise</dt>
                    <dd class="col-7">
                        <?php if ((float) $visit['promise_amount'] > 0): ?>
                            <?= View::e(View::money($visit['promise_amount'])) ?>
                            by <?= View::e(View::date($visit['promise_date'])) ?>
                        <?php else: ?>none<?php endif; ?>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Collected on visit</dt>
                    <dd class="col-7"><?= View::e(View::money($visit['collected_amount'])) ?></dd>

                    <dt class="col-5 text-muted fw-normal">Remarks</dt>
                    <dd class="col-7"><?= View::e($visit['remarks'] ?? '-') ?></dd>

                    <dt class="col-5 text-muted fw-normal">Recommendation</dt>
                    <dd class="col-7"><?= View::e($visit['recommendation'] ?? '-') ?></dd>

                    <dt class="col-5 text-muted fw-normal">Verified</dt>
                    <dd class="col-7">
                        <?php if ($visit['verified_at'] !== null): ?>
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <?= View::e($visit['verified_by_name'] ?? '') ?>
                            <span class="text-muted"><?= View::e(View::dateTime($visit['verified_at'])) ?></span>
                        <?php else: ?>
                            <span class="text-muted">pending supervisor verification</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">GPS verification</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted fw-normal">Coordinates</dt>
                    <dd class="col-7">
                        <a target="_blank" rel="noopener"
                           href="https://maps.google.com/?q=<?= View::e((string) $visit['latitude']) ?>,<?= View::e((string) $visit['longitude']) ?>">
                            <?= View::e(number_format((float) $visit['latitude'], 6)) ?>,
                            <?= View::e(number_format((float) $visit['longitude'], 6)) ?>
                        </a>
                    </dd>
                    <dt class="col-5 text-muted fw-normal">Accuracy</dt>
                    <dd class="col-7">
                        <?= $visit['accuracy_m'] === null ? '-' : View::e(round((float) $visit['accuracy_m'], 1) . ' m') ?>
                    </dd>
                    <dt class="col-5 text-muted fw-normal">Distance from customer</dt>
                    <dd class="col-7">
                        <?= $distance === null ? 'no stored customer position' : number_format($distance) . ' m' ?>
                    </dd>
                    <dt class="col-5 text-muted fw-normal">Mock location</dt>
                    <dd class="col-7">
                        <?php if ($isMock): ?>
                            <span class="badge badge-soft bg-danger">DETECTED</span>
                        <?php else: ?>
                            <span class="badge badge-soft bg-success">not detected</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted fw-normal">Resolved address</dt>
                    <dd class="col-7"><?= View::e($visit['resolved_address'] ?? '-') ?></dd>
                    <dt class="col-5 text-muted fw-normal">Device</dt>
                    <dd class="col-7"><code class="small"><?= View::e($visit['device_id'] ?? '-') ?></code></dd>
                    <dt class="col-5 text-muted fw-normal">App version</dt>
                    <dd class="col-7"><?= View::e($visit['app_version'] ?? '-') ?></dd>
                    <dt class="col-5 text-muted fw-normal">Sync source</dt>
                    <dd class="col-7"><?= View::e(View::label((string) $visit['sync_source'])) ?></dd>
                    <dt class="col-5 text-muted fw-normal">Visit UID</dt>
                    <dd class="col-7"><code class="small"><?= View::e($visit['visit_uid']) ?></code></dd>
                </dl>

                <?php if ($mapsKey === ''): ?>
                    <div class="alert alert-secondary small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Add a Google Maps API key in <a href="<?= View::e(View::url('settings/maps')) ?>">Settings &rarr; Google Maps</a>
                        to show an inline map here. The coordinates above already link out to Google Maps.
                    </div>
                <?php else: ?>
                    <iframe class="mt-3 w-100 rounded border" height="240" loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade" title="Visit location"
                            src="https://www.google.com/maps/embed/v1/place?key=<?= View::e(rawurlencode($mapsKey)) ?>&q=<?= View::e((string) $visit['latitude']) ?>,<?= View::e((string) $visit['longitude']) ?>&zoom=16"></iframe>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">
                Photo evidence (<?= count($photos) ?>)
                <span class="text-muted fw-normal small">- watermarked at capture</span>
            </div>
            <div class="card-body">
                <?php if ($photos === []): ?>
                    <p class="text-muted small mb-0">No photos were attached to this visit.</p>
                <?php else: ?>
                    <div class="row g-2">
                        <?php foreach ($photos as $photo): ?>
                            <?php
                            $full = PhotoStorageService::url($photo['file_path']);
                            $thumb = PhotoStorageService::url($photo['thumb_path']) ?? $full;
                            ?>
                            <div class="col-6">
                                <a href="<?= View::e((string) $full) ?>" target="_blank" rel="noopener">
                                    <img src="<?= View::e((string) $thumb) ?>" alt="Visit photo"
                                         class="img-fluid rounded border" loading="lazy">
                                </a>
                                <div class="small text-muted mt-1">
                                    <?= View::e(View::dateTime($photo['captured_at'])) ?>
                                    &middot; <?= View::e(round((int) $photo['size_bytes'] / 1024)) ?> KB
                                    <?php if ((int) $photo['watermarked'] === 1): ?>
                                        &middot; <i class="bi bi-patch-check text-success" title="Watermarked"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted font-monospace" style="font-size:.66rem">
                                    sha256 <?= View::e(substr((string) $photo['file_hash'], 0, 24)) ?>...
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($visit['signature_path'])): ?>
            <div class="card mb-3">
                <div class="card-header">Signature</div>
                <div class="card-body">
                    <img src="<?= View::e((string) PhotoStorageService::url($visit['signature_path'])) ?>"
                         alt="Customer signature" class="img-fluid border rounded bg-white" style="max-height:150px">
                </div>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header">Borrower &amp; account</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted fw-normal">Customer</dt>
                    <dd class="col-7">
                        <a href="<?= View::e(View::url('customers/' . (int) $visit['customer_id'])) ?>">
                            <?= View::e($visit['full_name']) ?>
                        </a>
                    </dd>
                    <dt class="col-5 text-muted fw-normal">Guardian</dt>
                    <dd class="col-7"><?= View::e($visit['guardian_name'] ?? '-') ?></dd>
                    <dt class="col-5 text-muted fw-normal">Mobile</dt>
                    <dd class="col-7"><?= $visit['mobile_last4'] !== null ? '******' . View::e($visit['mobile_last4']) : '-' ?></dd>
                    <dt class="col-5 text-muted fw-normal">Village / district</dt>
                    <dd class="col-7"><?= View::e($visit['village'] ?? '-') ?> / <?= View::e($visit['district'] ?? '-') ?></dd>
                    <dt class="col-5 text-muted fw-normal">Outstanding</dt>
                    <dd class="col-7"><?= View::e(View::money($visit['outstanding_amount'])) ?></dd>
                    <dt class="col-5 text-muted fw-normal">Overdue</dt>
                    <dd class="col-7 text-danger"><?= View::e(View::money($visit['overdue_amount'])) ?></dd>
                    <dt class="col-5 text-muted fw-normal">Classification</dt>
                    <dd class="col-7">
                        <span class="badge badge-soft bg-<?= View::e(View::assetClassBadge((string) $visit['asset_class'])) ?>">
                            <?= View::e($visit['asset_class']) ?>
                        </span>
                        <?= (int) $visit['dpd'] ?> DPD
                    </dd>
                </dl>
            </div>
        </div>

        <?php if ($recoveries !== []): ?>
            <div class="card">
                <div class="card-header">Collections during this visit</div>
                <div class="table-responsive">
                    <table class="table table-lrms align-middle">
                        <thead><tr><th>Receipt</th><th class="lrms-num">Amount</th><th>Mode</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recoveries as $recovery): ?>
                            <tr>
                                <td class="small"><code><?= View::e($recovery['receipt_number']) ?></code></td>
                                <td class="lrms-num"><?= View::e(View::money($recovery['amount'], false)) ?></td>
                                <td class="small"><?= View::e(strtoupper((string) $recovery['payment_mode'])) ?></td>
                                <td class="small"><?= View::e(ucfirst((string) $recovery['status'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
