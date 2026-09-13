<?php
/**
 * POST /api/webauthn_auth_finish.php
 *
 * Body: JSON {
 *   id: <credentialIdB64url>,
 *   clientDataJsonB64:   <b64url>,
 *   authenticatorDataB64:<b64url>,
 *   signatureB64:        <b64url>,
 * }
 *
 * Verifies the assertion and — on success — marks the current session
 * as PIN-verified so require_login() lets the user through to the app.
 */
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

header('Content-Type: application/json; charset=utf-8');
$u = current_user();
if (!$u) { http_response_code(401); exit(json_encode(['ok'=>false,'error'=>'Not signed in.'])); }
if (!is_post()) { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST required'])); }
csrf_check();

$raw = file_get_contents('php://input') ?: '';
$d   = json_decode($raw, true) ?: [];

$ok = wa_verify_assertion(
    (int)$u['id'],
    (string)($d['id']                   ?? ''),
    (string)($d['clientDataJsonB64']    ?? ''),
    (string)($d['authenticatorDataB64'] ?? ''),
    (string)($d['signatureB64']         ?? '')
);

if (!$ok) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Biometric check failed.']));
}

// Same session flag the PIN screen sets — we're unlocked.
pin_mark_verified();
log_activity((int)$u['company_id'], (int)$u['id'], 'webauthn_auth', 'user', (int)$u['id']);
echo json_encode(['ok' => true]);
