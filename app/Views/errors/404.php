<?php

/** @var string $path */

use App\Core\Auth;
use App\Core\View;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found &middot; LRMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
    <div class="card shadow-sm">
        <div class="card-body p-4 text-center">
            <i class="bi bi-signpost-split text-secondary" style="font-size:3rem"></i>
            <h1 class="h4 mt-3">Page not found</h1>
            <p class="text-muted mb-1">There is nothing at this address:</p>
            <p><code><?= View::e('/' . ($path ?? '')) ?></code></p>
            <a class="btn btn-success mt-2"
               href="<?= View::e(View::url(Auth::check() ? 'dashboard' : 'login')) ?>">
                <i class="bi bi-house me-1"></i><?= Auth::check() ? 'Back to dashboard' : 'Go to sign in' ?>
            </a>
        </div>
    </div>
</div>
</body>
</html>
