<?php
/**
 * cron/evolution_media_sync.php
 *
 * Async re-fetch for Evolution media that missed its webhook slot.
 *
 * Why this exists: when a WhatsApp voice note / photo / video arrives
 * at Meta, Evolution (Baileys under the hood) fires the
 * MESSAGES_UPSERT webhook immediately — but the actual media bytes
 * are still being downloaded from Meta's CDN in the background. The
 * webhook handler tries three quick attempts to grab the base64
 * (~5s worst case) inside inc/evolution_api.php's
 * evolution_fetch_media_base64(). If Baileys is still behind after
 * 5s — which happens for big voice notes, slow CDN edges, or a
 * flaky Baileys session — the message row is inserted with
 * media_local_path=NULL and the inbox shows "media not synced".
 *
 * This cron re-tries every candidate message for 15 minutes. Once
 * the bytes land, we write the file, stamp media_local_path, and the
 * next inbox poll renders the audio bubble correctly.
 *
 * Install (add to /etc/cron.d/aiserve-portal):
 *     * * * * * www-data /usr/bin/php /var/www/inbox/cron/evolution_media_sync.php
 *
 * Safe to run by hand:
 *     php cron/evolution_media_sync.php
 *
 * Prints one line per candidate: OK|MISS|SKIP  msg_id  wa_msg_id  kind
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/evolution_api.php';

$db = aiserve_db();

// Only sweep messages young enough that Baileys might still hold the
// media. After 15 min the odds are the Meta CDN URL has expired and
// no amount of retrying will help — spare the cron the noise.
$stmt = $db->prepare(
    "SELECT m.id, m.wa_message_id, m.message_type, m.media_mime_type,
            m.media_filename, m.company_id, m.channel_id,
            co.id AS company_pk, co.name AS company_name,
            co.skip_stickers, co.media_max_kb,
            co.evolution_base_url, co.evolution_api_key,
            co.evolution_instance
     FROM messages m
     INNER JOIN companies co ON co.id = m.company_id
     INNER JOIN channels c   ON c.id  = m.channel_id
     WHERE m.direction = 'incoming'
       AND m.media_local_path IS NULL
       AND m.wa_message_id IS NOT NULL AND m.wa_message_id <> ''
       AND m.message_type IN ('audio','image','video','document')
       AND c.provider = 'evolution'
       AND m.created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
     ORDER BY m.id ASC
     LIMIT 100"
);
$stmt->execute();
$rows = $stmt->fetchAll();

if (!$rows) {
    // Quiet on empty runs so cron mail doesn't spam. Uncomment for
    // manual debugging.
    // fwrite(STDOUT, "no candidates\n");
    exit(0);
}

$updated = 0;
$missed  = 0;
$skipped = 0;

foreach ($rows as $r) {
    $msgId    = (int)$r['id'];
    $waMsgId  = (string)$r['wa_message_id'];
    $kind     = (string)$r['message_type'];
    // evolution_api.php reads $company['evolution_instance'] — the DB
    // column is 'evolution_instance', not 'evolution_instance_name'.
    $company  = [
        'id'                 => (int)$r['company_pk'],
        'evolution_base_url' => $r['evolution_base_url'],
        'evolution_api_key'  => $r['evolution_api_key'],
        'evolution_instance' => $r['evolution_instance'],
    ];

    if (!evolution_is_configured($company)) {
        $skipped++;
        fwrite(STDOUT, "SKIP  {$msgId}  {$waMsgId}  {$kind}  (evolution not configured)\n");
        continue;
    }

    $b64 = null;
    try {
        // Single-shot: this cron re-runs every minute for 15 min, so
        // stacking retries inside one candidate would just burn the
        // sweeper's runtime for no gain.
        $b64 = evolution_fetch_media_base64($company, $waMsgId, true);
    } catch (Throwable $e) {
        error_log('[AiServe evolution_media_sync] fetch threw for msg ' . $msgId . ': ' . $e->getMessage());
    }

    if ($b64 === null || $b64 === '') {
        $missed++;
        fwrite(STDOUT, "MISS  {$msgId}  {$waMsgId}  {$kind}\n");
        continue;
    }

    // Size guard — same envelope the webhook enforces.
    $maxKb    = max(64, (int)($r['media_max_kb'] ?? 10240));
    $maxBytes = $maxKb * 1024;
    $approx   = (int)(strlen($b64) * 3 / 4);
    if ($approx > $maxBytes) {
        error_log('[AiServe evolution_media_sync] oversized media '
            . '(' . $approx . 'B > ' . $maxBytes . 'B) for msg ' . $msgId);
        $missed++;
        fwrite(STDOUT, "MISS  {$msgId}  {$waMsgId}  {$kind}  (oversized)\n");
        continue;
    }

    try {
        $destDir = __DIR__ . '/../uploads/' . (int)$r['company_id'] . '/inbound';
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        $ext = $r['media_filename']
            ? '.' . pathinfo((string)$r['media_filename'], PATHINFO_EXTENSION)
            : evolution_extension_for_mime((string)$r['media_mime_type']);
        $fname  = $waMsgId . '_' . bin2hex(random_bytes(4)) . $ext;
        $target = $destDir . '/' . $fname;
        $bytes  = file_put_contents($target, base64_decode($b64));
        if ($bytes === false) {
            error_log('[AiServe evolution_media_sync] write failed for msg ' . $msgId);
            $missed++;
            fwrite(STDOUT, "MISS  {$msgId}  {$waMsgId}  {$kind}  (write failed)\n");
            continue;
        }
        @chmod($target, 0640);
        $normalized = realpath($target);
        $abs        = $normalized !== false ? $normalized : $target;

        $upd = $db->prepare(
            'UPDATE messages
             SET media_local_path = ?
             WHERE id = ? AND media_local_path IS NULL'
        );
        $upd->execute([$abs, $msgId]);

        $updated++;
        fwrite(STDOUT, "OK    {$msgId}  {$waMsgId}  {$kind}  ({$approx}B)\n");
    } catch (Throwable $e) {
        error_log('[AiServe evolution_media_sync] persist failed for msg ' . $msgId . ': ' . $e->getMessage());
        $missed++;
        fwrite(STDOUT, "MISS  {$msgId}  {$waMsgId}  {$kind}  (exception)\n");
    }
}

fwrite(STDOUT, "summary: {$updated} updated, {$missed} still missing, {$skipped} skipped\n");
