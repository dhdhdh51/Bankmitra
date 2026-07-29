<?php

/**
 * @var array<string,string> $types
 */

use App\Core\View;

$icons = [
    'bc_wise' => 'person-badge', 'branch_wise' => 'bank', 'district_wise' => 'map',
    'village_wise' => 'signpost', 'npa' => 'exclamation-octagon', 'recovery' => 'cash-coin',
    'visit' => 'geo-alt', 'gps' => 'crosshair', 'photo' => 'images',
    'attendance' => 'calendar-check', 'followup' => 'bell',
];

$descriptions = [
    'bc_wise'       => 'Coverage, visits, recovery and target achievement per BC agent.',
    'branch_wise'   => 'Portfolio, NPA ratio, visits and recovery per branch.',
    'district_wise' => 'Rolled up by district.',
    'village_wise'  => 'Rolled up by village - useful for planning field routes.',
    'npa'           => 'Every sub-standard, doubtful and loss account with DPD and last visit.',
    'recovery'      => 'Receipt-level collection register with verification status.',
    'visit'          => 'Visit-level register with promises, photos and GPS distance.',
    'gps'           => 'Location sanity check: mock GPS and visits far from the customer.',
    'photo'         => 'Photo evidence inventory with watermark flag and SHA-256 hash.',
    'attendance'    => 'Check in/out, hours worked, distance travelled and geofence flags.',
    'followup'      => 'Pending promises with due dates and reminder status.',
];
?>

<div class="lrms-page-head">
    <div>
        <h1>Reports</h1>
        <div class="lrms-page-sub">
            Every report can be exported to Excel (.xlsx), CSV or PDF, and is automatically limited
            to your access scope.
        </div>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($types as $key => $label): ?>
        <div class="col-md-6 col-xl-4">
            <a class="card h-100 text-decoration-none text-body"
               href="<?= View::e(View::url('reports/' . $key)) ?>">
                <div class="card-body d-flex gap-3">
                    <div class="lrms-tile-icon flex-shrink-0">
                        <i class="bi bi-<?= View::e($icons[$key] ?? 'file-earmark-text') ?>"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="fw-semibold mb-1"><?= View::e($label) ?></div>
                        <div class="small text-muted"><?= View::e($descriptions[$key] ?? '') ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
