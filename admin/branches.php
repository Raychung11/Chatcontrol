<?php
/**
 * Branches admin — CRUD for the branch dimension on contacts.
 *
 * Branches represent a physical location or business unit that owns a
 * customer (KL Office, Penang Office, etc). Not the same as departments,
 * which are conversation-routing buckets (Sales, Support, Billing).
 * A contact belongs to at most one branch; branch is optional.
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$err = '';
$msg = '';

if (is_post()) {
    csrf_check();
    $action   = (string)($_POST['action'] ?? '');
    $branchId = (int)($_POST['branch_id'] ?? 0);
    $name     = trim((string)($_POST['name'] ?? ''));

    if ($action === 'create' && $name !== '') {
        try {
            $db->prepare('INSERT INTO branches (company_id, name, status) VALUES (?, ?, "active")')
               ->execute([$companyId, $name]);
            log_activity($companyId, (int)$current_user['id'], 'branch_created',
                'branch', (int)$db->lastInsertId(), $name);
            $msg = 'Branch created.';
        } catch (PDOException $e) {
            $err = (int)$e->errorInfo[1] === 1062
                ? 'A branch with that name already exists.'
                : 'Could not create branch.';
        }
    } elseif ($action === 'rename' && $branchId > 0 && $name !== '') {
        try {
            $db->prepare('UPDATE branches SET name = ? WHERE id = ? AND company_id = ?')
               ->execute([$name, $branchId, $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'branch_renamed',
                'branch', $branchId, $name);
            $msg = 'Branch renamed.';
        } catch (PDOException $e) {
            $err = 'Could not rename (name may already be in use).';
        }
    } elseif ($action === 'toggle' && $branchId > 0) {
        $db->prepare(
            'UPDATE branches SET status = IF(status = "active", "inactive", "active")
             WHERE id = ? AND company_id = ?'
        )->execute([$branchId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'branch_toggled', 'branch', $branchId);
    } elseif ($action === 'delete' && $branchId > 0) {
        // Contacts.branch_id FK is ON DELETE SET NULL, so contacts stay
        // put, just lose their branch tag. No cascading data loss.
        $db->prepare('DELETE FROM branches WHERE id = ? AND company_id = ?')
           ->execute([$branchId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'branch_deleted', 'branch', $branchId);
        $msg = 'Branch deleted. Contacts formerly in it are now unassigned.';
    }
}

$stmt = $db->prepare(
    'SELECT b.*,
            (SELECT COUNT(*) FROM contacts WHERE branch_id = b.id) AS contact_count
     FROM branches b
     WHERE b.company_id = ?
     ORDER BY b.name'
);
$stmt->execute([$companyId]);
$branches = $stmt->fetchAll();

layout_start($current_user, 'Branches', 'branches');
?>
<div class="card">
  <div class="card-head">
    <h2>Branches</h2>
    <a class="btn" href="/contacts.php">Contacts →</a>
  </div>
  <p class="muted small">
    A branch is a location or business unit that owns a customer
    (e.g. <em>KL Office</em>, <em>Penang Office</em>). Assign a branch to a
    contact from the chat side panel, from the contacts list, or via the
    CSV importer's <code>branch</code> column. Different from
    <a href="/admin/departments.php">departments</a>, which route conversations
    to a team (Sales / Support / Billing).
  </p>

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="inline-form" style="display:flex; gap:8px; margin-bottom:12px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <input type="text" name="name" placeholder="New branch name (e.g. KL Office)" required maxlength="120" style="flex:1; min-width:180px;">
    <button class="btn btn-primary" type="submit">Create</button>
  </form>

  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Contacts</th>
        <th>Status</th>
        <th>Created</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$branches): ?>
        <tr><td colspan="5" class="muted">No branches yet — create your first above.</td></tr>
      <?php endif; ?>
      <?php foreach ($branches as $b): ?>
        <tr>
          <td>
            <form method="post" style="display:flex; gap:6px;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="branch_id" value="<?= (int)$b['id'] ?>">
              <input type="text" name="name" value="<?= e($b['name']) ?>" maxlength="120" required style="min-width:180px;">
              <button class="btn btn-sm" type="submit">Rename</button>
            </form>
          </td>
          <td>
            <a href="/contacts.php?branch_id=<?= (int)$b['id'] ?>"><?= (int)$b['contact_count'] ?></a>
          </td>
          <td><?= status_badge($b['status']) ?></td>
          <td class="muted small"><?= e(fmt_dt($b['created_at'])) ?></td>
          <td class="actions">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="branch_id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-sm" type="submit">
                <?= $b['status'] === 'active' ? 'Disable' : 'Enable' ?>
              </button>
            </form>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Delete this branch? Contacts in it will become unassigned but their conversations stay.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="branch_id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
