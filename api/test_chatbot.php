<?php
/**
 * POST /api/test_chatbot.php
 *
 * Sends a one-off test message via the AiServe Chatbot Gateway using the
 * currently-saved credentials, so the admin can verify base URL + Bearer
 * token + connectivity without leaving Settings.
 *
 * Body (form):
 *   to    : recipient phone (digits, e.g. 60163917794)
 *   _csrf : token
 *
 * Returns JSON: { ok, error?, wa_message_id?, http_code, raw }
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/aiserve_chatbot_api.php';

$user = require_role(['super_admin']);

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

$to = trim((string)($_POST['to'] ?? ''));
$to = preg_replace('/[^0-9]/', '', $to) ?: '';

if ($to === '' || strlen($to) < 8) {
    json_response(['ok' => false, 'error' => 'Enter a valid recipient phone (digits with country code, e.g. 60123456789).'], 400);
}

$stmt = aiserve_db()->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([(int)$user['company_id']]);
$company = $stmt->fetch() ?: [];

if (!chatbot_is_configured($company)) {
    json_response([
        'ok'    => false,
        'error' => 'Save the AiServe Chatbot Gateway base URL and Bearer token first.',
    ], 400);
}

$result = chatbot_send_text($company, $to, 'AiServe portal connection test — if you received this, the gateway is wired up correctly.');

log_activity((int)$user['company_id'], (int)$user['id'], 'chatbot_test_send', 'company',
    (int)$user['company_id'], $result['ok'] ? ('ok to ' . $to) : ('failed: ' . substr((string)$result['error'], 0, 200)));

json_response([
    'ok'            => $result['ok'],
    'error'         => $result['error'],
    'wa_message_id' => $result['wa_message_id'],
    'http_code'     => $result['http_code'],
]);
