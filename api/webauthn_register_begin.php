<?php
/**
 * POST /api/webauthn_register_begin.php
 * → { challenge, rp, user, pubKeyCredParams, ... }  (PublicKeyCredentialCreationOptions)
 *
 * User must already be signed in (password) to register a biometric.
 * Returns the options the browser feeds into navigator.credentials.create().
 */
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

header('Content-Type: application/json; charset=utf-8');
$u = require_login();
if (!is_post()) { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST required'])); }
csrf_check();

echo json_encode([
    'ok'        => true,
    'challenge' => wa_new_challenge(),
    'rp'        => ['id' => wa_rp_id(), 'name' => APP_NAME],
    'user'      => [
        // WebAuthn userHandle: base64url of the user id. Kept opaque
        // (no email/PII) so a captured credential doesn't leak identity.
        'id'          => wa_b64url_encode('u_' . (int)$u['id']),
        'name'        => (string)($u['email'] ?? ('user' . (int)$u['id'])),
        'displayName' => (string)($u['name']  ?? 'User ' . (int)$u['id']),
    ],
    // ES256 first, then RS256 as a fallback for older Windows Hello.
    'pubKeyCredParams' => [
        ['type' => 'public-key', 'alg' => -7],
        ['type' => 'public-key', 'alg' => -257],
    ],
    'authenticatorSelection' => [
        'userVerification'     => 'required',   // biometric / PIN, not just presence
        'residentKey'          => 'preferred',  // enable passkey where supported
        'authenticatorAttachment' => 'platform',// Face ID / Touch ID / Windows Hello
    ],
    'attestation' => 'none',                    // we don't verify attestation
    'timeout'     => 60000,
    'excludeCredentials' => array_map(function ($cid) {
        return ['type' => 'public-key', 'id' => $cid];
    }, wa_credentials_for_user((int)$u['id'])),
]);
