<?php
/**
 * Branch-based round-robin agent assignment (phase 28).
 *
 * Pool: every active user mapped to the branch via user_branches. Any
 * role is eligible (super_admin, manager, agent). If the operator only
 * wants agents in the pool, they just tick only agents' rows in
 * admin/user_edit.php.
 *
 * Ordering: users sorted by id ASC. We store the id of the last user we
 * assigned to on branches.last_assigned_user_id; the next call picks the
 * lowest id greater than that, wrapping back to the first user when we
 * fall off the end.
 *
 * Concurrency: two webhook workers picking simultaneously could both
 * read the same last_assigned cursor and both hand the conversation to
 * the same user, briefly desynchronising the rotation. That's cosmetic
 * (rotation catches up on the next round). If it becomes a real issue,
 * wrap the cursor read + write in a transaction with SELECT ... FOR UPDATE.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Return the next user_id to assign a conversation to for this branch,
 * updating the rotation cursor as a side effect. Returns null if the
 * branch has no active users in the pool.
 */
function branch_next_agent_id(PDO $db, int $branchId, int $companyId): ?int
{
    if ($branchId <= 0) return null;

    $stmt = $db->prepare(
        'SELECT u.id
         FROM users u
         INNER JOIN user_branches ub ON ub.user_id = u.id
         WHERE ub.branch_id = ?
           AND u.company_id = ?
           AND u.status = "active"
         ORDER BY u.id ASC'
    );
    $stmt->execute([$branchId, $companyId]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    if (!$ids) return null;

    $cursorStmt = $db->prepare('SELECT last_assigned_user_id FROM branches WHERE id = ?');
    $cursorStmt->execute([$branchId]);
    $last = (int)($cursorStmt->fetchColumn() ?: 0);

    $next = null;
    foreach ($ids as $uid) {
        if ($uid > $last) { $next = $uid; break; }
    }
    if ($next === null) {
        // Cursor was at (or past) the end of the pool - wrap to first.
        $next = $ids[0];
    }

    $db->prepare('UPDATE branches SET last_assigned_user_id = ? WHERE id = ?')
       ->execute([$next, $branchId]);

    return $next;
}

/**
 * If the given conversation is (still) unassigned and its contact belongs
 * to a branch, pick the next agent in that branch's rotation and stamp
 * the conversation with them. No-op if:
 *   - The conversation already has an assigned_user_id (routing rules,
 *     manual assign, previous webhook)
 *   - The contact has no branch_id
 *   - The branch has no active users in its rotation pool
 *
 * Called from the webhook ingesters after they create the conversation
 * row.
 */
function branch_rotation_apply(PDO $db, int $conversationId): void
{
    $stmt = $db->prepare(
        'SELECT c.id, c.company_id, c.assigned_user_id, ct.branch_id
         FROM conversations c
         INNER JOIN contacts ct ON ct.id = c.contact_id
         WHERE c.id = ? LIMIT 1'
    );
    $stmt->execute([$conversationId]);
    $row = $stmt->fetch();
    if (!$row) return;
    if (!empty($row['assigned_user_id'])) return;   // already assigned

    $branchId = (int)($row['branch_id'] ?? 0);
    if ($branchId <= 0) return;

    $agentId = branch_next_agent_id($db, $branchId, (int)$row['company_id']);
    if (!$agentId) return;

    $db->prepare(
        'UPDATE conversations SET assigned_user_id = ? WHERE id = ?'
    )->execute([$agentId, $conversationId]);

    log_activity(
        (int)$row['company_id'], null,
        'branch_rotation_assigned', 'conversation', $conversationId,
        'branch=' . $branchId . ' agent=' . $agentId
    );
}
