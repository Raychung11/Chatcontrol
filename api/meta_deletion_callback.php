<?php
/**
 * POST /api/meta_deletion_callback.php
 *
 * Meta requires every app that reads user data to publish a data
 * deletion callback URL. When a Facebook user visits their app
 * dashboard and removes our app, Meta POSTs a signed_request here.
 * We must:
 *   1. Verify the signature (HMAC-SHA256 with our App Secret).
 *   2. Delete the user's data on our side (or at least start the job).
 *   3. Respond with JSON { url, confirmation_code } — Meta shows the
 *      user that URL so they can check on the deletion status.
 *
 * Reference:
 *   https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback
 *
 * What we delete:
 *   - Every contacts row keyed on (platform=facebook|instagram, wa_id=user_id)
 *     across all workspaces, plus their conversations + messages
 *     (cascades via FK ON DELETE CASCADE).
 *   - We do NOT delete workspace-level content (channels, tokens, etc.).
 *     The customer's Page tokens are workspace assets, not user data.
 */

require_once __DIR__ . '/../inc/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$signed = (string)($_POST['signed_request'] ?? '');
if ($signed === '' || META_APP_SECRET === '') {
    http_response_code(400);
    exit(json_encode(['error' => 'signed_request required']));
}

$parts = explode('.', $signed, 2);
if (count($parts) !== 2) {
    http_response_code(400);
    exit(json_encode(['error' => 'Malformed signed_request']));
}
[$encSig, $encPayload] = $parts;

// URL-safe base64 (Meta variant) → standard base64
$sigBytes = base64_decode(strtr($encSig, '-_', '+/'), true);
$payload  = base64_decode(strtr($encPayload, '-_', '+/'), true);
if ($sigBytes === false || $payload === false) {
    http_response_code(400);
    exit(json_encode(['error' => 'Bad base64']));
}

$expected = hash_hmac('sha256', $encPayload, META_APP_SECRET, true);
if (!hash_equals($expected, $sigBytes)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Bad signature']));
}

$data = json_decode($payload, true);
if (!is_array($data) || empty($data['user_id'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing user_id']));
}
$userId = (string)$data['user_id'];

// Idempotent hard-delete of all contact rows keyed by this user id.
// ON DELETE CASCADE takes conversations + messages + notes + tags with it.
$db = aiserve_db();
try {
    $del = $db->prepare(
        'DELETE FROM contacts
         WHERE wa_id = ? AND platform IN ("facebook","instagram")'
    );
    $del->execute([$userId]);
    $rowCount = $del->rowCount();

    // Log at platform level (company_id = 0 — no workspace scope).
    log_activity(
        0, null, 'meta_user_deletion',
        'contact', null,
        'user_id=' . $userId . ' rows_deleted=' . $rowCount
    );
} catch (Throwable $e) {
    // Failing here means we didn't finish deletion. Meta still expects
    // 200 with a confirmation code so their side can check on us later
    // via the status URL — we'd re-run deletion out-of-band.
    error_log('[AiServe meta-deletion] delete failed: ' . $e->getMessage());
}

// Confirmation code the user can quote if they contact us / Meta about
// this deletion. Short hash of user id + timestamp is enough — Meta only
// requires it be non-empty and unique per callback.
$confirmationCode = substr(hash('sha256', $userId . '.' . time() . '.' . META_APP_SECRET), 0, 24);

$base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? ''));

http_response_code(200);
echo json_encode([
    'url'               => $base . '/api/meta_deletion_status.php?code=' . rawurlencode($confirmationCode),
    'confirmation_code' => $confirmationCode,
]);
