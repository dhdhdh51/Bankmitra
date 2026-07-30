<?php

/** @var string $permission */

use App\Core\Auth;
use App\Core\View;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Not allowed &middot; LRMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
    <div class="card shadow-sm">
        <div class="card-body p-4 text-center">
            <i class="bi bi-shield-lock text-danger" style="font-size:3rem"></i>
            <h1 class="h4 mt-3">You do not have access to this page</h1>
            <p class="text-muted mb-1">
                Your role (<strong><?= View::e(Auth::user()['role_name'] ?? 'unknown') ?></strong>)
                is missing the required permission.
            </p>
            <p class="small text-muted">
                Required permission: <code><?= View::e($permission ?? '') ?></code>
            </p>
            <p class="small text-muted mb-3">
                This attempt has been recorded in the audit log. If you believe you should have access,
                ask a Super Admin to grant it.
            </p>
            <a class="btn btn-success" href="<?= View::e(View::url('dashboard')) ?>">
                <i class="bi bi-house me-1"></i>Back to dashboard
            </a>
        </div>
    </div>
</div>
</body>
</html>
