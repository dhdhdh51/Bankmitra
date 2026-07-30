<?php

/**
 * Sidebar navigation. Items are filtered by permission so a Branch Manager or
 * Regional Office user never sees a link they cannot open.
 *
 * @var string $currentPath
 */

use App\Core\Auth;
use App\Core\View;

$path = $currentPath ?? '';

/** @var list<array{group:string,items:list<array{label:string,url:string,icon:string,permission:?string,match:string}>}> $sections */
$sections = [
    [
        'group' => '',
        'items' => [
            ['label' => 'Dashboard', 'url' => 'dashboard', 'icon' => 'speedometer2', 'permission' => null, 'match' => 'dashboard'],
        ],
    ],
    [
        'group' => 'Portfolio',
        'items' => [
            ['label' => 'Customers', 'url' => 'customers', 'icon' => 'people', 'permission' => 'customers.view', 'match' => 'customers'],
            ['label' => 'Loan Accounts', 'url' => 'loans', 'icon' => 'journal-text', 'permission' => 'loans.view', 'match' => 'loans'],
            ['label' => 'Excel Upload', 'url' => 'imports', 'icon' => 'file-earmark-arrow-up', 'permission' => 'loans.allocate', 'match' => 'imports'],
        ],
    ],
    [
        'group' => 'Field Work',
        'items' => [
            ['label' => 'Visits', 'url' => 'visits', 'icon' => 'geo-alt', 'permission' => 'visits.view', 'match' => 'visits'],
            ['label' => 'Recoveries', 'url' => 'recoveries', 'icon' => 'cash-coin', 'permission' => 'recoveries.view', 'match' => 'recoveries'],
            ['label' => 'Attendance', 'url' => 'attendance', 'icon' => 'calendar-check', 'permission' => 'attendance.view', 'match' => 'attendance'],
            ['label' => 'Live Tracking', 'url' => 'tracking', 'icon' => 'broadcast-pin', 'permission' => 'tracking.view', 'match' => 'tracking'],
        ],
    ],
    [
        'group' => 'Analysis',
        'items' => [
            ['label' => 'Reports', 'url' => 'reports', 'icon' => 'bar-chart-line', 'permission' => 'reports.view', 'match' => 'reports'],
        ],
    ],
    [
        'group' => 'Administration',
        'items' => [
            ['label' => 'Users', 'url' => 'users', 'icon' => 'person-badge', 'permission' => 'users.view', 'match' => 'users'],
            ['label' => 'Invitation Codes', 'url' => 'invites', 'icon' => 'ticket-perforated', 'permission' => 'invites.create', 'match' => 'invites'],
            ['label' => 'Branches', 'url' => 'branches', 'icon' => 'bank', 'permission' => 'branches.manage', 'match' => 'branches'],
            ['label' => 'Settings', 'url' => 'settings', 'icon' => 'sliders', 'permission' => 'settings.manage', 'match' => 'settings'],
            ['label' => 'Audit Log', 'url' => 'audit', 'icon' => 'shield-check', 'permission' => 'audit.view', 'match' => 'audit'],
        ],
    ],
];
?>
<nav class="lrms-nav">
    <?php foreach ($sections as $section): ?>
        <?php
        $visible = array_filter(
            $section['items'],
            static fn (array $item): bool => $item['permission'] === null || Auth::can($item['permission'])
        );
        if ($visible === []) {
            continue;
        }
        ?>
        <?php if ($section['group'] !== ''): ?>
            <div class="lrms-nav-group"><?= View::e($section['group']) ?></div>
        <?php endif; ?>
        <?php foreach ($visible as $item): ?>
            <?php
            $isActive = $path === $item['match']
                || str_starts_with($path, $item['match'] . '/');
            ?>
            <a class="lrms-nav-link<?= $isActive ? ' active' : '' ?>"
               href="<?= View::e(View::url($item['url'])) ?>">
                <i class="bi bi-<?= View::e($item['icon']) ?>"></i>
                <span><?= View::e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="lrms-nav-footer">
        <div class="small opacity-75">
            Signed in as<br>
            <strong><?= View::e(Auth::name()) ?></strong><br>
            <?= View::e($authUser['role_name'] ?? '') ?>
        </div>
    </div>
</nav>
