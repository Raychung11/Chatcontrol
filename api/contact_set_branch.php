<?php
/**
 * POST /api/contact_set_branch.php
 *
 * Set (or clear) the branch that owns a contact. Called from the chat
 * side panel. Auth model mirrors /api/contact_rename.php — resolve the
 * contact via conversation_id so we get the existing
 * user_can_view_conversation() workspace + role guard for free.
 *
 * Params:
 *   conversation_id : int, required
 *   branch_id       : int, 0 or empty = clear (unassign branch)
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
$branchId       = (int)($_POST['branch_id'] ?? 0);

if ($conversationId <= 0) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'conversation_id required']));
}

$db  = aiserve_db();
$row = $db->prepare(
    'SELECT c.company_id, c.contact_id, c.channel_id, c.assigned_user_id, c.department_id, c.status,
            ct.branch_id AS current_branch_id
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     WHERE c.id = ? AND c.company_id = ? LIMIT 1'
);
$row->execute([$conversationId, (int)$user['company_id']]);
$conv = $row->fetch();
if (!$conv) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Conversation not found']));
}
if (!user_can_view_conversation($user, $conv)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

// If a branch was specified, verify it belongs to this workspace.
// Guards against URL tampering that would tag a contact with a
// foreign branch id.
$branchName = null;
$storedBranch = null;
if ($branchId > 0) {
    $b = $db->prepare(
        'SELECT id, name FROM branches
         WHERE id = ? AND company_id = ? AND status = "active" LIMIT 1'
    );
    $b->execute([$branchId, (int)$user['company_id']]);
    $branch = $b->fetch();
    if (!$branch) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'Branch not found in this workspace']));
    }
    $branchName   = (string)$branch['name'];
    $storedBranch = (int)$branch['id'];
}

$db->prepare('UPDATE contacts SET branch_id = ? WHERE id = ?')
   ->execute([$storedBranch, (int)$conv['contact_id']]);

log_activity(
    (int)$user['company_id'], (int)$user['id'], 'contact_branch_changed',
    'contact', (int)$conv['contact_id'],
    'from=' . (int)($conv['current_branch_id'] ?? 0) . ' to=' . (int)$storedBranch
);

echo json_encode([
    'ok'          => true,
    'branch_id'   => $storedBranch,
    'branch_name' => $branchName,
]);
