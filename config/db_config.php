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

    return $pdo;
}
