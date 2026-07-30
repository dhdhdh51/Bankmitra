<?php

/**
 * @var list<array<string,mixed>> $agents
 * @var string $mapsKey
 * @var float $defaultLat
 * @var float $defaultLng
 * @var int $defaultZoom
 * @var int $pingInterval
 */

use App\Core\View;
?>

<div class="lrms-page-head">
    <div>
        <h1>Live tracking</h1>
        <div class="lrms-page-sub">
            Positions come from plain GPS pings sent by the app roughly every
            <?= number_format(max(60, $pingInterval) / 60, 0) ?> minute(s) - no paid tracking SDK.
            The list below refreshes with the page; the map polls every minute.
        </div>
    </div>
</div>

<?php if ($mapsKey === ''): ?>
    <div class="alert alert-warning">
        <i class="bi bi-map me-1"></i>
        <strong>No Google Maps API key configured.</strong>
        The table below still works. Add a key in
        <a href="<?= View::e(View::url('settings/maps')) ?>">Settings &rarr; Google Maps</a>
        to see agents plotted on a map.
    </div>
<?php else: ?>
    <div class="card mb-3">
        <div class="card-body p-2">
            <div id="lrmsTrackingMap" class="lrms-map">Loading map...</div>
        </div>
    </div>
    <script>
        window.lrmsTrackingConfig = {
            lat: <?= json_encode($defaultLat) ?>,
            lng: <?= json_encode($defaultLng) ?>,
            zoom: <?= json_encode($defaultZoom) ?>
        };
        function lrmsMapsReady() { window.lrmsInitTrackingMap(window.lrmsTrackingConfig); }
    </script>
    <script async defer
            src="https://maps.googleapis.com/maps/api/js?key=<?= View::e(rawurlencode($mapsKey)) ?>&callback=lrmsMapsReady"></script>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Last known positions (<?= count($agents) ?>)</span>
        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="window.location.reload()">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
    </div>

    <?php if ($agents === []): ?>
        <div class="lrms-empty">
            <i class="bi bi-broadcast"></i>
            No GPS pings received in the last 12 hours. Agents must have the app open (or its
            background worker enabled) and location permission granted.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>Agent</th><th>Branch</th><th>Last seen</th><th>Position</th>
                    <th class="lrms-num">Accuracy</th><th class="lrms-num">Speed</th>
                    <th class="lrms-num">Battery</th><th class="lrms-num">Visits today</th><th>Duty</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($agents as $agent): ?>
                    <tr class="<?= $agent['is_stale'] ? 'table-secondary' : '' ?>">
                        <td class="small">
                            <div class="fw-semibold"><?= View::e($agent['name']) ?></div>
                            <?php if (!empty($agent['bc_code'])): ?>
                                <div class="small text-muted"><?= View::e($agent['bc_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= View::e($agent['branch_name'] ?? '-') ?></td>
                        <td class="small">
                            <?php if ($agent['minutes_ago'] < 2): ?>
                                <span class="badge badge-soft bg-success">just now</span>
                            <?php elseif ($agent['minutes_ago'] < 60): ?>
                                <?= (int) $agent['minutes_ago'] ?> min ago
                            <?php else: ?>
                                <span class="text-muted"><?= number_format($agent['minutes_ago'] / 60, 1) ?> h ago</span>
                            <?php endif; ?>
                            <div class="small text-muted"><?= View::e(View::dateTime($agent['recorded_at'])) ?></div>
                        </td>
                        <td class="small">
                            <a target="_blank" rel="noopener"
                               href="https://maps.google.com/?q=<?= View::e((string) $agent['latitude']) ?>,<?= View::e((string) $agent['longitude']) ?>">
                                <?= View::e(number_format($agent['latitude'], 5)) ?>,
                                <?= View::e(number_format($agent['longitude'], 5)) ?>
                            </a>
                        </td>
                        <td class="lrms-num small">
                            <?= $agent['accuracy_m'] === null ? '-' : View::e(round($agent['accuracy_m'], 1) . ' m') ?>
                        </td>
                        <td class="lrms-num small">
                            <?= $agent['speed_kmph'] === null ? '-' : View::e(round($agent['speed_kmph'], 1) . ' km/h') ?>
                        </td>
                        <td class="lrms-num small">
                            <?php if ($agent['battery_pct'] === null): ?>
                                -
                            <?php else: ?>
                                <span class="<?= $agent['battery_pct'] < 20 ? 'text-danger fw-semibold' : '' ?>">
                                    <?= (int) $agent['battery_pct'] ?>%
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="lrms-num"><?= (int) $agent['visits_today'] ?></td>
                        <td class="small">
                            <?php if ($agent['checked_out']): ?>
                                <span class="badge badge-soft bg-secondary">off duty</span>
                            <?php elseif ($agent['checked_in']): ?>
                                <span class="badge badge-soft bg-success">on duty</span>
                            <?php else: ?>
                                <span class="badge badge-soft bg-warning">not checked in</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
