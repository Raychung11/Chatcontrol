<?php
/**
 * GET /api/media.php?p=company-{id}/{...path}
 *      OR
 * GET /api/media.php?msg=<message_id>
 *
 * Auth-gated serving of files from /uploads. Prevents path traversal,
 * enforces that the file belongs to the user's company.
 */

require_once __DIR__ . '/../inc/auth.php';

$user = require_login();

$rel = $_GET['p'] ?? '';
$msg = $_GET['msg'] ?? '';

$baseDir = realpath(__DIR__ . '/..') . '/uploads';

if ($msg !== '') {
    $stmt = aiserve_db()->prepare(
        'SELECT m.*, c.company_id AS conv_company_id, c.assigned_user_id, c.department_id
         FROM messages m
         INNER JOIN conversations c ON c.id = m.conversation_id
         WHERE m.id = ? LIMIT 1'
    );
    $stmt->execute([(int)$msg]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); exit('Not found.'); }

    // Permission: must be allowed to view the conversation
    if (!user_can_view_conversation($user, [
        'company_id'       => $row['conv_company_id'],
        'assigned_user_id' => $row['assigned_user_id'],
        'department_id'    => $row['department_id'],
    ])) {
        http_response_code(403); exit('Forbidden.');
    }
    if (empty($row['media_local_path'])) {
        http_response_code(404); exit('Media not yet downloaded.');
    }
    $abs = $row['media_local_path'];
    $mime = $row['media_mime_type'] ?: 'application/octet-stream';
    $filename = $row['media_filename'] ?: basename($abs);
} elseif (is_string($rel) && $rel !== '') {
    if (str_contains($rel, '..') || !preg_match('#^company-(\d+)/#', $rel, $m)) {
        http_response_code(400); exit('Bad path.');
    }
    $companyId = (int)$m[1];
    if ($companyId !== (int)$user['company_id']) {
        http_response_code(403); exit('Forbidden.');
    }
    $rel = preg_replace('#^company-\d+/#', $companyId . '/', $rel);
    $abs = $baseDir . '/' . $rel;
    $absReal = realpath($abs);
    if ($absReal === false || !str_starts_with($absReal, $baseDir . '/')) {
        http_response_code(404); exit('Not found.');
    }
    $abs = $absReal;
    $mime = mime_from_path($abs);
    $filename = basename($abs);
} else {
    http_response_code(400); exit('Missing parameter.');
}

if (!is_readable($abs)) {
    http_response_code(404); exit('Not readable.');
}

// HTTP Range support. Audio elements (especially iOS Safari) hit our
// media URL with 'Range: bytes=0-1' first as a probe, and refuse to
// begin playback if the server responds with the whole file (200)
// instead of a 206 Partial Content. Same for browser <video> and
// browser-native PDF viewers. Every media type benefits — audio just
// SILENTLY FAILED without it.
$fileSize = filesize($abs);
$start = 0;
$end   = $fileSize - 1;
$isRange = false;

if (isset($_SERVER['HTTP_RANGE'])
    && preg_match('/^bytes=(\d+)-(\d*)$/', (string)$_SERVER['HTTP_RANGE'], $m)) {
    $reqStart = (int)$m[1];
    $reqEnd   = ($m[2] !== '') ? (int)$m[2] : ($fileSize - 1);
    if ($reqStart > $reqEnd || $reqStart >= $fileSize) {
        // Client asked for a range past the end of the file.
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
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
if ($isRange) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    $fp = fopen($abs, 'rb');
    if ($fp === false) { http_response_code(500); exit; }
    fseek($fp, $start);
    $remaining = $end - $start + 1;
    // Stream in 64 KB chunks so we don't allocate the whole slice in
    // memory for a 20 MB voice note.
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
exit;

function mime_from_path(string $path): string
{
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, $path);
        finfo_close($fi);
        if ($mime !== '') return $mime;
    }
    return 'application/octet-stream';
}
