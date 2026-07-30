<?php

/**
 * Forgot password - OTP driven reset.
 *
 * @var string $step  'request' | 'reset'
 * @var array<string,string> $errors
 * @var string $identifier
 */

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

$errors = $errors ?? [];
$step = $step ?? 'request';
$identifier = $identifier ?? Session::oldInput('identifier');
?>

<h2 class="h6 text-center mb-3">Reset your password</h2>

<?php if ($step === 'request'): ?>

    <form method="post" action="<?= View::e(View::url('forgot-password')) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="step" value="request">

        <div class="mb-3">
            <label class="form-label" for="identifier">Registered mobile or email</label>
            <input type="text" class="form-control<?= isset($errors['identifier']) ? ' is-invalid' : '' ?>"
                   id="identifier" name="identifier" value="<?= View::e($identifier) ?>" required autofocus>
            <?php if (isset($errors['identifier'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['identifier']) ?></div>
            <?php endif; ?>
        </div>

        <button class="btn btn-success w-100 py-2" type="submit">
            <i class="bi bi-send me-1"></i>Send OTP
        </button>
    </form>

<?php else: ?>

    <div class="alert alert-info small py-2">
        <i class="bi bi-info-circle me-1"></i>
        OTP sent to <strong><?= View::e($maskedIdentifier ?? $identifier) ?></strong>.
    </div>

    <form method="post" action="<?= View::e(View::url('forgot-password')) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="step" value="reset">
        <input type="hidden" name="identifier" value="<?= View::e($identifier) ?>">

        <div class="mb-3">
            <label class="form-label" for="otp">OTP</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*"
                   class="form-control text-center<?= isset($errors['otp']) ? ' is-invalid' : '' ?>"
                   id="otp" name="otp" maxlength="8" autocomplete="one-time-code"
                   style="letter-spacing:.4rem" required autofocus>
            <?php if (isset($errors['otp'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['otp']) ?></div>
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

        <button class="btn btn-success w-100 py-2" type="submit">
            <i class="bi bi-check2-circle me-1"></i>Update password
        </button>
    </form>

<?php endif; ?>

<hr class="my-4">
<p class="text-center small mb-0">
    <a href="<?= View::e(View::url('login')) ?>">Back to sign in</a>
</p>
