<?php

/**
 * Main admin panel layout.
 *
 * @var string $content   rendered page body
 * @var string $pageTitle
 * @var array  $flashes
 * @var array|null $authUser
 * @var string $currentPath
 */

use App\Core\Auth;
use App\Core\View;
use Lib\Settings;

$appName = Settings::getString('company.app_name', 'LRMS');
$organisation = Settings::getString('company.organisation', '');
$heading = $pageTitle ?? $appName;
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= View::e($heading) ?> &middot; <?= View::e($appName) ?></title>

    <link rel="icon" href="<?= View::e(View::asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= View::e(View::asset('css/app.css')) ?>" rel="stylesheet">
</head>
<body>

<!-- ================= top bar ================= -->
<nav class="navbar navbar-expand-lg lrms-topbar sticky-top">
    <div class="container-fluid">
        <button class="btn btn-link text-white d-lg-none p-1 me-2" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#lrmsSidebar" aria-label="Menu">
            <i class="bi bi-list fs-3"></i>
        </button>

        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= View::e(View::url('dashboard')) ?>">
            <img src="<?= View::e(View::asset('img/logo.svg')) ?>" alt="" width="32" height="32">
            <span class="d-flex flex-column lh-1">
                <span class="fw-semibold"><?= View::e($appName) ?></span>
                <?php if ($organisation !== ''): ?>
                    <small class="opacity-75" style="font-size:.68rem"><?= View::e($organisation) ?></small>
                <?php endif; ?>
            </span>
        </a>

        <div class="ms-auto d-flex align-items-center gap-2">
            <form class="d-none d-md-flex" action="<?= View::e(View::url('customers')) ?>" method="get" role="search">
                <div class="input-group input-group-sm">
                    <input type="search" name="search" class="form-control" style="min-width:220px"
                           placeholder="Account / CIF / Mobile / Name / Village"
                           aria-label="Search" value="">
                    <button class="btn btn-light" type="submit"><i class="bi bi-search"></i></button>
                </div>
            </form>

            <div class="dropdown">
                <button class="btn btn-sm btn-outline-light dropdown-toggle d-flex align-items-center gap-2"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i>
                    <span class="d-none d-sm-inline"><?= View::e(Auth::name()) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li class="px-3 py-2">
                        <div class="fw-semibold"><?= View::e(Auth::name()) ?></div>
                        <div class="small text-muted"><?= View::e($authUser['role_name'] ?? '') ?></div>
                        <?php if (!empty($authUser['branch_name'])): ?>
                            <div class="small text-muted"><i class="bi bi-geo-alt"></i> <?= View::e($authUser['branch_name']) ?></div>
                        <?php endif; ?>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= View::e(View::url('password/change')) ?>">
                        <i class="bi bi-key me-2"></i>Change password</a></li>
                    <li>
                        <form method="post" action="<?= View::e(View::url('logout')) ?>" class="m-0">
                            <?= App\Core\Csrf::field() ?>
                            <button class="dropdown-item text-danger" type="submit">
                                <i class="bi bi-box-arrow-right me-2"></i>Sign out
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<div class="lrms-shell">
    <!-- ================= sidebar ================= -->
    <aside class="lrms-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="lrmsSidebar">
        <div class="offcanvas-header d-lg-none">
            <h6 class="offcanvas-title text-white mb-0">Menu</h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                    data-bs-target="#lrmsSidebar" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-0">
            <?= View::capture('partials/sidebar', [
                'currentPath' => $currentPath ?? '',
                'authUser'    => $authUser ?? null,
            ]) ?>
        </div>
    </aside>

    <!-- ================= main ================= -->
    <main class="lrms-main">
        <?= View::capture('partials/flash', ['flashes' => $flashes ?? []]) ?>
        <?= $content ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>window.LRMS_BASE = <?= json_encode(View::url()) ?>;</script>
<script src="<?= View::e(View::asset('js/app.js')) ?>"></script>
<?php if (!empty($pageScripts)): ?>
    <?= $pageScripts ?>
<?php endif; ?>
</body>
</html>
