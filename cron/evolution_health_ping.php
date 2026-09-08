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
require_once __DIR__ . '/../inc/alerts.php';

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

    // Alert on OK -> not-OK transition. Open (or refresh) a bell alert
    // and route the email fanout through inc/alerts.php so the same
    // event drives the in-app bell + browser desktop notification +
    // email — one source of truth. Throttling on email is handled by
    // alert_dispatch_email() marking dispatched_via=email on the
    // alerts row: the first call sends, subsequent calls no-op.
    $subjectRef = 'disconnected:' . $cid;
    if ($newState === 'connected') {
        // Reconnected — close any prior open alert so the bell clears.
        alert_close((int)$ch['company_id'], 'evolution_disconnected', $subjectRef);
    } elseif ($prevState === 'connected' || $prevState === 'unknown') {
        // Just went bad (or we just booted and it was already bad).
        $alertId = alert_open(
            (int)$ch['company_id'],
            'evolution_disconnected',
            $subjectRef,
            'WhatsApp channel ' . $name . ' is ' . $newState,
            "Session state on Evolution flipped to '" . $newState . "'. "
            . "Most likely the linked-devices list on the paired phone "
            . "was cleared, the phone has been offline for 14+ days, or "
            . "Evolution's Postgres/Redis restarted. Re-pair from "
            . "/admin/evolution_connect.php to restore inbound.",
            '/admin/evolution_connect.php',
            'error',
            $cid
        );
        // Fire email + WhatsApp DM once per incident. Both helpers are
        // idempotent via dispatched_via — a second cron tick against
        // the same open row won't re-send. WhatsApp DM tries a peer
        // Evolution channel on the same workspace so a disconnected
        // channel doesn't try to escalate through itself.
        alert_dispatch_email($alertId);
        alert_dispatch_whatsapp($alertId);
        $db->prepare('UPDATE channels SET alert_last_sent_at = NOW() WHERE id = ? LIMIT 1')
           ->execute([$cid]);
    }
}

exit(0);

// evolution_send_disconnect_alert() removed. Email fanout for a
// disconnected channel now flows through inc/alerts.php ::
// alert_dispatch_email() so the same event drives the in-app bell +
// browser desktop notification + email as one incident. See the
// alert_open()/alert_close() calls in the main loop above.
