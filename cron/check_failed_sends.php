<?php
/**
 * Cron: every 5 minutes, scan each workspace's recent outbound failures.
 * If a workspace has more than its threshold of failures in the last
 * 10 minutes, email its admin (or its configured alert_email) and log
 * the alert to activity_logs so we don't re-alert for 30 minutes.
 *
 * Hostinger cron-tab line:
 *   *\/5 * * * * /usr/bin/php /home/uXXXXX/domains/inbox.aiserve.my/public_html/cron/check_failed_sends.php >> ~/cron.log 2>&1
 *
 * Web access is blocked by cron/.htaccess. The script also self-blocks
 * if invoked over HTTP - cron-only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be invoked from the command line (cron).\n");
}

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/email.php';

$db = aiserve_db();

$WINDOW_MIN     = 10;   // look back this many minutes for failures
$COOLDOWN_MIN   = 30;   // don't re-alert sooner than this for the same workspace

$companies = $db->query(
    'SELECT c.id, c.name, c.slug,
            COALESCE(c.alert_email, "") AS alert_email,
            c.alert_failed_sends_enabled,
            c.alert_failed_sends_threshold
     FROM companies c
     WHERE c.status = "active" AND c.alert_failed_sends_enabled = 1'
)->fetchAll();

$now = date('Y-m-d H:i:s');
echo "[" . $now . "] checking " . count($companies) . " workspace(s)\n";

foreach ($companies as $co) {
    $companyId = (int)$co['id'];
    $threshold = max(1, (int)$co['alert_failed_sends_threshold']);

    // 1. Cooldown check - did we alert this workspace within the last
    //    COOLDOWN_MIN minutes?
    $cool = $db->prepare(
        'SELECT id FROM activity_logs
         WHERE company_id = ? AND action_type = "alert_failed_sends"
           AND created_at > (NOW() - INTERVAL ? MINUTE)
         ORDER BY id DESC LIMIT 1'
    );
    $cool->execute([$companyId, $COOLDOWN_MIN]);
    if ($cool->fetchColumn()) {
        echo "  $companyId  " . $co['slug'] . "  - in cooldown, skipping\n";
        continue;
    }

    // 2. Count + collect failed outbound messages in the window.
    $fails = $db->prepare(
        'SELECT m.id, m.created_at, m.message_text, m.error_message,
                ct.display_name, ct.profile_name, ct.wa_id
         FROM messages m
         LEFT JOIN contacts ct ON ct.id = m.contact_id
         WHERE m.company_id = ? AND m.direction = "outgoing"
           AND m.status = "failed"
           AND m.created_at > (NOW() - INTERVAL ? MINUTE)
         ORDER BY m.id DESC LIMIT 50'
    );
    $fails->execute([$companyId, $WINDOW_MIN]);
    $rows = $fails->fetchAll();
    $count = count($rows);
    if ($count < $threshold) {
        echo "  $companyId  " . $co['slug'] . "  - $count failures (under threshold $threshold)\n";
        continue;
    }

    // 3. Decide recipients.
    $recipients = [];
    if (!empty($co['alert_email']) && filter_var($co['alert_email'], FILTER_VALIDATE_EMAIL)) {
        $recipients[] = $co['alert_email'];
    } else {
        $u = $db->prepare(
            'SELECT email FROM users
             WHERE company_id = ? AND role = "super_admin" AND status = "active"
             ORDER BY id ASC LIMIT 5'
        );
        $u->execute([$companyId]);
        foreach ($u->fetchAll() as $row) {
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) $recipients[] = $row['email'];
        }
    }
    if (!$recipients) {
        echo "  $companyId  " . $co['slug'] . "  - no recipients configured, skipping\n";
        continue;
    }

    // 4. Build the email body.
    $subject = sprintf('[AiServe] %d failed outbound messages in the last %d min — %s',
        $count, $WINDOW_MIN, $co['name']);

    $body  = "Hi,\n\n";
    $body .= "AiServe Inbox detected $count failed outbound WhatsApp messages in the last "
           . $WINDOW_MIN . " minutes for your workspace \"" . $co['name'] . "\".\n\n";
    $body .= "Most common causes:\n";
    $body .= "  • Bearer token expired / wrong - check Admin → Channels\n";
    $body .= "  • Gateway URL has a typo or trailing slash\n";
    $body .= "  • Partner gateway / Hostinger temporarily unreachable\n\n";
    $body .= "Recent failures (newest first):\n";
    $body .= str_repeat('-', 60) . "\n";
    foreach (array_slice($rows, 0, 10) as $r) {
        $who   = $r['display_name'] ?: $r['profile_name'] ?: ('+' . $r['wa_id']);
        $when  = $r['created_at'];
        $text  = mb_substr((string)$r['message_text'], 0, 80);
        $err   = mb_substr((string)$r['error_message'], 0, 100) ?: '(no error message captured)';
        $body .= sprintf("[%s] to %s\n  text: %s\n  err : %s\n\n", $when, $who, $text, $err);
    }
    if ($count > 10) {
        $body .= "...and " . ($count - 10) . " more.\n\n";
    }
    $body .= "Open the inbox: " . (APP_BASE_URL ?: 'https://inbox.aiserve.my') . "/inbox/index.php\n";
    $body .= "Manage channels: " . (APP_BASE_URL ?: 'https://inbox.aiserve.my') . "/admin/channels.php\n\n";
    $body .= "We won't re-alert for the next " . $COOLDOWN_MIN . " minutes.\n\n";
    $body .= "— AiServe Inbox\n";

    // 5. Send.
    $sentTo = [];
    foreach ($recipients as $to) {
        if (send_email($to, $subject, $body)) {
            $sentTo[] = $to;
        }
    }
    if (!$sentTo) {
        echo "  $companyId  " . $co['slug'] . "  - $count failures but mail() failed for all recipients\n";
        continue;
    }

    // 6. Stamp the cooldown.
    log_activity($companyId, null, 'alert_failed_sends', 'company', $companyId,
        sprintf('Alerted %d recipients about %d failures in %d min',
            count($sentTo), $count, $WINDOW_MIN));

    echo "  $companyId  " . $co['slug'] . "  - ALERT sent to: " . implode(', ', $sentTo) . " ($count failures)\n";
}

echo "[done]\n";
