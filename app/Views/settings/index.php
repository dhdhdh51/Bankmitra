<?php

/**
 * Settings hub.
 *
 * @var array<string,array{label:string,icon:string,description:string,configured:int,total:int}> $groups
 * @var list<array{module:string,label:string,missing:list<string>,url:string}> $missingConfig
 */

use App\Core\View;
?>

<div class="lrms-page-head">
    <div>
        <h1>Settings &amp; Integrations</h1>
        <div class="lrms-page-sub">
            Everything the system needs at runtime lives here. Saved values take effect immediately -
            no code changes and no re-upload required.
        </div>
    </div>
</div>

<?php if ($missingConfig !== []): ?>
    <div class="alert alert-warning shadow-sm">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong><?= count($missingConfig) ?> integration(s) are incomplete.</strong>
        Features that depend on them are skipped gracefully instead of erroring:
        <?php $labels = array_map(static fn (array $m): string => $m['label'], $missingConfig); ?>
        <?= View::e(implode(', ', $labels)) ?>.
    </div>
<?php endif; ?>

<div class="alert alert-secondary small">
    <i class="bi bi-shield-lock me-1"></i>
    Keys and passwords are stored <strong>AES-256 encrypted</strong> in the database using the
    <code>app_key</code> from <code>config/config.php</code>. They are never shown again after saving
    and never appear in logs. Leave a secret field blank to keep the existing value.
</div>

<div class="row g-3">
    <?php foreach ($groups as $key => $group): ?>
        <?php
        $complete = $group['configured'] >= $group['total'];
        $partial = $group['configured'] > 0 && !$complete;
        ?>
        <div class="col-md-6 col-xl-4">
            <a class="card h-100 text-decoration-none text-body"
               href="<?= View::e(View::url('settings/' . $key)) ?>">
                <div class="card-body d-flex gap-3">
                    <div class="lrms-tile-icon flex-shrink-0">
                        <i class="bi bi-<?= View::e($group['icon']) ?>"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="fw-semibold"><?= View::e($group['label']) ?></span>
                            <?php if ($complete): ?>
                                <span class="badge badge-soft bg-success">Configured</span>
                            <?php elseif ($partial): ?>
                                <span class="badge badge-soft bg-warning">Partial</span>
                            <?php else: ?>
                                <span class="badge badge-soft bg-secondary">Not set</span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted"><?= View::e($group['description']) ?></div>
                        <div class="small text-muted mt-2">
                            <?= (int) $group['configured'] ?> of <?= (int) $group['total'] ?> fields filled
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
