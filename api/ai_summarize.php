<?php
/**
 * POST /api/ai_summarize.php
 *
 * Two modes:
 *
 *   mode=generate (default)
 *     Body: conversation_id, _csrf
 *     Pulls all the conversation's text messages, asks Claude for a
 *     structured handover summary, returns it.
 *
 *   mode=save_note
 *     Body: conversation_id, summary_text, _csrf
 *     Persists a previously-generated summary as an internal note on the
 *     conversation so the receiving agent sees it in the chat side panel.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/provider.php';

$user = require_login();

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

$mode           = (string)($_POST['mode'] ?? 'generate');
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

// ---- save_note: persist a summary the user already reviewed ----
if ($mode === 'save_note') {
    $summary = trim((string)($_POST['summary_text'] ?? ''));
    if ($summary === '') {
        json_response(['ok' => false, 'error' => 'Empty summary - nothing to save.'], 400);
    }
    $noteText = "[AI handover summary - " . date('Y-m-d H:i') . "]\n\n" . $summary;
    $ins = $db->prepare(
        'INSERT INTO internal_notes (company_id, conversation_id, user_id, note_text)
         VALUES (?, ?, ?, ?)'
    );
    $ins->execute([(int)$user['company_id'], $conversationId, (int)$user['id'], $noteText]);
    log_activity((int)$user['company_id'], (int)$user['id'], 'ai_summary_saved',
        'conversation', $conversationId, 'len=' . mb_strlen($summary));
    json_response(['ok' => true, 'note_id' => (int)$db->lastInsertId()]);
}

// ---- generate: AI handover summary ----
$company = load_company_settings((int)$user['company_id']) ?: [];
if (!ai_is_configured($company)) {
    json_response(['ok' => false, 'error' => 'AI is not enabled for this workspace. Configure it in Admin → AI Settings.'], 400);
}

$mstmt = $db->prepare(
    'SELECT m.direction, m.sender_type, m.message_text, m.created_at,
            u.name AS sender_name
     FROM messages m
     LEFT JOIN users u ON u.id = m.sender_user_id
     WHERE m.conversation_id = ?
       AND m.message_text IS NOT NULL AND m.message_text <> ""
     ORDER BY m.id ASC LIMIT 100'
);
$mstmt->execute([$conversationId]);
$rows = $mstmt->fetchAll();

$result = ai_summarize_conversation($company, $rows);

if (!$result['ok']) {
    log_activity((int)$user['company_id'], (int)$user['id'], 'ai_summary_failed',
        'conversation', $conversationId, substr((string)$result['error'], 0, 200));
    json_response($result, 502);
}

log_activity((int)$user['company_id'], (int)$user['id'], 'ai_summary_generated',
    'conversation', $conversationId,
    'model=' . ($result['model'] ?? '?') . ' tokens=' . json_encode($result['usage'] ?? []));

json_response($result);
