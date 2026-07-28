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

    // Phase 31: broadcast quota auto-suspend. Free / paid plans that
    // have exhausted their monthly recipient allowance get their
    // running broadcasts paused, with a note so operators know why.
    // PAYG plans have unlimited remaining and skip this branch entirely.
    $quota = broadcast_quota_for_workspace($companyId);
    if (!$quota['unlimited'] && $quota['remaining'] <= 0) {
        echo "  bcast=$bid  workspace quota exhausted (" . $quota['used']
           . " / " . $quota['limit'] . "), pausing\n";
        $db->prepare(
            'UPDATE broadcasts SET status = "paused" WHERE id = ? AND status = "running"'
        )->execute([$bid]);
        log_activity($companyId, null, 'broadcast_auto_paused', 'broadcast', $bid,
            'quota_exhausted used=' . $quota['used'] . ' limit=' . $quota['limit']);
        continue;
    }

    // ATOMIC CLAIM: stamp last_batch_at up front so an overlapping cron
    // tick (Hostinger routinely overlaps runs when a batch takes 30+ s)
    // is fenced out of this broadcast until the next interval opens.
    // If UPDATE affects 0 rows another worker beat us to it - skip.
    // Previously last_batch_at was stamped AFTER the send loop, which let
    // two ticks both claim the same queued recipients and double-send.
    $claim = $db->prepare(
        'UPDATE broadcasts
         SET last_batch_at = NOW(),
             started_at    = COALESCE(started_at, NOW())
         WHERE id = ?
           AND status = "running"
           AND (last_batch_at IS NULL
                OR last_batch_at <= NOW() - INTERVAL batch_interval_min MINUTE)'
    );
    $claim->execute([$bid]);
    if ($claim->rowCount() === 0) {
        echo "  bcast=$bid  another cron worker holds this turn, skipping\n";
        continue;
    }

    // Now safe to pick the batch - the broadcast-level lock above means
    // no other worker can be processing this broadcast at the same time.
    // The unique key (broadcast_id, wa_id) is a second belt in case of a
    // manual retry.
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
    $messageText = (string)($b['message_text'] ?? '');

    // -----------------------------------------------------------
    // Load the up-to-4 media items for this broadcast (phase 25).
    // Fallback: if broadcast_media_items has no rows for this bid
    // (broadcast created before the migration), use the legacy
    // broadcasts.media_* columns as a single implicit item.
    // -----------------------------------------------------------
    $itemsStmt = $db->prepare(
        'SELECT sequence, media_path, media_kind, media_mime_type, media_filename
         FROM broadcast_media_items
         WHERE broadcast_id = ?
         ORDER BY sequence ASC'
    );
    $itemsStmt->execute([$bid]);
    $items = $itemsStmt->fetchAll();
    if (!$items && !empty($b['media_path'])) {
        $items = [[
            'sequence'        => 1,
            'media_path'      => (string)$b['media_path'],
            'media_kind'      => (string)($b['media_kind']      ?? ''),
            'media_mime_type' => (string)($b['media_mime_type'] ?? ''),
            'media_filename'  => (string)($b['media_filename']  ?? ''),
        ]];
    }

    // Upload each item once per batch (reused across every recipient).
    // For Cloud API this is one Meta /media upload per item per batch.
    // For Evolution / AiServe Chatbot it's a no-op that returns the
    // local path unchanged. Items that fail to upload are dropped from
    // this batch's send list rather than failing every recipient.
    $mediaRefs = [];
    foreach ($items as $it) {
        $path = (string)$it['media_path'];
        if ($path === '' || !is_file($path)) {
            echo "  bcast=$bid  media file missing on disk: $path — skipping this item\n";
            continue;
        }
        $up = provider_upload_media($channel, $path, (string)$it['media_mime_type']);
        if (!$up['ok']) {
            echo "  bcast=$bid  media upload FAIL (seq " . (int)$it['sequence'] . "): "
                 . substr((string)($up['error'] ?? ''), 0, 120) . " — skipping this item\n";
            continue;
        }
        $mediaRefs[] = [
            'ref'      => $up['media_ref'],
            'kind'     => (string)$it['media_kind'],
            'mime'     => (string)$it['media_mime_type'],
            'filename' => (string)$it['media_filename'],
        ];
    }
    $lastIdx = count($mediaRefs) - 1;   // -1 if no media

    foreach ($batch as $r) {
        $rid = (int)$r['id'];
        $wa  = (string)$r['wa_id'];

        try {
            $target = broadcast_resolve_target($db, $companyId, $channelId, $wa,
                $r['display_name'] ?: null);
            $contactId      = $target['contact_id'];
            $conversationId = $target['conversation_id'];

            // Send each media item in order. Caption (message_text) goes
            // on the LAST item so it appears at the bottom of the
            // recipient's chat, right above the reply box — the standard
            // WhatsApp multi-photo-with-caption pattern.
            //
            // If ANY item fails, mark the whole recipient as failed and
            // stop the chain (don't try later items on the same recipient
            // once one has failed — it usually means the number is bad).
            $result = ['ok' => true, 'wa_message_id' => null, 'error' => null];
            if ($mediaRefs) {
                foreach ($mediaRefs as $idx => $m) {
                    $isLast  = ($idx === $lastIdx);
                    $caption = $isLast && $messageText !== '' ? $messageText : null;
                    $step = provider_send_media(
                        $channel, $wa, $m['kind'], $m['ref'],
                        $caption,
                        $m['filename'] !== '' ? $m['filename'] : null,
                        $m['mime']     !== '' ? $m['mime']     : null
                    );
                    if (!$step['ok']) { $result = $step; break; }
                    $result = $step;   // the LAST successful step is what we record
                }
                // If there were media items but caption never got attached
                // (because none succeeded and message_text is set) send
                // the text separately so the customer at least gets the
                // message body. Only when at least one media step ran.
            } else {
                $result = provider_send_text($channel, $wa, $messageText);
            }

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

    // Only bump the per-run counters here - last_batch_at was already
    // stamped by the atomic claim above, so overlapping ticks don't need
    // us to touch it again.
    $db->prepare(
        'UPDATE broadcasts
         SET sent_count = sent_count + ?, failed_count = failed_count + ?
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
