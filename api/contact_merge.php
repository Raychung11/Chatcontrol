<?php
/**
 * POST /api/contact_merge.php
 *
 * Merge source contact INTO target contact. Moves conversations,
 * messages, internal notes, tags, and the wa_lid stamp; then deletes
 * the source contact row. Idempotent — safe to re-run if a client
 * retries.
 *
 * Params:
 *   source_id : int — contact to be absorbed (deleted after)
 *   target_id : int — contact that survives
 *   _csrf     : token
 *
 * Only super_admin + manager can merge (it's a destructive operation).
 * Both contacts must belong to the operator's workspace.
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

$sourceId = (int)($_POST['source_id'] ?? 0);
$targetId = (int)($_POST['target_id'] ?? 0);
if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
    exit(json_encode(['ok' => false, 'error' => 'source_id and target_id required, and must differ.']));
}

$companyId = (int)$user['company_id'];
$db        = aiserve_db();

// Scope check — both must be in this workspace.
$chk = $db->prepare(
    'SELECT id, wa_id, wa_lid, display_name FROM contacts
     WHERE company_id = ? AND id IN (?, ?)'
);
$chk->execute([$companyId, $sourceId, $targetId]);
$rows = $chk->fetchAll();
if (count($rows) !== 2) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'One or both contacts not found in this workspace.']));
}
$byId = [];
foreach ($rows as $r) $byId[(int)$r['id']] = $r;
$src = $byId[$sourceId];
$tgt = $byId[$targetId];

$moved = ['messages' => 0, 'conversations' => 0, 'notes' => 0, 'tags' => 0];

try {
    $db->beginTransaction();

    // 1. Move every message + conversation from source → target.
    //    Messages carry contact_id AND conversation_id; conversations
    //    carry contact_id. Both need to point at the target.
    $u = $db->prepare(
        'UPDATE messages SET contact_id = ? WHERE contact_id = ? AND company_id = ?'
    );
    $u->execute([$targetId, $sourceId, $companyId]);
    $moved['messages'] = $u->rowCount();

    $u = $db->prepare(
        'UPDATE conversations SET contact_id = ? WHERE contact_id = ? AND company_id = ?'
    );
    $u->execute([$targetId, $sourceId, $companyId]);
    $moved['conversations'] = $u->rowCount();

    // internal_notes may not have contact_id (they're per-conversation),
    // so nothing to move there — the conversation's new contact_id
    // covers them transitively. Same for conversation_tag_map (keyed
    // on conversation_id).
    $moved['notes'] = 0;   // noted for the audit line, not moved directly

    // 2. Merge wa_lid — if the source had a stamp and the target didn't,
    //    copy it over. This is the "LID phantom + real-phone" auto-merge
    //    case the codebase's existing evolution ingest was designed to
    //    handle live; we replicate it here for the manual-merge path.
    if (!empty($src['wa_lid']) && empty($tgt['wa_lid'])) {
        $db->prepare('UPDATE contacts SET wa_lid = ? WHERE id = ?')
           ->execute([$src['wa_lid'], $targetId]);
    }

    // 3. Delete the source contact. ON DELETE CASCADE rules on any
    //    dangling FK take care of orphans (there shouldn't be any
    //    since we moved messages + conversations first).
    $del = $db->prepare('DELETE FROM contacts WHERE id = ? AND company_id = ?');
    $del->execute([$sourceId, $companyId]);
    if ($del->rowCount() !== 1) {
        throw new RuntimeException('Source contact could not be deleted (already gone?).');
    }

    log_activity($companyId, (int)$user['id'], 'contacts_merged',
        'contact', $targetId,
        'src=' . $sourceId . ' (' . (string)$src['display_name'] . ')'
      . ' → tgt=' . $targetId . ' (' . (string)$tgt['display_name'] . ')'
      . ' · moved ' . $moved['messages'] . ' msgs, '
      . $moved['conversations'] . ' convs');

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[contact_merge] ' . $e->getMessage());
    exit(json_encode(['ok' => false, 'error' => 'Merge failed: ' . $e->getMessage()]));
}

echo json_encode([
    'ok'        => true,
    'target_id' => $targetId,
    'moved'     => $moved,
]);
