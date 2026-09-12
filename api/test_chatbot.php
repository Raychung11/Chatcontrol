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
require_once __DIR__ . '/../inc/channels.php';

// super_admin (workspace owner) may test their OWN channels. Platform-
// admin restriction was overly strict - it stopped customers from
// self-verifying a new channel and forced them to open a support ticket.
$user = require_role(['super_admin']);

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

// Rate limit: max 10 test sends per user per hour. Test messages are a
// diagnostic tool, not a bulk sender - without this cap a compromised or
// misbehaving super_admin could script arbitrary numbers and burn through
// the workspace's partner-gateway spend or trigger anti-spam blocks
// downstream.
$db = aiserve_db();
$rate = $db->prepare(
    'SELECT COUNT(*) FROM activity_logs
     WHERE company_id = ? AND user_id = ?
       AND action_type = "chatbot_test_send"
       AND created_at > NOW() - INTERVAL 1 HOUR'
);
$rate->execute([(int)$user['company_id'], (int)$user['id']]);
if ((int)$rate->fetchColumn() >= 10) {
    json_response(['ok' => false,
        'error' => 'Test-send limit reached (10 per hour). Wait a bit or use a real conversation to verify.'], 429);
}

$to = trim((string)($_POST['to'] ?? ''));
$to = preg_replace('/[^0-9]/', '', $to) ?: '';

if ($to === '' || strlen($to) < 8) {
    json_response(['ok' => false, 'error' => 'Enter a valid recipient phone (digits with country code, e.g. 60123456789).'], 400);
}

$channelId = (int)($_POST['channel_id'] ?? 0);
$channel = $channelId > 0 ? channel_by_id($channelId) : channel_default_for_company((int)$user['company_id']);
if (!$channel || (int)$channel['company_id'] !== (int)$user['company_id']) {
    json_response(['ok' => false, 'error' => 'No channel configured. Add one in Admin → Channels.'], 400);
}
if (!chatbot_is_configured($channel)) {
    json_response([
        'ok'    => false,
        'error' => 'This channel is not configured for the AiServe Chatbot Gateway. Set base URL and Bearer token in Channels.',
    ], 400);
}

$result = chatbot_send_text($channel, $to, 'AiServe portal connection test — if you received this, the gateway is wired up correctly.');

log_activity((int)$user['company_id'], (int)$user['id'], 'chatbot_test_send', 'company',
    (int)$user['company_id'], $result['ok'] ? ('ok to ' . $to) : ('failed: ' . substr((string)$result['error'], 0, 200)));

json_response([
    'ok'            => $result['ok'],
    'error'         => $result['error'],
    'wa_message_id' => $result['wa_message_id'],
    'http_code'     => $result['http_code'],
]);
