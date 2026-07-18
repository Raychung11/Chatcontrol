<?php
/**
 * Meta (Facebook + Instagram) webhook receiver.
 *
 * GET  /webhook/meta.php  — hub.challenge verification handshake.
 * POST /webhook/meta.php  — comment events (feed for FB pages, comments for IG).
 *
 * Meta App Dashboard config:
 *   Callback URL       = https://YOUR-DOMAIN/webhook/meta.php
 *   Verify Token       = same value as META_WEBHOOK_VERIFY_TOKEN in
 *                        config/meta_config.php (env-driven)
 *   Subscribed fields  = feed (Page object), comments (Instagram object)
 *
 * We enforce two hard requirements before touching any DB row:
 *
 *   1. hub.verify_token equals our META_WEBHOOK_VERIFY_TOKEN — checked on
 *      the GET handshake.
 *   2. X-Hub-Signature-256 equals HMAC-SHA256(raw_body, META_APP_SECRET)
 *      — checked on every POST. Timing-safe compare via hash_equals.
 *
 * Log the raw body to webhook_events for diagnostics (best-effort — never
 * blocks the 200 response Meta expects).
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/meta_api.php';
require_once __DIR__ . '/../inc/meta_ingest.php';

header('Cache-Control: no-store');

// -------------------- GET verification --------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $mode      = (string)($_GET['hub_mode']         ?? '');
    $token     = (string)($_GET['hub_verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge']    ?? '');
    $expected  = META_WEBHOOK_VERIFY_TOKEN;

    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('Verification failed.');
}

// -------------------- POST inbound --------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$rawBody = file_get_contents('php://input') ?: '';

// -------------------- Signature verification --------------------
// Meta signs every webhook POST with your App Secret. If the signature
// doesn't match, someone is spoofing a webhook — reject flat.
$provided = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($provided === '' || META_APP_SECRET === '') {
    http_response_code(403);
    error_log('[AiServe meta-webhook] Missing signature or app secret');
    exit('Signature required.');
}
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, META_APP_SECRET);
if (!hash_equals($expected, $provided)) {
    http_response_code(403);
    error_log('[AiServe meta-webhook] Signature mismatch');
    exit('Bad signature.');
}

// -------------------- Parse + dispatch --------------------
$payload   = json_decode($rawBody, true);
$httpStatus = 200;
$errorText  = null;
$commentCount = 0;

if (!is_array($payload)) {
    $httpStatus = 400;
    $errorText  = 'Invalid JSON payload';
    error_log('[AiServe meta-webhook] ' . $errorText);
} else {
    $object = (string)($payload['object'] ?? '');
    if (!in_array($object, ['page', 'instagram'], true)) {
        // Meta sends other object types (user, permissions) — accept
        // silently so Meta doesn't retry.
        $httpStatus = 200;
    } else {
        try {
            foreach (($payload['entry'] ?? []) as $entry) {
                $entryId = (string)($entry['id'] ?? '');
                if ($entryId === '') {
                    continue;
                }
                $channel = meta_channel_for_entry($object, $entryId);
                if (!$channel) {
                    // Webhook for a Page/IG account we don't own — log
                    // and skip. This happens when a customer disconnects
                    // and Meta hasn't dropped their subscription yet.
                    error_log('[AiServe meta-webhook] Unknown entry id ' . $entryId . ' object=' . $object);
                    continue;
                }
                $companyIdForLog = (int)$channel['company_id'];
                foreach (($entry['changes'] ?? []) as $change) {
                    if ($object === 'page') {
                        meta_ingest_page_change($channel, $change);
                    } else {
                        meta_ingest_ig_change($channel, $change);
                    }
                    if (($change['field'] ?? '') === 'feed'
                        && ($change['value']['item'] ?? '') === 'comment') {
                        $commentCount++;
                    } elseif (($change['field'] ?? '') === 'comments') {
                        $commentCount++;
                    }
                }
            }
        } catch (Throwable $e) {
            $errorText = mb_substr('Exception: ' . $e->getMessage(), 0, 500);
            error_log('[AiServe meta-webhook] ' . $errorText);
        }
    }
}

// Log every POST for diagnostics — same table WhatsApp uses. company_id
// is 0 if we couldn't resolve any channel from this payload (unknown
// tenant); the log row still records that we saw it.
try {
    $logBody   = mb_substr($rawBody, 0, 65000);
    $companyId = (int)($companyIdForLog ?? 0);
    $logStmt = aiserve_db()->prepare(
        'INSERT INTO webhook_events
            (company_id, method, http_status, message_count, status_count, error_text, raw_body, ip_address)
         VALUES (?, "POST", ?, ?, 0, ?, ?, ?)'
    );
    $logStmt->execute([
        $companyId, $httpStatus, $commentCount, $errorText, $logBody, client_ip(),
    ]);
} catch (Throwable $e) {
    error_log('[AiServe meta-webhook] webhook_events insert failed: ' . $e->getMessage());
}

http_response_code($httpStatus);
echo $httpStatus === 200 ? 'EVENT_RECEIVED' : 'BAD_REQUEST';
