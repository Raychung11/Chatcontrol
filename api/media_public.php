<?php
/**
 * GET /api/media_public.php?ch=<channel_id>&p=<rel_path>&sig=<hmac_sha256>
 *  (or legacy: ?c=<company_id>&p=<rel_path>&sig=<hmac_sha256>)
 *
 * Unauthenticated, HMAC-signed file serving. Used by the AiServe Chatbot
 * gateway to fetch outbound media files (its sendMessage endpoint takes a
 * mediaUrl, not a file upload, so we have to expose the bytes by URL).
 *
 * Signature secret is the channel's webhook_token (new) or the company's
 * webhook_verify_token (legacy URLs in flight at deploy time).
 */

require_once __DIR__ . '/../inc/helpers.php';

$channelId = (int)($_GET['ch'] ?? 0);
$companyIdLegacy = (int)($_GET['c'] ?? 0);
$rel = (string)($_GET['p'] ?? '');
$sig = (string)($_GET['sig'] ?? '');

if (($channelId <= 0 && $companyIdLegacy <= 0) || $rel === '' || strlen($sig) !== 64) {
    http_response_code(400);
    exit('Bad request.');
}
if (str_contains($rel, '..') || str_contains($rel, '\\')) {
    http_response_code(400);
    exit('Bad path.');
}

$db = aiserve_db();
$secret    = '';
$companyId = 0;

if ($channelId > 0) {
    $stmt = $db->prepare('SELECT company_id, webhook_token FROM channels WHERE id = ? LIMIT 1');
    $stmt->execute([$channelId]);
    $row = $stmt->fetch();
    if ($row) {
        $companyId = (int)$row['company_id'];
        $secret    = (string)$row['webhook_token'];
    }
    $expected = hash_hmac('sha256', $channelId . ':' . $rel, $secret);
} else {
    $companyId = $companyIdLegacy;
    $stmt = $db->prepare('SELECT webhook_verify_token FROM companies WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    $secret = (string)($stmt->fetchColumn() ?: '');
    $expected = hash_hmac('sha256', $companyId . ':' . $rel, $secret);
}

if ($secret === '' || $companyId <= 0) {
    http_response_code(403);
    exit('Forbidden.');
}
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    exit('Bad signature.');
}

$uploadsDir = realpath(__DIR__ . '/../uploads');
$abs        = realpath($uploadsDir . '/' . $companyId . '/' . $rel);
if (!$uploadsDir || !$abs || !str_starts_with($abs, $uploadsDir . '/' . $companyId . '/') || !is_readable($abs)) {
    http_response_code(404);
    exit('Not found.');
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string)finfo_file($fi, $abs);
    finfo_close($fi);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . rawurlencode(basename($abs)) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($abs);
