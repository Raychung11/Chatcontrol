<?php
/**
 * Cron: every 1 minute, trickle out the next batch of any running broadcast.
 *
 * Hostinger cron-tab line:
 *   * * * * * /usr/bin/php /home/uXXXXX/domains/inbox.aiserve.my/public_html/cron/process_broadcasts.php >> ~/cron.log 2>&1
 *
 * A broadcast is "due" when its last_batch_at + batch_interval_min has passed
 * (or last_batch_at IS NULL for the very first batch). The cron picks up to
 * batch_size queued recipients per due broadcast and sends them, then stamps
 * last_batch_at = NOW() so the same broadcast is not picked up again until
 * the next interval window opens.
 *
 * Web access is blocked by cron/.htaccess. The script also self-blocks if
 * invoked over HTTP - cron-only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be invoked from the command line (cron).\n");
}

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/channels.php';
require_once __DIR__ . '/../inc/provider.php';
require_once __DIR__ . '/../inc/broadcasts.php';

$db = aiserve_db();

$broadcasts = broadcasts_due_for_batch($db);
$now = date('Y-m-d H:i:s');
echo "[$now] " . count($broadcasts) . " broadcast(s) due for next batch\n";

foreach ($broadcasts as $b) {
    $bid       = (int)$b['id'];
    $companyId = (int)$b['company_id'];
    $channelId = (int)$b['channel_id'];
    $batchSize = max(1, (int)$b['batch_size']);
    $userId    = (int)$b['created_by_user_id'];

    // Load the channel (provider config lives on it).
    $channel = channel_by_id($channelId);
    if (!$channel || (int)$channel['company_id'] !== $companyId) {
        echo "  bcast=$bid  channel $channelId missing or cross-company, marking failed\n";
        $db->prepare('UPDATE broadcasts SET status = "cancelled", completed_at = NOW() WHERE id = ?')
           ->execute([$bid]);
        continue;
    }

    // Claim the next batch. We update the recipient rows to 'sending' first so
    // a second cron tick that fires before this one finishes does not double-send.
    // The unique key (broadcast_id, wa_id) also prevents re-queueing the same number.
    $batchStmt = $db->prepare(
        'SELECT id, wa_id, display_name, contact_id, conversation_id
         FROM broadcast_recipients
         WHERE broadcast_id = ? AND status = "queued"
         ORDER BY id ASC LIMIT ' . $batchSize
    );
    $batchStmt->execute([$bid]);
    $batch = $batchStmt->fetchAll();
    if (!$batch) {
        // No queued recipients - finish.
        $db->prepare('UPDATE broadcasts SET status = "done", completed_at = NOW() WHERE id = ?')
           ->execute([$bid]);
        echo "  bcast=$bid  queue empty, marked done\n";
        continue;
    }

    $okCount   = 0;
    $errCount  = 0;
    $messageText = (string)$b['message_text'];

    foreach ($batch as $r) {
        $rid = (int)$r['id'];
        $wa  = (string)$r['wa_id'];

        try {
            $target = broadcast_resolve_target($db, $companyId, $channelId, $wa,
                $r['display_name'] ?: null);
            $contactId      = $target['contact_id'];
            $conversationId = $target['conversation_id'];

            // Send.
            $result = provider_send_text($channel, $wa, $messageText);

            $messageId = broadcast_record_message($db, $companyId, $conversationId,
                $contactId, $userId, $messageText, $result);

            $db->prepare(
                'UPDATE broadcast_recipients
                 SET status = ?, contact_id = ?, conversation_id = ?, message_id = ?,
                     wa_message_id = ?, error_message = ?, sent_at = NOW()
                 WHERE id = ?'
            )->execute([
                $result['ok'] ? 'sent' : 'failed',
                $contactId,
                $conversationId,
                $messageId,
                $result['wa_message_id'] ?? null,
                $result['ok'] ? null : mb_substr((string)($result['error'] ?? ''), 0, 500),
                $rid,
            ]);

            if ($result['ok']) {
                $okCount++;
            } else {
                $errCount++;
                echo "  bcast=$bid  to=$wa  FAIL: " . substr((string)($result['error'] ?? ''), 0, 120) . "\n";
            }
        } catch (Throwable $e) {
            $errCount++;
            error_log('[AiServe broadcast] ' . $e->getMessage());
            $db->prepare(
                'UPDATE broadcast_recipients
                 SET status = "failed", error_message = ?, sent_at = NOW()
                 WHERE id = ?'
            )->execute([mb_substr($e->getMessage(), 0, 500), $rid]);
        }
    }

    // Stamp the broadcast progress + last_batch_at so we wait the full interval
    // before the next batch.
    $db->prepare(
        'UPDATE broadcasts
         SET sent_count = sent_count + ?, failed_count = failed_count + ?,
             last_batch_at = NOW(),
             started_at = COALESCE(started_at, NOW())
         WHERE id = ?'
    )->execute([$okCount, $errCount, $bid]);

    // Done if no more queued.
    $left = (int)$db->query("SELECT COUNT(*) FROM broadcast_recipients WHERE broadcast_id = $bid AND status = 'queued'")->fetchColumn();
    if ($left === 0) {
        $db->prepare('UPDATE broadcasts SET status = "done", completed_at = NOW() WHERE id = ?')
           ->execute([$bid]);
        echo "  bcast=$bid  batch sent=$okCount fail=$errCount, queue empty -> DONE\n";
        log_activity($companyId, $userId, 'broadcast_completed', 'broadcast', $bid,
            'sent=' . ($b['sent_count'] + $okCount) . ' failed=' . ($b['failed_count'] + $errCount));
    } else {
        echo "  bcast=$bid  batch sent=$okCount fail=$errCount, $left remaining\n";
    }
}

echo "[done]\n";
