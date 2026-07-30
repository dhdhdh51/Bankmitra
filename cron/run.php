<?php

// =====================================================================
// LRMS scheduled job runner - designed for a cPanel Cron Job.
//
// NOTE: line comments are used here on purpose. A cron expression contains
// the sequence  * /  which would close a /* ... */ block comment early.
// ---------------------------------------------------------------------
// cPanel setup (Cron Jobs -> Add New Cron Job)
// Recommended: every 15 minutes, calling the PHP CLI binary directly.
//
//   */15 * * * * /usr/local/bin/php /home/CPANELUSER/public_html/cron/run.php all >/dev/null 2>&1
//
// If your host does not allow CLI cron, use a URL cron instead. The key must
// match 'cron_key' in config/config.php:
//
//   */15 * * * * curl -s "https://YOUR-DOMAIN/cron/run.php?job=all&key=YOUR_CRON_KEY" >/dev/null
//
// The root .htaccess blocks /cron/ from the web by design. To use the URL
// form, remove 'cron' from the RedirectMatch line in .htaccess - the
// cron_key check below is then the only protection, so use a long key.
// ---------------------------------------------------------------------
// Jobs
//   reminders  - send SMS/push reminders for follow-ups due today
//   risk       - recompute the recovery-probability score for every open loan
//   cleanup    - purge expired OTPs, old GPS pings and old audit rows
//   attendance - auto close attendance rows left open overnight
//   backup     - write a full SQL backup into storage/backups
//   all        - every job above, in that order
// =====================================================================

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\CronService;

$isCli = PHP_SAPI === 'cli';

// ---------------------------------------------------------------------
// Authorisation
// ---------------------------------------------------------------------
if ($isCli) {
    $job = $argv[1] ?? 'all';
} else {
    $providedKey = (string) ($_GET['key'] ?? '');
    $expectedKey = (string) Config::get('cron_key', '');

    if ($expectedKey === '' || $expectedKey === 'CHANGE_ME_CRON_KEY') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Cron key is not configured. Set 'cron_key' in config/config.php.\n");
    }

    if (!hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Forbidden.\n");
    }

    $job = (string) ($_GET['job'] ?? 'all');
    header('Content-Type: text/plain; charset=utf-8');
}

$allowed = ['reminders', 'risk', 'cleanup', 'attendance', 'backup', 'all'];
if (!in_array($job, $allowed, true)) {
    echo 'Unknown job "' . $job . '". Allowed: ' . implode(', ', $allowed) . "\n";
    exit(1);
}

// Cron jobs can legitimately take a while on shared hosting.
@set_time_limit(900);
@ini_set('memory_limit', '256M');

$runId = Database::insert('cron_runs', ['job' => $job, 'status' => 'running']);
$started = microtime(true);
$output = [];
$processed = 0;
$status = 'success';

$service = new CronService();

try {
    $jobs = $job === 'all'
        ? ['reminders', 'risk', 'attendance', 'cleanup', 'backup']
        : [$job];

    foreach ($jobs as $name) {
        $jobStart = microtime(true);

        $result = match ($name) {
            'reminders'  => $service->sendReminders(),
            'risk'       => $service->recomputeRiskScores(),
            'cleanup'    => $service->cleanup(),
            'attendance' => $service->closeOpenAttendance(),
            'backup'     => $service->backup(),
            default      => ['processed' => 0, 'message' => 'skipped'],
        };

        $processed += (int) $result['processed'];
        $line = sprintf(
            '[%s] %s: %s (%.2fs)',
            date('H:i:s'),
            $name,
            $result['message'],
            microtime(true) - $jobStart
        );
        $output[] = $line;
        echo $line . "\n";
    }
} catch (\Throwable $e) {
    $status = 'failed';
    $line = 'ERROR: ' . $e->getMessage();
    $output[] = $line;
    echo $line . "\n";
    Lib\Logger::error('Cron job failed: ' . $e->getMessage(), ['job' => $job]);
}

Database::update('cron_runs', [
    'finished_at' => date('Y-m-d H:i:s'),
    'status'      => $status,
    'processed'   => $processed,
    'output'      => substr(implode("\n", $output), 0, 60000),
], ['id' => $runId]);

printf("Done in %.2fs. Processed %d item(s). Status: %s\n", microtime(true) - $started, $processed, $status);

exit($status === 'success' ? 0 : 1);
