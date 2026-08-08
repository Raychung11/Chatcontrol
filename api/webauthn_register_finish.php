<?php
/**
 * POST /api/webauthn_register_finish.php
 *
 * Body: JSON {
 *   id: <credentialIdB64url>,
 *   publicKeyB64: <spki-der-b64url>,      // from credential.response.getPublicKey()
 *   clientDataJsonB64: <b64url>,
 *   deviceName: string?                    // "iPhone 15 Face ID" etc.
 * }
 *
 * Verifies the challenge, stores the credential.
 */
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

header('Content-Type: application/json; charset=utf-8');
$u = require_login();
if (!is_post()) { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST required'])); }
csrf_check();

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true) ?: [];

$credId = (string)($data['id']                ?? '');
$pubKey = (string)($data['publicKeyB64']      ?? '');
$cdJson = (string)($data['clientDataJsonB64'] ?? '');
$name   = trim((string)($data['deviceName']   ?? ''));
$name   = $name === '' ? null : mb_substr($name, 0, 120);

if ($credId === '' || $pubKey === '' || $cdJson === '') {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Missing fields.']));
}

// Verify the challenge in clientDataJSON matches what we issued.
$clientData = json_decode(wa_b64url_decode($cdJson), true);
if (!is_array($clientData)
    || ($clientData['type'] ?? '') !== 'webauthn.create'
    || !isset($clientData['challenge'], $clientData['origin'])) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Bad clientData.']));
}
if (!wa_consume_challenge((string)$clientData['challenge'])) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Challenge expired or mismatched. Try again.']));
}
if ((string)$clientData['origin'] !== wa_origin()) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Origin mismatch.']));
}

if (!wa_register_credential((int)$u['id'], $credId, $pubKey, $name)) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Could not save credential.']));
}

log_activity((int)$u['company_id'], (int)$u['id'], 'webauthn_registered', 'user', (int)$u['id'], $name ?: 'unknown device');
echo json_encode(['ok' => true]);
