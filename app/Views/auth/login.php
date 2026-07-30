<?php

/**
 * Sign in - password or OTP, both supported as required.
 *
 * @var array<string,string> $errors
 * @var string $mode      'password' | 'otp'
 * @var string $identifier
 * @var bool   $otpSent
 * @var bool   $smsAvailable
 * @var bool   $emailAvailable
 * @var int    $resendSeconds
 */

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

$errors = $errors ?? [];
$mode = $mode ?? 'password';
$identifier = $identifier ?? Session::oldInput('identifier');
$otpSent = $otpSent ?? false;
?>

<ul class="nav nav-pills nav-fill mb-3 bg-body-secondary rounded p-1" role="tablist">
    <li class="nav-item">
        <a class="nav-link<?= $mode === 'password' ? ' active' : '' ?>"
           href="<?= View::e(View::url('login')) ?>">
            <i class="bi bi-key me-1"></i>Password
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $mode === 'otp' ? ' active' : '' ?>"
           href="<?= View::e(View::url('login?mode=otp')) ?>">
            <i class="bi bi-shield-lock me-1"></i>OTP
        </a>
    </li>
</ul>

<?php if ($mode === 'password'): ?>

    <form method="post" action="<?= View::e(View::url('login')) ?>" novalidate>
        <?= Csrf::field() ?>

        <div class="mb-3">
            <label class="form-label" for="identifier">Mobile, email or employee code</label>
            <input type="text" class="form-control<?= isset($errors['identifier']) ? ' is-invalid' : '' ?>"
                   id="identifier" name="identifier" value="<?= View::e($identifier) ?>"
                   autocomplete="username" required autofocus>
            <?php if (isset($errors['identifier'])): ?>
                <div class="invalid-feedback"><?= View::e($errors['identifier']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
                <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                       id="password" name="password" autocomplete="current-password" required>
                <button class="btn btn-outline-secondary" type="button"
                        onclick="var f=document.getElementById('password');f.type=f.type==='password'?'text':'password';this.querySelector('i').classList.toggle('bi-eye');this.querySelector('i').classList.toggle('bi-eye-slash');"
                        aria-label="Show password">
                    <i class="bi bi-eye"></i>
                </button>
                <?php if (isset($errors['password'])): ?>
                    <div class="invalid-feedback"><?= View::e($errors['password']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <button class="btn btn-success w-100 py-2" type="submit">
            <i class="bi bi-box-arrow-in-right me-1"></i>Sign in
        </button>
    </form>

<?php else: ?>

    <?php if (!$otpSent): ?>

        <?php if (!$smsAvailable && !$emailAvailable): ?>
            <div class="alert alert-warning small">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                Neither the SMS gateway nor SMTP is configured on this server yet, so OTP sign-in
                cannot work. Use a password, or configure them in
                <strong>Settings &rarr; Integrations</strong>.
            </div>
        <?php endif; ?>

        <form method="post" action="<?= View::e(View::url('login/otp/request')) ?>" novalidate>
            <?= Csrf::field() ?>

            <div class="mb-3">
                <label class="form-label" for="otp_identifier">Registered mobile or email</label>
                <input type="text" class="form-control<?= isset($errors['identifier']) ? ' is-invalid' : '' ?>"
                       id="otp_identifier" name="identifier" value="<?= View::e($identifier) ?>"
                       autocomplete="username" required autofocus
                       placeholder="9876543210 or you@bank.com">
                <?php if (isset($errors['identifier'])): ?>
                    <div class="invalid-feedback"><?= View::e($errors['identifier']) ?></div>
                <?php endif; ?>
                <div class="form-text">
                    We will send a one-time password by
                    <?= $smsAvailable ? 'SMS' : '' ?><?= $smsAvailable && $emailAvailable ? ' or ' : '' ?><?= $emailAvailable ? 'email' : '' ?><?= (!$smsAvailable && !$emailAvailable) ? 'SMS or email (not configured yet)' : '' ?>.
                </div>
            </div>

            <button class="btn btn-success w-100 py-2" type="submit">
                <i class="bi bi-send me-1"></i>Send OTP
            </button>
        </form>

    <?php else: ?>

        <form method="post" action="<?= View::e(View::url('login/otp')) ?>" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="identifier" value="<?= View::e($identifier) ?>">

            <div class="alert alert-info small py-2">
                <i class="bi bi-info-circle me-1"></i>
                OTP sent to <strong><?= View::e($maskedIdentifier ?? $identifier) ?></strong>.
            </div>

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

            <button class="btn btn-success w-100 py-2 mb-2" type="submit">
                <i class="bi bi-check2-circle me-1"></i>Verify &amp; sign in
            </button>
        </form>

        <form method="post" action="<?= View::e(View::url('login/otp/request')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="identifier" value="<?= View::e($identifier) ?>">
            <button class="btn btn-link w-100 btn-sm" type="submit">Resend OTP</button>
        </form>

    <?php endif; ?>

<?php endif; ?>

<hr class="my-4">

<div class="d-flex justify-content-between small">
    <a href="<?= View::e(View::url('forgot-password')) ?>" class="text-decoration-none">Forgot password?</a>
    <a href="<?= View::e(View::url('register')) ?>" class="text-decoration-none">Have an invitation code?</a>
</div>

<p class="text-muted small text-center mt-3 mb-0">
    Open sign-up is disabled. New accounts need an invitation code issued by an administrator.
</p>
