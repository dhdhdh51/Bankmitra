<?php

/**
 * Flash message strip.
 * @var list<array{type:string,message:string}> $flashes
 */

use App\Core\View;

$icons = [
    'success' => 'check-circle-fill',
    'danger'  => 'exclamation-triangle-fill',
    'warning' => 'exclamation-circle-fill',
    'info'    => 'info-circle-fill',
];
?>
<?php foreach ($flashes ?? [] as $flash): ?>
    <?php $type = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info'; ?>
    <div class="alert alert-<?= View::e($type) ?> alert-dismissible d-flex align-items-start gap-2 shadow-sm" role="alert">
        <i class="bi bi-<?= View::e($icons[$type]) ?> mt-1"></i>
        <div class="flex-grow-1"><?= View::e($flash['message']) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
