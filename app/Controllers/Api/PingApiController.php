<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Response;
use Lib\Settings;

/**
 * GET /api/v1/ping
 *
 * The very first call the app makes. Deliberately public and exempt from
 * maintenance mode, because it is what tells the app that maintenance mode
 * is on in the first place.
 */
final class PingApiController extends ApiController
{
    protected bool $requiresAuth = false;
    protected bool $allowDuringMaintenance = true;

    public function ping(): void
    {
        Response::ok([
            'app_name'            => Settings::getString('company.app_name', 'LRMS'),
            'organisation'        => Settings::getString('company.organisation', ''),
            'server_time'         => date('c'),
            'latest_app_version'  => Settings::getString('app.app_version', '1.0.0'),
            'min_app_version'     => Settings::getString('app.min_app_version', '1.0.0'),
            'force_update'        => Settings::getBool('app.force_update', false),
            'maintenance_mode'    => Settings::getBool('app.maintenance_mode', false),
            'maintenance_message' => Settings::getString('app.maintenance_message', ''),
            'apk_url'             => Settings::getString('app.apk_url', ''),
            'otp_length'          => Settings::getInt('security.otp_length', 6),
            'otp_expiry_minutes'  => Settings::getInt('security.otp_expiry_minutes', 10),
            'otp_resend_seconds'  => Settings::getInt('security.otp_resend_seconds', 60),
            'sms_available'       => \Lib\SmsGateway::isConfigured(),
            'email_available'     => \Lib\Mailer::isConfigured(),
        ]);
    }
}
