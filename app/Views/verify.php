<?php

/**
 * Public QR verification page - deliberately standalone (no layout, no session
 * requirement) and deliberately free of any customer data.
 *
 * @var array<string,mixed>|null $document
 * @var string|null $error
 * @var array<string,string> $context
 * @var string $organisation
 * @var string $appName
 */

use App\Core\View;

$typeLabels = [
    'visit_report'      => 'Field visit report',
    'loan_statement'    => 'Loan recovery statement',
    'recovery_receipt'  => 'Recovery receipt',
    'tabular_report'    => 'Management report',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Document verification &middot; <?= View::e($appName) ?></title>
    <link rel="icon" href="<?= View::e(View::asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= View::e(View::asset('css/app.css')) ?>" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4 py-md-5" style="max-width:560px">

    <div class="text-center mb-4">
        <img src="<?= View::e(View::asset('img/logo.svg')) ?>" alt="" width="56" height="56">
        <h1 class="h5 mt-2 mb-0"><?= View::e($organisation !== '' ? $organisation : $appName) ?></h1>
        <p class="text-muted small mb-0">Document verification</p>
    </div>

    <?php if ($document === null): ?>

        <div class="card border-danger shadow-sm">
            <div class="card-body text-center p-4">
                <i class="bi bi-x-octagon-fill text-danger" style="font-size:2.6rem"></i>
                <h2 class="h5 mt-3">Not verified</h2>
                <p class="mb-0"><?= View::e($error ?? 'This document could not be verified.') ?></p>
                <p class="small text-muted mt-3 mb-0">
                    If you were handed a printed document with this code, please report it to the branch.
                </p>
            </div>
        </div>

    <?php else: ?>

        <div class="card border-success shadow-sm">
            <div class="card-body p-4">
                <div class="text-center">
                    <i class="bi bi-patch-check-fill text-success" style="font-size:2.6rem"></i>
                    <h2 class="h5 mt-3 mb-1">Genuine document</h2>
                    <p class="text-muted small">
                        This document was issued by this system and has not been altered.
                    </p>
                </div>

                <hr>

                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">Document type</dt>
                    <dd class="col-7 fw-semibold">
                        <?= View::e($typeLabels[$document['report_type']] ?? View::label((string) $document['report_type'])) ?>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Document ID</dt>
                    <dd class="col-7"><code><?= View::e($document['doc_uid']) ?></code></dd>

                    <dt class="col-5 text-muted fw-normal">Issued on</dt>
                    <dd class="col-7"><?= View::e(View::dateTime($document['created_at'])) ?></dd>

                    <?php if (!empty($document['generated_by_name'])): ?>
                        <dt class="col-5 text-muted fw-normal">Issued by</dt>
                        <dd class="col-7"><?= View::e($document['generated_by_name']) ?></dd>
                    <?php endif; ?>

                    <?php foreach ($context as $label => $value): ?>
                        <dt class="col-5 text-muted fw-normal"><?= View::e($label) ?></dt>
                        <dd class="col-7"><?= View::e($value) ?></dd>
                    <?php endforeach; ?>

                    <dt class="col-5 text-muted fw-normal">Content fingerprint</dt>
                    <dd class="col-7">
                        <code class="small" style="word-break:break-all">
                            <?= View::e(substr((string) $document['content_hash'], 0, 32)) ?>...
                        </code>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Times verified</dt>
                    <dd class="col-7"><?= (int) $document['verify_count'] ?></dd>
                </dl>
            </div>
        </div>

        <p class="text-muted small text-center mt-3 mb-0">
            For privacy, this page does not display customer names, account numbers or amounts.
            Compare the Document ID above with the one printed on your copy.
        </p>

    <?php endif; ?>

    <p class="text-center small text-muted mt-4 mb-0">
        <?= View::e($appName) ?> &middot; Loan Recovery Management System
    </p>
</div>
</body>
</html>
