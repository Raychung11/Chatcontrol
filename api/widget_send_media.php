<?php
/**
 * POST /api/widget_send_media.php  (multipart/form-data)
 *
 * Customer uploaded a photo from the web chat widget.
 *
 * Fields:
 *   session_token : required, matches widget_send.php
 *   photo         : required, the uploaded file (image/*)
 *   caption       : optional, text to attach to the photo (max 4000 chars)
 *
 * Returns { ok, message_id, media_url } — media_url is a public
 * session-scoped URL the widget renders in the outbound bubble.
 *
 * Server side mirrors widget_send.php's message-row shape but writes
 * message_type = 'image' + media_local_path + media_mime_type +
 * media_filename so the operator's inbox renders the photo inline via
 * the existing chat_render pipeline.
 *
 * The flow engine gets dispatched with the caption text (or a
 * "[photo]" placeholder), so a bot doesn't crash on empty text — any
 * caption-carrying prompt (address, complaint text, whatever) still
 * routes correctly.
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/channels.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST required']));
}

$sessionToken = trim((string)($_POST['session_token'] ?? ''));
$caption      = trim((string)($_POST['caption'] ?? ''));

if (!preg_match('/^[a-f0-9]{48}$/', $sessionToken)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Bad session token']));
}
if (mb_strlen($caption) > 4000) {
    $caption = mb_substr($caption, 0, 4000);
}

// -------------------- File-upload guardrails --------------------
if (empty($_FILES['photo']) || !is_array($_FILES['photo'])) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'No photo attached']));
}
$upload = $_FILES['photo'];
if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'Photo too large (server limit)',
        UPLOAD_ERR_FORM_SIZE  => 'Photo too large',
        UPLOAD_ERR_PARTIAL    => 'Upload interrupted — try again',
        UPLOAD_ERR_NO_FILE    => 'No photo attached',
        UPLOAD_ERR_NO_TMP_DIR => 'Server misconfigured (no tmp dir)',
        UPLOAD_ERR_CANT_WRITE => 'Server can\'t write the file',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by extension',
    ];
    $msg = $errMap[(int)$upload['error']] ?? 'Upload error';
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => $msg]));
}

$maxBytes = 8 * 1024 * 1024; // 8 MB — matches WhatsApp's image limit
if ((int)$upload['size'] > $maxBytes) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Photo larger than 8 MB — please compress and retry']));
}

// Read the MIME by sniffing content (never trust the browser-declared
// type — a rogue client could label a .php as image/jpeg). Allow only
// the common browser-safe raster formats.
$tmpPath = (string)($upload['tmp_name'] ?? '');
if (!is_uploaded_file($tmpPath)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Bad upload']));
}
$sniffedMime = @mime_content_type($tmpPath) ?: '';
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];
if (!isset($allowed[$sniffedMime])) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Only JPG / PNG / WEBP / GIF / HEIC photos allowed']));
}
$ext = $allowed[$sniffedMime];

// -------------------- Resolve session --------------------
$db = aiserve_db();
$st = $db->prepare(
    'SELECT s.*, c.company_id, ct.wa_id
     FROM web_chat_sessions s
     INNER JOIN channels  c  ON c.id  = s.channel_id
     INNER JOIN contacts  ct ON ct.id = s.contact_id
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

// -------------------- Store the file --------------------
$dir = realpath(__DIR__ . '/..') . '/uploads/company-' . $companyId
     . '/webchat/' . date('Ym');
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    error_log('[AiServe widget_send_media] mkdir failed: ' . $dir);
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Server can\'t save the upload']));
}
$storedName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$storedPath = $dir . '/' . $storedName;
if (!@move_uploaded_file($tmpPath, $storedPath)) {
    error_log('[AiServe widget_send_media] move_uploaded_file failed: ' . $storedPath);
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Server can\'t save the upload']));
}
@chmod($storedPath, 0644);

// Try to sanitize the original filename shown to the operator — never
// let a user-supplied filename be persisted verbatim (it may contain
// slashes, tag chars, etc.). Keep just the basename, cap length.
$origName = (string)($upload['name'] ?? '');
$origName = mb_substr(preg_replace('/[\r\n\t"<>\\\\\/]+/', '', $origName) ?: 'photo.' . $ext, 0, 120);

// -------------------- Insert conversation + message rows --------------------
try {
    // Find or create an open conversation for this contact on this channel
    // (mirrors widget_send.php exactly so the two endpoints stay in sync).
    $q = $db->prepare(
        'SELECT id, status FROM conversations
         WHERE company_id = ? AND contact_id = ? AND channel_id = ?
           AND status IN ("open","pending","escalated")
         ORDER BY id DESC LIMIT 1'
    );
    $q->execute([$companyId, $contactId, $channelId]);
    $conv = $q->fetch();

    $previewText = $caption !== '' ? mb_substr($caption, 0, 500) : '[photo]';

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

    if (empty($sess['conversation_id'])) {
        $db->prepare('UPDATE web_chat_sessions SET conversation_id = ?, last_seen_at = NOW() WHERE session_token = ?')
           ->execute([$conversationId, $sessionToken]);
    } else {
        $db->prepare('UPDATE web_chat_sessions SET last_seen_at = NOW() WHERE session_token = ?')
           ->execute([$sessionToken]);
    }

    $wcMsgId = 'wc_' . bin2hex(random_bytes(8));
    $mi = $db->prepare(
        'INSERT INTO messages
            (company_id, channel_id, conversation_id, contact_id, sender_type,
             wa_message_id, direction, message_type, message_text,
             media_local_path, media_mime_type, media_filename,
             status, created_at)
         VALUES (?, ?, ?, ?, "customer", ?, "incoming", "image", ?,
                 ?, ?, ?,
                 "received", NOW())'
    );
    $mi->execute([
        $companyId, $channelId, $conversationId, $contactId, $wcMsgId,
        $caption !== '' ? $caption : null,
        $storedPath, $sniffedMime, $origName,
    ]);
    $messageId = (int)$db->lastInsertId();

    // Dispatch to the flow engine so a bot can still react — pass the
    // caption if provided, else a "[photo]" sentinel so flow_engine
    // doesn't get empty text (which many nodes treat as no-reply).
    try {
        require_once __DIR__ . '/../inc/flow_engine.php';
        flow_engine_dispatch($db, $companyId, $conversationId, $caption !== '' ? $caption : '[photo]');
    } catch (Throwable $e) {
        error_log('[AiServe widget_send_media flow_engine] ' . $e->getMessage());
    }

    echo json_encode([
        'ok'              => true,
        'message_id'      => $messageId,
        'conversation_id' => $conversationId,
        'media_url'       => '/api/widget_media.php?token=' . $sessionToken . '&id=' . $messageId,
        'caption'         => $caption,
    ]);
} catch (Throwable $e) {
    // Clean up the orphan file so the disk doesn't fill on repeated
    // DB failures. The row wasn't committed — the file has no owner.
    @unlink($storedPath);
    error_log('[AiServe widget_send_media] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]));
}
