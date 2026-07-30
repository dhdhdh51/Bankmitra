<?php

/**
 * @var array<string,mixed>|null $user
 * @var list<array<string,mixed>> $roles
 * @var list<array<string,mixed>> $branches
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;

$isEdit = $user !== null;
$action = $isEdit ? View::url('users/' . (int) $user['id'] . '/edit') : View::url('users/create');
$roleId = (int) ($user['role_id'] ?? 4);
?>

<div class="lrms-page-head">
    <div>
        <h1><?= $isEdit ? 'Edit user' : 'Add user' ?></h1>
        <div class="lrms-page-sub">
            <?php if ($isEdit): ?>
                Editing <?= View::e($user['full_name']) ?>
            <?php else: ?>
                Creating an account directly. To let someone self-register instead, issue an
                <a href="<?= View::e(View::url('invites')) ?>">invitation code</a>.
            <?php endif; ?>
        </div>
    </div>
    <div class="ms-auto">
        <a class="btn btn-sm btn-outline-secondary" href="<?= View::e(View::url('users')) ?>">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="card" style="max-width:820px">
    <form method="post" action="<?= View::e($action) ?>" novalidate>
        <?= Csrf::field() ?>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="full_name">Full name *</label>
                    <input type="text" class="form-control" id="full_name" name="full_name"
                           value="<?= View::e($user['full_name'] ?? '') ?>" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="employee_code">Employee code</label>
                    <input type="text" class="form-control" id="employee_code" name="employee_code"
                           value="<?= View::e($user['employee_code'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="email">Email *</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?= View::e($user['email'] ?? '') ?>" required
                           placeholder="name@example.com">
                    <div class="form-text">Login ID and email OTP delivery. Stored encrypted.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="mobile">Mobile <span class="text-muted">(optional)</span></label>
                    <input type="tel" inputmode="numeric" class="form-control" id="mobile" name="mobile"
                           value="<?= View::e($user['mobile'] ?? '') ?>" placeholder="9876543210">
                    <div class="form-text">
                        Needed only for <strong>SMS OTP</strong>. A BC agent who will sign in
                        on the Android app should have one. Stored encrypted.
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="role_id">Role *</label>
                    <select class="form-select" id="role_id" name="role_id"
                            <?= ($isEdit && !Auth::isSuperAdmin()) ? 'disabled' : '' ?> required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>"
                                    data-code="<?= View::e($role['code']) ?>"
                                <?= $roleId === (int) $role['id'] ? 'selected' : '' ?>>
                                <?= View::e($role['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isEdit && !Auth::isSuperAdmin()): ?>
                        <div class="form-text">Only a Super Admin can change roles.</div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="branch_id">Branch</label>
                    <select class="form-select" id="branch_id" name="branch_id">
                        <option value="">- none -</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>"
                                <?= (int) ($user['branch_id'] ?? 0) === (int) $branch['id'] ? 'selected' : '' ?>>
                                <?= View::e($branch['code']) ?> - <?= View::e($branch['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($branches === []): ?>
                        <div class="form-text text-warning-emphasis">
                            No branches yet. Import a spreadsheet or
                            <a href="<?= View::e(View::url('branches/create')) ?>">add a branch</a> first.
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$isEdit): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password"
                               autocomplete="new-password">
                        <div class="form-text">
                            Leave blank and the system will generate a temporary password and show it once.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <hr class="my-4">
            <h2 class="h6">BC Agent details</h2>
            <p class="small text-muted">
                Only used when the role is <strong>BC Agent</strong>. The BC code must match the
                <code>BC_CODE</code> column of the allocation spreadsheet so accounts are assigned
                automatically on import.
            </p>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="bc_code">BC code</label>
                    <input type="text" class="form-control" id="bc_code" name="bc_code"
                           value="<?= View::e($user['bc_code'] ?? '') ?>"
                           <?= $isEdit && empty($user['bc_code']) ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="monthly_target">Monthly recovery target</label>
                    <input type="number" step="0.01" min="0" class="form-control"
                           id="monthly_target" name="monthly_target"
                           value="<?= View::e((string) ($user['monthly_target'] ?? '0')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="visit_target">Visits per day target</label>
                    <input type="number" min="0" max="255" class="form-control"
                           id="visit_target" name="visit_target"
                           value="<?= View::e((string) ($user['visit_target'] ?? '0')) ?>">
                </div>
            </div>
        </div>

        <div class="card-footer bg-white">
            <button class="btn btn-success" type="submit">
                <i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Save changes' : 'Create user' ?>
            </button>
            <a class="btn btn-outline-secondary" href="<?= View::e(View::url('users')) ?>">Cancel</a>
        </div>
    </form>
</div>
