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
 * Pick the next agent for a branch's rotation using a fair, load-aware
 * algorithm:
 *
 *   1. Skip users who are away.
 *   2. Prefer 'available' users. Fall back to 'busy' only when zero
 *      available users exist (so leads never queue while someone's
 *      technically eligible).
 *   3. Within that tier, pick the user with the FEWEST currently-open
 *      assigned conversations (status IN open/pending/escalated).
 *   4. Tie-break by longest time since their last assignment (using the
 *      rotation cursor + user id) so equal-load users still see fair
 *      round-robin between themselves.
 *
 * Falls back cleanly to id-ASC if the availability column isn't in
 * the DB yet (pre-phase-41 workspace).
 *
 * Side effect: updates branches.last_assigned_user_id so tie-breakers
 * on the next call move on to the next equally-loaded user.
 */
function branch_next_agent_id(PDO $db, int $branchId, int $companyId): ?int
{
    if ($branchId <= 0) return null;

    // Detect whether the availability column exists so we work on both
    // pre- and post-phase-41 DBs without a hard dependency.
    static $hasAvail = null;
    if ($hasAvail === null) {
        try {
            $t = $db->query("SHOW COLUMNS FROM users LIKE 'availability'")->fetchAll();
            $hasAvail = count($t) > 0;
        } catch (Throwable $e) { $hasAvail = false; }
    }

    // Fetch every candidate + their open-load count in a single query.
    $availCol = $hasAvail ? 'u.availability' : '"available" AS availability';
    $sql =
        "SELECT u.id, $availCol AS availability,
                (SELECT COUNT(*) FROM conversations
                  WHERE assigned_user_id = u.id
                    AND status IN ('open','pending','escalated')) AS open_load
         FROM users u
         INNER JOIN user_branches ub ON ub.user_id = u.id
         WHERE ub.branch_id = ?
           AND u.company_id = ?
           AND u.status = 'active'
         " . ($hasAvail ? "AND u.availability <> 'away'" : '') . "
         ORDER BY u.id ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$branchId, $companyId]);
    $cands = $stmt->fetchAll();
    if (!$cands) return null;

    // Split into tiers so 'busy' only wins when no 'available' exists.
    $available = array_values(array_filter($cands, fn($c) => $c['availability'] === 'available'));
    $busy      = array_values(array_filter($cands, fn($c) => $c['availability'] === 'busy'));
    $pool = $available ?: $busy;
    if (!$pool) return null;

    // Cursor lets tie-broken users still round-robin among themselves.
    $cur = $db->prepare('SELECT last_assigned_user_id FROM branches WHERE id = ?');
    $cur->execute([$branchId]);
    $last = (int)($cur->fetchColumn() ?: 0);

    // Sort: fewest open leads first, then "cursor rank" — users past the
    // cursor sort before users before it (so we wrap the ring fairly).
    usort($pool, function ($a, $b) use ($last) {
        $la = (int)$a['open_load']; $lb = (int)$b['open_load'];
        if ($la !== $lb) return $la <=> $lb;
        // Tie: prefer the next user past the cursor.
        $rankA = ((int)$a['id'] > $last) ? 0 : 1;
        $rankB = ((int)$b['id'] > $last) ? 0 : 1;
        if ($rankA !== $rankB) return $rankA <=> $rankB;
        return (int)$a['id'] <=> (int)$b['id'];
    });

    $next = (int)$pool[0]['id'];
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
