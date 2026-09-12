<?php
/**
 * POST /api/send_message.php
 * Body (form-encoded or JSON):
 *   conversation_id : int
 *   message_text    : string
 *   _csrf           : token
 *
 * Sends a WhatsApp text message via Meta Cloud API and persists the message row.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/provider.php';
require_once __DIR__ . '/../inc/whatsapp_api.php';
require_once __DIR__ . '/../inc/channels.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}

// Accept JSON or form input
$inputJson = null;
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ctype, 'application/json') !== false) {
    $inputJson = json_decode(file_get_contents('php://input') ?: '', true);
    if (is_array($inputJson) && !empty($inputJson['_csrf'])) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $inputJson['_csrf'];
    }
}
csrf_check();

$conversationId = (int)($inputJson['conversation_id'] ?? $_POST['conversation_id'] ?? 0);
$messageText    = trim((string)($inputJson['message_text']    ?? $_POST['message_text']    ?? ''));
// Optional: the AI's original draft (if the agent used the composer's
// suggestion). Captured so we can diff draft vs sent and feed it back
// into the KB's style-rule learning cron.
$aiDraft        = trim((string)($inputJson['ai_draft']        ?? $_POST['ai_draft']        ?? ''));

if ($conversationId <= 0 || $messageText === '') {
    json_response(['ok' => false, 'error' => 'conversation_id and message_text are required.'], 400);
}
if (mb_strlen($messageText) > 4000) {
    json_response(['ok' => false, 'error' => 'Message too long (max 4000 characters).'], 400);
}

$db = aiserve_db();

$stmt = $db->prepare(
    'SELECT c.*, ct.wa_id, ct.display_name
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     WHERE c.id = ? AND c.company_id = ? LIMIT 1'
);
$stmt->execute([$conversationId, (int)$user['company_id']]);
$conv = $stmt->fetch();
if (!$conv) {
    json_response(['ok' => false, 'error' => 'Conversation not found.'], 404);
}
if (!user_can_view_conversation($user, $conv)) {
    json_response(['ok' => false, 'error' => 'You do not have access to this conversation.'], 403);
}
if ($conv['status'] === 'closed') {
    json_response(['ok' => false, 'error' => 'Conversation is closed. Reopen it first.'], 400);
}

$company = load_company_settings((int)$user['company_id']);
if (!$company) {
    json_response(['ok' => false, 'error' => 'Company settings missing.'], 500);
}
$channel = channel_for_conversation($conv);
if (!$channel) {
    json_response(['ok' => false, 'error' => 'This conversation has no channel. Configure a channel in Admin → Channels.'], 500);
}

// 24-hour service window only applies on the official Cloud API.
if (provider_enforces_24h_window($channel) && !is_within_service_window($conv['service_window_expires_at'])) {
    json_response([
        'ok'    => false,
        'error' => '24-hour reply window expired. Please send an approved template message instead.',
        'window_expired' => true,
    ], 400);
}

// 1. Save pending outgoing message
$ins = $db->prepare(
    'INSERT INTO messages
        (company_id, channel_id, conversation_id, contact_id, sender_type, sender_user_id,
         direction, message_type, message_text, status)
     VALUES (?, ?, ?, ?, "agent", ?, "outgoing", "text", ?, "pending")'
);
$ins->execute([
    (int)$user['company_id'], (int)$channel['id'], $conversationId, (int)$conv['contact_id'],
    (int)$user['id'], $messageText,
]);
$messageRowId = (int)$db->lastInsertId();

// 2. Call provider (Meta Cloud API or Evolution)
$result = provider_send_text($channel, (string)$conv['wa_id'], $messageText);

// 3. Persist outcome
if ($result['ok']) {
    $upd = $db->prepare(
        'UPDATE messages
         SET status = "sent", wa_message_id = ?, sent_at = NOW()
         WHERE id = ?'
    );
    $upd->execute([$result['wa_message_id'], $messageRowId]);

    // First-response timestamp
    $firstResp = $db->prepare(
        'UPDATE conversations
         SET last_message_text = ?, last_message_at = NOW(),
             unread_count = 0,
             first_response_at = COALESCE(first_response_at, NOW())
         WHERE id = ?'
    );
    $firstResp->execute([mb_substr($messageText, 0, 500), $conversationId]);

    log_activity((int)$user['company_id'], (int)$user['id'], 'message_sent',
        'conversation', $conversationId, 'Agent reply sent');

    // AI-draft learning capture — save (customer message, ai draft,
    // agent's actual send) whenever an AI draft was provided. Best-
    // effort: never blocks the send. Pre-phase47 workspaces silently
    // skip (table doesn't exist yet).
    if ($aiDraft !== '') {
        try {
            $lastIn = $db->prepare(
                "SELECT message_text FROM messages
                 WHERE conversation_id = ? AND direction = 'incoming'
                   AND message_text IS NOT NULL AND message_text <> ''
                 ORDER BY id DESC LIMIT 1"
            );
            $lastIn->execute([$conversationId]);
            $custMsg = (string)($lastIn->fetchColumn() ?: '');
            if ($custMsg !== '') {
                $dist = levenshtein(
                    mb_substr($aiDraft, 0, 255),
                    mb_substr($messageText, 0, 255)
                );
                $db->prepare(
                    'INSERT INTO ai_edit_examples
                        (company_id, conversation_id, customer_message,
                         ai_draft, agent_sent, edit_distance, agent_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    (int)$user['company_id'], $conversationId,
                    mb_substr($custMsg,    0, 2000),
                    mb_substr($aiDraft,    0, 2000),
                    mb_substr($messageText,0, 2000),
                    $dist, (int)$user['id'],
                ]);
            }
        } catch (Throwable $e) {
            error_log('[AiServe ai_edit_examples] ' . $e->getMessage());
        }
    }

    json_response([
        'ok'             => true,
        'message_id'     => $messageRowId,
        'wa_message_id'  => $result['wa_message_id'],
    ]);
}

// Failure path
$upd = $db->prepare('UPDATE messages SET status = "failed", error_message = ? WHERE id = ?');
$upd->execute([substr((string)$result['error'], 0, 500), $messageRowId]);

log_activity((int)$user['company_id'], (int)$user['id'], 'message_send_failed',
    'conversation', $conversationId, $result['error']);

json_response([
    'ok'         => false,
    'error'      => $result['error'] ?: 'Failed to send message.',
    'message_id' => $messageRowId,
    'http_code'  => $result['http_code'],
], 502);
