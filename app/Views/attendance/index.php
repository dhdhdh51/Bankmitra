<?php

/**
 * @var list<array<string,mixed>> $records
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var array<string,mixed> $summary
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $agents
 */

use App\Core\Auth;
use App\Core\View;
use App\Services\PhotoStorageService;

$minutes = (int) ($summary['minutes'] ?? 0);
?>

<div class="lrms-page-head">
    <div>
        <h1>Attendance</h1>
        <div class="lrms-page-sub">
            Marked from the mobile app with GPS and a selfie. Check-ins outside the branch geofence
            are flagged rather than blocked.
        </div>
    </div>
    <?php if (Auth::can('reports.export')): ?>
        <div class="ms-auto">
            <a class="btn btn-sm btn-outline-success"
               href="<?= View::e(View::url('reports/attendance?from=' . urlencode($filters['from']) . '&to=' . urlencode($filters['to']))) ?>">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Attendance register
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3">
        <div class="lrms-tile">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= number_format((int) ($summary['records'] ?? 0)) ?></div>
                <div class="lrms-tile-label">Records</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="lrms-tile tone-teal">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= number_format(intdiv($minutes, 60)) ?>h</div>
                <div class="lrms-tile-label">Total hours worked</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="lrms-tile tone-blue">
            <div class="min-w-0">
                <div class="lrms-tile-value"><?= number_format((float) ($summary['distance'] ?? 0), 1) ?></div>
                <div class="lrms-tile-label">Km travelled</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <a class="text-decoration-none" href="?flag=outside&amp;from=<?= View::e($filters['from']) ?>&amp;to=<?= View::e($filters['to']) ?>">
            <div class="lrms-tile tone-amber">
                <div class="min-w-0">
                    <div class="lrms-tile-value"><?= number_format((int) ($summary['outside'] ?? 0)) ?></div>
                    <div class="lrms-tile-label">Outside geofence</div>
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
            <?php if ($agents !== []): ?>
                <div class="col-md-3">
                    <label class="form-label" for="user_id">Staff member</label>
                    <select class="form-select form-select-sm" id="user_id" name="user_id">
                        <option value="">All</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= (int) $agent['id'] ?>" <?= $filters['user_id'] === (int) $agent['id'] ? 'selected' : '' ?>>
                                <?= View::e($agent['full_name']) ?>
                                <?= !empty($agent['bc_code']) ? ' (' . View::e($agent['bc_code']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (['present', 'half_day', 'absent', 'leave', 'holiday'] as $status): ?>
                        <option value="<?= View::e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= View::e(View::label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-success w-100" type="submit">Apply</button>
            </div>
        </form>
    </div>

    <?php if ($records === []): ?>
        <div class="lrms-empty">
            <i class="bi bi-calendar-x"></i>
            No attendance in this period. Agents mark attendance from the mobile app.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>Date</th><th>Staff</th><th>Branch</th>
                    <th>In</th><th>Out</th><th class="lrms-num">Worked</th>
                    <th class="lrms-num">Km</th><th>Status</th><th>Geofence</th><th>Selfies</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <?php $worked = (int) $record['worked_minutes']; ?>
                    <tr class="<?= (int) $record['is_outside_geofence'] === 1 ? 'table-warning' : '' ?>">
                        <td class="small text-nowrap"><?= View::e(View::date($record['attendance_date'])) ?></td>
                        <td class="small">
                            <?= View::e($record['full_name']) ?>
                            <?php if (!empty($record['bc_code'])): ?>
                                <div class="small text-muted"><?= View::e($record['bc_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= View::e($record['branch_name'] ?? '-') ?></td>
                        <td class="small">
                            <?php if ($record['check_in_at'] !== null): ?>
                                <?= View::e(date('h:i A', strtotime((string) $record['check_in_at']))) ?>
                                <?php if ($record['check_in_lat'] !== null): ?>
                                    <a class="ms-1" target="_blank" rel="noopener" title="Open in maps"
                                       href="https://maps.google.com/?q=<?= View::e((string) $record['check_in_lat']) ?>,<?= View::e((string) $record['check_in_lng']) ?>">
                                        <i class="bi bi-geo-alt"></i>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($record['check_out_at'] !== null): ?>
                                <?= View::e(date('h:i A', strtotime((string) $record['check_out_at']))) ?>
                            <?php else: ?>
                                <span class="text-warning-emphasis">not checked out</span>
                            <?php endif; ?>
                        </td>
                        <td class="lrms-num"><?= sprintf('%dh %02dm', intdiv($worked, 60), $worked % 60) ?></td>
                        <td class="lrms-num"><?= View::e(number_format((float) $record['distance_km'], 1)) ?></td>
                        <td>
                            <?php
                            $tone = ['present' => 'success', 'half_day' => 'warning', 'absent' => 'danger',
                                     'leave' => 'info', 'holiday' => 'secondary'][$record['status']] ?? 'secondary';
                            ?>
                            <span class="badge badge-soft bg-<?= View::e($tone) ?>">
                                <?= View::e(View::label((string) $record['status'])) ?>
                            </span>
                        </td>
                        <td class="small">
                            <?php if ((int) $record['is_outside_geofence'] === 1): ?>
                                <span class="badge badge-soft bg-warning">outside</span>
                            <?php else: ?>
                                <span class="text-muted">inside</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach (['check_in_photo' => 'In', 'check_out_photo' => 'Out'] as $field => $label): ?>
                                <?php $url = PhotoStorageService::url($record[$field] ?? null); ?>
                                <?php if ($url !== null): ?>
                                    <a href="<?= View::e($url) ?>" target="_blank" rel="noopener" title="<?= $label ?> selfie">
                                        <img src="<?= View::e($url) ?>" alt="<?= $label ?> selfie"
                                             class="lrms-photo-thumb" style="width:32px;height:32px" loading="lazy">
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
