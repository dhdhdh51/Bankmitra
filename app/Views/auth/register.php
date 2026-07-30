<?php

/**
 * Invitation-code registration (open signup is disabled by design).
 *
 * Three steps in one page:
 *   1. enter the invitation code   -> $step = 'code'
 *   2. fill the profile + get OTP  -> $step = 'profile'
 *   3. enter the OTP and finish    -> $step = 'otp'
 *
 * @var string $step
 * @var array<string,string> $errors
 * @var array<string,mixed>|null $invite
 */

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

$errors = $errors ?? [];
$step = $step ?? 'code';
$invite = $invite ?? null;
$old = static fn (string $key): string => Session::oldInput($key);
?>

<h2 class="h6 text-center mb-3">Create your account</h2>

<div class="d-flex justify-content-center gap-2 mb-4 small">
    <span class="badge <?= $step === 'code' ? 'bg-success' : 'bg-secondary' ?>">1. Code</span>
    <span class="badge <?= $step === 'profile' ? 'bg-success' : 'bg-secondary' ?>">2. Profile</span>
    <span class="badge <?= $step === 'otp' ? 'bg-success' : 'bg-secondary' ?>">3. Verify</span>
</div>

<?php if ($step === 'code'): ?>

    <form method="post" action="<?= View::e(View::url('register')) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="step" value="code">

        <div class="mb-3">
            <label class="form-label" for="invite_code">Invitation code</label>
            <input type="text" class="form-control text-uppercase<?= isset($errors['invite_code']) ? ' is-invalid' : '' ?>"
                   id="invite_code" name="invite_code" value="<?= View::e($old('invite_code')) ?>"
                   style="letter-spacing:.18rem" required autofocus autocapitalize="characters">
            <?php if (isset($errors['invite_code'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['invite_code']) ?></div>
            <?php endif; ?>
            <div class="form-text">Ask your Branch Manager or administrator for this code.</div>
        </div>

        <button class="btn btn-success w-100 py-2" type="submit">Continue</button>
    </form>

<?php elseif ($step === 'profile' && $invite !== null): ?>

    <div class="alert alert-success small py-2">
        <div><strong>Code accepted.</strong></div>
        <div>Role: <strong><?= View::e($invite['role_name']) ?></strong></div>
        <?php if (!empty($invite['branch_name'])): ?>
            <div>Branch: <strong><?= View::e($invite['branch_name']) ?></strong></div>
        <?php endif; ?>
        <?php if ((int) $invite['requires_approval'] === 1): ?>
            <div class="mt-1 text-warning-emphasis">
                <i class="bi bi-info-circle"></i> Your account will need administrator approval before you can sign in.
            </div>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= View::e(View::url('register')) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="step" value="profile">
        <input type="hidden" name="invite_code" value="<?= View::e($invite['code']) ?>">

        <div class="mb-3">
            <label class="form-label" for="full_name">Full name</label>
            <input type="text" class="form-control<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>"
                   id="full_name" name="full_name" value="<?= View::e($old('full_name')) ?>" required autofocus>
            <?php if (isset($errors['full_name'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['full_name']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                   id="email" name="email" value="<?= View::e($old('email')) ?>"
                   placeholder="name@example.com" required>
            <?php if (isset($errors['email'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['email']) ?></div>
            <?php endif; ?>
            <div class="form-text">The OTP will be sent here. This becomes your login ID.</div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="mobile">Mobile number <span class="text-muted fw-normal">(optional)</span></label>
            <input type="tel" inputmode="numeric" class="form-control<?= isset($errors['mobile']) ? ' is-invalid' : '' ?>"
                   id="mobile" name="mobile" value="<?= View::e($old('mobile')) ?>"
                   placeholder="9876543210">
            <?php if (isset($errors['mobile'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['mobile']) ?></div>
            <?php endif; ?>
            <div class="form-text">Only used for SMS reminders. Not needed to sign in.</div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="employee_code">Employee / BC code <span class="text-muted fw-normal">(optional)</span></label>
            <input type="text" class="form-control<?= isset($errors['employee_code']) ? ' is-invalid' : '' ?>"
                   id="employee_code" name="employee_code" value="<?= View::e($old('employee_code')) ?>">
            <?php if (isset($errors['employee_code'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['employee_code']) ?></div>
            <?php endif; ?>
            <div class="form-text">
                For BC Agents this should match the <code>BC CODE</code> column in the allocation
                spreadsheet, so accounts are assigned to you automatically.
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                   id="password" name="password" autocomplete="new-password" required>
            <?php if (isset($errors['password'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['password']) ?></div>
            <?php endif; ?>
            <div class="form-text">At least 8 characters with an uppercase letter, a lowercase letter and a digit.</div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="password_confirm">Confirm password</label>
            <input type="password" class="form-control<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>"
                   id="password_confirm" name="password_confirm" autocomplete="new-password" required>
            <?php if (isset($errors['password_confirm'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['password_confirm']) ?></div>
            <?php endif; ?>
        </div>

        <button class="btn btn-success w-100 py-2" type="submit">
            <i class="bi bi-send me-1"></i>Send OTP &amp; continue
        </button>
    </form>

<?php else: ?>

    <div class="alert alert-info small py-2">
        <i class="bi bi-info-circle me-1"></i>
        OTP sent to <strong><?= View::e($maskedIdentifier ?? '') ?></strong>.
    </div>

    <form method="post" action="<?= View::e(View::url('register')) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="step" value="otp">

        <div class="mb-3">
            <label class="form-label" for="otp">Enter OTP</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*"
                   class="form-control form-control-lg text-center<?= isset($errors['otp']) ? ' is-invalid' : '' ?>"
                   id="otp" name="otp" maxlength="8" autocomplete="one-time-code"
                   style="letter-spacing:.5rem" required autofocus>
            <?php if (isset($errors['otp'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['otp']) ?></div>
            <?php endif; ?>
        </div>

        <button class="btn btn-success w-100 py-2" type="submit">
            <i class="bi bi-check2-circle me-1"></i>Complete registration
        </button>
    </form>

<?php endif; ?>

<hr class="my-4">
<p class="text-center small mb-0">
    Already have an account? <a href="<?= View::e(View::url('login')) ?>">Sign in</a>
</p>
