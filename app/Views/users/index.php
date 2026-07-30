<?php

/**
 * @var list<array<string,mixed>> $users
 * @var list<array<string,mixed>> $roles
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var array{search:string,status:string,role_id:int} $filters
 * @var array<string,mixed> $counts
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;

$statusBadge = [
    'active' => 'success', 'pending' => 'warning',
    'suspended' => 'danger', 'disabled' => 'secondary',
];
?>

<div class="lrms-page-head">
    <div>
        <h1>Users</h1>
        <div class="lrms-page-sub">
            Approve new registrations, reset device bindings and manage access.
        </div>
    </div>
    <?php if (Auth::can('users.create')): ?>
        <div class="ms-auto d-flex gap-2">
            <a class="btn btn-sm btn-outline-success" href="<?= View::e(View::url('invites')) ?>">
                <i class="bi bi-ticket-perforated me-1"></i>Invitation codes
            </a>
            <a class="btn btn-sm btn-success" href="<?= View::e(View::url('users/create')) ?>">
                <i class="bi bi-plus-lg me-1"></i>Add user
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-2 mb-3">
    <?php foreach (['pending' => 'warning', 'active' => 'success', 'suspended' => 'danger', 'disabled' => 'secondary'] as $key => $tone): ?>
        <div class="col-6 col-md-3">
            <a class="text-decoration-none" href="?status=<?= View::e($key) ?>">
                <div class="lrms-tile">
                    <div class="min-w-0">
                        <div class="lrms-tile-value text-<?= View::e($tone) ?>">
                            <?= number_format((int) ($counts[$key] ?? 0)) ?>
                        </div>
                        <div class="lrms-tile-label"><?= View::e(ucfirst($key)) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-body pb-2">
        <form class="row g-2 align-items-end" method="get" data-auto-submit>
            <div class="col-md-4">
                <label class="form-label" for="search">Search</label>
                <input type="search" class="form-control form-control-sm" id="search" name="search"
                       value="<?= View::e($filters['search']) ?>" placeholder="Name, code, mobile or email">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (['pending', 'active', 'suspended', 'disabled'] as $status): ?>
                        <option value="<?= View::e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= View::e(ucfirst($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="role_id">Role</label>
                <select class="form-select form-select-sm" id="role_id" name="role_id">
                    <option value="">All</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= (int) $role['id'] ?>" <?= $filters['role_id'] === (int) $role['id'] ? 'selected' : '' ?>>
                            <?= View::e($role['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <?php if ($users === []): ?>
        <div class="lrms-empty"><i class="bi bi-people"></i>No users match these filters.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>Name</th><th>Role</th><th>Branch</th><th>Mobile</th>
                    <th>Device</th><th>Last login</th><th>Status</th><th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <?php $id = (int) $user['id']; ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= View::e($user['full_name']) ?></div>
                            <div class="small text-muted">
                                <?= View::e($user['employee_code'] ?? '-') ?>
                                <?php if (!empty($user['bc_code'])): ?>
                                    &middot; BC <?= View::e($user['bc_code']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="small"><?= View::e($user['role_name']) ?></td>
                        <td class="small"><?= View::e($user['branch_name'] ?? '-') ?></td>
                        <td class="small">
                            <?= $user['mobile_last4'] !== null ? '******' . View::e($user['mobile_last4']) : '-' ?>
                        </td>
                        <td class="small">
                            <?php if (!empty($user['device_id'])): ?>
                                <i class="bi bi-phone text-success"></i>
                                <?= View::e($user['device_model'] ?? 'bound') ?>
                            <?php else: ?>
                                <span class="text-muted">not bound</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= View::e(View::dateTime($user['last_login_at'])) ?></td>
                        <td>
                            <span class="badge badge-soft bg-<?= View::e($statusBadge[$user['status']] ?? 'secondary') ?>">
                                <?= View::e(ucfirst((string) $user['status'])) ?>
                            </span>
                            <?php if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()): ?>
                                <div class="small text-danger"><i class="bi bi-lock"></i> locked</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                        data-bs-toggle="dropdown" aria-expanded="false">Manage</button>
                                <ul class="dropdown-menu dropdown-menu-end shadow small">
                                    <?php if (Auth::can('users.edit')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= View::e(View::url('users/' . $id . '/edit')) ?>">
                                                <i class="bi bi-pencil me-2"></i>Edit
                                            </a>
                                        </li>
                                    <?php endif; ?>

                                    <?php if ($user['status'] === 'pending' && Auth::can('users.approve')): ?>
                                        <li>
                                            <form method="post" action="<?= View::e(View::url('users/' . $id . '/approve')) ?>"
                                                  data-confirm="Approve <?= View::e($user['full_name']) ?>?">
                                                <?= Csrf::field() ?>
                                                <button class="dropdown-item text-success" type="submit">
                                                    <i class="bi bi-check2-circle me-2"></i>Approve
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (Auth::can('users.edit')): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="post" action="<?= View::e(View::url('users/' . $id . '/reset-device')) ?>"
                                                  data-confirm="Clear the device binding for <?= View::e($user['full_name']) ?>? They will be able to sign in from a new phone.">
                                                <?= Csrf::field() ?>
                                                <button class="dropdown-item" type="submit">
                                                    <i class="bi bi-phone-flip me-2"></i>Reset device
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="post" action="<?= View::e(View::url('users/' . $id . '/reset-password')) ?>"
                                                  data-confirm="Issue a temporary password for <?= View::e($user['full_name']) ?>?">
                                                <?= Csrf::field() ?>
                                                <button class="dropdown-item" type="submit">
                                                    <i class="bi bi-key me-2"></i>Reset password
                                                </button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <?php foreach (['active' => 'Activate', 'suspended' => 'Suspend', 'disabled' => 'Disable'] as $status => $label): ?>
                                            <?php if ($user['status'] === $status) { continue; } ?>
                                            <li>
                                                <form method="post" action="<?= View::e(View::url('users/' . $id . '/status')) ?>"
                                                      data-confirm="<?= View::e($label) ?> <?= View::e($user['full_name']) ?>?">
                                                    <?= Csrf::field() ?>
                                                    <input type="hidden" name="status" value="<?= View::e($status) ?>">
                                                    <button class="dropdown-item<?= $status === 'active' ? '' : ' text-danger' ?>" type="submit">
                                                        <i class="bi bi-<?= $status === 'active' ? 'play-circle' : 'slash-circle' ?> me-2"></i><?= View::e($label) ?>
                                                    </button>
                                                </form>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
