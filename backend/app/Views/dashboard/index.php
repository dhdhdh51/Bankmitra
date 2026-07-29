<?php

/**
 * Dashboard.
 *
 * @var array<string,mixed> $stats
 * @var list<array{module:string,label:string,missing:list<string>,url:string}> $missingConfig
 * @var list<array<string,mixed>> $bcRanking
 * @var list<array<string,mixed>> $branchRanking
 * @var list<array<string,mixed>> $recentVisits
 * @var list<array<string,mixed>> $pendingUsers
 * @var array{configured:bool,last_run:?string,status:?string,stale:bool} $cronStatus
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;

$isBc = ($stats['scope'] ?? '') === 'bc_agent';
?>

<div class="lrms-page-head">
    <div>
        <h1>Dashboard</h1>
        <div class="lrms-page-sub">
            <?= View::e(date('l, d F Y')) ?>
            &middot; <?= View::e($authUser['role_name'] ?? '') ?>
            <?php if (!empty($authUser['branch_name'])): ?>
                &middot; <?= View::e($authUser['branch_name']) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php // ---------------- missing configuration warning ---------------- ?>
<?php if ($missingConfig !== [] && Auth::can('settings.manage')): ?>
    <div class="alert alert-warning shadow-sm">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div class="flex-grow-1">
                <strong>Missing configuration</strong>
                <div class="small mt-1">
                    These integrations are not set up yet, so the related features are being skipped
                    silently rather than failing:
                </div>
                <ul class="small mb-2 mt-2">
                    <?php foreach ($missingConfig as $item): ?>
                        <li>
                            <a href="<?= View::e(View::url($item['url'])) ?>" class="fw-semibold">
                                <?= View::e($item['label']) ?>
                            </a>
                            &mdash; missing: <code><?= View::e(implode(', ', $item['missing'])) ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="btn btn-sm btn-warning" href="<?= View::e(View::url('settings')) ?>">
                    <i class="bi bi-sliders me-1"></i>Open Settings
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php // ---------------- cron warning ---------------- ?>
<?php if (Auth::isSuperAdmin() && $cronStatus['stale']): ?>
    <div class="alert alert-info shadow-sm small">
        <i class="bi bi-clock-history me-1"></i>
        <?php if (!$cronStatus['configured']): ?>
            <strong>Cron job not detected.</strong>
            Reminders, risk scoring, cleanup and backups will not run until you add the cPanel cron job.
            See <code>docs/DEPLOYMENT.md</code> step 7.
        <?php else: ?>
            <strong>Cron looks stale.</strong>
            The last run was <?= View::e(View::dateTime($cronStatus['last_run'])) ?>
            (status: <?= View::e((string) $cronStatus['status']) ?>). Check the cPanel cron job.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php // ---------------- tiles ---------------- ?>
<div class="row g-3 mb-4">
    <?php
    $tiles = $isBc
        ? [
            ['label' => 'Assigned accounts', 'value' => number_format((int) $stats['assigned_accounts']), 'icon' => 'journal-text', 'tone' => ''],
            ['label' => "Visited today", 'value' => number_format((int) $stats['visited_today']), 'icon' => 'geo-alt-fill', 'tone' => 'tone-teal'],
            ['label' => 'Pending today', 'value' => number_format((int) $stats['pending_today']), 'icon' => 'hourglass-split', 'tone' => 'tone-amber'],
            ['label' => 'Promises', 'value' => number_format((int) $stats['promise_count']), 'icon' => 'hand-thumbs-up', 'tone' => 'tone-blue'],
            ['label' => 'Recovery today', 'value' => View::moneyShort($stats['recovery_today']), 'icon' => 'cash-coin', 'tone' => 'tone-teal'],
            ['label' => 'Recovery this month', 'value' => View::moneyShort($stats['recovery_month']), 'icon' => 'graph-up-arrow', 'tone' => 'tone-blue'],
            ['label' => 'NPA accounts', 'value' => number_format((int) $stats['npa_count']), 'icon' => 'exclamation-octagon', 'tone' => 'tone-red'],
            ['label' => 'Follow-ups due', 'value' => number_format((int) $stats['followups_due']), 'icon' => 'bell', 'tone' => 'tone-purple'],
        ]
        : [
            ['label' => 'Total accounts', 'value' => number_format((int) $stats['total_accounts']), 'icon' => 'journal-text', 'tone' => ''],
            ['label' => 'BC agents', 'value' => number_format((int) $stats['bc_count']), 'icon' => 'person-badge', 'tone' => 'tone-blue'],
            ['label' => 'Branches', 'value' => number_format((int) $stats['branch_count']), 'icon' => 'bank', 'tone' => 'tone-purple'],
            ['label' => 'Visits today', 'value' => number_format((int) $stats['visited_today']), 'icon' => 'geo-alt-fill', 'tone' => 'tone-teal'],
            ['label' => 'Recovery today', 'value' => View::moneyShort($stats['recovery_today']), 'icon' => 'cash-coin', 'tone' => 'tone-teal'],
            ['label' => 'Recovery this month', 'value' => View::moneyShort($stats['recovery_month']), 'icon' => 'graph-up-arrow', 'tone' => 'tone-blue'],
            ['label' => 'Total outstanding', 'value' => View::moneyShort($stats['total_outstanding']), 'icon' => 'wallet2', 'tone' => 'tone-amber'],
            ['label' => 'NPA accounts', 'value' => number_format((int) $stats['npa_count']), 'icon' => 'exclamation-octagon', 'tone' => 'tone-red'],
        ];
    ?>
    <?php foreach ($tiles as $tile): ?>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="lrms-tile <?= View::e($tile['tone']) ?>">
                <div class="lrms-tile-icon"><i class="bi bi-<?= View::e($tile['icon']) ?>"></i></div>
                <div class="min-w-0">
                    <div class="lrms-tile-value"><?= View::e($tile['value']) ?></div>
                    <div class="lrms-tile-label"><?= View::e($tile['label']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php // ---------------- BC target progress ---------------- ?>
<?php if ($isBc && (float) $stats['monthly_target'] > 0): ?>
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-end mb-2">
                <div>
                    <div class="lrms-tile-label">Monthly target</div>
                    <div class="fw-semibold"><?= View::e(View::money($stats['recovery_month'])) ?>
                        <span class="text-muted fw-normal">of <?= View::e(View::money($stats['monthly_target'])) ?></span>
                    </div>
                </div>
                <div class="h4 mb-0 text-success"><?= View::e(number_format((float) $stats['target_achieved_pct'], 1)) ?>%</div>
            </div>
            <?php $pct = min(100, (float) $stats['target_achieved_pct']); ?>
            <div class="progress" style="height:.6rem" role="progressbar"
                 aria-valuenow="<?= View::e((string) $pct) ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar bg-success" style="width: <?= View::e((string) $pct) ?>%"></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php // ---------------- attendance strip ---------------- ?>
<?php $att = $stats['attendance'] ?? []; ?>
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <i class="bi bi-calendar-check fs-4 text-success"></i>
        <div class="flex-grow-1 small">
            <?php if (!empty($att['checked_in'])): ?>
                Checked in at <strong><?= View::e(date('h:i A', strtotime((string) $att['check_in_at']))) ?></strong>
                <?php if (!empty($att['checked_out'])): ?>
                    &middot; checked out at <strong><?= View::e(date('h:i A', strtotime((string) $att['check_out_at']))) ?></strong>
                <?php else: ?>
                    &middot; <span class="text-success">currently on duty</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="text-muted">Not checked in today. Attendance is marked from the mobile app
                    (GPS + selfie required).</span>
            <?php endif; ?>
        </div>
        <?php if (Auth::can('attendance.view')): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= View::e(View::url('attendance')) ?>">
                View attendance
            </a>
        <?php endif; ?>
    </div>
</div>

<?php // ---------------- charts ---------------- ?>
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Visits &amp; recovery - last 14 days</span>
            </div>
            <div class="card-body">
                <div style="height:280px"><canvas id="lrmsTrendChart"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Asset classification</div>
            <div class="card-body">
                <div style="height:280px"><canvas id="lrmsAssetChart"></canvas></div>
            </div>
        </div>
    </div>
</div>

<?php // ---------------- pending approvals ---------------- ?>
<?php if ($pendingUsers !== []): ?>
    <div class="card mb-4 border-warning">
        <div class="card-header text-warning-emphasis">
            <i class="bi bi-person-plus me-1"></i>Accounts awaiting approval
            (<?= count($pendingUsers) ?>)
        </div>
        <div class="table-responsive">
            <table class="table table-lrms table-hover">
                <thead>
                <tr><th>Name</th><th>Code</th><th>Role</th><th>Branch</th><th>Requested</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pendingUsers as $pending): ?>
                    <tr>
                        <td><?= View::e($pending['full_name']) ?></td>
                        <td><?= View::e($pending['employee_code'] ?? '-') ?></td>
                        <td><?= View::e($pending['role_name']) ?></td>
                        <td><?= View::e($pending['branch_name'] ?? '-') ?></td>
                        <td class="text-muted"><?= View::e(View::dateTime($pending['created_at'])) ?></td>
                        <td class="text-end">
                            <form method="post"
                                  action="<?= View::e(View::url('users/' . (int) $pending['id'] . '/approve')) ?>"
                                  class="d-inline"
                                  data-confirm="Approve <?= View::e($pending['full_name']) ?>?">
                                <?= Csrf::field() ?>
                                <button class="btn btn-sm btn-success" type="submit">Approve</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <?php // ---------------- recent visits ---------------- ?>
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Recent visits</span>
                <a class="small text-decoration-none" href="<?= View::e(View::url('visits')) ?>">View all</a>
            </div>
            <?php if ($recentVisits === []): ?>
                <div class="lrms-empty">
                    <i class="bi bi-geo"></i>
                    No visits recorded yet. Visits appear here as soon as BC agents submit them from the app.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-lrms table-hover">
                        <thead>
                        <tr>
                            <th>Account</th><th>Customer</th><th>Status</th>
                            <th class="lrms-num">Promise</th><th class="lrms-num">Collected</th><th>When</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentVisits as $visit): ?>
                            <tr>
                                <td>
                                    <a class="text-decoration-none"
                                       href="<?= View::e(View::url('visits/' . (int) $visit['id'])) ?>">
                                        <?= View::e($visit['account_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?= View::e($visit['full_name']) ?>
                                    <div class="small text-muted"><?= View::e($visit['village'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-soft bg-<?= View::e(View::visitStatusBadge((string) $visit['visit_status'])) ?>">
                                        <?= View::e(View::label((string) $visit['visit_status'])) ?>
                                    </span>
                                </td>
                                <td class="lrms-num"><?= View::e(View::money($visit['promise_amount'], false)) ?></td>
                                <td class="lrms-num"><?= View::e(View::money($visit['collected_amount'], false)) ?></td>
                                <td class="text-muted small"><?= View::e(View::dateTime($visit['visited_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php // ---------------- rankings ---------------- ?>
    <?php if (!$isBc): ?>
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header">Top BC agents this month</div>
                <?php if ($bcRanking === []): ?>
                    <div class="lrms-empty"><i class="bi bi-trophy"></i>No BC agents yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-lrms table-hover">
                            <thead>
                            <tr><th>#</th><th>Agent</th><th class="lrms-num">Visits</th><th class="lrms-num">Recovered</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($bcRanking as $index => $bc): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <?= View::e($bc['full_name']) ?>
                                        <div class="small text-muted">
                                            <?= View::e($bc['bc_code']) ?>
                                            <?php if (!empty($bc['branch_name'])): ?>
                                                &middot; <?= View::e($bc['branch_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="lrms-num"><?= View::e((string) (int) $bc['visits_month']) ?></td>
                                    <td class="lrms-num"><?= View::e(View::moneyShort($bc['recovered_month'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
