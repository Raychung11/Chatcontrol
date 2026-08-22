<?php
/**
 * GET /api/widget_media.php?token=<session>&id=<message_id>
 *
 * Public (session-token gated) media server for the web chat widget.
 * A widget customer isn't logged in — they can't hit /api/media.php,
 * which requires a workspace user session — so photos are served
 * here, scoped strictly to messages on THEIR conversation.
 *
 * Guarantees:
 *   - The requested message must belong to a conversation the
 *     session_token's session is linked to. No message id from a
 *     different customer or workspace is servable, even by guessing.
 *   - Sends inline for images so <img src> renders in the widget.
 *     Sends attachment for other media so it downloads instead of
 *     rendering as garbled text.
 *   - Sets a short-cache header so browsers reuse the fetch, but
 *     never a shared/CDN cache (this URL carries the session token).
 */

require_once __DIR__ . '/../inc/helpers.php';

$sessionToken = trim((string)($_GET['token'] ?? ''));
$msgId        = (int)($_GET['id'] ?? 0);

if (!preg_match('/^[a-f0-9]{48}$/', $sessionToken) || $msgId <= 0) {
    http_response_code(400);
    exit('Bad request.');
}

$db = aiserve_db();
$row = $db->prepare(
    'SELECT m.media_local_path, m.media_mime_type, m.media_filename,
            m.direction, m.message_type
     FROM web_chat_sessions s
     INNER JOIN messages m
        ON m.conversation_id = s.conversation_id
     WHERE s.session_token = ? AND s.expires_at > NOW()
       AND m.id = ?
     LIMIT 1'
);
$row->execute([$sessionToken, $msgId]);
$row = $row->fetch();
if (!$row) {
    http_response_code(404);
    exit('Not found.');
}
if (empty($row['media_local_path']) || !is_file((string)$row['media_local_path'])) {
    http_response_code(404);
    exit('Media not available.');
}

$abs      = (string)$row['media_local_path'];
$mime     = (string)($row['media_mime_type'] ?? '') ?: 'application/octet-stream';
$filename = (string)($row['media_filename']  ?? '') ?: basename($abs);

// Images render inline; anything else is offered as a download so the
// widget doesn't accidentally show binary as text.
$isImage    = str_starts_with($mime, 'image/');
$disposition = $isImage ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($filename) . '"');
// Short-cache: reuse within a browsing session, never share across
// users. This URL carries the session token so it MUST stay private.
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($abs);
