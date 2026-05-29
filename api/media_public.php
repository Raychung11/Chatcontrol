<?php
/**
 * GET /api/media_public.php?c=<company_id>&p=<rel_path>&sig=<hmac_sha256>
 *
 * Unauthenticated, HMAC-signed file serving. Used by the AiServe Chatbot
 * gateway to fetch outbound media files (its sendMessage endpoint takes a
 * mediaUrl, not a file upload, so we have to expose the bytes by URL).
 *
 * The signature is computed by chatbot_public_media_url() in
 * inc/aiserve_chatbot_api.php using the company's webhook_verify_token as
 * the HMAC key. Anyone with the URL can fetch the file, so the URL is
 * effectively a bearer token - share carefully.
 */

require_once __DIR__ . '/../inc/helpers.php';

$companyId = (int)($_GET['c'] ?? 0);
$rel       = (string)($_GET['p'] ?? '');
$sig       = (string)($_GET['sig'] ?? '');

if ($companyId <= 0 || $rel === '' || $sig === '' || strlen($sig) !== 64) {
    http_response_code(400);
    exit('Bad request.');
}
if (str_contains($rel, '..') || str_contains($rel, '\\')) {
    http_response_code(400);
    exit('Bad path.');
}

$stmt = aiserve_db()->prepare('SELECT webhook_verify_token FROM companies WHERE id = ? LIMIT 1');
$stmt->execute([$companyId]);
$secret = (string)($stmt->fetchColumn() ?: '');
if ($secret === '') {
    http_response_code(403);
    exit('Forbidden.');
}

$expected = hash_hmac('sha256', $companyId . ':' . $rel, $secret);
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
