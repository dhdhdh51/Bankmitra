<?php

/**
 * Layout for the unauthenticated pages (login, register, forgot password).
 *
 * @var string $content
 * @var string $pageTitle
 * @var array  $flashes
 */

use App\Core\View;
use Lib\Settings;

$appName = Settings::getString('company.app_name', 'LRMS');
$organisation = Settings::getString('company.organisation', '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= View::e($pageTitle ?? 'Sign in') ?> &middot; <?= View::e($appName) ?></title>
    <link rel="icon" href="<?= View::e(View::asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= View::e(View::asset('css/app.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="lrms-auth">
    <div class="lrms-auth-card">
        <div class="lrms-auth-head">
            <img src="<?= View::e(View::asset('img/logo.svg')) ?>" alt="<?= View::e($appName) ?> logo">
            <h1 class="h5 mt-3 mb-0 fw-semibold"><?= View::e($appName) ?></h1>
            <p class="text-muted small mb-0">
                <?= $organisation !== '' ? View::e($organisation) : 'Loan Recovery Management System' ?>
            </p>
        </div>
        <div class="lrms-auth-body">
            <?= View::capture('partials/flash', ['flashes' => $flashes ?? []]) ?>
            <?= $content ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= View::e(View::asset('js/app.js')) ?>"></script>
</body>
</html>
