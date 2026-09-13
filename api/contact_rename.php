<?php
/**
 * POST /api/contact_rename.php
 *
 * Renames a contact's display_name. Used from the chat side panel so
 * agents can override the WhatsApp / FB profile name when it's wrong
 * (nickname, empty, spam-y, non-Latin script that agent can't read, etc).
 *
 * The webhook ingester writes display_name only when it's empty, so
 * once an agent sets it, WhatsApp / FB profile updates won't clobber
 * their rename. profile_name keeps tracking the platform-side name for
 * reference.
 *
 * Params:
 *   conversation_id : int, required — we resolve contact_id from it so
 *                     we can reuse user_can_view_conversation() for authz
 *                     rather than exposing raw contact_id in the URL.
 *   display_name    : string, required, trimmed. Empty is allowed and
 *                     means "revert to profile_name / wa_id fallback".
 */

require_once __DIR__ . '/../inc/auth.php';

$user = require_login();

if (!is_post()) {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}
csrf_check();

header('Content-Type: application/json; charset=utf-8');

$conversationId = (int)($_POST['conversation_id'] ?? 0);
$newName        = trim((string)($_POST['display_name'] ?? ''));

if ($conversationId <= 0) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'conversation_id required']));
}
if (mb_strlen($newName) > 190) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Name too long (max 190).']));
}

$db = aiserve_db();
$stmt = $db->prepare(
    'SELECT c.*, ct.id AS contact_id, ct.wa_id, ct.display_name AS current_name,
            ct.profile_name
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     WHERE c.id = ? AND c.company_id = ?
     LIMIT 1'
);
$stmt->execute([$conversationId, (int)$user['company_id']]);
$conv = $stmt->fetch();
if (!$conv) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Conversation not found']));
}
if (!user_can_view_conversation($user, $conv)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

// Empty new name -> store NULL. Chat header falls back to profile_name,
// then wa_id, on render.
$stored = $newName === '' ? null : $newName;

$upd = $db->prepare('UPDATE contacts SET display_name = ? WHERE id = ?');
$upd->execute([$stored, (int)$conv['contact_id']]);

log_activity(
    (int)$user['company_id'],
    (int)$user['id'],
    'contact_renamed',
    'contact',
    (int)$conv['contact_id'],
    'from="' . mb_substr((string)$conv['current_name'], 0, 120)
        . '" to="' . mb_substr($newName, 0, 120) . '"'
);

// Return the resolved display name so the UI can update the header
// without re-fetching. Mirrors chat.php's fallback order:
//   display_name  ->  profile_name  ->  wa_id
$resolved = $stored !== null
    ? $stored
    : ((string)($conv['profile_name'] ?? '') !== ''
        ? (string)$conv['profile_name']
        : (string)$conv['wa_id']);

echo json_encode([
    'ok'           => true,
    'display_name' => $resolved,
    'was_reset'    => $stored === null,
]);
