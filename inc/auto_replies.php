<?php
/**
 * Keyword auto-reply engine.
 *
 * Runs on every inbound customer message. First rule whose match_value
 * matches the message text wins; we send the reply (text and/or media)
 * via the same channel the customer messaged into, then log to
 * auto_reply_fires so we can enforce a per-conversation cooldown.
 *
 * Group chats and closed conversations are skipped upstream by the
 * webhook - by the time we get called we know the conversation is
 * legitimate.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/provider.php';
require_once __DIR__ . '/channels.php';

/**
 * Load all active auto-reply rules for a workspace + channel, ordered
 * by priority (low number = high priority, matches how routing rules
 * behave elsewhere in the app).
 *
 * @return array<int,array>
 */
function auto_replies_load(int $companyId, int $channelId): array
{
    $stmt = aiserve_db()->prepare(
        'SELECT * FROM auto_replies
         WHERE company_id = ? AND status = "active"
           AND (channel_id IS NULL OR channel_id = ?)
         ORDER BY priority ASC, id ASC'
    );
    $stmt->execute([$companyId, $channelId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return the first rule whose match_value matches $text, or null.
 * Matching is case-insensitive across all four match_type modes.
 */
function auto_reply_match(array $rules, string $text): ?array
{
    $needleText = mb_strtolower(trim($text));
    if ($needleText === '') return null;

    foreach ($rules as $r) {
        $needle = mb_strtolower(trim((string)$r['match_value']));
        if ($needle === '') continue;
        $hit = false;
        switch ($r['match_type']) {
            case 'equals':
                $hit = ($needleText === $needle);
                break;
            case 'starts_with':
                $hit = str_starts_with($needleText, $needle);
                break;
            case 'regex':
                // PCRE - value is written without delimiters; add /iu.
                // A workspace admin could save a catastrophically-backtracking
                // pattern like /(a+)+b/ that runs for tens of seconds on
                // adversarial input, holding the PHP-FPM worker and
                // starving other tenants sharing this host. Clamp
                // backtrack + recursion limits around this call - PCRE
                // returns false and preg_last_error() surfaces the reason.
                $pattern = '/' . str_replace('/', '\\/', $r['match_value']) . '/iu';
                $oldBt = ini_get('pcre.backtrack_limit');
                $oldRc = ini_get('pcre.recursion_limit');
                ini_set('pcre.backtrack_limit', '100000');
                ini_set('pcre.recursion_limit', '100000');
                $hit = @preg_match($pattern, $text) === 1;
                ini_set('pcre.backtrack_limit', (string)$oldBt);
                ini_set('pcre.recursion_limit', (string)$oldRc);
                break;
            case 'contains':
            default:
                $hit = str_contains($needleText, $needle);
                break;
        }
        if ($hit) return $r;
    }
    return null;
}

/**
 * Main entry point: given a freshly-recorded incoming customer message,
 * check every active rule for this workspace + channel, fire the first
 * match, and log the outbound message so the inbox reflects it.
 *
 * Called from the webhook after the incoming row is committed.
 * Returns true if a rule fired.
 */
function keyword_auto_reply_handle(array $company, array $channel, int $conversationId, int $contactId, string $waId, string $messageText): bool
{
    if (trim($messageText) === '') return false;

    $companyId = (int)$company['id'];
    $channelId = (int)$channel['id'];
    $rules     = auto_replies_load($companyId, $channelId);
    if (!$rules) return false;

    $matched = auto_reply_match($rules, $messageText);
    if (!$matched) return false;

    $ruleId       = (int)$matched['id'];
    $cooldownMin  = (int)($matched['cooldown_min'] ?? 60);

    // Atomic cooldown claim, replaces the previous read-then-write pattern
    // that could double-fire under a concurrent-webhook race.
    // The cooldown bucket is floor(unix_ts / cooldown_seconds) so two
    // events within the same cooldown window collapse to the same bucket
    // number; the UNIQUE key on (rule, conversation, bucket) then makes
    // the INSERT reject one of them at the DB layer. cooldown_min=0 means
    // "no cooldown" - we still use a bucket-per-second so simultaneous
    // events dedupe but consecutive ones fire.
    $bucketSize = max(1, $cooldownMin * 60);
    $bucket     = (int)floor(time() / $bucketSize);
    $db         = aiserve_db();
    $matchedTxt = mb_substr($messageText, 0, 500);

    try {
        $claim = $db->prepare(
            'INSERT INTO auto_reply_fires
                (auto_reply_id, conversation_id, matched_text, cooldown_bucket)
             VALUES (?, ?, ?, ?)'
        );
        $claim->execute([$ruleId, $conversationId, $matchedTxt, $bucket]);
        $fireId = (int)$db->lastInsertId();
    } catch (PDOException $e) {
        // Errno 1062 = ER_DUP_ENTRY - another worker just fired in this
        // cooldown bucket. Bail out cleanly.
        if (($e->errorInfo[1] ?? 0) === 1062) return false;
        throw $e;
    }

    $replyText = trim((string)($matched['reply_text'] ?? ''));
    $mediaKind = (string)($matched['media_kind'] ?? 'none');
    $mediaPath = (string)($matched['media_path'] ?? '');
    $mediaName = (string)($matched['media_filename'] ?? '');
    $mediaMime = (string)($matched['media_mime'] ?? '');

    // Dispatch via provider - identical path an agent-sent reply would
    // take, so delivery ticks, error handling, and per-provider quirks
    // (DoH DNS pinning, media URL signing, template restrictions) are
    // all reused.
    // Loud error when the DB says a media file exists but disk disagrees.
    // Otherwise the rule just silently stops firing and the admin has no
    // idea why - happens on host migration, chmod, accidental rm, etc.
    if ($mediaKind !== 'none' && $mediaPath !== '' && !file_exists($mediaPath)) {
        error_log('[AiServe auto_reply] rule=' . $ruleId
            . ' media file missing on disk: ' . $mediaPath . ' - falling back to text-only');
    }
    if ($mediaKind !== 'none' && $mediaPath !== '' && file_exists($mediaPath)) {
        // For non-Cloud-API providers, provider_upload_media returns the
        // local path as the reference. For Cloud API it uploads to Meta.
        $ref = provider_upload_media($channel, $mediaPath, $mediaMime ?: 'application/octet-stream');
        if (!$ref['ok']) {
            error_log('[AiServe auto_reply] media upload failed rule=' . $ruleId
                . ' err=' . ($ref['error'] ?? '?'));
            // Fall back to text-only so the customer still gets something.
            $result = provider_send_text($channel, $waId, $replyText ?: 'Sending you our info shortly.');
            $recordedType = 'text';
            $recordedMedia = null;
        } else {
            $result = provider_send_media($channel, $waId, $mediaKind,
                $ref['media_ref'], $replyText ?: null, $mediaName ?: null, $mediaMime ?: null);
            $recordedType = $mediaKind;
            $recordedMedia = $mediaPath;
        }
    } else {
        if ($replyText === '') return false;
        $result = provider_send_text($channel, $waId, $replyText);
        $recordedType = 'text';
        $recordedMedia = null;
    }

    // Record the outbound message so it shows up in the agent's chat view.
    $ins = $db->prepare(
        'INSERT INTO messages
            (company_id, channel_id, conversation_id, contact_id,
             sender_type, sender_user_id, wa_message_id,
             direction, message_type, message_text,
             media_url, media_local_path, media_mime_type, media_filename,
             status, error_message, sent_at)
         VALUES (?, ?, ?, ?, "ai", NULL, ?, "outgoing", ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $companyId, $channelId, $conversationId, $contactId,
        $result['wa_message_id'] ?? null,
        $recordedType,
        $replyText,
        null,               // media_url filled by outbound provider path if needed
        $recordedMedia,     // local path for reference
        $mediaMime ?: null,
        $mediaName ?: null,
        $result['ok'] ? 'sent' : 'failed',
        $result['ok'] ? null : mb_substr((string)($result['error'] ?? ''), 0, 500),
        $result['ok'] ? date('Y-m-d H:i:s') : null,
    ]);
    $messageId = (int)$db->lastInsertId();

    // Only bump the conversation timestamps if the send actually succeeded.
    // If we clear last_message_at on a failed send, the awaiting-reply
    // chip disappears from the inbox even though the customer never got
    // their reply -> urgent messages silently hidden from the queue during
    // a Meta / AiServe outage. Failed sends should stay "awaiting".
    if ($result['ok']) {
        $db->prepare(
            'UPDATE conversations
             SET last_message_text = ?,
                 last_message_at   = NOW(),
                 first_response_at = COALESCE(first_response_at, NOW())
             WHERE id = ?'
        )->execute([mb_substr($replyText ?: '(auto-reply media)', 0, 500), $conversationId]);
    }

    // Stamp the fire row we already claimed with the message_id so admins
    // can jump from the audit list to the sent bubble.
    $db->prepare('UPDATE auto_reply_fires SET message_id = ? WHERE id = ?')
       ->execute([$messageId, $fireId]);

    // Bump rule stats.
    $db->prepare(
        'UPDATE auto_replies
         SET trigger_count = trigger_count + 1, last_triggered_at = NOW()
         WHERE id = ?'
    )->execute([$ruleId]);

    log_activity($companyId, null, 'auto_reply_fired', 'conversation', $conversationId,
        'rule=' . $ruleId . ' name=' . mb_substr((string)$matched['name'], 0, 100));

    return true;
}

/**
 * Deferred dispatch called from the webhook's post-response loop.
 * Loads the conversation + latest incoming message + channel from DB
 * and hands off to keyword_auto_reply_handle. Any exception is logged
 * and swallowed so a bad rule cannot break the webhook.
 */
function keyword_auto_reply_dispatch(array $company, int $conversationId): bool
{
    try {
        $db = aiserve_db();
        $conv = $db->prepare(
            'SELECT c.*, ct.wa_id AS contact_wa_id
             FROM conversations c
             JOIN contacts ct ON ct.id = c.contact_id
             WHERE c.id = ? AND c.company_id = ? LIMIT 1'
        );
        $conv->execute([$conversationId, (int)$company['id']]);
        $conv = $conv->fetch();
        if (!$conv) return false;
        if ($conv['status'] === 'closed') return false;

        $lastIn = $db->prepare(
            'SELECT message_text FROM messages
             WHERE conversation_id = ? AND direction = "incoming"
               AND sender_type = "customer"
             ORDER BY id DESC LIMIT 1'
        );
        $lastIn->execute([$conversationId]);
        $messageText = (string)($lastIn->fetchColumn() ?: '');
        if ($messageText === '') return false;

        $channel = channel_for_conversation($conv);
        if (!$channel) return false;

        return keyword_auto_reply_handle(
            $company, $channel,
            (int)$conv['id'], (int)$conv['contact_id'],
            (string)$conv['contact_wa_id'], $messageText
        );
    } catch (Throwable $e) {
        error_log('[AiServe auto_reply] dispatch failed conv=' . $conversationId
            . ' err=' . $e->getMessage());
        return false;
    }
}
