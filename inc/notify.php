<?php
/**
 * inc/notify.php — one place to fan out "new inbound message" notifications.
 *
 * Every path that inserts an incoming customer message
 * (webhook/evolution.php, webhook/whatsapp.php, api/widget_send.php,
 * webhook/instagram.php, webhook/facebook.php, …) calls
 * notify_new_inbound() with the conversation + message ids. This
 * helper resolves the assigned agent and fires a Web Push via
 * inc/push.php.
 *
 * Design choices for MVP:
 *
 *   - Push to the assigned agent only. Unassigned conversations get
 *     no push for now — the workspace alerts bell and the awaiting-
 *     reply tab-title indicator already cover that case.
 *
 *   - Never blocks the caller. Any exception is logged and swallowed;
 *     inbound message ingest must not fail because push failed.
 *
 *   - Deduplication happens at the SW's notification 'tag' field —
 *     the same conversation's tag replaces prior notifications
 *     rather than stacking.
 *
 *   - No per-conversation "agent is currently viewing this" heuristic
 *     yet — that needs a heartbeat we don't have. WhatsApp itself
 *     pushes when you have the app open on the same chat too, so
 *     this matches the mental model.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/push.php';

function notify_new_inbound(int $conversationId, int $messageId): void
{
    if ($conversationId <= 0 || $messageId <= 0) return;

    try {
        $db = aiserve_db();
        $stmt = $db->prepare(
            'SELECT c.id, c.company_id, c.assigned_user_id,
                    m.message_text, m.message_type,
                    ct.display_name, ct.wa_id,
                    co.name AS workspace_name
             FROM conversations c
             INNER JOIN messages m  ON m.id = ?
             INNER JOIN contacts ct ON ct.id = c.contact_id
             INNER JOIN companies co ON co.id = c.company_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$messageId, $conversationId]);
        $r = $stmt->fetch();
        if (!$r) return;

        $userId = (int)($r['assigned_user_id'] ?? 0);
        if ($userId <= 0) return; // MVP: unassigned → no push

        $senderName = trim((string)($r['display_name'] ?? '')) ?: (string)$r['wa_id'];
        $mtype      = (string)($r['message_type'] ?? 'text');
        $bodyText   = (string)($r['message_text'] ?? '');
        $preview    = notify_message_preview($mtype, $bodyText);

        $title = $senderName . ' · ' . (string)$r['workspace_name'];
        // Same tag per conversation so a burst of messages collapses
        // into one lock-screen entry.
        $tag   = 'conv-' . (int)$r['id'];
        $url   = '/inbox/chat.php?id=' . (int)$r['id'];

        push_send_to_user($userId, [
            'title' => $title,
            'body'  => $preview,
            'url'   => $url,
            'tag'   => $tag,
        ]);
    } catch (Throwable $e) {
        error_log('[AiServe notify_new_inbound] ' . $e->getMessage());
    }
}

function notify_message_preview(string $type, string $text): string
{
    $text = trim($text);
    switch ($type) {
        case 'audio':    return '🎙 Voice note';
        case 'image':    return '📷 Photo'  . ($text !== '' ? ' — ' . mb_substr($text, 0, 120) : '');
        case 'video':    return '🎞 Video'   . ($text !== '' ? ' — ' . mb_substr($text, 0, 120) : '');
        case 'document': return '📎 Document' . ($text !== '' ? ' — ' . mb_substr($text, 0, 120) : '');
        case 'location': return '📍 Location';
        case 'sticker':  return '🖼 Sticker';
        default:         return $text !== '' ? mb_substr($text, 0, 160) : '(new message)';
    }
}
