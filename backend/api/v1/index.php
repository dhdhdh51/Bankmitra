<?php

/**
 * LRMS REST API v1 - front controller.
 *
 * Versioned on purpose: a future /api/v2/ can change shapes freely while
 * already-installed copies of the Android app keep talking to /api/v1/.
 *
 * See docs/API.md for the full contract.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Controllers\Api\AttendanceApiController;
use App\Controllers\Api\AuthApiController;
use App\Controllers\Api\CustomerApiController;
use App\Controllers\Api\DashboardApiController;
use App\Controllers\Api\FollowUpApiController;
use App\Controllers\Api\MeApiController;
use App\Controllers\Api\NotificationApiController;
use App\Controllers\Api\PingApiController;
use App\Controllers\Api\RecoveryApiController;
use App\Controllers\Api\SyncApiController;
use App\Controllers\Api\TrackingApiController;
use App\Controllers\Api\VisitApiController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

// The API is stateless; no session cookie is issued.
header('X-Api-Version: 1');
header('Vary: Authorization');

$request = new Request();

// Pre-flight for browser-based testing tools.
if ($request->method() === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, X-Auth-Token, X-Device-Id, X-App-Version, Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

$router = new Router();

// ---------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------
$router->get('ping', [PingApiController::class, 'ping']);
$router->get('', [PingApiController::class, 'ping']);

$router->post('auth/invite/validate', [AuthApiController::class, 'validateInvite']);
$router->post('auth/otp/request', [AuthApiController::class, 'requestOtp']);
$router->post('auth/otp/verify', [AuthApiController::class, 'verifyOtp']);
$router->post('auth/login', [AuthApiController::class, 'login']);
$router->post('auth/register', [AuthApiController::class, 'register']);
$router->post('auth/password/reset', [AuthApiController::class, 'resetPassword']);

// ---------------------------------------------------------------------
// Authenticated (Bearer token)
// ---------------------------------------------------------------------
$router->post('auth/logout', [AuthApiController::class, 'logout']);

$router->get('me', [MeApiController::class, 'show']);
$router->post('me/fcm-token', [MeApiController::class, 'saveFcmToken']);
$router->post('me/password', [MeApiController::class, 'changePassword']);

$router->get('dashboard', [DashboardApiController::class, 'index']);

$router->get('customers', [CustomerApiController::class, 'index']);
$router->get('loans/{id:\d+}', [CustomerApiController::class, 'show']);

$router->get('visits', [VisitApiController::class, 'index']);
$router->post('visits', [VisitApiController::class, 'store']);

$router->get('recoveries', [RecoveryApiController::class, 'index']);
$router->post('recoveries', [RecoveryApiController::class, 'store']);

$router->get('attendance/today', [AttendanceApiController::class, 'today']);
$router->post('attendance/check-in', [AttendanceApiController::class, 'checkIn']);
$router->post('attendance/check-out', [AttendanceApiController::class, 'checkOut']);

$router->post('tracking/ping', [TrackingApiController::class, 'store']);

$router->get('followups', [FollowUpApiController::class, 'index']);
$router->post('followups/{id:\d+}/complete', [FollowUpApiController::class, 'complete']);

$router->get('notifications', [NotificationApiController::class, 'index']);
$router->post('notifications/{id:\d+}/read', [NotificationApiController::class, 'markRead']);

$router->get('sync/bootstrap', [SyncApiController::class, 'bootstrap']);

// ---------------------------------------------------------------------
// Unknown route
// ---------------------------------------------------------------------
$router->fallback(static function (Request $request): void {
    Response::fail(
        'Unknown endpoint: ' . $request->method() . ' /api/v1/' . $request->path()
            . '. See docs/API.md for the list of available endpoints.',
        'not_found',
        404
    );
});

$router->dispatch($request);
