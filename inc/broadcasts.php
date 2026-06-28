<?php
/**
 * Broadcast / blast helpers.
 *
 * The cron worker at cron/process_broadcasts.php trickles messages out at
 * batch_size per batch_interval_min minutes to avoid burst-rate bans from
 * Meta and the partner gateways. These helpers are the shared layer used by
 * both the admin pages (create, list, view) and the cron worker (load due
 * broadcasts, claim batch, record delivery).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/channels.php';

/**
 * Strip everything except digits. WhatsApp ids are pure-digit strings like
 * "60123456789" - no leading +, no spaces, no @s.whatsapp.net suffix.
 */
function broadcast_normalize_wa(string $raw): string
{
    $raw = trim($raw);
    if (str_contains($raw, '@')) $raw = explode('@', $raw)[0];
    return preg_replace('/[^0-9]/', '', $raw) ?: '';
}

/**
 * Parse a textarea of pasted numbers (comma, semicolon, newline, space
 * separated) into a deduped list of normalized wa_ids. Drops anything shorter
 * than 8 digits to filter out obvious typos.
 *
 * @return array<int,string>
 */
function broadcast_parse_numbers(string $blob): array
{
    $parts = preg_split('/[\s,;]+/', $blob) ?: [];
    $out   = [];
    $seen  = [];
    foreach ($parts as $p) {
        $wa = broadcast_normalize_wa($p);
        if (strlen($wa) < 8) continue;
        if (isset($seen[$wa])) continue;
        $seen[$wa] = true;
        $out[] = $wa;
    }
    return $out;
}

/**
 * Estimate "this blast will take X minutes" given the total recipient count,
 * batch size, and interval. Returns minutes (rounded up).
 */
function broadcast_eta_minutes(int $totalRecipients, int $batchSize, int $intervalMin): int
{
    if ($totalRecipients <= 0 || $batchSize <= 0 || $intervalMin <= 0) return 0;
    $batches = (int)ceil($totalRecipients / max(1, $batchSize));
    // First batch goes out immediately, so total wait = (batches - 1) × interval.
    return max(0, ($batches - 1) * $intervalMin);
}

/**
 * Resolve contact_id + conversation_id for one recipient. Creates the contact
 * if missing, finds an open conversation, otherwise opens a new one.
 *
 * Returns ['contact_id' => int, 'conversation_id' => int].
 */
function broadcast_resolve_target(PDO $db, int $companyId, int $channelId, string $waId, ?string $displayName = null): array
{
    $stmt = $db->prepare('SELECT id FROM contacts WHERE company_id = ? AND wa_id = ? LIMIT 1');
    $stmt->execute([$companyId, $waId]);
    $contactId = (int)($stmt->fetchColumn() ?: 0);
    if ($contactId === 0) {
        $ins = $db->prepare(
            'INSERT INTO contacts (company_id, wa_id, phone, display_name, last_message_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $ins->execute([$companyId, $waId, $waId, $displayName ?: $waId]);
        $contactId = (int)$db->lastInsertId();
    }

    $stmt = $db->prepare(
        'SELECT id FROM conversations
         WHERE company_id = ? AND contact_id = ? AND status IN ("open","pending","escalated")
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$companyId, $contactId]);
    $conversationId = (int)($stmt->fetchColumn() ?: 0);
    if ($conversationId === 0) {
        $ins = $db->prepare(
            'INSERT INTO conversations
                (company_id, channel_id, contact_id, status,
                 last_message_at, unread_count)
             VALUES (?, ?, ?, "open", NOW(), 0)'
        );
        $ins->execute([$companyId, $channelId, $contactId]);
        $conversationId = (int)$db->lastInsertId();
    }

    return ['contact_id' => $contactId, 'conversation_id' => $conversationId];
}

/**
 * Record one outbound blast message in the messages table after the provider
 * accepted (or rejected) it. Mirrors how api/send_message.php writes its row
 * so the blast bubble shows up in the customer's chat thread just like a
 * normal agent reply.
 *
 * Returns the messages.id.
 */
function broadcast_record_message(PDO $db, int $companyId, int $conversationId, int $contactId, int $senderUserId, string $text, array $result): int
{
    $ins = $db->prepare(
        'INSERT INTO messages
            (company_id, conversation_id, contact_id, sender_type, sender_user_id,
             direction, message_type, message_text,
             wa_message_id, status, error_message, sent_at)
         VALUES (?, ?, ?, "agent", ?, "outgoing", "text", ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $companyId, $conversationId, $contactId, $senderUserId,
        $text,
        $result['wa_message_id'] ?? null,
        $result['ok'] ? 'sent' : 'failed',
        $result['ok'] ? null : mb_substr((string)($result['error'] ?? ''), 0, 500),
        $result['ok'] ? date('Y-m-d H:i:s') : null,
    ]);
    $messageId = (int)$db->lastInsertId();

    // Bump the conversation's last_message timestamps so the inbox row floats
    // up and the awaiting-reply state stays correct.
    $db->prepare(
        'UPDATE conversations
         SET last_message_text = ?, last_message_at = NOW()
         WHERE id = ?'
    )->execute([mb_substr($text, 0, 500), $conversationId]);

    return $messageId;
}

/**
 * Return broadcasts that are ready to send their next batch right now.
 * A broadcast is ready when:
 *   - status = 'running'
 *   - either last_batch_at IS NULL or last_batch_at <= NOW() - interval
 *   - has at least one queued recipient
 *
 * @return array<int,array>
 */
function broadcasts_due_for_batch(PDO $db): array
{
    return $db->query(
        'SELECT b.*
         FROM broadcasts b
         WHERE b.status = "running"
           AND (b.last_batch_at IS NULL
                OR b.last_batch_at <= NOW() - INTERVAL b.batch_interval_min MINUTE)
           AND EXISTS (
             SELECT 1 FROM broadcast_recipients r
             WHERE r.broadcast_id = b.id AND r.status = "queued"
           )
         ORDER BY b.id ASC'
    )->fetchAll();
}

/**
 * Compute a short human-readable progress label like "12 / 50 sent · 2 failed".
 */
function broadcast_progress_label(array $b): string
{
    $sent   = (int)($b['sent_count']   ?? 0);
    $failed = (int)($b['failed_count'] ?? 0);
    $total  = (int)($b['total_recipients'] ?? 0);
    $bits   = [$sent . ' / ' . $total . ' sent'];
    if ($failed > 0) $bits[] = $failed . ' failed';
    return implode(' · ', $bits);
}
