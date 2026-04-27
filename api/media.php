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

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($abs);
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
