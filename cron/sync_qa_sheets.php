<?php
/**
 * cron/sync_qa_sheets.php — hourly.
 *
 * For every active row in kb_qa_sheets, fetch the published-CSV URL,
 * parse (question, answer[, tag]) rows, and replace the kb_qa_pairs
 * rows tagged with that sheet's URL. Hand-added pairs
 * (source_sheet_url IS NULL) are never touched.
 *
 * The sync helper writes last_synced_at / last_synced_count / last_error
 * back to the kb_qa_sheets row so the operator sees status in
 * /admin/knowledge.php without opening the log.
 *
 * Cron entry:
 *   17 * * * * php /var/www/aiserve/cron/sync_qa_sheets.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/knowledge_base.php';

$db = aiserve_db();

// The table may not exist yet on very old workspaces — bail silently.
try {
    $s = $db->query('SELECT id, name FROM kb_qa_sheets WHERE active = 1 ORDER BY id ASC');
    $sheets = $s->fetchAll();
} catch (Throwable $e) {
    // Table missing = phase-48 not applied. That's fine — nothing to do.
    exit(0);
}

$syncedOk   = 0;
$syncedErr  = 0;
$totalRows  = 0;
$errorLines = [];

foreach ($sheets as $sheet) {
    $sheetId = (int)$sheet['id'];
    $name    = (string)$sheet['name'];
    try {
        $r = kb_sync_qa_sheet($sheetId);
    } catch (Throwable $e) {
        $r = ['ok' => false, 'count' => 0, 'error' => 'Exception: ' . $e->getMessage()];
    }
    if ($r['ok']) {
        $syncedOk++;
        $totalRows += (int)$r['count'];
    } else {
        $syncedErr++;
        $errorLines[] = "  [{$sheetId}] {$name}: " . (string)($r['error'] ?? 'unknown');
    }
}

echo "sheets ok: {$syncedOk}, err: {$syncedErr}, rows synced: {$totalRows}\n";
if ($errorLines) {
    echo "errors:\n" . implode("\n", $errorLines) . "\n";
}
