<?php

/**
 * LRMS Admin Panel - front controller.
 *
 * Upload the whole `backend/` folder contents into your cPanel document root
 * (public_html, or public_html/lrms for a subfolder install). The .htaccess
 * next to this file rewrites every request here.
 */

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Controllers\AttendanceController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\BranchController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\InviteController;
use App\Controllers\LoanController;
use App\Controllers\RecoveryController;
use App\Controllers\ReportController;
use App\Controllers\SettingsController;
use App\Controllers\TrackingController;
use App\Controllers\UploadController;
use App\Controllers\UserController;
use App\Controllers\VerifyController;
use App\Controllers\VisitController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

$request = new Request();
$router = new Router();

// ---------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------
$router->get('', [DashboardController::class, 'index']);
$router->any('login', [AuthController::class, 'login']);
$router->post('login/otp/request', [AuthController::class, 'requestOtp']);
$router->any('login/otp', [AuthController::class, 'loginWithOtp']);
$router->any('register', [AuthController::class, 'register']);
$router->any('forgot-password', [AuthController::class, 'forgotPassword']);
$router->get('logout', [AuthController::class, 'logout']);
$router->post('logout', [AuthController::class, 'logout']);

// Public QR verification landing page for printed reports/receipts.
$router->get('verify/{uid}', [VerifyController::class, 'show']);

// Health probe for uptime monitors (no secrets exposed).
$router->get('health', static function (): void {
    Response::json([
        'success' => true,
        'message' => 'LRMS is running.',
        'data' => ['time' => date('c'), 'php' => PHP_VERSION],
    ]);
});

// ---------------------------------------------------------------------
// Authenticated
// ---------------------------------------------------------------------
$router->get('dashboard', [DashboardController::class, 'index']);
$router->get('dashboard/charts', [DashboardController::class, 'charts']);
$router->any('password/change', [AuthController::class, 'changePassword']);

// Users & access
$router->get('users', [UserController::class, 'index']);
$router->any('users/create', [UserController::class, 'create']);
$router->any('users/{id:\d+}/edit', [UserController::class, 'edit']);
$router->post('users/{id:\d+}/approve', [UserController::class, 'approve']);
$router->post('users/{id:\d+}/status', [UserController::class, 'changeStatus']);
$router->post('users/{id:\d+}/reset-device', [UserController::class, 'resetDevice']);
$router->post('users/{id:\d+}/reset-password', [UserController::class, 'resetPassword']);

// Invitation codes
$router->get('invites', [InviteController::class, 'index']);
$router->any('invites/create', [InviteController::class, 'create']);
$router->post('invites/{id:\d+}/revoke', [InviteController::class, 'revoke']);

// Branches
$router->get('branches', [BranchController::class, 'index']);
$router->any('branches/create', [BranchController::class, 'create']);
$router->any('branches/{id:\d+}/edit', [BranchController::class, 'edit']);

// Customers & loans
$router->get('customers', [CustomerController::class, 'index']);
$router->get('customers/{id:\d+}', [CustomerController::class, 'show']);
$router->get('loans', [LoanController::class, 'index']);
$router->get('loans/{id:\d+}', [LoanController::class, 'show']);
$router->post('loans/{id:\d+}/allocate', [LoanController::class, 'allocate']);
$router->get('loans/{id:\d+}/statement', [LoanController::class, 'statement']);

// Excel upload + allocation engine
$router->get('uploads', [UploadController::class, 'index']);
$router->any('uploads/import', [UploadController::class, 'import']);
$router->get('uploads/template', [UploadController::class, 'template']);
$router->get('uploads/{id:\d+}/errors', [UploadController::class, 'errors']);
$router->any('uploads/allocate', [UploadController::class, 'allocate']);

// Visits
$router->get('visits', [VisitController::class, 'index']);
$router->get('visits/{id:\d+}', [VisitController::class, 'show']);
$router->get('visits/{id:\d+}/pdf', [VisitController::class, 'pdf']);
$router->post('visits/{id:\d+}/verify', [VisitController::class, 'verify']);

// Recoveries
$router->get('recoveries', [RecoveryController::class, 'index']);
$router->post('recoveries/{id:\d+}/verify', [RecoveryController::class, 'verify']);
$router->post('recoveries/{id:\d+}/reject', [RecoveryController::class, 'reject']);
$router->get('recoveries/{id:\d+}/receipt', [RecoveryController::class, 'receipt']);

// Attendance
$router->get('attendance', [AttendanceController::class, 'index']);

// Live tracking
$router->get('tracking', [TrackingController::class, 'index']);
$router->get('tracking/data', [TrackingController::class, 'data']);

// Reports
$router->get('reports', [ReportController::class, 'index']);
$router->get('reports/{type}', [ReportController::class, 'show']);
$router->get('reports/{type}/export', [ReportController::class, 'export']);

// Settings / integrations
$router->get('settings', [SettingsController::class, 'index']);
$router->any('settings/{group}', [SettingsController::class, 'group']);
$router->post('settings/test/smtp', [SettingsController::class, 'testSmtp']);
$router->post('settings/test/sms', [SettingsController::class, 'testSms']);

// Audit log
$router->get('audit', [AuditController::class, 'index']);

// ---------------------------------------------------------------------
// 404
// ---------------------------------------------------------------------
$router->fallback(static function (Request $request): void {
    Session::start();
    Response::html(View::render('errors/404', ['path' => $request->path()]), 404);
});

$router->dispatch($request);
