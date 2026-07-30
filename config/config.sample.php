<?php
/**
 * LRMS - environment configuration
 * ---------------------------------------------------------------------
 * 1. Copy this file to  config/config.php
 * 2. Fill in the database credentials from cPanel > MySQL Databases
 * 3. Generate APP_KEY once with:
 *        php -r "echo bin2hex(random_bytes(32));"
 *    and paste the 64-character result below.
 *
 * NOTHING ELSE belongs in this file. SMTP / SMS / Maps / Firebase keys
 * are configured from the Admin Panel (Settings > Integrations) and are
 * stored AES-encrypted in the `settings` table.
 * ---------------------------------------------------------------------
 * config.php is git-ignored on purpose - never commit real credentials.
 */

return [

    // ---------------- Database (cPanel > MySQL Databases) ------------
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'cpaneluser_lrms',
        'user'    => 'cpaneluser_lrms',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',

        // Leave empty for normal cPanel hosts. A handful of hosts expose MySQL
        // only through a unix socket - if you get "connection refused" with
        // host=localhost, ask support for the socket path and put it here,
        // e.g. '/var/lib/mysql/mysql.sock'. When set, host/port are ignored.
        'unix_socket' => '',
    ],

    // ---------------- Encryption -------------------------------------
    // 64 hex chars = 32 bytes = AES-256 key.
    // If you ever change this, every *_enc column becomes unreadable.
    'app_key' => 'CHANGE_ME_64_HEX_CHARS',

    // ---------------- URLs -------------------------------------------
    // base_url MUST end with a slash. Leave 'auto' to detect it from the
    // request (works for both https://domain.com/ and /lrms/ subfolders).
    'base_url' => 'auto',

    // ---------------- Runtime ----------------------------------------
    'timezone'  => 'Asia/Kolkata',

    // 'production' hides error details from users (recommended once live).
    // 'development' prints them. Errors are ALWAYS written to storage/logs.
    'env'       => 'production',

    // Secret used to authorise cPanel cron URLs (cron/run.php?job=x&key=y).
    // Generate with: php -r "echo bin2hex(random_bytes(16));"
    'cron_key'  => 'CHANGE_ME_CRON_KEY',

    // Upload limits (bytes). Shared hosting usually caps at 2M-64M in PHP
    // itself; these are the application-level guards.
    'max_photo_bytes' => 8 * 1024 * 1024,
    'max_excel_bytes' => 20 * 1024 * 1024,
];
