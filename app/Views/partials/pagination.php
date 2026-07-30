<?php

/**
 * Pagination bar, preserving the current query string filters.
 *
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 */

use App\Core\View;

$meta = $meta ?? ['total' => 0, 'page' => 1, 'perPage' => 25, 'pages' => 1];
if ($meta['pages'] <= 1) {
    if ($meta['total'] > 0) {
        echo '<div class="text-muted small px-3 py-2">' . (int) $meta['total'] . ' record(s)</div>';
    }
    return;
}

$query = $_GET;
unset($query['_route'], $query['page']);
$link = static function (int $page) use ($query): string {
    $query['page'] = $page;
    return '?' . http_build_query($query);
};

$current = $meta['page'];
$last = $meta['pages'];
$from = max(1, $current - 2);
$to = min($last, $current + 2);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top">
    <div class="text-muted small">
        Showing page <?= (int) $current ?> of <?= (int) $last ?>
        &middot; <?= number_format((int) $meta['total']) ?> record(s)
    </div>
    <nav aria-label="Pagination">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item<?= $current <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= View::e($link(max(1, $current - 1))) ?>" aria-label="Previous">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php if ($from > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= View::e($link(1)) ?>">1</a></li>
                <?php if ($from > 2): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($page = $from; $page <= $to; $page++): ?>
                <li class="page-item<?= $page === $current ? ' active' : '' ?>">
                    <a class="page-link" href="<?= View::e($link($page)) ?>"><?= $page ?></a>
                </li>
            <?php endfor; ?>

            <?php if ($to < $last): ?>
                <?php if ($to < $last - 1): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= View::e($link($last)) ?>"><?= $last ?></a></li>
            <?php endif; ?>

            <li class="page-item<?= $current >= $last ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= View::e($link(min($last, $current + 1))) ?>" aria-label="Next">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
</div>
