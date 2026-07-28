<?php
/**
 * Cron: daily. Warn each workspace's admin(s) when they've crossed 80%
 * of their broadcast quota for the current calendar month.
 *
 * Hostinger cron-tab line (once per day at 09:00 workspace time):
 *   0 9 * * * /usr/bin/php /home/uXXXXX/domains/inbox.aiserve.my/public_html/cron/broadcast_quota_alerts.php >> ~/cron_broadcast_alerts.log 2>&1
 *
 * Only sends once per (workspace, calendar month) — stamped on
 * companies.broadcast_quota_alert_month so the alert doesn't fire every
 * day for the rest of the month once triggered.
 *
 * PAYG workspaces skip the check entirely (their "quota" is infinite).
 * Free workspaces get an upgrade CTA; paid workspaces get a "consider
 * upgrading to PAYG" hint.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../inc/helpers.php';

$db = aiserve_db();
$now = date('Y-m-d H:i:s');
$currentMonth = date('Y-m');

echo "[$now] broadcast quota alerts — current month $currentMonth\n";

// Every active workspace that hasn't been alerted this month yet.
$stmt = $db->query(
    "SELECT id, name, plan, broadcast_plan, alert_email, broadcast_quota_alert_month
     FROM companies
     WHERE status = 'active'
       AND broadcast_plan IN ('free','paid')
       AND (broadcast_quota_alert_month IS NULL OR broadcast_quota_alert_month <> '$currentMonth')"
);

$sent = 0;
$skipped = 0;
foreach ($stmt->fetchAll() as $c) {
    $companyId = (int)$c['id'];
    $quota = broadcast_quota_for_workspace($companyId);

    if ($quota['unlimited']) {
        $skipped++;
        continue;
    }
    if ($quota['limit'] <= 0 || $quota['used'] < $quota['limit'] * 0.8) {
        $skipped++;
        continue;
    }

    // Find recipients: workspace-level alert_email if set, otherwise
    // every super_admin's email address for that workspace.
    $recipients = [];
    if (!empty($c['alert_email']) && filter_var((string)$c['alert_email'], FILTER_VALIDATE_EMAIL)) {
        $recipients[] = (string)$c['alert_email'];
    }
    $r = $db->prepare(
        "SELECT email FROM users
         WHERE company_id = ? AND status = 'active' AND role = 'super_admin'"
    );
    $r->execute([$companyId]);
    foreach ($r->fetchAll() as $u) {
        if (filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) {
            $recipients[] = (string)$u['email'];
        }
    }
    $recipients = array_values(array_unique($recipients));

    if (!$recipients) {
        echo "  workspace {$c['name']} — over 80% quota but no recipients configured, skipping\n";
        $skipped++;
        continue;
    }

    $pct = (int)round(($quota['used'] / $quota['limit']) * 100);
    $remainingCount = number_format($quota['remaining']);
    $currency = $quota['currency'];

    $subject = "[{$c['name']}] Broadcast usage at {$pct}% — {$remainingCount} recipients left this month";

    $upgradeCta = $quota['plan'] === 'free'
        ? "Upgrade to the paid plan: {$currency} " . rtrim(rtrim(number_format($quota['price'], 2), '0'), '.')
          . "/month for " . number_format($quota['paid_limit']) . " recipients.\n"
          . "Or switch to pay-as-you-go for unlimited sends at {$currency} "
          . rtrim(rtrim(number_format($quota['payg_rate'], 2), '0'), '.') . " per recipient.\n"
        : "Switch to pay-as-you-go for unlimited sends at {$currency} "
          . rtrim(rtrim(number_format($quota['payg_rate'], 2), '0'), '.') . " per recipient — no monthly cap.\n";

    $body =
        "Hi,\n\n"
        . "Your workspace \"{$c['name']}\" has used " . number_format($quota['used'])
        . " of its " . number_format($quota['limit']) . " monthly broadcast recipients ({$pct}%).\n"
        . number_format($quota['remaining']) . " recipients remain until the quota resets on the 1st of next month.\n\n"
        . "$upgradeCta\n"
        . "Contact your platform administrator to change plan.\n\n"
        . "— AiServe Inbox\n";

    $headers = [
        'From: AiServe Inbox <noreply@' . preg_replace('/^https?:\\/\\//', '', (string)platform_setting('operator_email', 'noreply@inbox.aiserve.my')) . '>',
        'Reply-To: ' . platform_setting('operator_email', 'support@aiserve.my'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    foreach ($recipients as $to) {
        @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    // Stamp so we don't email this workspace again this calendar month.
    $db->prepare('UPDATE companies SET broadcast_quota_alert_month = ? WHERE id = ?')
       ->execute([$currentMonth, $companyId]);

    log_activity($companyId, null, 'broadcast_quota_alert_sent', 'company', $companyId,
        "pct={$pct} used={$quota['used']} limit={$quota['limit']} to=" . implode(',', $recipients));

    echo "  workspace {$c['name']} — alerted {$pct}% ({$quota['used']}/{$quota['limit']}) → "
       . count($recipients) . " recipient(s)\n";
    $sent++;
}

echo "[done] alerts sent=$sent, skipped=$skipped\n";
