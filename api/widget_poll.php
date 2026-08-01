<?php
/**
 * GET /api/widget_poll.php?token=<session>&since=<message_id>
 *
 * Returns any OUTGOING messages on the session's conversation with
 * id > since, so the widget can render bot/agent replies as they land.
 *
 * Kept intentionally cheap: one prepared SELECT + one UPDATE for
 * last_seen. Called every ~3 seconds from the widget. Rows are capped
 * at 50 to avoid a huge payload after a long-idle browser resurrects.
 */

require_once __DIR__ . '/../inc/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$sessionToken = trim((string)($_GET['token'] ?? ''));
$since        = (int)($_GET['since'] ?? 0);

if (!preg_match('/^[a-f0-9]{48}$/', $sessionToken)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Bad session token']));
}

$db = aiserve_db();
$sess = $db->prepare(
    'SELECT conversation_id FROM web_chat_sessions
     WHERE session_token = ? AND expires_at > NOW() LIMIT 1'
);
$sess->execute([$sessionToken]);
$row = $sess->fetch();

if (!$row) {
    // Session gone; tell the widget so it can offer a reload.
    http_response_code(410);
    exit(json_encode(['ok' => false, 'error' => 'Session expired']));
}

// Keep last-seen fresh (useful for stats + "who's online" later).
$db->prepare('UPDATE web_chat_sessions SET last_seen_at = NOW() WHERE session_token = ?')
   ->execute([$sessionToken]);

$conversationId = (int)($row['conversation_id'] ?? 0);
if ($conversationId <= 0) {
    // No conversation yet (customer hasn't sent anything). Return empty.
    echo json_encode(['ok' => true, 'messages' => []]);
    exit;
}

$ms = $db->prepare(
    'SELECT id, direction, message_text, created_at, sender_type
     FROM messages
     WHERE conversation_id = ? AND id > ? AND direction = "outgoing"
     ORDER BY id ASC LIMIT 50'
);
$ms->execute([$conversationId, $since]);
$msgs = [];
foreach ($ms->fetchAll() as $m) {
    $msgs[] = [
        'id'         => (int)$m['id'],
        'direction'  => (string)$m['direction'],
        'sender'     => (string)$m['sender_type'],
        'text'       => (string)$m['message_text'],
        'created_at' => (string)$m['created_at'],
    ];
}

echo json_encode(['ok' => true, 'messages' => $msgs]);
