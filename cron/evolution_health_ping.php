<?php
/**
 * cron/evolution_health_ping.php
 *
 * Poll every active Evolution channel's /instance/connectionState
 * endpoint and cache the result on the channels row so
 * /admin/channels_health.php can render live state without fanning
 * out one HTTP request per row. Also emails the workspace's
 * alert_email when a channel transitions to disconnected.
 *
 * Install:
 *     * every 5 minutes via /etc/cron.d/aiserve-portal
 *     *​/​5 * * * * www-data /usr/bin/php /var/www/inbox/cron/evolution_health_ping.php
 *
 * Safe to invoke by hand for a diagnostic run:
 *     php cron/evolution_health_ping.php
 *
 * Prints one line per channel: STATE  channel_id  workspace  name
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/channels.php';
require_once __DIR__ . '/../inc/evolution_api.php';
require_once __DIR__ . '/../inc/email.php';

$db = aiserve_db();

// Skip channels we probed within the last 4 minutes so overlapping
// cron runs (someone triggered it manually while the 5-min tick is
// firing) don't hammer the Evolution box.
$stmt = $db->prepare(
    "SELECT c.*, co.name AS company_name, co.alert_email
     FROM channels c
     INNER JOIN companies co ON co.id = c.company_id
     WHERE c.provider = 'evolution' AND c.status = 'active'
       AND (c.probe_last_at IS NULL OR c.probe_last_at < DATE_SUB(NOW(), INTERVAL 4 MINUTE))
     ORDER BY c.id ASC"
);
$stmt->execute();
$channels = $stmt->fetchAll();

if (!$channels) {
    fwrite(STDOUT, "no evolution channels due for probe\n");
    exit(0);
}

foreach ($channels as $ch) {
    $cid  = (int)$ch['id'];
    $name = (string)$ch['name'];
    $wsN  = (string)$ch['company_name'];

    // Probe. evolution_connection_state returns
    // ['ok' => bool, 'state' => 'connected'|'connecting'|'disconnected', ...]
    // Falls through to 'disconnected' on any HTTP / auth failure so a
    // stuck box shows red on the channel health page.
    $probe = evolution_connection_state($ch);
    $newState = $probe['ok'] ? (string)$probe['state'] : 'disconnected';
    if (!in_array($newState, ['connected','connecting','disconnected'], true)) {
        $newState = 'unknown';
    }
    $prevState = (string)($ch['probe_state'] ?? 'unknown');

    // Backpressure indicator — count outgoing messages we tried to
    // send in the last hour but haven't yet observed as 'sent'. High
    // number = the queue drained slowly, usually because the session
    // dropped between the send call and the delivery ack.
    $queue = 0;
    try {
        $q = $db->prepare(
            "SELECT COUNT(*) FROM messages
             WHERE channel_id = ?
               AND direction  = 'outgoing'
               AND (status IS NULL OR status IN ('pending','queued','sending'))
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $q->execute([$cid]);
        $queue = (int)$q->fetchColumn();
    } catch (Throwable $e) { /* schema drift — leave zero */ }

    // Update the probe columns. probe_state_since gets bumped ONLY
    // when the state actually changed, so "been in this state since ..."
    // renders correctly on the health page.
    if ($prevState !== $newState) {
        $db->prepare(
            'UPDATE channels
             SET probe_state = ?, probe_state_since = NOW(),
                 probe_last_at = NOW(), probe_queue_size = ?
             WHERE id = ? LIMIT 1'
        )->execute([$newState, $queue, $cid]);
        log_activity((int)$ch['company_id'], null, 'channel_probe_state_change',
                     'channel', $cid, $prevState . ' -> ' . $newState);
    } else {
        $db->prepare(
            'UPDATE channels
             SET probe_last_at = NOW(), probe_queue_size = ?
             WHERE id = ? LIMIT 1'
        )->execute([$queue, $cid]);
    }

    fwrite(STDOUT, sprintf(
        "%-12s ch=%d  ws=%s  name=%s  queue=%d\n",
        strtoupper($newState), $cid, $wsN, $name, $queue
    ));

    // Alert on OK -> not-OK transition. Throttled to at most one
    // email per 30 min per channel so a customer with a chronically
    // disconnected number doesn't bury their inbox.
    if ($prevState === 'connected' && $newState !== 'connected') {
        $lastAlert = $ch['alert_last_sent_at'] ? strtotime((string)$ch['alert_last_sent_at']) : 0;
        if (!$lastAlert || (time() - $lastAlert) > 1800) {
            evolution_send_disconnect_alert($db, $ch, $newState);
            $db->prepare('UPDATE channels SET alert_last_sent_at = NOW() WHERE id = ? LIMIT 1')
               ->execute([$cid]);
        }
    }
}

exit(0);

/**
 * Fire off a disconnect alert email to the workspace's alert_email
 * plus every super_admin on the workspace (belt-and-braces so a
 * misconfigured alert_email doesn't silently swallow the ping).
 */
function evolution_send_disconnect_alert(PDO $db, array $channel, string $newState): void
{
    $companyId = (int)$channel['company_id'];
    $wsName    = (string)($channel['company_name'] ?? 'Your workspace');
    $chName    = (string)$channel['name'];
    $stateLbl  = $newState === 'connecting' ? 'reconnecting' : 'disconnected';

    // Recipient set: alert_email + every active super_admin's email.
    $to = [];
    $ae = trim((string)($channel['alert_email'] ?? ''));
    if ($ae !== '' && filter_var($ae, FILTER_VALIDATE_EMAIL)) $to[] = $ae;

    try {
        $s = $db->prepare(
            "SELECT email FROM users
             WHERE company_id = ? AND role = 'super_admin' AND status = 'active'
               AND email IS NOT NULL AND email <> ''"
        );
        $s->execute([$companyId]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $e) {
            if (filter_var((string)$e, FILTER_VALIDATE_EMAIL)) $to[] = (string)$e;
        }
    } catch (Throwable $e) { /* noop */ }
    $to = array_values(array_unique($to));
    if (!$to) return;

    $base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
        ? rtrim((string)APP_BASE_URL, '/')
        : 'https://inbox.aiserve.my';
    $repairUrl = $base . '/admin/evolution_connect.php';
    $healthUrl = $base . '/admin/channels_health.php';

    $subject = '🚨 WhatsApp ' . $stateLbl . ' — ' . $wsName . ' (' . $chName . ')';
    $body = "Your WhatsApp Evolution channel has stopped responding.\n\n"
          . "Workspace: " . $wsName . "\n"
          . "Channel:   " . $chName . "\n"
          . "State:     " . $newState . "\n"
          . "Detected:  " . date('Y-m-d H:i:s') . "\n\n"
          . "MOST LIKELY CAUSE:\n"
          . "  The WhatsApp Web session on the paired phone was cleared\n"
          . "  (linked-devices list changed, phone offline for 14+ days,\n"
          . "  or Evolution's Postgres/Redis restarted).\n\n"
          . "TO FIX (2 minutes):\n"
          . "  1. Open " . $repairUrl . "\n"
          . "  2. Click 'Pair WhatsApp' → scan the QR from the same phone\n"
          . "  3. Wait for the state to flip back to 'connected'\n\n"
          . "Live status: " . $healthUrl . "\n\n"
          . "— AiServe Inbox\n"
          . "(Alerts are throttled to once per 30 minutes per channel.)";

    foreach ($to as $addr) {
        try {
            send_email($addr, $subject, $body, 'AiServe Inbox alerts');
        } catch (Throwable $e) {
            error_log('[AiServe evolution_health_ping] mail failed to ' . $addr . ': ' . $e->getMessage());
        }
    }
    log_activity($companyId, null, 'channel_disconnect_alert_sent',
                 'channel', (int)$channel['id'], implode(',', $to));
}
