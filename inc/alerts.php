<?php
/**
 * inc/alerts.php
 *
 * Central helper for the workspace notification bell.
 *
 * A detector (Evolution health probe, silent-inbound sweeper, media
 * sync sweeper) calls alert_open() when a condition trips, and
 * alert_close() when it clears. The bell in the app header polls
 * /api/alerts_ping.php for a workspace's open rows and renders a
 * red toast + a browser desktop notification on the first sighting.
 *
 * Dedup: multiple detector runs against the same condition
 * (channel_id or message_id) must not spam the table. alert_open()
 * SELECT-then-INSERTs under the (company_id, kind, subject_ref,
 * resolved_at IS NULL) key — one open row per real problem.
 *
 * Dispatch legs (dispatched_via CSV):
 *   'inapp'    — the bell shows it; automatic on any POLL
 *   'browser'  — a page-scoped Notification was shown; the client
 *                writes this back on success so we don't re-pop
 *   'email'    — email dispatched. Written by alert_dispatch_email()
 *                so we send at most one email per incident.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/email.php';

/**
 * Open (or refresh) an alert for a (company_id, kind, subject_ref).
 *
 * Returns the alerts.id — new or existing.
 */
function alert_open(int $companyId, string $kind, string $subjectRef, string $title, string $body = '', string $href = '', string $severity = 'warn', ?int $channelId = null): int
{
    $db = aiserve_db();

    // Look for an existing open row so a chatter cron doesn't insert
    // 60 alerts a minute.
    $stmt = $db->prepare(
        'SELECT id FROM alerts
         WHERE company_id = ? AND kind = ? AND subject_ref = ? AND resolved_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$companyId, $kind, $subjectRef]);
    $existingId = (int)$stmt->fetchColumn();
    if ($existingId > 0) {
        // Refresh the title / body / href in case the condition mutated
        // (e.g. queue_size grew) — but keep created_at and dispatched_via
        // so we don't re-fire email/browser dispatch for the same event.
        $upd = $db->prepare(
            'UPDATE alerts
             SET title = ?, body = ?, href = ?, severity = ?, channel_id = ?
             WHERE id = ? LIMIT 1'
        );
        $upd->execute([$title, $body, $href, $severity, $channelId, $existingId]);
        return $existingId;
    }

    $ins = $db->prepare(
        'INSERT INTO alerts
            (company_id, channel_id, kind, severity, subject_ref, title, body, href, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $ins->execute([$companyId, $channelId, $kind, $severity, $subjectRef, $title, $body, $href]);
    return (int)$db->lastInsertId();
}

/**
 * Mark every open row matching (company_id, kind, subject_ref) as
 * resolved. Idempotent — a second call is a no-op.
 *
 * Returns the number of rows closed.
 */
function alert_close(int $companyId, string $kind, string $subjectRef): int
{
    $db = aiserve_db();
    $upd = $db->prepare(
        'UPDATE alerts
         SET resolved_at = NOW()
         WHERE company_id = ? AND kind = ? AND subject_ref = ? AND resolved_at IS NULL'
    );
    $upd->execute([$companyId, $kind, $subjectRef]);
    return (int)$upd->rowCount();
}

/**
 * Open alerts for a workspace, newest first.
 *
 * Consumed by /api/alerts_ping.php (bell poll) and by any dashboard
 * widget that renders "open incidents".
 */
function alerts_open_for_company(int $companyId, int $limit = 20): array
{
    $db = aiserve_db();
    $stmt = $db->prepare(
        'SELECT id, kind, severity, subject_ref, title, body, href,
                dispatched_via, created_at
         FROM alerts
         WHERE company_id = ? AND resolved_at IS NULL
         ORDER BY id DESC
         LIMIT ' . (int)$limit
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Append a delivery leg to alerts.dispatched_via so a second dispatch
 * of the same leg (email fanout, browser notification) doesn't fire
 * for the same incident.
 */
function alert_mark_dispatched(int $alertId, string $leg): void
{
    $db  = aiserve_db();
    $row = $db->prepare('SELECT dispatched_via FROM alerts WHERE id = ? LIMIT 1');
    $row->execute([$alertId]);
    $current = (string)($row->fetchColumn() ?: '');
    $parts   = array_filter(array_map('trim', explode(',', $current)));
    if (in_array($leg, $parts, true)) return;
    $parts[] = $leg;
    $db->prepare('UPDATE alerts SET dispatched_via = ? WHERE id = ? LIMIT 1')
       ->execute([implode(',', $parts), $alertId]);
}

function alert_has_dispatched(int $alertId, string $leg): bool
{
    $db  = aiserve_db();
    $row = $db->prepare('SELECT dispatched_via FROM alerts WHERE id = ? LIMIT 1');
    $row->execute([$alertId]);
    $parts = array_filter(array_map('trim', explode(',', (string)($row->fetchColumn() ?: ''))));
    return in_array($leg, $parts, true);
}

/**
 * Send an email fanout for an alert. All active super_admins on the
 * workspace + the workspace.alert_email get one message. Once per
 * incident (marked in dispatched_via so we never re-send).
 */
function alert_dispatch_email(int $alertId): bool
{
    if (alert_has_dispatched($alertId, 'email')) return false;
    $db  = aiserve_db();
    $row = $db->prepare(
        'SELECT a.*, co.name AS company_name, co.alert_email
         FROM alerts a
         INNER JOIN companies co ON co.id = a.company_id
         WHERE a.id = ? LIMIT 1'
    );
    $row->execute([$alertId]);
    $a = $row->fetch();
    if (!$a) return false;

    $to = [];
    $ae = trim((string)($a['alert_email'] ?? ''));
    if ($ae !== '' && filter_var($ae, FILTER_VALIDATE_EMAIL)) $to[] = $ae;

    try {
        $s = $db->prepare(
            "SELECT email FROM users
             WHERE company_id = ? AND role = 'super_admin' AND status = 'active'
               AND email IS NOT NULL AND email <> ''"
        );
        $s->execute([(int)$a['company_id']]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $e) {
            if (filter_var((string)$e, FILTER_VALIDATE_EMAIL)) $to[] = (string)$e;
        }
    } catch (Throwable $e) { /* noop */ }

    $to = array_values(array_unique($to));
    if (!$to) return false;

    $subject = '🚨 ' . (string)$a['company_name'] . ' — ' . (string)$a['title'];
    $body    = (string)$a['title'] . "\n\n"
             . (string)$a['body'] . "\n\n"
             . ($a['href'] ? 'Fix: ' . (string)$a['href'] . "\n\n" : '')
             . 'Workspace: ' . (string)$a['company_name'] . "\n"
             . 'Time: ' . (string)$a['created_at'] . " (UTC)\n\n"
             . '— AiServe channel-health watcher';

    $anyOk = false;
    foreach ($to as $addr) {
        if (send_email($addr, $subject, $body, 'AiServe Alerts')) $anyOk = true;
    }
    if ($anyOk) alert_mark_dispatched($alertId, 'email');
    return $anyOk;
}
