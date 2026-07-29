<?php

/**
 * @var list<array<string,mixed>> $roles
 * @var list<array<string,mixed>> $branches
 * @var array{expiry_days:int,role_id:int,requires_approval:bool} $defaults
 */

use App\Core\Csrf;
use App\Core\View;
?>

<div class="lrms-page-head">
    <div>
        <h1>Generate invitation code</h1>
        <div class="lrms-page-sub">
            The role and branch are baked into the code, so the new user cannot choose their own access level.
        </div>
    </div>
    <div class="ms-auto">
        <a class="btn btn-sm btn-outline-secondary" href="<?= View::e(View::url('invites')) ?>">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="card" style="max-width:720px">
    <form method="post" action="<?= View::e(View::url('invites/create')) ?>" novalidate>
        <?= Csrf::field() ?>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="role_id">Role *</label>
                    <select class="form-select" id="role_id" name="role_id" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>"
                                <?= $defaults['role_id'] === (int) $role['id'] ? 'selected' : '' ?>>
                                <?= View::e($role['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">You can only issue codes for roles below your own.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="branch_id">Branch</label>
                    <select class="form-select" id="branch_id" name="branch_id">
                        <option value="">- any branch -</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>">
                                <?= View::e($branch['code']) ?> - <?= View::e($branch['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="max_uses">Maximum uses *</label>
                    <input type="number" class="form-control" id="max_uses" name="max_uses"
                           value="1" min="1" max="500" required>
                    <div class="form-text">1 = single use.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="expiry_days">Valid for (days) *</label>
                    <input type="number" class="form-control" id="expiry_days" name="expiry_days"
                           value="<?= (int) $defaults['expiry_days'] ?>" min="1" max="365" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="quantity">How many codes</label>
                    <input type="number" class="form-control" id="quantity" name="quantity"
                           value="1" min="1" max="50">
                    <div class="form-text">Generate a batch for onboarding several agents.</div>
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" value="1"
                               id="requires_approval" name="requires_approval"
                            <?= $defaults['requires_approval'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="requires_approval">
                            New account needs administrator approval before it can sign in
                        </label>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <input type="text" class="form-control" id="notes" name="notes" maxlength="255"
                           placeholder="e.g. For 3 new BC agents at Bihta branch">
                </div>
            </div>
        </div>

        <div class="card-footer bg-white">
            <button class="btn btn-success" type="submit">
                <i class="bi bi-ticket-perforated me-1"></i>Generate
            </button>
            <a class="btn btn-outline-secondary" href="<?= View::e(View::url('invites')) ?>">Cancel</a>
        </div>
    </form>
</div>
