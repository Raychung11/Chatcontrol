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
// NFC card token forwarded from chat.php. Optional. When present and
// valid it links the session to the physical card so widget_send.php
// can attribute the conversation (label, table, branch, campaign).
$nfcToken     = trim((string)($_POST['nfc_token'] ?? ''));
if (!preg_match('/^[a-f0-9]{16}$/i', $nfcToken)) $nfcToken = '';

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

// Resolve the NFC card (if any) so both the resume and fresh-session
// paths below can stamp the link. Scoped to this workspace so a
// forged token from another company can't attach.
$nfcCardId = null;
if ($nfcToken !== '') {
    $s = $db->prepare(
        'SELECT id FROM nfc_cards
         WHERE token = ? AND company_id = ? AND enabled = 1 LIMIT 1'
    );
    $s->execute([strtolower($nfcToken), $companyId]);
    $nfcCardId = (int)$s->fetchColumn() ?: null;
}

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
        // On a resume arriving with an nfc_token — the same customer scanned
        // a second (or different) card in the same browser — refresh the
        // link so the FRESH conversation, if one gets created, picks up the
        // new card's metadata. We don't retroactively rewrite an existing
        // conversation's attribution — the first card owns it.
        if ($nfcCardId !== null) {
            $db->prepare('UPDATE web_chat_sessions
                          SET last_seen_at = NOW(),
                              expires_at   = NOW() + INTERVAL 30 DAY,
                              nfc_card_id  = COALESCE(nfc_card_id, ?)
                          WHERE session_token = ?')
               ->execute([$nfcCardId, $sessionToken]);
        } else {
            $db->prepare('UPDATE web_chat_sessions SET last_seen_at = NOW(), expires_at = NOW() + INTERVAL 30 DAY WHERE session_token = ?')
               ->execute([$sessionToken]);
        }

        $history = [];
        $lastId = 0;
        if (!empty($sess['conversation_id'])) {
            $ms = $db->prepare(
                'SELECT id, direction, message_text, created_at,
                        message_type, media_local_path, media_mime_type, media_filename
                 FROM messages WHERE conversation_id = ?
                 ORDER BY id DESC LIMIT 50'
            );
            $ms->execute([(int)$sess['conversation_id']]);
            $rows = array_reverse($ms->fetchAll());
            foreach ($rows as $m) {
                // Same media-URL rule as widget_poll.php — expose the
                // session-scoped /api/widget_media.php URL only when the
                // file actually exists on disk. Applies to BOTH directions
                // so a returning customer sees their own photo they sent
                // earlier plus any photos the operator sent back.
                $mtype    = (string)($m['message_type'] ?? 'text');
                $mediaUrl = null;
                if ($mtype !== 'text'
                    && !empty($m['media_local_path'])
                    && is_file((string)$m['media_local_path'])) {
                    $mediaUrl = '/api/widget_media.php?token=' . $sessionToken . '&id=' . (int)$m['id'];
                }
                $history[] = [
                    'id'         => (int)$m['id'],
                    'direction'  => (string)$m['direction'],
                    'text'       => (string)$m['message_text'],
                    'created_at' => (string)$m['created_at'],
                    'type'       => $mtype,
                    'media_url'  => $mediaUrl,
                    'media_mime' => (string)($m['media_mime_type'] ?? ''),
                    'media_name' => (string)($m['media_filename']  ?? ''),
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

    // Session row. nfc_card_id may be NULL for direct-widget visits
    // (no card involved) or the FK to the physical card that spawned
    // this session.
    $db->prepare(
        'INSERT INTO web_chat_sessions
            (session_token, channel_id, contact_id, context, nfc_card_id,
             ip, user_agent, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL 30 DAY)'
    )->execute([
        $newToken, (int)$channel['id'], $contactId,
        $context ?: null,
        $nfcCardId,
        mb_substr(client_ip(), 0, 45),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    // Back-fill the tap event with the session token so the admin
    // analytics can join taps to sessions ("of the 40 taps on this
    // card, 27 turned into a conversation"). Best-effort — nothing
    // breaks if the tap row doesn't exist (deep-linked in a browser
    // without going through tap.php).
    if ($nfcCardId !== null) {
        try {
            $db->prepare(
                'UPDATE nfc_tap_events
                 SET session_token = ?
                 WHERE card_id = ? AND session_token IS NULL
                   AND tapped_at > NOW() - INTERVAL 10 MINUTE
                 ORDER BY id DESC LIMIT 1'
            )->execute([$newToken, $nfcCardId]);
        } catch (Throwable $e) { /* noop */ }
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[AiServe widget_start] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]));
}

echo json_encode([
    'ok'              => true,
    'session_token'   => $newToken,
    'contact_id'      => $contactId,
    'conversation_id' => 0,
    'last_msg_id'     => 0,
    'history'         => [],
]);
