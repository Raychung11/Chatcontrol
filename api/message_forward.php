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

// Load source msg + verify same workspace. Include media fields so we
// can carry image / video / audio / document over — not just text.
$srcStmt = $db->prepare(
    'SELECT m.id, m.message_text, m.message_type, m.media_local_path,
            m.media_mime_type, m.media_filename,
            m.conversation_id, m.company_id
     FROM messages m WHERE m.id = ? AND m.company_id = ? LIMIT 1'
);
$srcStmt->execute([$srcId, $companyId]);
$src = $srcStmt->fetch();
if (!$src) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Source message not found in this workspace.']));
}
$text        = trim((string)$src['message_text']);
$msgType     = (string)($src['message_type'] ?? 'text');
$mediaLocal  = (string)($src['media_local_path'] ?? '');
$mediaMime   = (string)($src['media_mime_type']  ?? '');
$mediaName   = (string)($src['media_filename']   ?? '');
$hasMedia    = in_array($msgType, ['image', 'video', 'audio', 'document', 'sticker'], true)
            && $mediaLocal !== '' && is_readable($mediaLocal);

// Reject only if there's nothing to send at all — text-only messages
// still need non-empty text; media messages can forward with an empty
// caption (WhatsApp is fine with that).
if (!$hasMedia && $text === '') {
    exit(json_encode(['ok' => false, 'error' => 'Source message has no text or media to forward.']));
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
// If the source carried media, we send the media (with the source's text
// as caption). For Cloud API targets we must upload the local file to
// Meta first to get a media_id; other providers accept the local path
// directly.
try {
    if ($hasMedia) {
        $mediaRef = $mediaLocal;
        if (provider_name($channel) === 'cloud_api') {
            $up = provider_upload_media($channel, $mediaLocal, $mediaMime ?: 'application/octet-stream');
            if (!$up['ok']) {
                exit(json_encode([
                    'ok' => false,
                    'error' => 'Media upload to Meta failed: ' . (string)($up['error'] ?? 'unknown'),
                ]));
            }
            $mediaRef = (string)$up['media_id'];
        }
        $result = provider_send_media(
            $channel,
            (string)$tgt['wa_id'],
            $msgType === 'sticker' ? 'image' : $msgType,   // gateways typically accept sticker as image
            $mediaRef,
            $text !== '' ? $text : null,
            $mediaName !== '' ? $mediaName : null,
            $mediaMime !== '' ? $mediaMime : null
        );
    } else {
        $result = provider_send_text($channel, (string)$tgt['wa_id'], $text);
    }
} catch (Throwable $e) {
    error_log('[message_forward] provider send: ' . $e->getMessage());
    exit(json_encode(['ok' => false, 'error' => 'Provider send failed: ' . $e->getMessage()]));
}

// Record the outgoing message. When forwarded WITH media, copy the
// media fields onto the new row so the target conversation renders
// the image / video / audio inline just like a native send. We reuse
// the source's media_local_path — the file stays where it is; both
// messages point at the same bytes, which is fine because soft-delete
// on the source leaves the file intact.
try {
    $newType = $hasMedia ? $msgType : 'text';
    $ins = $db->prepare(
        'INSERT INTO messages
            (company_id, channel_id, conversation_id, contact_id, sender_type,
             sender_user_id, wa_message_id, direction, message_type,
             message_text, media_local_path, media_mime_type, media_filename,
             status, sent_at)
         VALUES (?, ?, ?, ?, "agent",
                 ?, ?, "outgoing", ?,
                 ?, ?, ?, ?,
                 ?, ?)'
    );
    $ins->execute([
        $companyId, (int)$tgt['channel_id'], (int)$tgt['id'], (int)$tgt['contact_id'],
        (int)$user['id'],
        $result['wa_message_id'] ?? null,
        $newType,
        $text,
        $hasMedia ? $mediaLocal : null,
        $hasMedia ? $mediaMime  : null,
        $hasMedia ? $mediaName  : null,
        $result['ok'] ? 'sent' : 'failed',
        $result['ok'] ? date('Y-m-d H:i:s') : null,
    ]);
    $newMsgId = (int)$db->lastInsertId();

    // Preview shown in the inbox list — for a bare media forward with no
    // caption, fall back to a bracket-tag ('[image]') so the row doesn't
    // look empty.
    $preview = $text !== ''
        ? $text
        : ($hasMedia ? '[' . $msgType . ($mediaName !== '' ? ' · ' . $mediaName : '') . ']' : '');
    $db->prepare(
        'UPDATE conversations
         SET last_message_text = ?, last_message_at = NOW()
         WHERE id = ?'
    )->execute([mb_substr($preview, 0, 500), (int)$tgt['id']]);

    log_activity($companyId, (int)$user['id'], 'message_forwarded',
        'message', $srcId, 'src=' . $srcId . ' → conv=' . $targetId
      . ' as msg=' . $newMsgId . ($hasMedia ? ' [' . $msgType . ']' : ''));

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
