<?php
/**
 * Cron: daily. Delete inbound media older than each workspace's
 * companies.media_retention_days (default 90 days from phase 18).
 *
 * Hostinger cron-tab line:
 *   30 3 * * * /usr/bin/php /home/uXXXXX/domains/inbox.aiserve.my/public_html/cron/cleanup_media.php >> ~/cron.log 2>&1
 *
 * What it does per workspace:
 *   1. SELECT messages older than retention that still have a
 *      media_local_path.
 *   2. unlink() the file on disk.
 *   3. NULL out the messages.media_local_path column so the chat view
 *      shows "media expired" instead of a dead link.
 *   4. Also cleans up orphaned files under uploads/{cid}/inbound/ that
 *      aren't referenced by any row (e.g. from crashed webhook writes).
 *
 * Auto-reply catalog files (uploads/{cid}/auto_reply/) are NOT touched -
 * those are operator-configured and their retention is manual.
 *
 * Web access is blocked by cron/.htaccess. The script also self-blocks
 * if invoked over HTTP - cron-only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be invoked from the command line (cron).\n");
}

require_once __DIR__ . '/../inc/helpers.php';

$db = aiserve_db();
$companies = $db->query(
    'SELECT id, slug, media_retention_days
     FROM companies
     WHERE status = "active"'
)->fetchAll();

$now = date('Y-m-d H:i:s');
echo "[$now] cleanup_media across " . count($companies) . " workspace(s)\n";

$totalDeleted = 0;
$totalBytes   = 0;

foreach ($companies as $co) {
    $cid = (int)$co['id'];
    $days = max(7, (int)$co['media_retention_days']); // hard floor 7 days
    $dir  = realpath(__DIR__ . '/../uploads/' . $cid . '/inbound');

    // 1. Delete file + null path for any message older than retention.
    try {
        $stmt = $db->prepare(
            'SELECT id, media_local_path
             FROM messages
             WHERE company_id = ?
               AND media_local_path IS NOT NULL
               AND media_local_path <> ""
               AND created_at < NOW() - INTERVAL ? DAY'
        );
        $stmt->execute([$cid, $days]);
        $rows = $stmt->fetchAll();

        $localDeleted = 0;
        $localBytes   = 0;
        $upd = $db->prepare('UPDATE messages SET media_local_path = NULL WHERE id = ?');
        foreach ($rows as $r) {
            $path = (string)$r['media_local_path'];
            if ($path !== '' && file_exists($path)) {
                $sz = @filesize($path) ?: 0;
                if (@unlink($path)) {
                    $localBytes   += $sz;
                    $localDeleted++;
                }
            }
            $upd->execute([(int)$r['id']]);
        }
        echo "  $cid  " . $co['slug'] . "  retention=" . $days . "d  message-files=" . $localDeleted
           . "  freed=" . round($localBytes / 1024 / 1024, 2) . " MB\n";
        $totalDeleted += $localDeleted;
        $totalBytes   += $localBytes;
    } catch (Throwable $e) {
        error_log('[AiServe cleanup_media] company=' . $cid . ' err=' . $e->getMessage());
        continue;
    }

    // 2. Orphan sweep: any file under uploads/{cid}/inbound older than the
    //    retention window that has no messages row still pointing at it.
    if ($dir && is_dir($dir)) {
        $orphans = 0;
        $orphanBytes = 0;
        $cutoff = time() - ($days * 86400);
        $it = new DirectoryIterator($dir);
        foreach ($it as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            $filePath = $f->getPathname();
            if ($f->getMTime() >= $cutoff) continue;

            $check = $db->prepare('SELECT id FROM messages WHERE media_local_path = ? LIMIT 1');
            $check->execute([$filePath]);
            if ($check->fetchColumn()) continue;

            $sz = $f->getSize();
            if (@unlink($filePath)) {
                $orphans++;
                $orphanBytes += $sz;
            }
        }
        if ($orphans > 0) {
            echo "  $cid  " . $co['slug'] . "  orphan files=" . $orphans
               . "  freed=" . round($orphanBytes / 1024 / 1024, 2) . " MB\n";
            $totalDeleted += $orphans;
            $totalBytes   += $orphanBytes;
        }
    }
}

echo "[done] total files=" . $totalDeleted
   . "  freed=" . round($totalBytes / 1024 / 1024, 2) . " MB\n";
