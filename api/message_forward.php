<?php
/**
 * POST /api/message_forward.php
 *
 * Body (form-encoded):
 *   message_id             : int — source message
 *   target_conversation_id : int — conversation to forward INTO
 *   _csrf                  : token
 *
 * Sends the source message's text as a fresh outgoing message on the
 * target conversation. Text-only forward (v1) — media is not carried
 * over. Both source and target must belong to the operator's workspace.
 *
 * Returns:
 *   { ok:true, target_conversation_id, wa_message_id }
 *   { ok:false, error:string }
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/channels.php';
require_once __DIR__ . '/../inc/provider.php';

$user = require_login();

if (!is_post()) {
    http_response_code(405);
    header('Content-Type: application/json');
    exit(json_encode(['ok' => false, 'error' => 'POST required.']));
}
csrf_check();
header('Content-Type: application/json');

$srcId    = (int)($_POST['message_id']             ?? 0);
$targetId = (int)($_POST['target_conversation_id'] ?? 0);
if ($srcId <= 0 || $targetId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'message_id and target_conversation_id required.']));
}

$companyId = (int)$user['company_id'];
$db        = aiserve_db();

// Load source msg + verify same workspace.
$srcStmt = $db->prepare(
    'SELECT m.id, m.message_text, m.conversation_id, m.company_id
     FROM messages m WHERE m.id = ? AND m.company_id = ? LIMIT 1'
);
$srcStmt->execute([$srcId, $companyId]);
$src = $srcStmt->fetch();
if (!$src) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Source message not found in this workspace.']));
}
$text = trim((string)$src['message_text']);
if ($text === '') {
    exit(json_encode(['ok' => false, 'error' => 'Source message has no text to forward.']));
}

// Load target conversation + its channel + contact wa_id.
$tgtStmt = $db->prepare(
    'SELECT c.id, c.channel_id, c.contact_id, c.company_id, c.status,
            ct.wa_id
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     WHERE c.id = ? AND c.company_id = ? LIMIT 1'
);
$tgtStmt->execute([$targetId, $companyId]);
$tgt = $tgtStmt->fetch();
if (!$tgt) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Target conversation not found in this workspace.']));
}
if ($tgt['status'] === 'closed') {
    exit(json_encode(['ok' => false, 'error' => 'Target conversation is closed — reopen it first.']));
}

$channel = channel_by_id((int)$tgt['channel_id']);
if (!$channel) {
    exit(json_encode(['ok' => false, 'error' => 'Target channel not found or disabled.']));
}

// Send + log — mirrors the api/send_message.php path minus AI drafting.
try {
    $result = provider_send_text($channel, (string)$tgt['wa_id'], $text);
} catch (Throwable $e) {
    error_log('[message_forward] provider_send_text: ' . $e->getMessage());
    exit(json_encode(['ok' => false, 'error' => 'Provider send failed: ' . $e->getMessage()]));
}

// Record the outgoing message.
try {
    $ins = $db->prepare(
        'INSERT INTO messages
            (company_id, channel_id, conversation_id, contact_id, sender_type,
             sender_user_id, wa_message_id, direction, message_type,
             message_text, status, sent_at)
         VALUES (?, ?, ?, ?, "agent",
                 ?, ?, "outgoing", "text",
                 ?, ?, ?)'
    );
    $ins->execute([
        $companyId, (int)$tgt['channel_id'], (int)$tgt['id'], (int)$tgt['contact_id'],
        (int)$user['id'],
        $result['wa_message_id'] ?? null,
        $text,
        $result['ok'] ? 'sent' : 'failed',
        $result['ok'] ? date('Y-m-d H:i:s') : null,
    ]);
    $newMsgId = (int)$db->lastInsertId();

    $db->prepare(
        'UPDATE conversations
         SET last_message_text = ?, last_message_at = NOW()
         WHERE id = ?'
    )->execute([mb_substr($text, 0, 500), (int)$tgt['id']]);

    log_activity($companyId, (int)$user['id'], 'message_forwarded',
        'message', $srcId, 'src=' . $srcId . ' → conv=' . $targetId . ' as msg=' . $newMsgId);

    echo json_encode([
        'ok' => $result['ok'],
        'target_conversation_id' => (int)$tgt['id'],
        'new_message_id' => $newMsgId,
        'wa_message_id'  => $result['wa_message_id'] ?? null,
        'error' => $result['ok'] ? null : (string)($result['error'] ?? 'Send failed'),
    ]);
} catch (Throwable $e) {
    error_log('[message_forward log] ' . $e->getMessage());
    exit(json_encode(['ok' => false, 'error' => 'Send succeeded but DB log failed: ' . $e->getMessage()]));
}
