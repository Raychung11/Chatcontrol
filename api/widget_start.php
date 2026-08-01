<?php
/**
 * POST /api/widget_start.php
 *
 * Start (or resume) a web-chat session for a specific channel token.
 *
 * Params:
 *   channel_token : required, matches channels.webhook_token where provider='web_chat'
 *   session_token : optional, if the browser has one from a previous visit
 *   context       : optional, arbitrary URL context (table number etc.)
 *
 * Returns:
 *   { ok, session_token, contact_id, conversation_id, last_msg_id, history:[...] }
 *
 * If session_token is supplied and still valid, we resume — same contact
 * + conversation, and history is the last N outgoing messages. If not,
 * we mint a fresh session with a new anonymous contact.
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/channels.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST required']));
}

$channelToken = trim((string)($_POST['channel_token'] ?? ''));
$sessionToken = trim((string)($_POST['session_token'] ?? ''));
$context      = mb_substr(trim((string)($_POST['context'] ?? '')), 0, 120);

if ($channelToken === '') {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Missing channel token']));
}

$channel = channel_by_token($channelToken);
if (!$channel || $channel['provider'] !== 'web_chat' || $channel['status'] !== 'active') {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Channel not found']));
}

$db = aiserve_db();
$companyId = (int)$channel['company_id'];

// -------------------- Resume path --------------------
if ($sessionToken !== '' && preg_match('/^[a-f0-9]{48}$/', $sessionToken)) {
    $st = $db->prepare(
        'SELECT * FROM web_chat_sessions
         WHERE session_token = ? AND channel_id = ? AND expires_at > NOW()
         LIMIT 1'
    );
    $st->execute([$sessionToken, (int)$channel['id']]);
    $sess = $st->fetch();
    if ($sess) {
        // Bump last_seen + expiry. History = last 50 messages on the conv.
        $db->prepare('UPDATE web_chat_sessions SET last_seen_at = NOW(), expires_at = NOW() + INTERVAL 30 DAY WHERE session_token = ?')
           ->execute([$sessionToken]);

        $history = [];
        $lastId = 0;
        if (!empty($sess['conversation_id'])) {
            $ms = $db->prepare(
                'SELECT id, direction, message_text, created_at
                 FROM messages WHERE conversation_id = ?
                 ORDER BY id DESC LIMIT 50'
            );
            $ms->execute([(int)$sess['conversation_id']]);
            $rows = array_reverse($ms->fetchAll());
            foreach ($rows as $m) {
                $history[] = [
                    'id'         => (int)$m['id'],
                    'direction'  => (string)$m['direction'],
                    'text'       => (string)$m['message_text'],
                    'created_at' => (string)$m['created_at'],
                ];
                $lastId = max($lastId, (int)$m['id']);
            }
        }
        echo json_encode([
            'ok'              => true,
            'session_token'   => $sessionToken,
            'contact_id'      => (int)$sess['contact_id'],
            'conversation_id' => (int)($sess['conversation_id'] ?? 0),
            'last_msg_id'     => $lastId,
            'history'         => $history,
        ]);
        exit;
    }
}

// -------------------- Fresh session --------------------
$newToken = bin2hex(random_bytes(24));   // 48 hex chars
$waId     = 'web_' . substr($newToken, 0, 16);
$displayName = 'Web visitor';

try {
    $db->beginTransaction();
    // Create anonymous contact for this session.
    $ins = $db->prepare(
        'INSERT INTO contacts (company_id, wa_id, platform, display_name, last_message_at)
         VALUES (?, ?, "web_chat", ?, NOW())'
    );
    $ins->execute([$companyId, $waId, $displayName]);
    $contactId = (int)$db->lastInsertId();

    // Session row.
    $db->prepare(
        'INSERT INTO web_chat_sessions
            (session_token, channel_id, contact_id, context, ip, user_agent, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL 30 DAY)'
    )->execute([
        $newToken, (int)$channel['id'], $contactId,
        $context ?: null,
        mb_substr(client_ip(), 0, 45),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[AiServe widget_start] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Could not start session']));
}

echo json_encode([
    'ok'              => true,
    'session_token'   => $newToken,
    'contact_id'      => $contactId,
    'conversation_id' => 0,
    'last_msg_id'     => 0,
    'history'         => [],
]);
