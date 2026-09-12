<?php
/**
 * POST /api/widget_send.php
 *
 * Customer sent a text message from the web chat widget.
 *
 * Params:
 *   session_token : required
 *   text          : required, max 4000 chars
 *
 * Returns { ok, message_id }.
 *
 * On the server side this is the moral equivalent of a WhatsApp
 * inbound webhook: resolve session -> contact -> channel, create
 * conversation if none, insert an incoming message row, then dispatch
 * to the flow engine so F&B / qualification / any active flow with a
 * matching trigger picks it up transparently.
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/channels.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST required']));
}

$sessionToken = trim((string)($_POST['session_token'] ?? ''));
$text         = trim((string)($_POST['text'] ?? ''));

if (!preg_match('/^[a-f0-9]{48}$/', $sessionToken)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Bad session token']));
}
if ($text === '') {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Empty message']));
}
if (mb_strlen($text) > 4000) {
    $text = mb_substr($text, 0, 4000);
}

$db = aiserve_db();
$st = $db->prepare(
    'SELECT s.*, c.company_id, ct.wa_id,
            nc.label        AS nfc_label,
            nc.table_number AS nfc_table_number,
            nc.branch_id    AS nfc_branch_id,
            nc.campaign     AS nfc_campaign
     FROM web_chat_sessions s
     INNER JOIN channels  c  ON c.id  = s.channel_id
     INNER JOIN contacts  ct ON ct.id = s.contact_id
     LEFT  JOIN nfc_cards nc ON nc.id = s.nfc_card_id
     WHERE s.session_token = ? AND s.expires_at > NOW() LIMIT 1'
);
$st->execute([$sessionToken]);
$sess = $st->fetch();
if (!$sess) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Session expired — reload the page']));
}

$companyId = (int)$sess['company_id'];
$channelId = (int)$sess['channel_id'];
$contactId = (int)$sess['contact_id'];
$waId      = (string)$sess['wa_id'];

try {
    // Find or create an open conversation for this contact on this channel.
    $q = $db->prepare(
        'SELECT id, status FROM conversations
         WHERE company_id = ? AND contact_id = ? AND channel_id = ?
           AND status IN ("open","pending","escalated")
         ORDER BY id DESC LIMIT 1'
    );
    $q->execute([$companyId, $contactId, $channelId]);
    $conv = $q->fetch();

    $previewText = mb_substr($text, 0, 500);
    $isNewConversation = false;

    if (!$conv) {
        $ins = $db->prepare(
            'INSERT INTO conversations
                (company_id, channel_id, contact_id, status,
                 last_message_text, last_message_at,
                 last_customer_message_at, unread_count)
             VALUES (?, ?, ?, "open", ?, NOW(), NOW(), 1)'
        );
        $ins->execute([$companyId, $channelId, $contactId, $previewText]);
        $conversationId = (int)$db->lastInsertId();
        $isNewConversation = true;

        // Stash any context (URL ?t=, or a decorated line for an NFC
        // tap that carries structured metadata) as an internal system
        // note on the new conversation so agents see it immediately.
        // Best-effort — the note is a nicety, if the internal_notes
        // schema doesn't allow NULL user_id, we swallow the error and
        // move on rather than fail the entire message delivery.
        $noteLines = [];
        if (!empty($sess['nfc_card_id'])) {
            $nfcLine = '📇 Tapped NFC card';
            if (!empty($sess['nfc_label'])) {
                $nfcLine .= ': ' . (string)$sess['nfc_label'];
            }
            $noteLines[] = $nfcLine;
            if (!empty($sess['nfc_table_number'])) {
                $noteLines[] = '🪑 Table ' . (int)$sess['nfc_table_number'];
            }
            if (!empty($sess['nfc_campaign'])) {
                $noteLines[] = '🎯 Campaign: ' . (string)$sess['nfc_campaign'];
            }
        }
        if (!empty($sess['context'])) {
            $noteLines[] = 'Context: ' . (string)$sess['context'];
        }
        if ($noteLines) {
            try {
                $db->prepare(
                    'INSERT INTO internal_notes (company_id, conversation_id, user_id, note_text)
                     VALUES (?, ?, NULL, ?)'
                )->execute([$companyId, $conversationId, implode("\n", $noteLines)]);
            } catch (Throwable $noteErr) {
                error_log('[AiServe widget_send internal_notes] ' . $noteErr->getMessage());
            }
        }

        // NFC-card branch routing: stamp the customer's contact with
        // the card's branch_id (only if the contact isn't already
        // pinned to a different branch — respect prior manual assign)
        // then defer to the shared branch_rotation_apply() helper so
        // widget conversations follow the same rotation as WhatsApp
        // inbounds. Best-effort — never let a routing hiccup drop
        // the message.
        if (!empty($sess['nfc_branch_id'])) {
            try {
                $db->prepare(
                    'UPDATE contacts SET branch_id = ?
                     WHERE id = ? AND (branch_id IS NULL OR branch_id = 0)'
                )->execute([(int)$sess['nfc_branch_id'], $contactId]);
                require_once __DIR__ . '/../inc/branch_rotation.php';
                branch_rotation_apply($db, $conversationId);
            } catch (Throwable $rrErr) {
                error_log('[AiServe widget_send branch_rotation] ' . $rrErr->getMessage());
            }
        }
    } else {
        $conversationId = (int)$conv['id'];
        $newStatus = ($conv['status'] === 'closed') ? 'open' : $conv['status'];
        $db->prepare(
            'UPDATE conversations
             SET status = ?, last_message_text = ?, last_message_at = NOW(),
                 last_customer_message_at = NOW(),
                 unread_count = unread_count + 1
             WHERE id = ?'
        )->execute([$newStatus, $previewText, $conversationId]);
    }

    // Link the session to the conversation on first message.
    if (empty($sess['conversation_id'])) {
        $db->prepare('UPDATE web_chat_sessions SET conversation_id = ?, last_seen_at = NOW() WHERE session_token = ?')
           ->execute([$conversationId, $sessionToken]);
    } else {
        $db->prepare('UPDATE web_chat_sessions SET last_seen_at = NOW() WHERE session_token = ?')
           ->execute([$sessionToken]);
    }

    // Message row — same shape a WhatsApp inbound would take.
    $wcMsgId = 'wc_' . bin2hex(random_bytes(8));
    $mi = $db->prepare(
        'INSERT INTO messages
            (company_id, channel_id, conversation_id, contact_id, sender_type,
             wa_message_id, direction, message_type, message_text, status, created_at)
         VALUES (?, ?, ?, ?, "customer", ?, "incoming", "text", ?, "received", NOW())'
    );
    $mi->execute([$companyId, $channelId, $conversationId, $contactId, $wcMsgId, $text]);
    $messageId = (int)$db->lastInsertId();

    // Dispatch to the flow engine — same as WhatsApp inbound. Any flow
    // with a matching trigger (new_conversation OR keyword) will fire.
    try {
        require_once __DIR__ . '/../inc/flow_engine.php';
        flow_engine_dispatch($db, $companyId, $conversationId, $text);
    } catch (Throwable $e) {
        error_log('[AiServe widget_send flow_engine] ' . $e->getMessage());
    }

    // PWA push to the assigned agent — same shape as the Evolution /
    // Meta paths so the phone lock screen looks identical regardless
    // of which channel the customer used.
    require_once __DIR__ . '/../inc/notify.php';
    notify_new_inbound($conversationId, $messageId);

    echo json_encode(['ok' => true, 'message_id' => $messageId, 'conversation_id' => $conversationId]);
} catch (Throwable $e) {
    // Surface the real message so /admin/webchat_debug.php and the
    // widget's error banner both show the actual cause. Not a leak —
    // this endpoint is only reachable with a valid session and the
    // error text is a DB / schema description, not user data.
    error_log('[AiServe widget_send] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]));
}
