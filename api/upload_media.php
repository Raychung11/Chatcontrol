<?php
/**
 * POST /api/upload_media.php
 * Multipart: file=<file>, _csrf=<token>
 *
 * 1. Saves file to /uploads/{company_id}/agent_outgoing/
 * 2. Uploads to Meta Cloud API to obtain a media_id
 * 3. Returns { ok, media_id, mime_type, kind, filename, preview_url? }
 *
 * The media_id is then used by /api/send_media.php to actually send the message.
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

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'No file uploaded.'], 400);
}

$tmp      = (string)$_FILES['file']['tmp_name'];
$origName = (string)$_FILES['file']['name'];
$size     = (int)   $_FILES['file']['size'];

if ($size <= 0 || $size > 16 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'File too large (max 16 MB).'], 400);
}

$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string)finfo_file($fi, $tmp);
    finfo_close($fi);
}
if ($mime === '') {
    $mime = (string)($_FILES['file']['type'] ?? 'application/octet-stream');
}

$kind = media_kind_from_mime($mime);
if ($kind === null) {
    json_response(['ok' => false, 'error' => 'Unsupported media type: ' . $mime], 400);
}

$company = load_company_settings((int)$user['company_id']);
if (!$company) {
    json_response(['ok' => false, 'error' => 'Company settings missing.'], 500);
}
// The Meta upload step needs a specific channel's credentials. Caller can
// pass channel_id in the POST; otherwise use the workspace's default.
$channelId = (int)($_POST['channel_id'] ?? 0);
$channel = $channelId > 0 ? channel_by_id($channelId) : channel_default_for_company((int)$user['company_id']);
if (!$channel || (int)$channel['company_id'] !== (int)$user['company_id']) {
    json_response(['ok' => false, 'error' => 'No channel configured for this workspace.'], 500);
}

// 1. Move to /uploads/{company_id}/agent_outgoing/
$baseDir = __DIR__ . '/../uploads/' . (int)$user['company_id'] . '/agent_outgoing';
if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    json_response(['ok' => false, 'error' => 'Could not create upload directory.'], 500);
}
$safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $origName) ?: 'upload';
$safe = substr($safe, 0, 80);
$filename = bin2hex(random_bytes(6)) . '_' . $safe;
$dest     = $baseDir . '/' . $filename;
if (!move_uploaded_file($tmp, $dest)) {
    json_response(['ok' => false, 'error' => 'Could not save uploaded file.'], 500);
}
@chmod($dest, 0640);

// 2. Upload to provider (no-op for Evolution; Meta requires it)
$result = provider_upload_media($channel, $dest, $mime);
if (!$result['ok']) {
    @unlink($dest);
    json_response(['ok' => false, 'error' => $result['error'] ?: 'Upload failed.'], 502);
}

// 3. For images, expose a portal-served preview URL so the JS can show it.
$previewUrl = null;
if ($kind === 'image') {
    $relative = 'company-' . (int)$user['company_id'] . '/agent_outgoing/' . $filename;
    $previewUrl = '/api/media.php?p=' . urlencode($relative);
}

json_response([
    'ok'          => true,
    'media_id'    => $result['media_ref'],   // Cloud API media_id, or local path for Evolution
    'mime_type'   => $mime,
    'kind'        => $kind,
    'filename'    => $origName,
    'preview_url' => $previewUrl,
    'local_path'  => $dest,
    'rel_path'    => 'company-' . (int)$user['company_id'] . '/agent_outgoing/' . $filename,
    'provider'    => provider_name($channel),
    'channel_id'  => (int)$channel['id'],
]);

function media_kind_from_mime(string $mime): ?string
{
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/')) return 'audio';
    $docTypes = [
        'application/pdf', 'application/zip',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv',
    ];
    if (in_array($mime, $docTypes, true)) return 'document';
    return null;
}
