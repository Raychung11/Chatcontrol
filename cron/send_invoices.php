<?php
/**
 * cron/send_invoices.php — auto-invoice cron.
 *
 * Runs every 15 min. For every activity_logs row of type
 * broadcast_plan_selfserve_change created since the last run, ensures
 * an invoice exists (idempotent via UNIQUE(source_activity_id)) and
 * emails it to the workspace's super_admin.
 *
 * Cron entry (add to /var/spool/cron on VPS):
 *   *\/15 * * * * php /var/www/aiserve/cron/send_invoices.php >/dev/null 2>&1
 *
 * Safe to run manually for debugging:  php cron/send_invoices.php
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/invoicing.php';

$db = aiserve_db();

// Cursor stored in platform_settings so we don't re-scan the whole
// activity log on every run.
$cursorKey = 'invoicing_cron_cursor';
$since     = platform_setting($cursorKey, '0');
if (!ctype_digit((string)$since)) $since = '0';
$since     = (int)$since;

$rows = $db->prepare(
    "SELECT id, company_id, message, created_at
     FROM activity_logs
     WHERE id > ? AND action = 'broadcast_plan_selfserve_change'
     ORDER BY id ASC
     LIMIT 200"
);
$rows->execute([$since]);
$activity = $rows->fetchAll();

if (!$activity) {
    echo "No new upgrades to invoice.\n";
    exit(0);
}

$created = 0;
$emailed = 0;
$maxId   = $since;
foreach ($activity as $log) {
    $maxId = max($maxId, (int)$log['id']);
    $invoiceId = invoicing_create_for_upgrade($log);
    if (!$invoiceId) continue;
    $created++;
    if (invoicing_send_email($invoiceId)) {
        $emailed++;
        echo "Invoice #{$invoiceId} emailed for activity #{$log['id']}\n";
    } else {
        echo "Invoice #{$invoiceId} created but email failed for activity #{$log['id']}\n";
    }
}

// Advance the cursor even if some emails failed — the invoice row
// exists, admin can resend from /admin/invoices.php.
try {
    aiserve_db()->prepare(
        'INSERT INTO platform_settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    )->execute([$cursorKey, (string)$maxId]);
} catch (Throwable $e) {
    error_log('[send_invoices] cursor persist failed: ' . $e->getMessage());
}

echo "Processed " . count($activity) . " log rows · created {$created} invoices · emailed {$emailed}\n";
