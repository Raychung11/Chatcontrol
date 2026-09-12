<?php
/**
 * cron/detect_silent_channels.php
 *
 * Detects the second kind of "stuck" — a channel whose session
 * reports connected but no inbound webhooks are flowing. That
 * silent-failure mode has three usual causes:
 *
 *   1. Nginx / PHP-FPM overloaded on the portal side, so the
 *      partner's webhook POSTs land 502 and get dropped.
 *   2. Meta throttling on a hot number (rare on WhatsApp Cloud API,
 *      more common on Evolution / Baileys).
 *   3. Evolution's internal event queue backed up but the
 *      /instance/connectionState endpoint still returns 'open'.
 *
 * Because a legitimately quiet channel (a shop that only chats on
 * weekends) must not fire this alert, the threshold is self-tuning
 * per channel: we compare the current "gap since last inbound" to
 * the channel's own p95 inbound-cadence over the last 14 days. If
 * the channel is normally silent, the p95 is huge and the current
 * gap will still be under it.
 *
 * Install:
 *   * * * * * www-data /usr/bin/php /var/www/aiserve/cron/detect_silent_channels.php
 *   (safe to run every minute — cheap query per channel, no HTTP)
 *
 * Prints one line per active channel: STATE  ch=<id>  ws=<name>
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/alerts.php';

$db = aiserve_db();

// Only active channels — a paused channel isn't expected to receive.
$stmt = $db->prepare(
    "SELECT c.id, c.company_id, c.name, c.provider, co.name AS company_name
     FROM channels c
     INNER JOIN companies co ON co.id = c.company_id
     WHERE c.status = 'active'
     ORDER BY c.id ASC"
);
$stmt->execute();
$channels = $stmt->fetchAll();

// Absolute floor: don't alert on a brand-new channel that has never
// received a message (nothing to compare against) or a channel that
// just took its first message ever. 30 minutes gives room for a
// legit "customer hasn't messaged yet today" state on a hot number.
$FLOOR_MIN_GAP_SEC = 30 * 60;

// Absolute ceiling: even for a normally-quiet channel, 24 h without
// inbound while status=active is a signal something is off — likely
// the webhook URL rotted (channel token changed, subdomain moved,
// etc). Fire at 24 h regardless of historical cadence.
$HARD_CEIL_SEC = 24 * 3600;

foreach ($channels as $c) {
    $chId = (int)$c['id'];

    // 1. When did we last receive on this channel?
    $q = $db->prepare(
        "SELECT UNIX_TIMESTAMP(MAX(created_at)) AS last_ts,
                COUNT(*) AS n
         FROM messages
         WHERE channel_id = ? AND direction = 'incoming'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)"
    );
    $q->execute([$chId]);
    $r = $q->fetch();
    $lastTs = (int)($r['last_ts'] ?? 0);
    $n      = (int)($r['n']       ?? 0);

    if ($n < 20 || $lastTs === 0) {
        // Not enough history to compute a cadence; skip silently.
        // The Evolution health probe still watches session drop for
        // channels with no traffic history.
        fwrite(STDOUT, "SKIP  ch={$chId}  ws={$c['company_name']}  (only {$n} inbound in 14d)\n");
        continue;
    }

    $now = time();
    $gap = $now - $lastTs;
    $subjectRef = 'silent:' . $chId;

    // 2. Compute the channel's historical p95 inter-message gap. We
    // pull inbound timestamps oldest-first, walk the deltas, sort,
    // pick the 95th percentile. Cheap for < ~50k rows and keeps
    // portable across MariaDB versions (no window functions needed).
    $g = $db->prepare(
        "SELECT UNIX_TIMESTAMP(created_at) AS ts
         FROM messages
         WHERE channel_id = ? AND direction = 'incoming'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
         ORDER BY created_at ASC"
    );
    $g->execute([$chId]);
    $timestamps = $g->fetchAll(PDO::FETCH_COLUMN);
    $gaps = [];
    for ($i = 1, $L = count($timestamps); $i < $L; $i++) {
        $delta = (int)$timestamps[$i] - (int)$timestamps[$i - 1];
        if ($delta > 0) $gaps[] = $delta;
    }
    if (!$gaps) {
        fwrite(STDOUT, "SKIP  ch={$chId}  ws={$c['company_name']}  (no gaps)\n");
        continue;
    }
    sort($gaps, SORT_NUMERIC);
    $p95Index = (int)floor(count($gaps) * 0.95);
    $p95Index = min($p95Index, count($gaps) - 1);
    $p95      = (int)$gaps[$p95Index];

    // Threshold: 3× the channel's own p95, floored + ceilinged so we
    // never alert too eagerly on a chatty channel or too late on a
    // quiet one.
    $threshold = max($FLOOR_MIN_GAP_SEC, min($HARD_CEIL_SEC, $p95 * 3));

    if ($gap >= $threshold) {
        $mins    = (int)floor($gap / 60);
        $p95mins = (int)floor($p95 / 60);
        $title   = 'No inbound on ' . $c['name'] . ' for ' . $mins . ' min';
        $body    = 'This channel usually receives at least one message every '
                 . $p95mins . ' min (p95 over 14d). Nothing has come in for '
                 . $mins . ' min. Check the channel health page for session '
                 . 'state and webhook config.';
        $href    = '/admin/channels_health.php';

        $alertId = alert_open(
            (int)$c['company_id'],
            'silent_inbound',
            $subjectRef,
            $title,
            $body,
            $href,
            'warn',
            $chId
        );
        // Dispatch the "background" legs once per incident.
        alert_dispatch_email($alertId);
        alert_dispatch_whatsapp($alertId);
        fwrite(STDOUT, "ALERT ch={$chId}  ws={$c['company_name']}  gap={$mins}m  thr=" . (int)floor($threshold / 60) . "m\n");
    } else {
        // Traffic returned — clear a previously-open silent alert.
        $closed = alert_close((int)$c['company_id'], 'silent_inbound', $subjectRef);
        if ($closed > 0) {
            fwrite(STDOUT, "CLEAR ch={$chId}  ws={$c['company_name']}\n");
        } else {
            fwrite(STDOUT, "OK    ch={$chId}  ws={$c['company_name']}  gap=" . (int)floor($gap / 60) . "m\n");
        }
    }
}
