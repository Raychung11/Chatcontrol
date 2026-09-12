<?php
/**
 * POST /api/ai_translate.php
 *
 * Body: message_id, target_lang (optional, defaults to company setting), _csrf
 *
 * Translates the message body into the target language and caches the
 * result on messages.translated_text so subsequent clicks are free. If
 * a cached translation exists for the requested target language, return
 * it immediately.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/provider.php';

$user = require_login();
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

$messageId  = (int)($_POST['message_id']  ?? 0);
$targetLang = strtolower(trim((string)($_POST['target_lang'] ?? '')));
if ($messageId <= 0) {
    json_response(['ok' => false, 'error' => 'message_id required.'], 400);
}

$db = aiserve_db();

// Load message (scoped to workspace).
$stmt = $db->prepare(
    'SELECT m.id, m.message_text, m.translated_text, m.translated_to_lang,
            m.conversation_id
     FROM messages m
     WHERE m.id = ? AND m.company_id = ? LIMIT 1'
);
$stmt->execute([$messageId, (int)$user['company_id']]);
$msg = $stmt->fetch();
if (!$msg) {
    json_response(['ok' => false, 'error' => 'Message not found.'], 404);
}

// Verify agent can see this conversation (respects agent-scope rules).
$conv = $db->prepare('SELECT * FROM conversations WHERE id = ? LIMIT 1');
$conv->execute([(int)$msg['conversation_id']]);
$conv = $conv->fetch();
if (!$conv || !user_can_view_conversation($user, $conv)) {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

$company = load_company_settings((int)$user['company_id']) ?: [];
if ($targetLang === '') {
    $targetLang = (string)($company['translate_target_lang'] ?? 'en') ?: 'en';
}

// Cache hit? Return without hitting the AI.
if (!empty($msg['translated_text']) && $msg['translated_to_lang'] === $targetLang) {
    json_response([
        'ok'          => true,
        'text'        => (string)$msg['translated_text'],
        'target_lang' => $targetLang,
        'cached'      => true,
    ]);
}

$source = trim((string)$msg['message_text']);
if ($source === '') {
    json_response(['ok' => false, 'error' => 'Message has no text to translate.'], 400);
}

if (!ai_is_configured($company)) {
    json_response(['ok' => false, 'error' => 'AI is not enabled for this workspace. Configure it in Admin → AI Settings.'], 400);
}

$result = ai_translate_message($company, $source, $targetLang);
if (!$result['ok']) {
    log_activity((int)$user['company_id'], (int)$user['id'], 'ai_translate_failed',
        'message', $messageId, substr((string)$result['error'], 0, 300));
    json_response($result, 502);
}

// Cache.
$db->prepare(
    'UPDATE messages
     SET translated_text = ?, translated_to_lang = ?, translated_at = NOW()
     WHERE id = ?'
)->execute([$result['text'], $targetLang, $messageId]);

log_activity((int)$user['company_id'], (int)$user['id'], 'ai_translate',
    'message', $messageId, 'to=' . $targetLang);

json_response([
    'ok'          => true,
    'text'        => $result['text'],
    'target_lang' => $targetLang,
    'cached'      => false,
]);
