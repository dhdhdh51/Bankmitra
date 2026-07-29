<?php

/**
 * @var list<array<string,mixed>> $visits
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var array<string,mixed> $summary
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $agents
 */

use App\Core\Auth;
use App\Core\View;
use App\Services\PhotoStorageService;
?>

<div class="lrms-page-head">
    <div>
        <h1>Visits</h1>
        <div class="lrms-page-sub">
            Every visit is GPS verified at capture. Rows flagged red failed a location sanity check.
        </div>
    </div>
    <?php if (Auth::can('reports.export')): ?>
        <div class="ms-auto d-flex gap-2">
            <a class="btn btn-sm btn-outline-success"
               href="<?= View::e(View::url('reports/visit?from=' . urlencode($filters['from']) . '&to=' . urlencode($filters['to']))) ?>">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Visit register
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= View::e(View::url('reports/gps?from=' . urlencode($filters['from']) . '&to=' . urlencode($filters['to']))) ?>">
                <i class="bi bi-geo me-1"></i>GPS report
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3">
        <div class="lrms-tile">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= number_format((int) $meta['total']) ?></div>
                <div class="lrms-tile-label">Visits in period</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="lrms-tile tone-blue">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= View::e(View::moneyShort($summary['promise'] ?? 0)) ?></div>
                <div class="lrms-tile-label">Promised</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="lrms-tile tone-teal">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= View::e(View::moneyShort($summary['collected'] ?? 0)) ?></div>
                <div class="lrms-tile-label">Collected on visits</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <a class="text-decoration-none" href="?flag=suspicious&amp;from=<?= View::e($filters['from']) ?>&amp;to=<?= View::e($filters['to']) ?>">
            <div class="lrms-tile tone-red">
                <div class="min-w-0">
                    <div class="lrms-tile-value"><?= number_format((int) ($summary['mock_count'] ?? 0)) ?></div>
                    <div class="lrms-tile-label">Mock GPS detected</div>
                </div>
            </div>
        </a>
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
                    <?php foreach (['visited', 'not_available', 'promise', 'paid', 'ots', 'legal', 'skip', 'untraceable'] as $status): ?>
                        <option value="<?= View::e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= View::e(View::label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
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
            <div class="col-md-2">
                <label class="form-label" for="search">Search</label>
                <input type="search" class="form-control form-control-sm" id="search" name="search"
                       value="<?= View::e($filters['search']) ?>" placeholder="Account / name / village">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-success w-100" type="submit">Apply</button>
            </div>
        </form>
    </div>

    <?php if ($visits === []): ?>
        <div class="lrms-empty"><i class="bi bi-geo"></i>No visits in this period.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>Photo</th><th>When</th><th>Account</th><th>Customer</th><th>BC</th>
                    <th>Status</th><th class="lrms-num">Promise</th><th class="lrms-num">Collected</th>
                    <th>GPS</th><th>Verified</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($visits as $visit): ?>
                    <?php
                    $isMock = (int) $visit['is_mock_location'] === 1;
                    $isFar = $visit['distance_from_customer_m'] !== null
                        && (int) $visit['distance_from_customer_m'] > 1000;
                    ?>
                    <tr class="<?= ($isMock || $isFar) ? 'table-danger' : '' ?>">
                        <td>
                            <?php $thumb = PhotoStorageService::url($visit['thumb']); ?>
                            <?php if ($thumb !== null): ?>
                                <a href="<?= View::e($thumb) ?>" target="_blank" rel="noopener">
                                    <img src="<?= View::e($thumb) ?>" alt="Visit photo" class="lrms-photo-thumb"
                                         style="width:40px;height:40px" loading="lazy">
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">none</span>
                            <?php endif; ?>
                            <?php if ((int) $visit['photo_count'] > 1): ?>
                                <div class="small text-muted">+<?= (int) $visit['photo_count'] - 1 ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small text-nowrap"><?= View::e(View::dateTime($visit['visited_at'])) ?></td>
                        <td class="small">
                            <a class="text-decoration-none" href="<?= View::e(View::url('loans/' . (int) $visit['loan_id'])) ?>">
                                <?= View::e($visit['account_number']) ?>
                            </a>
                        </td>
                        <td class="small">
                            <?= View::e($visit['full_name']) ?>
                            <div class="small text-muted"><?= View::e($visit['village'] ?? '-') ?></div>
                        </td>
                        <td class="small">
                            <?= View::e($visit['agent_name'] ?? '-') ?>
                            <?php if (!empty($visit['bc_code'])): ?>
                                <div class="small text-muted"><?= View::e($visit['bc_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-soft bg-<?= View::e(View::visitStatusBadge((string) $visit['visit_status'])) ?>">
                                <?= View::e(View::label((string) $visit['visit_status'])) ?>
                            </span>
                        </td>
                        <td class="lrms-num"><?= View::e(View::money($visit['promise_amount'], false)) ?></td>
                        <td class="lrms-num"><?= View::e(View::money($visit['collected_amount'], false)) ?></td>
                        <td class="small">
                            <?php if ($isMock): ?>
                                <span class="badge badge-soft bg-danger">MOCK GPS</span>
                            <?php elseif ($visit['distance_from_customer_m'] !== null): ?>
                                <?= (int) $visit['distance_from_customer_m'] ?> m
                            <?php else: ?>
                                <span class="text-muted">no ref</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($visit['verified_at'] !== null): ?>
                                <i class="bi bi-check-circle-fill text-success" title="Verified"></i>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= View::e(View::url('visits/' . (int) $visit['id'])) ?>">View</a>
                            <a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener"
                               href="<?= View::e(View::url('visits/' . (int) $visit['id'] . '/pdf')) ?>"
                               title="Visit report PDF">
                                <i class="bi bi-file-earmark-pdf"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
