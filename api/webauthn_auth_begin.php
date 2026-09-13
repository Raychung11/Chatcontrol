<?php
/**
 * POST /api/webauthn_auth_begin.php
 * → { challenge, allowCredentials, ... }  (PublicKeyCredentialRequestOptions)
 *
 * Called from /pin.php when the user taps "Use Face ID / Touch ID".
 * The user IS logged in (remember-me populated the session), just not
 * PIN-verified yet — so we can safely look up their credentials.
 */
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

header('Content-Type: application/json; charset=utf-8');

// current_user() (not require_login) because require_login would
// bounce us to /pin.php in a loop.
$u = current_user();
if (!$u) { http_response_code(401); exit(json_encode(['ok'=>false,'error'=>'Not signed in.'])); }
if (!is_post()) { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST required'])); }
csrf_check();

$creds = wa_credentials_for_user((int)$u['id']);
if (!$creds) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'No biometric credential registered on this account.']));
}

echo json_encode([
    'ok'        => true,
    'challenge' => wa_new_challenge(),
    'rpId'      => wa_rp_id(),
    'timeout'   => 60000,
    'userVerification' => 'required',
    'allowCredentials' => array_map(function ($cid) {
        return ['type' => 'public-key', 'id' => $cid, 'transports' => ['internal']];
    }, $creds),
]);
