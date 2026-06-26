<?php
/**
 * AiServe Shared WhatsApp Inbox
 * Database & runtime configuration.
 *
 * Copy this file to /config/db_config.local.php to override per-environment,
 * or edit the values below directly for VPS / shared hosting deployment.
 */

// Prefer environment-overridable values where possible.
$DB_HOST = getenv('AISERVE_DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('AISERVE_DB_PORT') ?: '3306';
$DB_NAME = getenv('AISERVE_DB_NAME') ?: 'aiserve_inbox';
$DB_USER = getenv('AISERVE_DB_USER') ?: 'aiserve';
$DB_PASS = getenv('AISERVE_DB_PASS') ?: 'change_me_db_password';

// Single-tenant MVP: every record is tied to this company id.
// Future SaaS: resolve company_id from authenticated user / domain.
if (!defined('ACTIVE_COMPANY_ID')) {
    define('ACTIVE_COMPANY_ID', (int)(getenv('AISERVE_COMPANY_ID') ?: 1));
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'AiServe Shared WhatsApp Inbox');
}

if (!defined('APP_BASE_URL')) {
    // Set to your portal URL, e.g. https://inbox.aiserve.example.com
    define('APP_BASE_URL', getenv('AISERVE_BASE_URL') ?: '');
}

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', getenv('AISERVE_TZ') ?: 'Asia/Kuala_Lumpur');
}
date_default_timezone_set(APP_TIMEZONE);

// Session cookie security
if (!defined('APP_SESSION_NAME')) {
    define('APP_SESSION_NAME', 'AISERVE_SESSID');
}

/**
 * Build a single shared PDO instance.
 *
 * @return PDO
 */
function aiserve_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;

    $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    } catch (Throwable $e) {
        // Don't leak DSN/creds to the browser.
        error_log('[AiServe] DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Database connection error. Please contact administrator.');
    }

    // Hostinger's MySQL server defaults to UTC, but the portal stores
    // wall-clock timestamps via NOW() / CURRENT_TIMESTAMP and renders them
    // with fmt_dt() under APP_TIMEZONE. To keep MySQL-side time matching
    // the rest of the app, set the session timezone to APP_TIMEZONE's
    // current offset on every connect. Computed dynamically so it picks
    // up DST changes for timezones that observe them.
    try {
        $tz = new DateTimeZone(APP_TIMEZONE);
        $offsetSecs = $tz->getOffset(new DateTime('now', $tz));
        $h = (int)($offsetSecs / 3600);
        $m = abs((int)(($offsetSecs % 3600) / 60));
        $sign = $h >= 0 ? '+' : '-';
        $tzString = sprintf('%s%02d:%02d', $sign, abs($h), $m);
        $pdo->exec("SET time_zone = '" . $tzString . "'");
    } catch (Throwable $e) {
        error_log('[AiServe] Could not set MySQL session timezone: ' . $e->getMessage());
    }

    return $pdo;
}
