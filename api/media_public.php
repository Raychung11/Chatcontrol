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

// Debug logging: capture EVERY hit so operators can see whether the
// gateway actually fetched a signed URL after they hit send. Solves the
// "sent in portal but customer got no photo" mystery - if there's no log
// entry the gateway never even tried; if there IS an entry with 200 the
// gateway got the bytes and the miss is on their side; anything else
// tells us exactly which check failed.
function _media_log(string $status, int $companyId, int $channelId, string $rel, string $note = ''): void
{
    try {
        aiserve_db()->prepare(
            'INSERT INTO activity_logs
                (company_id, user_id, action_type, entity_type, entity_id, description)
             VALUES (?, NULL, ?, ?, ?, ?)'
        )->execute([
            $companyId ?: null,
            'media_public_fetch',
            'channel',
            $channelId ?: null,
            mb_substr(
                $status . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?')
                . ' ua=' . mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '?'), 0, 60)
                . ' p=' . mb_substr($rel, 0, 200)
                . ($note !== '' ? ' ' . $note : ''),
                0, 500
            ),
        ]);
    } catch (Throwable $e) {
        error_log('[AiServe media_public_log] ' . $e->getMessage());
    }
}

if (($channelId <= 0 && $companyIdLegacy <= 0) || $rel === '' || strlen($sig) !== 64) {
    _media_log('400_bad_request', $companyIdLegacy, $channelId, $rel, 'missing params');
    http_response_code(400);
    exit('Bad request.');
}
if (str_contains($rel, '..') || str_contains($rel, '\\')) {
    _media_log('400_bad_path', $companyIdLegacy, $channelId, $rel);
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
    _media_log('403_no_secret', $companyId, $channelId, $rel);
    http_response_code(403);
    exit('Forbidden.');
}
if (!hash_equals($expected, $sig)) {
    _media_log('403_bad_signature', $companyId, $channelId, $rel,
        'expected=' . substr($expected, 0, 8) . ' got=' . substr($sig, 0, 8));
    http_response_code(403);
    exit('Bad signature.');
}

$uploadsDir = realpath(__DIR__ . '/../uploads');
$abs        = realpath($uploadsDir . '/' . $companyId . '/' . $rel);
if (!$uploadsDir || !$abs || !str_starts_with($abs, $uploadsDir . '/' . $companyId . '/') || !is_readable($abs)) {
    _media_log('404_not_found', $companyId, $channelId, $rel,
        'exists=' . ($abs && file_exists($abs) ? '1' : '0')
        . ' readable=' . ($abs && is_readable($abs) ? '1' : '0'));
    http_response_code(404);
    exit('Not found.');
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string)finfo_file($fi, $abs);
    finfo_close($fi);
}

_media_log('200_ok', $companyId, $channelId, $rel, 'mime=' . $mime . ' bytes=' . filesize($abs));

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . rawurlencode(basename($abs)) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($abs);
