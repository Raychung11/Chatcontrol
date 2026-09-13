<?php
/**
 * POST /api/conversation_new.php
 *
 * Start a conversation with a customer who has NOT messaged us yet.
 * Called from /inbox/new_chat.php.
 *
 * Inputs (form-encoded):
 *   channel_id   : int, required, must belong to caller's workspace
 *   wa_id        : phone in country-code format e.g. 60123456789
 *   display_name : optional agent-provided name for the new contact
 *   Depending on channel provider:
 *     - Cloud API  : template_id (int) + var_1, var_2, ... form fields
 *     - Others     : message_text (string)
 *
 * On success returns { ok, conversation_id } — the caller (JS) redirects
 * the browser to /inbox/chat.php?id=<conversation_id>.
 *
 * Cloud API 24-hour window rule is enforced by requiring a template on
 * this path; free text to a fresh number would fail Meta-side anyway.
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
csrf_check();

$companyId  = (int)$user['company_id'];
$db         = aiserve_db();

$channelId  = (int)($_POST['channel_id']  ?? 0);
$rawWaId    = (string)($_POST['wa_id']    ?? '');
$displayNm  = trim((string)($_POST['display_name'] ?? ''));

// -------------------- Phone normalization --------------------
// Keep only digits. Drop a leading + if present. Do NOT auto-add country
// code — we trust the operator to enter the full E.164 without the +
// (matches the inbound webhook's from field). Anything shorter than 8
// digits is almost certainly a typo.
$waId = preg_replace('/\D+/', '', $rawWaId);
if (strlen($waId) < 8 || strlen($waId) > 20) {
    json_response(['ok' => false, 'error' => 'Enter the number in international format, digits only (e.g. 60123456789).'], 400);
}

// -------------------- Channel validation --------------------
$chStmt = $db->prepare(
    'SELECT * FROM channels
     WHERE id = ? AND company_id = ? AND status = "active" LIMIT 1'
);
$chStmt->execute([$channelId, $companyId]);
$channel = $chStmt->fetch();
if (!$channel) {
    json_response(['ok' => false, 'error' => 'Pick a channel that belongs to your workspace.'], 400);
}

// -------------------- Contact upsert --------------------
$contactStmt = $db->prepare(
    'SELECT id, display_name, profile_name FROM contacts
     WHERE company_id = ? AND wa_id = ? AND platform = "whatsapp" LIMIT 1'
);
$contactStmt->execute([$companyId, $waId]);
$contact = $contactStmt->fetch();

if ($contact) {
    $contactId = (int)$contact['id'];
    if ($displayNm !== '' && $displayNm !== (string)($contact['display_name'] ?? '')) {
        $db->prepare('UPDATE contacts SET display_name = ? WHERE id = ?')
           ->execute([$displayNm, $contactId]);
    }
} else {
    $ins = $db->prepare(
        'INSERT INTO contacts (company_id, wa_id, platform, phone, display_name, last_message_at)
         VALUES (?, ?, "whatsapp", ?, ?, NOW())'
    );
    $ins->execute([$companyId, $waId, $waId, $displayNm !== '' ? $displayNm : $waId]);
    $contactId = (int)$db->lastInsertId();
}

// -------------------- Existing open conversation? --------------------
// If this contact already has an open conversation on this channel, we
// don't want to double-create — just append the message to it.
$existing = $db->prepare(
    'SELECT id FROM conversations
     WHERE company_id = ? AND contact_id = ? AND channel_id = ?
       AND status IN ("open","pending","escalated")
     ORDER BY id DESC LIMIT 1'
);
$existing->execute([$companyId, $contactId, (int)$channel['id']]);
$conversationId = (int)($existing->fetchColumn() ?: 0);

$isCloud = provider_name($channel) === 'cloud_api';

// -------------------- Cloud API: template branch --------------------
if ($isCloud) {
    $templateId = (int)($_POST['template_id'] ?? 0);
    if ($templateId <= 0) {
        json_response(['ok' => false, 'error' => 'Cloud API requires an approved template for the first message. Pick one.'], 400);
    }
    $tStmt = $db->prepare(
        'SELECT * FROM message_templates WHERE id = ? AND company_id = ? LIMIT 1'
    );
    $tStmt->execute([$templateId, $companyId]);
    $tpl = $tStmt->fetch();
    if (!$tpl) {
        json_response(['ok' => false, 'error' => 'Template not found.'], 404);
    }
    if ($tpl['status'] !== 'approved') {
        json_response(['ok' => false, 'error' => 'Template is not approved by Meta.'], 400);
    }

    // Collect var_1, var_2, ... from the form in order.
    $vars = [];
    preg_match_all('/\{\{(\d+)\}\}/', (string)$tpl['body_text'], $m);
    $needed = $m[1] ? max(array_map('intval', $m[1])) : 0;
    for ($i = 1; $i <= $needed; $i++) {
        $v = trim((string)($_POST['var_' . $i] ?? ''));
        if ($v === '') {
            json_response(['ok' => false, 'error' => 'Fill in variable {{' . $i . '}}.'], 400);
        }
        $vars[] = $v;
    }

    $preview = (string)$tpl['body_text'];
    foreach ($vars as $i => $v) {
        $preview = str_replace('{{' . ($i + 1) . '}}', $v, $preview);
    }

    // Create conversation if new.
    if ($conversationId <= 0) {
        $conversationId = conversation_create($db, $companyId, (int)$channel['id'], $contactId, $preview);
    }

    // Persist message row before send so we log even on failure.
    $ins = $db->prepare(
        'INSERT INTO messages
            (company_id, channel_id, conversation_id, contact_id, sender_type, sender_user_id,
             direction, message_type, template_name, message_text, status)
         VALUES (?, ?, ?, ?, "agent", ?, "outgoing", "template", ?, ?, "pending")'
    );
    $ins->execute([
        $companyId, (int)$channel['id'], $conversationId, $contactId,
        (int)$user['id'], $tpl['template_name'], $preview,
    ]);
    $messageRowId = (int)$db->lastInsertId();

    $result = provider_send_template(
        $channel, $waId,
        (string)$tpl['template_name'],
        (string)$tpl['language'],
        $vars
    );
    finalize_send($db, $messageRowId, $conversationId, $preview, $result);

    if (!$result['ok']) {
        json_response([
            'ok'             => false,
            'error'          => $result['error'] ?? 'Send failed.',
            'conversation_id'=> $conversationId,
        ], 400);
    }

    log_activity($companyId, (int)$user['id'], 'conversation_started',
        'conversation', $conversationId,
        'proactive start via template ' . $tpl['template_name']);

    json_response(['ok' => true, 'conversation_id' => $conversationId]);
}

// -------------------- Evolution / Chatbot: free text branch --------------------
$messageText = trim((string)($_POST['message_text'] ?? ''));
if ($messageText === '') {
    json_response(['ok' => false, 'error' => 'Type a message to send.'], 400);
}
if (mb_strlen($messageText) > 4000) {
    json_response(['ok' => false, 'error' => 'Message too long (max 4000).'], 400);
}

if ($conversationId <= 0) {
    $conversationId = conversation_create($db, $companyId, (int)$channel['id'], $contactId, $messageText);
}

$ins = $db->prepare(
    'INSERT INTO messages
        (company_id, channel_id, conversation_id, contact_id, sender_type, sender_user_id,
         direction, message_type, message_text, status)
     VALUES (?, ?, ?, ?, "agent", ?, "outgoing", "text", ?, "pending")'
);
$ins->execute([
    $companyId, (int)$channel['id'], $conversationId, $contactId,
    (int)$user['id'], $messageText,
]);
$messageRowId = (int)$db->lastInsertId();

$result = provider_send_text($channel, $waId, $messageText);
finalize_send($db, $messageRowId, $conversationId, $messageText, $result);

if (!$result['ok']) {
    json_response([
        'ok'             => false,
        'error'          => $result['error'] ?? 'Send failed.',
        'conversation_id'=> $conversationId,
    ], 400);
}

log_activity($companyId, (int)$user['id'], 'conversation_started',
    'conversation', $conversationId, 'proactive start via free text');

json_response(['ok' => true, 'conversation_id' => $conversationId]);


// -------------------- helpers --------------------

/**
 * Create a fresh conversation row. Kept as a helper because both send
 * branches call into it identically — we open the conversation BEFORE
 * the provider call so even a failed send leaves the customer visible
 * in the inbox with a "failed" message row (agent can retry).
 */
function conversation_create(PDO $db, int $companyId, int $channelId, int $contactId, string $preview): int
{
    $ins = $db->prepare(
        'INSERT INTO conversations
            (company_id, channel_id, contact_id, status, last_message_text, last_message_at, unread_count)
         VALUES (?, ?, ?, "open", ?, NOW(), 0)'
    );
    $ins->execute([$companyId, $channelId, $contactId, mb_substr($preview, 0, 500)]);
    return (int)$db->lastInsertId();
}

/**
 * Common post-send bookkeeping: mark the message row sent/failed and
 * bump conversation.last_message_* on success.
 */
function finalize_send(PDO $db, int $messageId, int $conversationId, string $preview, array $result): void
{
    if ($result['ok']) {
        $db->prepare(
            'UPDATE messages SET status="sent", wa_message_id=?, sent_at=NOW() WHERE id=?'
        )->execute([$result['wa_message_id'] ?? null, $messageId]);
        $db->prepare(
            'UPDATE conversations
             SET last_message_text = ?, last_message_at = NOW(),
                 first_response_at = COALESCE(first_response_at, NOW())
             WHERE id = ?'
        )->execute([mb_substr($preview, 0, 500), $conversationId]);
    } else {
        $db->prepare(
            'UPDATE messages SET status="failed", error_message=? WHERE id=?'
        )->execute([mb_substr((string)($result['error'] ?? 'send failed'), 0, 500), $messageId]);
    }
}
