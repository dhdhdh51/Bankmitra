<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use Lib\Mailer;
use Lib\Settings;
use Lib\SmsGateway;

/**
 * Settings / Integrations - the central control panel.
 *
 * Everything the application needs at runtime (SMTP, SMS, Maps, Firebase, app
 * version/force-update/maintenance, invitation defaults, security policy) is
 * edited here and takes effect on the next request. Nothing is hardcoded and
 * nothing needs a redeploy.
 *
 * Sensitive values are AES-encrypted at rest and are NEVER sent back to the
 * browser - the form shows a "Configured" badge and an empty input, and an
 * empty submission means "leave unchanged".
 */
final class SettingsController extends Controller
{
    protected ?string $permission = 'settings.manage';

    /**
     * Group definitions drive both the index page and the edit forms, so a new
     * setting only has to be added here (and seeded in schema.sql).
     *
     * @var array<string,array{label:string,icon:string,description:string,fields:array<string,array{label:string,type:string,help?:string,options?:array<string,string>}>}>
     */
    private const GROUPS = [
        'company' => [
            'label' => 'Organisation',
            'icon' => 'building',
            'description' => 'App name, bank name and support contacts shown across the panel, the app and PDF reports.',
            'fields' => [
                'app_name'      => ['label' => 'Application name', 'type' => 'text'],
                'organisation'  => ['label' => 'Bank / organisation name', 'type' => 'text'],
                'logo_path'     => ['label' => 'Logo path', 'type' => 'text', 'help' => 'Relative to the installation root, e.g. assets/img/logo.svg'],
                'support_email' => ['label' => 'Support email', 'type' => 'email'],
                'support_phone' => ['label' => 'Support phone', 'type' => 'text'],
                'timezone'      => ['label' => 'Timezone', 'type' => 'text', 'help' => 'e.g. Asia/Kolkata'],
            ],
        ],
        'smtp' => [
            'label' => 'Email (SMTP)',
            'icon' => 'envelope-at',
            'description' => 'Used for email OTP. Sent with PHP sockets - no Composer library required.',
            'fields' => [
                'host'       => ['label' => 'SMTP host', 'type' => 'text', 'help' => 'e.g. smtp.gmail.com or your cPanel mail server'],
                'port'       => ['label' => 'Port', 'type' => 'number', 'help' => '587 for TLS, 465 for SSL, 25 for none'],
                'encryption' => ['label' => 'Encryption', 'type' => 'select', 'options' => ['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'None']],
                'username'   => ['label' => 'Username', 'type' => 'text'],
                'password'   => ['label' => 'Password', 'type' => 'password'],
                'from_email' => ['label' => 'From email', 'type' => 'email'],
                'from_name'  => ['label' => 'From name', 'type' => 'text'],
                'timeout'    => ['label' => 'Socket timeout (seconds)', 'type' => 'number'],
            ],
        ],
        'sms' => [
            'label' => 'SMS Gateway',
            'icon' => 'chat-dots',
            'description' => 'Used for mobile OTP and reminders. Works with any HTTP gateway via a URL template.',
            'fields' => [
                'provider'        => ['label' => 'Provider name', 'type' => 'text', 'help' => 'Free text, for your reference only'],
                'api_url'         => ['label' => 'API URL template', 'type' => 'textarea', 'help' => 'Placeholders: {api_key} {api_secret} {sender_id} {mobile} {mobile_91} {message} {message_raw} {dlt_template_id}'],
                'method'          => ['label' => 'HTTP method', 'type' => 'select', 'options' => ['GET' => 'GET', 'POST' => 'POST (form)', 'POST_JSON' => 'POST (JSON)']],
                'body_template'   => ['label' => 'POST body template', 'type' => 'textarea', 'help' => 'Only for POST / POST_JSON. Same placeholders as the URL.'],
                'api_key'         => ['label' => 'API key', 'type' => 'password'],
                'api_secret'      => ['label' => 'API secret', 'type' => 'password'],
                'sender_id'       => ['label' => 'Sender ID / header', 'type' => 'text'],
                'dlt_template_id' => ['label' => 'DLT template ID', 'type' => 'text', 'help' => 'Required by Indian telecom regulations'],
                'otp_template'    => ['label' => 'OTP message template', 'type' => 'textarea', 'help' => 'Placeholders: {otp} {minutes} {app_name}'],
            ],
        ],
        'maps' => [
            'label' => 'Google Maps',
            'icon' => 'map',
            'description' => 'The key is delivered to the Android app at runtime, so it is never compiled into the APK.',
            'fields' => [
                'api_key'      => ['label' => 'Maps API key', 'type' => 'password'],
                'default_lat'  => ['label' => 'Default latitude', 'type' => 'text'],
                'default_lng'  => ['label' => 'Default longitude', 'type' => 'text'],
                'default_zoom' => ['label' => 'Default zoom', 'type' => 'number'],
            ],
        ],
        'firebase' => [
            'label' => 'Firebase (Push)',
            'icon' => 'bell',
            'description' => 'Optional. Without it, notifications are still stored in-app; only push delivery is skipped.',
            'fields' => [
                'project_id'           => ['label' => 'Firebase project ID', 'type' => 'text'],
                'service_account_json' => ['label' => 'Service account JSON', 'type' => 'textarea', 'help' => 'Firebase Console > Project settings > Service accounts > Generate new private key. Paste the whole file.'],
                'server_key'           => ['label' => 'Legacy server key', 'type' => 'text', 'help' => 'Deprecated by Google - prefer the service account JSON above.'],
            ],
        ],
        'app' => [
            'label' => 'Mobile App',
            'icon' => 'phone',
            'description' => 'Version gating, maintenance mode and the field-work rules the app enforces.',
            'fields' => [
                'app_version'          => ['label' => 'Latest app version', 'type' => 'text'],
                'min_app_version'      => ['label' => 'Minimum allowed version', 'type' => 'text', 'help' => 'Older builds get a "please update" response instead of writing data.'],
                'force_update'         => ['label' => 'Force update', 'type' => 'bool'],
                'maintenance_mode'     => ['label' => 'Maintenance mode', 'type' => 'bool', 'help' => 'Blocks the API (except /ping) with a friendly message.'],
                'maintenance_message'  => ['label' => 'Maintenance message', 'type' => 'textarea'],
                'apk_url'              => ['label' => 'APK download URL', 'type' => 'text', 'help' => 'Your GitHub release URL, shown when a force update is required.'],
                'gps_ping_interval'    => ['label' => 'GPS ping interval (seconds)', 'type' => 'number'],
                'visit_photo_min'      => ['label' => 'Minimum photos per visit', 'type' => 'number'],
                'visit_max_distance_m' => ['label' => 'Max distance from customer (metres)', 'type' => 'number', 'help' => '0 disables the check.'],
            ],
        ],
        'invite' => [
            'label' => 'Invitations',
            'icon' => 'ticket-perforated',
            'description' => 'Defaults applied when generating new invitation codes.',
            'fields' => [
                'default_expiry_days' => ['label' => 'Default expiry (days)', 'type' => 'number'],
                'default_role_id'     => ['label' => 'Default role ID', 'type' => 'number', 'help' => '1=Super Admin, 2=Regional Office, 3=Branch Manager, 4=BC Agent'],
                'requires_approval'   => ['label' => 'New users need approval', 'type' => 'bool'],
                'code_length'         => ['label' => 'Code length', 'type' => 'number'],
            ],
        ],
        'security' => [
            'label' => 'Security',
            'icon' => 'shield-lock',
            'description' => 'OTP policy, lockout, session timeout, device binding and data retention.',
            'fields' => [
                'otp_length'             => ['label' => 'OTP length', 'type' => 'number', 'help' => '4 to 8 digits'],
                'otp_expiry_minutes'     => ['label' => 'OTP expiry (minutes)', 'type' => 'number'],
                'otp_resend_seconds'     => ['label' => 'OTP resend cooldown (seconds)', 'type' => 'number'],
                'max_login_attempts'     => ['label' => 'Max login attempts', 'type' => 'number'],
                'lockout_minutes'        => ['label' => 'Lockout duration (minutes)', 'type' => 'number'],
                'session_timeout_minutes' => ['label' => 'Web auto-logout (minutes)', 'type' => 'number'],
                'api_token_days'         => ['label' => 'App session validity (days)', 'type' => 'number'],
                'device_binding'         => ['label' => 'Enforce 1 account = 1 device', 'type' => 'bool'],
                'block_mock_gps'         => ['label' => 'Reject mock / fake GPS', 'type' => 'bool'],
                'audit_retention_days'   => ['label' => 'Audit log retention (days)', 'type' => 'number'],
                'gps_retention_days'     => ['label' => 'GPS ping retention (days)', 'type' => 'number'],
            ],
        ],
    ];

    public function index(): void
    {
        $summary = [];
        foreach (self::GROUPS as $key => $group) {
            $configured = 0;
            foreach (array_keys($group['fields']) as $field) {
                if (Settings::has($key . '.' . $field)) {
                    $configured++;
                }
            }
            $summary[$key] = [
                'label'       => $group['label'],
                'icon'        => $group['icon'],
                'description' => $group['description'],
                'configured'  => $configured,
                'total'       => count($group['fields']),
            ];
        }

        $this->view('settings/index', [
            'pageTitle'     => 'Settings & Integrations',
            'groups'        => $summary,
            'missingConfig' => Settings::missingConfiguration(),
        ]);
    }

    public function group(array $args): void
    {
        $key = (string) ($args['group'] ?? '');

        if (!isset(self::GROUPS[$key])) {
            $this->redirect('settings', 'warning', 'Unknown settings group: ' . $key);
            return;
        }

        $group = self::GROUPS[$key];

        if ($this->request->isPost()) {
            $this->save($key, $group);
            return;
        }

        // Build the field list with the current (non-sensitive) values.
        $fields = [];
        foreach ($group['fields'] as $name => $definition) {
            $path = $key . '.' . $name;
            $isSensitive = in_array($definition['type'], ['password'], true)
                || $name === 'service_account_json';

            $fields[$name] = $definition + [
                'name'         => $name,
                'is_sensitive' => $isSensitive,
                'is_configured' => Settings::has($path),
                'value'        => $isSensitive ? '' : (string) Settings::get($path, ''),
            ];
        }

        $this->view('settings/group', [
            'pageTitle'   => $group['label'] . ' settings',
            'groupKey'    => $key,
            'group'       => $group,
            'fields'      => $fields,
            'allGroups'   => self::GROUPS,
            'smtpReady'   => Mailer::isConfigured(),
            'smsReady'    => SmsGateway::isConfigured(),
        ]);
    }

    /**
     * @param array{label:string,icon:string,description:string,fields:array<string,array<string,mixed>>} $group
     */
    private function save(string $key, array $group): void
    {
        $this->verifyCsrf();

        $changed = [];
        $skipped = [];

        foreach ($group['fields'] as $name => $definition) {
            $path = $key . '.' . $name;
            $type = (string) $definition['type'];

            if ($type === 'bool') {
                $value = $this->request->bool($name) ? '1' : '0';
                $previous = Settings::getBool($path) ? '1' : '0';
                if ($value !== $previous) {
                    Settings::set($path, $value, Auth::id());
                    $changed[] = $name;
                }
                continue;
            }

            $submitted = (string) $this->request->input($name, '');
            $isSensitive = $type === 'password' || $name === 'service_account_json';

            // An empty submission for a sensitive field means "keep the
            // existing secret", not "erase it". Clearing is explicit.
            if ($isSensitive && $submitted === '') {
                if ($this->request->bool('__clear_' . $name)) {
                    Settings::set($path, '', Auth::id());
                    $changed[] = $name . ' (cleared)';
                } else {
                    $skipped[] = $name;
                }
                continue;
            }

            $previous = (string) Settings::get($path, '');
            if ($submitted === $previous) {
                continue;
            }

            // Light validation so a typo cannot break OTP delivery silently.
            $error = $this->validateField($key, $name, $type, $submitted);
            if ($error !== null) {
                $this->redirect('settings/' . $key, 'danger', $error);
                return;
            }

            Settings::set($path, $submitted, Auth::id());
            $changed[] = $name;
        }

        Settings::flush();

        // Never write the values themselves to the audit log - only the names.
        Audit::log(
            'settings.update',
            'settings',
            $key,
            'Updated ' . $group['label'] . ' settings: ' . (($changed === []) ? 'no changes' : implode(', ', $changed)),
            null,
            null,
            'notice'
        );

        if ($changed === []) {
            Session::flash('info', 'No changes were made.');
        } else {
            Session::flash('success', count($changed) . ' setting(s) saved. They take effect immediately.');
        }
        if ($skipped !== []) {
            Session::flash('info', 'Left unchanged (blank means keep the existing secret): '
                . implode(', ', $skipped) . '.');
        }

        $this->redirect('settings/' . $key);
    }

    private function validateField(string $group, string $name, string $type, string $value): ?string
    {
        if ($type === 'number' && $value !== '' && !is_numeric($value)) {
            return ucfirst($name) . ' must be a number.';
        }
        if ($type === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return ucfirst($name) . ' must be a valid email address.';
        }
        if ($group === 'smtp' && $name === 'encryption' && !in_array($value, ['tls', 'ssl', 'none'], true)) {
            return 'SMTP encryption must be tls, ssl or none.';
        }
        if ($group === 'sms' && $name === 'method' && !in_array($value, ['GET', 'POST', 'POST_JSON'], true)) {
            return 'SMS method must be GET, POST or POST_JSON.';
        }
        if ($group === 'firebase' && $name === 'service_account_json' && $value !== '') {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || !isset($decoded['client_email'], $decoded['private_key'])) {
                return 'The Firebase service account JSON is not valid. Paste the complete file, '
                    . 'including client_email and private_key.';
            }
        }
        if ($group === 'security' && $name === 'otp_length' && $value !== ''
            && ((int) $value < 4 || (int) $value > 8)) {
            return 'OTP length must be between 4 and 8.';
        }
        if ($group === 'app' && in_array($name, ['app_version', 'min_app_version'], true) && $value !== ''
            && preg_match('/^\d+(\.\d+){0,3}$/', $value) !== 1) {
            return ucfirst(str_replace('_', ' ', $name)) . ' must look like 1.0.0.';
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Connection tests
    // ------------------------------------------------------------------

    /** POST settings/test/smtp */
    public function testSmtp(): void
    {
        $this->verifyCsrf();

        $mailer = new Mailer();
        $recipient = $this->request->str('to');

        if ($recipient !== '') {
            $result = $mailer->send(
                $recipient,
                'LRMS test email',
                '<p>This is a test email from your LRMS installation.</p>'
                . '<p>If you received it, email OTP delivery will work.</p>'
            );
            Audit::log('settings.test_smtp', 'settings', 'smtp',
                $result['sent'] ? 'Test email sent' : 'Test email failed: ' . $result['error']);

            Response::json([
                'success' => $result['sent'],
                'message' => $result['sent']
                    ? 'Test email sent to ' . $recipient . '. Check the inbox (and the spam folder).'
                    : $result['error'],
            ], $result['sent'] ? 200 : 502);
            return;
        }

        $result = $mailer->testConnection();
        Audit::log('settings.test_smtp', 'settings', 'smtp',
            $result['ok'] ? 'SMTP connection OK' : 'SMTP connection failed: ' . $result['message']);

        Response::json([
            'success' => $result['ok'],
            'message' => $result['message'],
            // The transcript never contains the password - Mailer redacts it.
            'data' => ['transcript' => $result['transcript']],
        ], $result['ok'] ? 200 : 502);
    }

    /** POST settings/test/sms */
    public function testSms(): void
    {
        $this->verifyCsrf();

        $mobile = $this->request->str('to');
        if ($mobile === '') {
            Response::json([
                'success' => false,
                'message' => 'Enter a mobile number to send the test SMS to.',
            ], 422);
            return;
        }

        $gateway = new SmsGateway();
        $result = $gateway->send(
            $mobile,
            'LRMS test message. If you received this, OTP delivery will work.',
            'test'
        );

        Audit::log('settings.test_sms', 'settings', 'sms',
            $result['sent'] ? 'Test SMS sent' : 'Test SMS failed: ' . $result['error']);

        Response::json([
            'success' => $result['sent'],
            'message' => $result['sent']
                ? 'Test SMS submitted to the gateway. Check the handset.'
                : $result['error'],
            'data' => ['gateway_response' => substr($gateway->lastResponse(), 0, 300)],
        ], $result['sent'] ? 200 : 502);
    }
}
