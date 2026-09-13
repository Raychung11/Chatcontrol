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

// Images render inline; audio + video render inline so the browser's
// native player can play them. Everything else is offered as a download
// so the widget doesn't accidentally show binary as text.
$isInline = str_starts_with($mime, 'image/')
         || str_starts_with($mime, 'audio/')
         || str_starts_with($mime, 'video/');
$disposition = $isInline ? 'inline' : 'attachment';

// HTTP Range support — iOS Safari and most browser audio players
// probe with 'Range: bytes=0-1' and refuse to start playback if the
// server responds with the whole file. Same reason we added it to
// api/media.php. Streams in 64 KB chunks so a big voice note doesn't
// buffer the whole payload in PHP memory.
$fileSize = filesize($abs);
$start = 0;
$end   = $fileSize - 1;
$isRange = false;

if (isset($_SERVER['HTTP_RANGE'])
    && preg_match('/^bytes=(\d+)-(\d*)$/', (string)$_SERVER['HTTP_RANGE'], $rm)) {
    $reqStart = (int)$rm[1];
    $reqEnd   = ($rm[2] !== '') ? (int)$rm[2] : ($fileSize - 1);
    if ($reqStart > $reqEnd || $reqStart >= $fileSize) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }
    $start   = $reqStart;
    $end     = min($reqEnd, $fileSize - 1);
    $isRange = true;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($filename) . '"');
// Short-cache: reuse within a browsing session, never share across
// users. This URL carries the session token so it MUST stay private.
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
if ($isRange) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    $fp = fopen($abs, 'rb');
    if ($fp === false) { http_response_code(500); exit; }
    fseek($fp, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(65536, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
        if (ob_get_level() > 0) @ob_flush();
        @flush();
    }
    fclose($fp);
} else {
    readfile($abs);
}
