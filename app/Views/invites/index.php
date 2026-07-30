<?php

/**
 * @var list<array<string,mixed>> $codes
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var array{status:string} $filters
 */

use App\Core\Csrf;
use App\Core\View;

$statusBadge = ['active' => 'success', 'exhausted' => 'secondary', 'expired' => 'warning', 'revoked' => 'danger'];
?>

<div class="lrms-page-head">
    <div>
        <h1>Invitation codes</h1>
        <div class="lrms-page-sub">
            Open sign-up is disabled. A new user can only register with one of these codes.
        </div>
    </div>
    <div class="ms-auto">
        <a class="btn btn-sm btn-success" href="<?= View::e(View::url('invites/create')) ?>">
            <i class="bi bi-plus-lg me-1"></i>Generate code
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body pb-2">
        <form class="row g-2 align-items-end" method="get" data-auto-submit>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (['active', 'exhausted', 'expired', 'revoked'] as $status): ?>
                        <option value="<?= View::e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= View::e(ucfirst($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($codes === []): ?>
        <div class="lrms-empty">
            <i class="bi bi-ticket-perforated"></i>
            No invitation codes yet. Generate one and share it with the new user.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>Code</th><th>Role</th><th>Branch</th><th>Uses</th>
                    <th>Approval</th><th>Expires</th><th>Status</th><th>Issued by</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($codes as $code): ?>
                    <?php
                    $expired = $code['expires_at'] !== null && strtotime((string) $code['expires_at']) < time();
                    ?>
                    <tr>
                        <td>
                            <code class="fs-6 user-select-all"><?= View::e($code['code']) ?></code>
                            <?php if (!empty($code['notes'])): ?>
                                <div class="small text-muted"><?= View::e($code['notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= View::e($code['role_name']) ?></td>
                        <td class="small"><?= View::e($code['branch_name'] ?? 'any') ?></td>
                        <td class="small">
                            <?= (int) $code['used_count'] ?> / <?= (int) $code['max_uses'] ?>
                            <?php if ((int) $code['max_uses'] === 1): ?>
                                <div class="small text-muted">single use</div>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ((int) $code['requires_approval'] === 1): ?>
                                <span class="badge badge-soft bg-warning">Needs approval</span>
                            <?php else: ?>
                                <span class="badge badge-soft bg-success">Auto active</span>
                            <?php endif; ?>
                        </td>
                        <td class="small<?= $expired ? ' text-danger' : '' ?>">
                            <?= View::e(View::dateTime($code['expires_at'])) ?>
                        </td>
                        <td>
                            <span class="badge badge-soft bg-<?= View::e($statusBadge[$code['status']] ?? 'secondary') ?>">
                                <?= View::e(ucfirst((string) $code['status'])) ?>
                            </span>
                        </td>
                        <td class="small text-muted"><?= View::e($code['created_by_name'] ?? 'system') ?></td>
                        <td class="text-end">
                            <?php if ($code['status'] === 'active'): ?>
                                <form method="post"
                                      action="<?= View::e(View::url('invites/' . (int) $code['id'] . '/revoke')) ?>"
                                      data-confirm="Revoke code <?= View::e($code['code']) ?>? It will stop working immediately.">
                                    <?= Csrf::field() ?>
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
