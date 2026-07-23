<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$userId = (int)($_GET['id'] ?? 0);
$user   = null;
if ($userId > 0) {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$userId, $companyId]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        exit('User not found.');
    }
}

$err = '';
$msg = '';

if (is_post()) {
    csrf_check();
    $name     = trim((string)($_POST['name']           ?? ''));
    $email    = trim((string)($_POST['email']          ?? ''));
    $phone    = trim((string)($_POST['phone']          ?? ''));
    $role     = (string)($_POST['role']                ?? 'agent');
    $deptId   = $_POST['department_id'] ?? '';
    $deptId   = ($deptId === '' || $deptId === '0') ? null : (int)$deptId;
    $status   = ((string)($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';
    $password = (string)($_POST['password'] ?? '');

    // Phase 26: channel_ids the agent can view. Only meaningful for
    // role=agent — the form hides the section for super_admin / manager.
    $channelIds = array_map('intval', (array)($_POST['channel_ids'] ?? []));
    $channelIds = array_values(array_unique(array_filter($channelIds, fn($i) => $i > 0)));

    // Phase 28: branch_ids this user is in the rotation pool for.
    // Applies to any role — a manager can be in the rotation too.
    $branchIds  = array_map('intval', (array)($_POST['branch_ids'] ?? []));
    $branchIds  = array_values(array_unique(array_filter($branchIds, fn($i) => $i > 0)));

    if (!in_array($role, ['super_admin', 'manager', 'agent'], true)) {
        $err = 'Invalid role.';
    } elseif ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Name and a valid email are required.';
    } elseif (!$user && $password === '') {
        $err = 'Password is required for new users.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $err = 'Password must be at least 8 characters.';
    } else {
        // Seat-limit guard - block adding a new active user beyond the plan.
        $isNewActive  = !$user && $status === 'active';
        $reactivating = $user && $user['status'] !== 'active' && $status === 'active';
        if ($isNewActive || $reactivating) {
            $cstmt = $db->prepare('SELECT plan FROM companies WHERE id = ?');
            $cstmt->execute([$companyId]);
            $plan  = (string)($cstmt->fetchColumn() ?: 'starter');
            $limit = plan_seat_limit($plan);
            $used  = company_user_count($companyId);
            if ($used >= $limit) {
                $pp = pricing_get();
                $wantedSeats = $used + 1;
                if ($plan === 'starter' && $wantedSeats <= $pp['bundle_seats']) {
                    $upgradeTo   = 'Growth';
                    $upgradeCost = $pp['bundle_price'];
                } else {
                    $upgradeTo   = 'Enterprise';
                    $extras      = max(0, $wantedSeats - $pp['bundle_seats']);
                    $upgradeCost = $pp['bundle_price'] + ($extras * $pp['extra_seat_price']);
                }
                $err = 'Seat limit reached (' . $used . ' / ' . $limit . ' on ' . ucfirst($plan)
                     . '). Deactivate someone, or upgrade to ' . $upgradeTo
                     . ' for ' . fmt_price($upgradeCost, $pp['currency']) . ' ' . $pp['period_label']
                     . ' (covers ' . $wantedSeats . ' seats).';
            }
        }
    }
    if ($err === '') {
        try {
            if ($user) {
                $sql = 'UPDATE users SET name=?, email=?, phone=?, role=?, department_id=?, status=?'
                     . ($password !== '' ? ', password_hash=?' : '')
                     . ' WHERE id=? AND company_id=?';
                $params = [$name, $email, $phone, $role, $deptId, $status];
                if ($password !== '') {
                    $params[] = password_hash($password, PASSWORD_BCRYPT);
                }
                $params[] = (int)$user['id'];
                $params[] = $companyId;
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                // Channel access for agents. Wipe + re-insert so
                // unchecking a box actually removes access.
                user_edit_save_channels($db, $companyId, (int)$user['id'], $role, $channelIds);
                user_edit_save_branches($db, $companyId, (int)$user['id'], $branchIds);
                log_activity($companyId, (int)$current_user['id'], 'user_updated', 'user', (int)$user['id']);
                $msg = 'User updated.';
                // refresh
                $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([(int)$user['id']]);
                $user = $stmt->fetch();
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO users (company_id, department_id, name, email, phone, password_hash, role, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $companyId, $deptId, $name, $email, $phone,
                    password_hash($password, PASSWORD_BCRYPT), $role, $status,
                ]);
                $newId = (int)$db->lastInsertId();
                user_edit_save_channels($db, $companyId, $newId, $role, $channelIds);
                user_edit_save_branches($db, $companyId, $newId, $branchIds);
                log_activity($companyId, (int)$current_user['id'], 'user_created', 'user', $newId);
                redirect('/admin/user_edit.php?id=' . $newId);
            }
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                $err = 'A user with that email already exists.';
            } else {
                error_log($e->getMessage());
                $err = 'Could not save user.';
            }
        }
    }
}

$dstmt = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$dstmt->execute([$companyId]);
$departments = $dstmt->fetchAll();

// Channels this workspace has, plus the ones this user is currently
// restricted to. Empty allowedChannelIds = unrestricted (all channels).
$chStmt = $db->prepare(
    'SELECT id, name, display_phone, provider FROM channels
     WHERE company_id = ? AND status = "active"
     ORDER BY is_default DESC, name'
);
$chStmt->execute([$companyId]);
$allChannels = $chStmt->fetchAll();

$allowedChannelIds = [];
if ($user) {
    $ac = $db->prepare('SELECT channel_id FROM user_channels WHERE user_id = ?');
    $ac->execute([(int)$user['id']]);
    $allowedChannelIds = array_map('intval', array_column($ac->fetchAll(), 'channel_id'));
}

// Workspace branches + the ones this user is currently in the rotation
// pool for. Only fetches if the branches table exists (phase 27 shipped)
// so a partially-migrated deploy doesn't 500.
$allBranches      = [];
$rotationBranchIds = [];
try {
    $bStmt = $db->prepare(
        'SELECT id, name FROM branches
         WHERE company_id = ? AND status = "active" ORDER BY name'
    );
    $bStmt->execute([$companyId]);
    $allBranches = $bStmt->fetchAll();
    if ($user) {
        $ub = $db->prepare('SELECT branch_id FROM user_branches WHERE user_id = ?');
        $ub->execute([(int)$user['id']]);
        $rotationBranchIds = array_map('intval', array_column($ub->fetchAll(), 'branch_id'));
    }
} catch (Throwable $e) {
    // branches / user_branches missing — silently skip the section.
}

layout_start($current_user, $user ? 'Edit user' : 'New user', 'users');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <label>Name<input type="text" name="name" required value="<?= e($user['name'] ?? ($_POST['name'] ?? '')) ?>"></label>
    <label>Email<input type="email" name="email" required value="<?= e($user['email'] ?? ($_POST['email'] ?? '')) ?>"></label>
    <label>Phone<input type="text" name="phone" value="<?= e($user['phone'] ?? ($_POST['phone'] ?? '')) ?>"></label>
    <label>Role
      <select name="role">
        <?php foreach (['super_admin','manager','agent'] as $r): ?>
          <option value="<?= $r ?>" <?= ($user['role'] ?? 'agent') === $r ? 'selected' : '' ?>>
            <?= e(role_label($r)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Department
      <select name="department_id">
        <option value="0">— None —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= ((int)($user['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
            <?= e($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Status
      <select name="status">
        <option value="active"   <?= ($user['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= ($user['status'] ?? '')       === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </label>
    <label><?= $user ? 'New password (leave blank to keep current)' : 'Password' ?>
      <input type="password" name="password" autocomplete="new-password" minlength="8" <?= $user ? '' : 'required' ?>>
    </label>

    <fieldset id="channel-access-fieldset"
              style="border:1px solid var(--c-border); border-radius:8px; padding:14px; margin:0;">
      <legend style="padding:0 6px; font-weight:600; font-size:14px;">Channel access</legend>
      <p class="muted small" style="margin:0 0 10px;">
        <strong>Agents only.</strong> Tick the channels this agent is allowed to see.
        <strong>Leaving every box unticked</strong> means the agent can see conversations
        on <em>every</em> channel (default, unrestricted).
        Managers and super admins always see every channel — this section is ignored for them.
      </p>
      <?php if (!$allChannels): ?>
        <p class="muted small">No channels yet. Add one in <a href="/admin/channels.php">Channels</a> first.</p>
      <?php else: ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:6px;">
          <?php foreach ($allChannels as $ch): ?>
            <label style="display:flex; align-items:center; gap:8px; font-weight:normal; font-size:14px;">
              <input type="checkbox" name="channel_ids[]" value="<?= (int)$ch['id'] ?>"
                     <?= in_array((int)$ch['id'], $allowedChannelIds, true) ? 'checked' : '' ?>>
              <span>
                <?= e($ch['name']) ?>
                <?php if ($ch['display_phone']): ?>
                  <br><small class="muted"><?= e($ch['display_phone']) ?> · <?= e($ch['provider']) ?></small>
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </fieldset>

    <?php if ($allBranches): ?>
    <fieldset style="border:1px solid var(--c-border); border-radius:8px; padding:14px; margin:0;">
      <legend style="padding:0 6px; font-weight:600; font-size:14px;">Branch rotation</legend>
      <p class="muted small" style="margin:0 0 10px;">
        Tick the branches this person is part of. When a new customer conversation
        opens for a contact belonging to a ticked branch, the system round-robins
        assignment among everyone in that branch's pool.
        Applies to any role — a manager can be in the rotation too.
        Untick everything to remove the user from all rotations.
      </p>
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:6px;">
        <?php foreach ($allBranches as $b): ?>
          <label style="display:flex; align-items:center; gap:8px; font-weight:normal; font-size:14px;">
            <input type="checkbox" name="branch_ids[]" value="<?= (int)$b['id'] ?>"
                   <?= in_array((int)$b['id'], $rotationBranchIds, true) ? 'checked' : '' ?>>
            <span><?= e($b['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <?php endif; ?>

    <div>
      <button class="btn btn-primary" type="submit"><?= $user ? 'Save changes' : 'Create user' ?></button>
      <a class="btn" href="/admin/users.php">Cancel</a>
    </div>
  </form>
</div>

<script>
// Hide the Channel access fieldset when role is manager or super_admin —
// the restriction is agent-only.
(function () {
  const roleSel = document.querySelector('select[name="role"]');
  const fset    = document.getElementById('channel-access-fieldset');
  if (!roleSel || !fset) return;
  function sync() {
    fset.style.display = (roleSel.value === 'agent') ? '' : 'none';
  }
  roleSel.addEventListener('change', sync);
  sync();
})();
</script>
<?php layout_end(); ?>

<?php
/**
 * Persist the agent's channel access list. Wipe + re-insert so an
 * unchecked box removes access; explicit no-op for managers / super
 * admins so the fieldset's hidden state doesn't accidentally revoke
 * everything on save.
 *
 * Belt-and-braces workspace scope: every channel_id is verified to
 * belong to the same company before insert. Prevents URL tampering
 * from cross-linking users to foreign channels.
 */
/**
 * Persist the user's branch rotation memberships (phase 28). Same
 * wipe-and-reinsert shape as user_edit_save_channels. Verifies every
 * branch belongs to this workspace before insert.
 */
function user_edit_save_branches(PDO $db, int $companyId, int $userId, array $branchIds): void
{
    try {
        if (!$branchIds) {
            $db->prepare('DELETE FROM user_branches WHERE user_id = ?')->execute([$userId]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
        $verify = $db->prepare(
            "SELECT id FROM branches WHERE company_id = ? AND id IN ($placeholders)"
        );
        $verify->execute(array_merge([$companyId], $branchIds));
        $valid = array_map('intval', array_column($verify->fetchAll(), 'id'));
        if (!$valid) {
            $db->prepare('DELETE FROM user_branches WHERE user_id = ?')->execute([$userId]);
            return;
        }
        $db->beginTransaction();
        $db->prepare('DELETE FROM user_branches WHERE user_id = ?')->execute([$userId]);
        $ins = $db->prepare('INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)');
        foreach ($valid as $bid) $ins->execute([$userId, $bid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[AiServe user_edit_save_branches] ' . $e->getMessage());
    }
}

function user_edit_save_channels(PDO $db, int $companyId, int $userId, string $role, array $channelIds): void
{
    if ($role !== 'agent') {
        // Managers + super admins bypass the restriction entirely.
        // Wipe any stale rows they might have from an earlier agent role.
        $db->prepare('DELETE FROM user_channels WHERE user_id = ?')->execute([$userId]);
        return;
    }
    if (!$channelIds) {
        // Empty list = unrestricted (see phase 26 migration comment).
        $db->prepare('DELETE FROM user_channels WHERE user_id = ?')->execute([$userId]);
        return;
    }
    $placeholders = implode(',', array_fill(0, count($channelIds), '?'));
    $verify = $db->prepare(
        "SELECT id FROM channels WHERE company_id = ? AND id IN ($placeholders)"
    );
    $verify->execute(array_merge([$companyId], $channelIds));
    $valid = array_map('intval', array_column($verify->fetchAll(), 'id'));
    if (!$valid) {
        $db->prepare('DELETE FROM user_channels WHERE user_id = ?')->execute([$userId]);
        return;
    }
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM user_channels WHERE user_id = ?')->execute([$userId]);
        $ins = $db->prepare('INSERT INTO user_channels (user_id, channel_id) VALUES (?, ?)');
        foreach ($valid as $cid) {
            $ins->execute([$userId, $cid]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[AiServe user_edit_save_channels] ' . $e->getMessage());
    }
}

