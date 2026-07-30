<?php

/**
 * Change password. Also used as the forced first-login reset, which is why
 * it renders in the plain auth layout when must_change_password is set.
 *
 * @var array<string,string> $errors
 * @var bool $forced
 */

use App\Core\Csrf;
use App\Core\View;

$errors = $errors ?? [];
$forced = $forced ?? false;
?>

<?php if ($forced): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-shield-exclamation me-1"></i>
        This account still uses the default password. Please set your own password before continuing.
    </div>
<?php else: ?>
    <div class="lrms-page-head">
        <div>
            <h1>Change password</h1>
            <div class="lrms-page-sub">Signing you out of all other devices.</div>
        </div>
    </div>
<?php endif; ?>

<div class="<?= $forced ? '' : 'card' ?>">
    <div class="<?= $forced ? '' : 'card-body' ?>" style="<?= $forced ? '' : 'max-width:520px' ?>">
        <form method="post" action="<?= View::e(View::url('password/change')) ?>" novalidate>
            <?= Csrf::field() ?>

            <div class="mb-3">
                <label class="form-label" for="current_password">Current password</label>
                <input type="password" class="form-control<?= isset($errors['current_password']) ? ' is-invalid' : '' ?>"
                       id="current_password" name="current_password" autocomplete="current-password" required autofocus>
                <?php if (isset($errors['current_password'])): ?>
                    <div class="invalid-feedback"><?= View::e($errors['current_password']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label class="form-label" for="new_password">New password</label>
                <input type="password" class="form-control<?= isset($errors['new_password']) ? ' is-invalid' : '' ?>"
                       id="new_password" name="new_password" autocomplete="new-password" required>
                <?php if (isset($errors['new_password'])): ?>
                    <div class="invalid-feedback"><?= View::e($errors['new_password']) ?></div>
                <?php endif; ?>
                <div class="form-text">At least 8 characters with an uppercase letter, a lowercase letter and a digit.</div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="new_password_confirm">Confirm new password</label>
                <input type="password" class="form-control<?= isset($errors['new_password_confirm']) ? ' is-invalid' : '' ?>"
                       id="new_password_confirm" name="new_password_confirm" autocomplete="new-password" required>
                <?php if (isset($errors['new_password_confirm'])): ?>
                    <div class="invalid-feedback"><?= View::e($errors['new_password_confirm']) ?></div>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-success" type="submit">
                    <i class="bi bi-check2 me-1"></i>Update password
                </button>
                <?php if (!$forced): ?>
                    <a class="btn btn-outline-secondary" href="<?= View::e(View::url('dashboard')) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
