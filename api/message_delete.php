<?php
/**
 * POST /api/message_delete.php
 *
 * Soft-delete a message. Row stays in the DB (audit trail) but
 * every list query filters WHERE deleted_at IS NULL so the bubble
 * disappears from the UI everywhere.
 *
 * Params:
 *   message_id : int
 *   _csrf      : token
 *
 * Only super_admin + manager can delete. Agents can't (they'd delete
 * their own mistakes silently — undesirable in a shared inbox).
 */

require_once __DIR__ . '/../inc/auth.php';

$user = require_role(['super_admin', 'manager']);

if (!is_post()) {
    http_response_code(405);
    header('Content-Type: application/json');
    exit(json_encode(['ok' => false, 'error' => 'POST required.']));
}
csrf_check();
header('Content-Type: application/json');

$msgId = (int)($_POST['message_id'] ?? 0);
if ($msgId <= 0) exit(json_encode(['ok' => false, 'error' => 'message_id required.']));

$companyId = (int)$user['company_id'];
$db        = aiserve_db();

// Scope-check + soft-delete in one round-trip.
$stmt = $db->prepare(
    'UPDATE messages
     SET deleted_at = NOW()
     WHERE id = ? AND company_id = ? AND deleted_at IS NULL'
);
$stmt->execute([$msgId, $companyId]);
if ($stmt->rowCount() === 0) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Message not found or already deleted.']));
}

log_activity($companyId, (int)$user['id'], 'message_deleted', 'message', $msgId);
echo json_encode(['ok' => true, 'message_id' => $msgId]);
