<?php
/**
 * POST /api/ai_suggest.php
 *
 * Body (form):
 *   conversation_id : int
 *   _csrf           : token
 *
 * Returns: { ok, suggestion, model, usage, error? }
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/provider.php';

$user = require_login();

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

$conversationId = (int)($_POST['conversation_id'] ?? 0);
if ($conversationId <= 0) {
    json_response(['ok' => false, 'error' => 'conversation_id required.'], 400);
}

$db = aiserve_db();

$stmt = $db->prepare(
    'SELECT c.*, ct.wa_id, ct.display_name, ct.profile_name
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     WHERE c.id = ? AND c.company_id = ? LIMIT 1'
);
$stmt->execute([$conversationId, (int)$user['company_id']]);
$conv = $stmt->fetch();
if (!$conv) {
    json_response(['ok' => false, 'error' => 'Conversation not found.'], 404);
}
if (!user_can_view_conversation($user, $conv)) {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

$company = load_company_settings((int)$user['company_id']) ?: [];
if (!ai_is_configured($company)) {
    json_response(['ok' => false, 'error' => 'AI is not enabled for this workspace. Configure it in Admin → AI Settings.'], 400);
}

// Pull the last N messages (chronological) for context.
$mstmt = $db->prepare(
    'SELECT direction, message_text, message_type, created_at
     FROM messages
     WHERE conversation_id = ? AND message_text IS NOT NULL AND message_text <> ""
     ORDER BY id DESC LIMIT 20'
);
$mstmt->execute([$conversationId]);
$rows = array_reverse($mstmt->fetchAll());

// Per-feature model override for the agent-composer draft.
$company['ai_model'] = ai_model_for_feature($company, 'suggest_reply');

$result = ai_suggest_reply($company, $conv, $rows);

if (!$result['ok']) {
    log_activity((int)$user['company_id'], (int)$user['id'], 'ai_suggest_failed',
        'conversation', $conversationId, substr((string)$result['error'], 0, 200));
    json_response($result, 502);
}

// Billing: log this agent-composer draft against the workspace.
require_once __DIR__ . '/../inc/ai_billing.php';
ai_log_usage((int)$user['company_id'], $conversationId, 'suggest_reply',
    $result['usage'] ?? null, $result['model'] ?? null);

log_activity((int)$user['company_id'], (int)$user['id'], 'ai_suggest',
    'conversation', $conversationId,
    'model=' . ($result['model'] ?? '?') . ' tokens=' . json_encode($result['usage'] ?? []));

json_response($result);
