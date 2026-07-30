<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\AuthService;
use App\Services\OtpService;
use Lib\Mailer;
use Lib\SmsGateway;

/**
 * Web sign-in, invitation registration, password reset and change.
 */
final class AuthController extends Controller
{
    protected bool $requiresAuth = false;

    private AuthService $service;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->service = new AuthService();
    }

    // ------------------------------------------------------------------
    // Sign in
    // ------------------------------------------------------------------
    public function login(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
            return;
        }

        $mode = $this->request->str('mode') === 'otp' ? 'otp' : 'password';

        if (!$this->request->isPost()) {
            $this->renderLogin($mode);
            return;
        }

        $this->verifyCsrf();

        $validator = new Validator($this->request->all());
        $validator->required('identifier')->required('password');
        if ($validator->fails()) {
            Session::flashInput($this->request->all());
            $this->renderLogin('password', $validator->errors());
            return;
        }

        $result = $this->service->attemptPassword(
            $this->request->str('identifier'),
            (string) $this->request->input('password', ''),
            $this->request->ip(),
            'web'
        );

        if (!$result['ok'] || $result['user'] === null) {
            Session::flashInput(['identifier' => $this->request->str('identifier')]);
            Session::flash('danger', $result['message']);
            $this->renderLogin('password');
            return;
        }

        $this->completeLogin($result['user']);
    }

    /** POST login/otp/request */
    public function requestOtp(): void
    {
        $this->verifyCsrf();

        $identifier = $this->request->str('identifier');
        $type = str_contains($identifier, '@') ? 'email' : 'mobile';

        $validator = new Validator($this->request->all());
        $validator->required('identifier');
        if ($type === 'mobile') {
            $validator->mobile('identifier');
        } else {
            $validator->email('identifier');
        }
        if ($validator->fails()) {
            Session::flashInput(['identifier' => $identifier]);
            $this->renderLogin('otp', $validator->errors());
            return;
        }

        $user = $this->service->findByIdentifier($identifier);
        if ($user === null) {
            // Do not reveal whether the account exists, but do not send anything.
            Session::flash('info', 'If this ' . ($type === 'mobile' ? 'number' : 'address')
                . ' is registered, an OTP has been sent to it.');
            $this->renderLogin('otp', [], true, $identifier, $identifier);
            return;
        }

        $status = $this->service->checkStatus($user);
        if (!$status['ok']) {
            Session::flash('warning', $status['message']);
            $this->renderLogin('otp');
            return;
        }

        $result = (new OtpService())->issue($identifier, $type, 'login', (int) $user['id'], $this->request->ip());

        if (!$result['ok']) {
            Session::flash('danger', $result['message']);
            Session::flashInput(['identifier' => $identifier]);
            $this->renderLogin('otp');
            return;
        }

        Session::set('_otp_identifier', $identifier);
        Session::flash('success', $result['message']);
        $this->renderLogin('otp', [], true, $identifier, $result['masked']);
    }

    /** POST login/otp */
    public function loginWithOtp(): void
    {
        if (!$this->request->isPost()) {
            $this->renderLogin('otp');
            return;
        }

        $this->verifyCsrf();

        $identifier = $this->request->str('identifier');
        if ($identifier === '') {
            $identifier = (string) Session::get('_otp_identifier', '');
        }
        $type = str_contains($identifier, '@') ? 'email' : 'mobile';

        $validator = new Validator($this->request->all());
        $validator->required('otp');
        if ($validator->fails()) {
            $this->renderLogin('otp', $validator->errors(), true, $identifier, $identifier);
            return;
        }

        $verification = (new OtpService())->verify($identifier, $type, 'login', $this->request->str('otp'));
        if (!$verification['ok']) {
            $this->renderLogin('otp', ['otp' => $verification['message']], true, $identifier, $identifier);
            return;
        }

        $user = $this->service->findByIdentifier($identifier);
        if ($user === null) {
            Session::flash('danger', 'Account not found.');
            $this->renderLogin('otp');
            return;
        }

        $status = $this->service->checkStatus($user);
        if (!$status['ok']) {
            Session::flash('warning', $status['message']);
            $this->renderLogin('otp');
            return;
        }

        Session::forget('_otp_identifier');
        $this->completeLogin($user);
    }

    /** @param array<string,mixed> $user */
    private function completeLogin(array $user): void
    {
        Auth::loginSession((int) $user['id']);
        Audit::log('login.success', 'user', (int) $user['id'], 'Signed in to the admin panel');

        $intended = Session::get('_intended');
        Session::forget('_intended');

        Session::flash('success', 'Welcome back, ' . $user['full_name'] . '.');

        if (is_string($intended) && $intended !== '' && !str_starts_with($intended, 'login')) {
            $this->redirect($intended);
            return;
        }
        $this->redirect('dashboard');
    }

    /** @param array<string,string> $errors */
    private function renderLogin(
        string $mode,
        array $errors = [],
        bool $otpSent = false,
        string $identifier = '',
        string $masked = ''
    ): void {
        $this->view('auth/login', [
            'pageTitle'        => 'Sign in',
            'mode'             => $mode,
            'errors'           => $errors,
            'otpSent'          => $otpSent,
            'identifier'       => $identifier !== '' ? $identifier : Session::oldInput('identifier'),
            'maskedIdentifier' => $masked,
            'smsAvailable'     => SmsGateway::isConfigured(),
            'emailAvailable'   => Mailer::isConfigured(),
        ], 'auth');
    }

    // ------------------------------------------------------------------
    // Sign out
    // ------------------------------------------------------------------
    public function logout(): void
    {
        if ($this->request->isPost()) {
            Csrf::verify($this->request);
        }

        if (Auth::check()) {
            Audit::log('logout', 'user', Auth::id(), 'Signed out of the admin panel');
        }

        Auth::logout();
        Session::start();
        Session::flash('info', 'You have been signed out.');
        Response::redirect(View::url('login'));
    }

    // ------------------------------------------------------------------
    // Registration by invitation
    // ------------------------------------------------------------------
    public function register(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
            return;
        }

        if (!$this->request->isPost()) {
            $this->view('auth/register', ['pageTitle' => 'Register', 'step' => 'code'], 'auth');
            return;
        }

        $this->verifyCsrf();
        $step = $this->request->str('step', 'code');

        // ---- step 1: validate the invitation code ----------------------
        if ($step === 'code') {
            $result = $this->service->validateInvite($this->request->str('invite_code'));
            if (!$result['ok'] || $result['invite'] === null) {
                Session::flashInput($this->request->all());
                $this->view('auth/register', [
                    'pageTitle' => 'Register',
                    'step' => 'code',
                    'errors' => ['invite_code' => $result['message']],
                ], 'auth');
                return;
            }

            Session::set('_invite_code', $result['invite']['code']);
            $this->view('auth/register', [
                'pageTitle' => 'Register',
                'step' => 'profile',
                'invite' => $result['invite'],
            ], 'auth');
            return;
        }

        // ---- step 2: collect the profile and send an OTP ----------------
        if ($step === 'profile') {
            $inviteResult = $this->service->validateInvite($this->request->str('invite_code'));
            if (!$inviteResult['ok'] || $inviteResult['invite'] === null) {
                Session::flash('danger', $inviteResult['message']);
                $this->view('auth/register', ['pageTitle' => 'Register', 'step' => 'code'], 'auth');
                return;
            }

            // Email is the identity and the OTP channel. Mobile is optional and
            // only used later for SMS reminders.
            $validator = new Validator($this->request->all());
            $validator->required('full_name')->maxLen('full_name', 150)
                ->required('email')->email('email')
                ->required('password')->strongPassword('password')
                ->required('password_confirm')->matches('password_confirm', 'password', 'The passwords do not match.')
                ->maxLen('employee_code', 40);
            if ($this->request->str('mobile') !== '') {
                $validator->mobile('mobile');
            }

            if ($validator->fails()) {
                Session::flashInput($this->request->all());
                $this->view('auth/register', [
                    'pageTitle' => 'Register',
                    'step' => 'profile',
                    'invite' => $inviteResult['invite'],
                    'errors' => $validator->errors(),
                ], 'auth');
                return;
            }

            // Reject an identifier that is already in use before sending an OTP.
            if ($this->service->findByIdentifier($this->request->str('email')) !== null) {
                Session::flashInput($this->request->all());
                $this->view('auth/register', [
                    'pageTitle' => 'Register',
                    'step' => 'profile',
                    'invite' => $inviteResult['invite'],
                    'errors' => ['email' => 'This email address is already registered. Try signing in instead.'],
                ], 'auth');
                return;
            }

            $typedMobile = $this->request->str('mobile');
            if ($typedMobile !== '' && $this->service->findByIdentifier($typedMobile) !== null) {
                Session::flashInput($this->request->all());
                $this->view('auth/register', [
                    'pageTitle' => 'Register',
                    'step' => 'profile',
                    'invite' => $inviteResult['invite'],
                    'errors' => ['mobile' => 'This mobile number is already registered. Try signing in instead.'],
                ], 'auth');
                return;
            }

            $otp = (new OtpService())->issue(
                $this->request->str('email'),
                'email',
                'register',
                null,
                $this->request->ip()
            );

            if (!$otp['ok']) {
                Session::flashInput($this->request->all());
                Session::flash('danger', $otp['message']);
                $this->view('auth/register', [
                    'pageTitle' => 'Register',
                    'step' => 'profile',
                    'invite' => $inviteResult['invite'],
                ], 'auth');
                return;
            }

            // Park the pending registration in the session until the OTP is verified.
            Session::set('_pending_registration', [
                'invite_code'   => $inviteResult['invite']['code'],
                'full_name'     => $this->request->str('full_name'),
                'mobile'        => $this->request->str('mobile'),
                'email'         => $this->request->str('email'),
                'employee_code' => $this->request->str('employee_code'),
                'password'      => (string) $this->request->input('password', ''),
            ]);

            $this->view('auth/register', [
                'pageTitle' => 'Register',
                'step' => 'otp',
                'maskedIdentifier' => $otp['masked'],
            ], 'auth');
            return;
        }

        // ---- step 3: verify the OTP and create the account ---------------
        $pending = Session::get('_pending_registration');
        if (!is_array($pending)) {
            Session::flash('warning', 'Your registration session expired. Please start again.');
            $this->view('auth/register', ['pageTitle' => 'Register', 'step' => 'code'], 'auth');
            return;
        }

        $validator = new Validator($this->request->all());
        $validator->required('otp');
        if ($validator->fails()) {
            $this->view('auth/register', [
                'pageTitle' => 'Register',
                'step' => 'otp',
                'errors' => $validator->errors(),
            ], 'auth');
            return;
        }

        $verification = (new OtpService())->verify(
            (string) $pending['email'],
            'email',
            'register',
            $this->request->str('otp')
        );
        if (!$verification['ok']) {
            $this->view('auth/register', [
                'pageTitle' => 'Register',
                'step' => 'otp',
                'errors' => ['otp' => $verification['message']],
            ], 'auth');
            return;
        }

        $inviteResult = $this->service->validateInvite((string) $pending['invite_code']);
        if (!$inviteResult['ok'] || $inviteResult['invite'] === null) {
            Session::flash('danger', $inviteResult['message']);
            $this->view('auth/register', ['pageTitle' => 'Register', 'step' => 'code'], 'auth');
            return;
        }

        $result = $this->service->registerWithInvite($inviteResult['invite'], [
            'full_name'     => (string) $pending['full_name'],
            'mobile'        => ((string) $pending['mobile']) !== '' ? (string) $pending['mobile'] : null,
            'email'         => (string) $pending['email'],
            'password'      => (string) $pending['password'],
            'employee_code' => ((string) $pending['employee_code']) !== '' ? (string) $pending['employee_code'] : null,
            'bc_code'       => ((string) $pending['employee_code']) !== '' ? (string) $pending['employee_code'] : null,
        ], $this->request->ip());

        Session::forget('_pending_registration');
        Session::forget('_invite_code');

        if (!$result['ok'] || $result['user_id'] === null) {
            Session::flash('danger', $result['message']);
            $this->view('auth/register', ['pageTitle' => 'Register', 'step' => 'code'], 'auth');
            return;
        }

        if ($result['pending']) {
            Session::flash('info', $result['message']);
            Response::redirect(View::url('login'));
            return;
        }

        $user = Auth::fetchUser($result['user_id']);
        if ($user === null) {
            Session::flash('success', 'Registration complete. Please sign in.');
            Response::redirect(View::url('login'));
            return;
        }

        $this->completeLogin($user);
    }

    // ------------------------------------------------------------------
    // Forgot password
    // ------------------------------------------------------------------
    public function forgotPassword(): void
    {
        if (!$this->request->isPost()) {
            $this->view('auth/forgot', ['pageTitle' => 'Reset password', 'step' => 'request'], 'auth');
            return;
        }

        $this->verifyCsrf();
        $step = $this->request->str('step', 'request');
        $identifier = $this->request->str('identifier');
        $type = str_contains($identifier, '@') ? 'email' : 'mobile';

        if ($step === 'request') {
            $validator = new Validator($this->request->all());
            $validator->required('identifier');
            if ($type === 'mobile') {
                $validator->mobile('identifier');
            } else {
                $validator->email('identifier');
            }
            if ($validator->fails()) {
                Session::flashInput($this->request->all());
                $this->view('auth/forgot', [
                    'pageTitle' => 'Reset password',
                    'step' => 'request',
                    'errors' => $validator->errors(),
                ], 'auth');
                return;
            }

            $user = $this->service->findByIdentifier($identifier);
            if ($user === null) {
                // Same message either way, so the form cannot enumerate accounts.
                Session::flash('info', 'If this account exists, an OTP has been sent to it.');
                $this->view('auth/forgot', [
                    'pageTitle' => 'Reset password',
                    'step' => 'reset',
                    'identifier' => $identifier,
                    'maskedIdentifier' => $identifier,
                ], 'auth');
                return;
            }

            $otp = (new OtpService())->issue($identifier, $type, 'reset_password', (int) $user['id'], $this->request->ip());
            if (!$otp['ok']) {
                Session::flash('danger', $otp['message']);
                Session::flashInput(['identifier' => $identifier]);
                $this->view('auth/forgot', ['pageTitle' => 'Reset password', 'step' => 'request'], 'auth');
                return;
            }

            Session::flash('success', $otp['message']);
            $this->view('auth/forgot', [
                'pageTitle' => 'Reset password',
                'step' => 'reset',
                'identifier' => $identifier,
                'maskedIdentifier' => $otp['masked'],
            ], 'auth');
            return;
        }

        // ---- apply the reset ------------------------------------------
        $validator = new Validator($this->request->all());
        $validator->required('otp')
            ->required('new_password')->strongPassword('new_password')
            ->required('new_password_confirm')
            ->matches('new_password_confirm', 'new_password', 'The passwords do not match.');
        if ($validator->fails()) {
            $this->view('auth/forgot', [
                'pageTitle' => 'Reset password',
                'step' => 'reset',
                'identifier' => $identifier,
                'maskedIdentifier' => $identifier,
                'errors' => $validator->errors(),
            ], 'auth');
            return;
        }

        $verification = (new OtpService())->verify($identifier, $type, 'reset_password', $this->request->str('otp'));
        if (!$verification['ok']) {
            $this->view('auth/forgot', [
                'pageTitle' => 'Reset password',
                'step' => 'reset',
                'identifier' => $identifier,
                'maskedIdentifier' => $identifier,
                'errors' => ['otp' => $verification['message']],
            ], 'auth');
            return;
        }

        $user = $this->service->findByIdentifier($identifier);
        if ($user === null) {
            Session::flash('danger', 'Account not found.');
            $this->view('auth/forgot', ['pageTitle' => 'Reset password', 'step' => 'request'], 'auth');
            return;
        }

        Database::update('users', [
            'password_hash'        => password_hash((string) $this->request->input('new_password', ''), PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'failed_attempts'      => 0,
            'locked_until'         => null,
        ], ['id' => (int) $user['id']]);

        $this->service->revokeAllTokens((int) $user['id'], 'password reset');

        Auth::setUser($user);
        Audit::log('password.reset', 'user', (int) $user['id'], 'Password reset via OTP from the web panel',
            null, null, 'notice');
        Auth::setUser(null);

        Session::flash('success', 'Password updated. Please sign in with your new password.');
        Response::redirect(View::url('login'));
    }

    // ------------------------------------------------------------------
    // Change password (authenticated)
    // ------------------------------------------------------------------
    public function changePassword(): void
    {
        // This action needs a session even though the controller is public.
        if (!Auth::check()) {
            Session::flash('warning', 'Please sign in to continue.');
            Response::redirect(View::url('login'));
            return;
        }

        $user = Auth::user();
        $forced = (int) ($user['must_change_password'] ?? 0) === 1;

        if (!$this->request->isPost()) {
            $this->view('auth/change_password', [
                'pageTitle' => 'Change password',
                'forced' => $forced,
            ], $forced ? 'auth' : 'app');
            return;
        }

        $this->verifyCsrf();

        $validator = new Validator($this->request->all());
        $validator->required('current_password')
            ->required('new_password')->strongPassword('new_password')
            ->required('new_password_confirm')
            ->matches('new_password_confirm', 'new_password', 'The passwords do not match.');

        $errors = $validator->errors();

        $currentHash = (string) ($user['password_hash'] ?? '');
        if ($currentHash !== '' && !isset($errors['current_password'])) {
            if (!password_verify((string) $this->request->input('current_password', ''), $currentHash)) {
                $errors['current_password'] = 'Your current password is incorrect.';
            }
        }

        if ($errors !== []) {
            $this->view('auth/change_password', [
                'pageTitle' => 'Change password',
                'forced' => $forced,
                'errors' => $errors,
            ], $forced ? 'auth' : 'app');
            return;
        }

        Database::update('users', [
            'password_hash'        => password_hash((string) $this->request->input('new_password', ''), PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ], ['id' => (int) $user['id']]);

        $this->service->revokeAllTokens((int) $user['id'], 'password changed');

        Audit::log('password.changed', 'user', (int) $user['id'], 'Password changed from the admin panel',
            null, null, 'notice');

        $this->redirect('dashboard', 'success',
            'Password updated. Any mobile app sessions have been signed out.');
    }
}
