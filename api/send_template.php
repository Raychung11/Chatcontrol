<?php
/**
 * POST /api/send_template.php
 *
 * Send an approved WhatsApp template message. Required for replies after
 * the 24-hour customer service window has expired.
 *
 * Body (form):
 *   conversation_id : int
 *   template_id     : int  (must belong to user's company, status=approved)
 *   var[]           : repeated body variable values, ordered
 *   _csrf           : token
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

$conversationId = (int)($_POST['conversation_id'] ?? 0);
$templateId     = (int)($_POST['template_id']     ?? 0);
$vars           = $_POST['var'] ?? [];

if (!is_array($vars)) $vars = [];
$vars = array_values(array_map(fn($v) => trim((string)$v), $vars));

if ($conversationId <= 0 || $templateId <= 0) {
    json_response(['ok' => false, 'error' => 'conversation_id and template_id required.'], 400);
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
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

$tStmt = $db->prepare(
    'SELECT * FROM message_templates WHERE id = ? AND company_id = ? LIMIT 1'
);
$tStmt->execute([$templateId, (int)$user['company_id']]);
$tpl = $tStmt->fetch();
if (!$tpl) {
    json_response(['ok' => false, 'error' => 'Template not found.'], 404);
}
if ($tpl['status'] !== 'approved') {
    json_response(['ok' => false, 'error' => 'Template is not approved.'], 400);
}

// Render preview text by substituting {{1}}, {{2}}, ... in body
$preview = (string)$tpl['body_text'];
foreach ($vars as $i => $v) {
    $preview = str_replace('{{' . ($i + 1) . '}}', $v, $preview);
}

$company = load_company_settings((int)$user['company_id']);
if (!$company) {
    json_response(['ok' => false, 'error' => 'Company settings missing.'], 500);
}
$channel = channel_for_conversation($conv);
if (!$channel) {
    json_response(['ok' => false, 'error' => 'This conversation has no channel.'], 500);
}

// Persist pending row
$ins = $db->prepare(
    'INSERT INTO messages
       (company_id, channel_id, conversation_id, contact_id, sender_type, sender_user_id,
        direction, message_type, template_name, message_text, status)
     VALUES (?, ?, ?, ?, "agent", ?, "outgoing", "template", ?, ?, "pending")'
);
$ins->execute([
    (int)$user['company_id'], (int)$channel['id'], $conversationId, (int)$conv['contact_id'],
    (int)$user['id'], $tpl['template_name'], $preview,
]);
$messageRowId = (int)$db->lastInsertId();

if (!provider_supports_templates($channel)) {
    $db->prepare('UPDATE messages SET status="failed", error_message=? WHERE id=?')
       ->execute(['Templates only supported on Cloud API provider.', $messageRowId]);
    json_response(['ok' => false, 'error' => 'Templates are only available with the Meta Cloud API provider.'], 400);
}

$result = provider_send_template(
    $channel,
    (string)$conv['wa_id'],
    (string)$tpl['template_name'],
    (string)$tpl['language'],
    $vars
);

if ($result['ok']) {
    $db->prepare(
        'UPDATE messages SET status="sent", wa_message_id=?, sent_at=NOW() WHERE id=?'
    )->execute([$result['wa_message_id'], $messageRowId]);

    $db->prepare(
        'UPDATE conversations
         SET last_message_text = ?, last_message_at = NOW(),
             unread_count = 0,
             first_response_at = COALESCE(first_response_at, NOW())
         WHERE id = ?'
    )->execute([mb_substr($preview, 0, 500), $conversationId]);

    log_activity((int)$user['company_id'], (int)$user['id'], 'template_sent',
        'conversation', $conversationId, $tpl['template_name']);

    json_response([
        'ok'            => true,
        'message_id'    => $messageRowId,
        'wa_message_id' => $result['wa_message_id'],
        'preview'       => $preview,
    ]);
}

$db->prepare('UPDATE messages SET status="failed", error_message=? WHERE id=?')
   ->execute([substr((string)$result['error'], 0, 500), $messageRowId]);

log_activity((int)$user['company_id'], (int)$user['id'], 'template_send_failed',
    'conversation', $conversationId, $result['error']);

json_response([
    'ok'         => false,
    'error'      => $result['error'] ?: 'Failed to send template.',
    'message_id' => $messageRowId,
    'http_code'  => $result['http_code'],
], 502);
