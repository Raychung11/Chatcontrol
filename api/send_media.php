<?php
/**
 * POST /api/send_media.php
 * Body (form):
 *   conversation_id : int
 *   media_id        : string (Meta media id from /api/upload_media.php)
 *   mime_type       : string
 *   kind            : image|video|audio|document
 *   filename        : string (original name, used for documents)
 *   caption         : string (optional)
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
$mediaId        = trim((string)($_POST['media_id']  ?? ''));
$mime           = trim((string)($_POST['mime_type'] ?? ''));
$kind           = (string)($_POST['kind']     ?? '');
$filename       = (string)($_POST['filename'] ?? '');
$caption        = trim((string)($_POST['caption']  ?? ''));
$localPath      = (string)($_POST['local_path'] ?? '');

if ($conversationId <= 0 || $mediaId === '' || !in_array($kind, ['image', 'video', 'audio', 'document'], true)) {
    json_response(['ok' => false, 'error' => 'conversation_id, media_id and kind are required.'], 400);
}
if (mb_strlen($caption) > 1024) {
    $caption = mb_substr($caption, 0, 1024);
}

$db = aiserve_db();
$stmt = $db->prepare(
    'SELECT c.*, ct.wa_id FROM conversations c
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
if ($conv['status'] === 'closed') {
    json_response(['ok' => false, 'error' => 'Conversation is closed.'], 400);
}

$company = load_company_settings((int)$user['company_id']);
if (!$company) {
    json_response(['ok' => false, 'error' => 'Company settings missing.'], 500);
}
$channel = channel_for_conversation($conv);
if (!$channel) {
    json_response(['ok' => false, 'error' => 'This conversation has no channel.'], 500);
}

if (provider_enforces_24h_window($channel) && !is_within_service_window($conv['service_window_expires_at'])) {
    json_response(['ok' => false, 'error' => '24-hour reply window expired. Send a template instead.', 'window_expired' => true], 400);
}

// Verify any provided local_path is inside this company's uploads dir
$safeLocalPath = null;
if ($localPath !== '') {
    $base = realpath(__DIR__ . '/../uploads/' . (int)$user['company_id']);
    $real = realpath($localPath);
    if ($base && $real && str_starts_with($real, $base . '/')) {
        $safeLocalPath = $real;
    }
}

// Persist pending message row first
$ins = $db->prepare(
    'INSERT INTO messages
        (company_id, channel_id, conversation_id, contact_id, sender_type, sender_user_id,
         direction, message_type, message_text, media_mime_type, media_filename, media_id, media_local_path, status)
     VALUES (?, ?, ?, ?, "agent", ?, "outgoing", ?, ?, ?, ?, ?, ?, "pending")'
);
$ins->execute([
    (int)$user['company_id'], (int)$channel['id'], $conversationId, (int)$conv['contact_id'],
    (int)$user['id'], $kind,
    $caption !== '' ? $caption : ('[' . $kind . ']' . ($filename ? ' ' . $filename : '')),
    $mime, $filename, $mediaId, $safeLocalPath,
]);
$messageRowId = (int)$db->lastInsertId();

// Cloud API needs Meta's media_id. Evolution and AiServe Chatbot need the local file.
$mediaRef = (provider_name($channel) === 'cloud_api')
    ? $mediaId
    : ($safeLocalPath ?: '');

if ($mediaRef === '') {
    $db->prepare('UPDATE messages SET status="failed", error_message=? WHERE id=?')
       ->execute(['Missing media reference for provider ' . provider_name($channel), $messageRowId]);
    json_response(['ok' => false, 'error' => 'Missing media reference.'], 400);
}

$result = provider_send_media(
    $channel, (string)$conv['wa_id'], $kind, $mediaRef,
    $caption !== '' ? $caption : null, $filename ?: null, $mime ?: null
);

if ($result['ok']) {
    $db->prepare(
        'UPDATE messages SET status="sent", wa_message_id=?, sent_at=NOW() WHERE id=?'
    )->execute([$result['wa_message_id'], $messageRowId]);

    $previewText = $caption !== '' ? $caption : ('[' . $kind . '] ' . $filename);
    $db->prepare(
        'UPDATE conversations
         SET last_message_text = ?, last_message_at = NOW(),
             unread_count = 0,
             first_response_at = COALESCE(first_response_at, NOW())
         WHERE id = ?'
    )->execute([mb_substr($previewText, 0, 500), $conversationId]);

    log_activity((int)$user['company_id'], (int)$user['id'], 'media_sent',
        'conversation', $conversationId, $kind . ($filename ? ' ' . $filename : ''));

    json_response([
        'ok'            => true,
        'message_id'    => $messageRowId,
        'wa_message_id' => $result['wa_message_id'],
    ]);
}

$db->prepare('UPDATE messages SET status="failed", error_message=? WHERE id=?')
   ->execute([substr((string)$result['error'], 0, 500), $messageRowId]);

log_activity((int)$user['company_id'], (int)$user['id'], 'media_send_failed',
    'conversation', $conversationId, $result['error']);

json_response([
    'ok'         => false,
    'error'      => $result['error'] ?: 'Failed to send media.',
    'message_id' => $messageRowId,
    'http_code'  => $result['http_code'],
], 502);
