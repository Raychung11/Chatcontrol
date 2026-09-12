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
    } elseif ($action === 'save_details' && $branchId > 0 && $name !== '') {
        // Save name + address + area_keywords in one pass. Handles the
        // 'edit branch' inline form below each row. HTML `required` is
        // client-side only, so an empty name check is enforced here
        // too — otherwise a curl or dev-tools POST could blank the name.
        $addr = mb_substr(trim((string)($_POST['address']       ?? '')), 0, 500);
        $kw   = mb_substr(trim((string)($_POST['area_keywords'] ?? '')), 0, 2000);
        try {
            $db->prepare(
                'UPDATE branches SET name = ?, address = ?, area_keywords = ?
                 WHERE id = ? AND company_id = ?'
            )->execute([$name, $addr ?: null, $kw ?: null, $branchId, $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'branch_updated',
                'branch', $branchId, $name);
            $msg = 'Branch updated.';
        } catch (PDOException $e) {
            $err = 'Could not save (name may already be in use).';
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
    <!-- No <thead> — the row body is a single full-width <details> editor
         card per branch (name/status/count are shown inside the <summary>),
         so a fixed column header row would misalign with everything below it. -->
    <tbody>
      <?php if (!$branches): ?>
        <tr><td>
          <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:14px; border-radius:8px;">
            <div style="font-weight:600; margin-bottom:8px;">🏢 No branches yet</div>
            <p style="margin:0 0 8px 0;">Branches represent physical outlets (KL Sentral, Penang, Jelutong). Customers can be routed to their nearest branch, and rotation picks agents whose primary branch matches.</p>
            <ul style="margin:0; padding-left:20px; line-height:1.7;">
              <li>Type a branch name above and click <strong>Create</strong></li>
              <li>Then fill in each branch's <strong>address + area keywords</strong> so the 🗺 Assign-to-nearest-branch flow node can AI-map customers</li>
            </ul>
          </div>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($branches as $b): ?>
        <tr>
          <td style="padding: 12px 14px;">
            <details <?= empty($b['address']) && empty($b['area_keywords']) ? 'open' : '' ?>
                     style="background:#fafbfc; border:1px solid #e3e8ee; border-radius:8px; padding: 10px 12px;">
              <summary style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                <span>
                  <strong style="font-size:14px;"><?= e($b['name']) ?></strong>
                  <?= status_badge($b['status']) ?>
                  <span class="muted small">·
                    <a href="/contacts.php?branch_id=<?= (int)$b['id'] ?>"><?= (int)$b['contact_count'] ?> contact(s)</a>
                    · created <?= e(fmt_dt($b['created_at'])) ?>
                  </span>
                  <?php if (empty($b['address'])): ?>
                    <span style="background:#fef3c7; color:#78350f; padding:1px 8px; border-radius:999px; font-size:11px; margin-left:6px;">
                      ⚠ no address — nearest-branch AI mapping needs this
                    </span>
                  <?php endif; ?>
                </span>
                <span class="muted small">▾ edit</span>
              </summary>

              <form method="post" style="display:grid; gap:10px; margin-top:12px; grid-template-columns: 1fr 2fr;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_details">
                <input type="hidden" name="branch_id" value="<?= (int)$b['id'] ?>">

                <label style="display:block;">
                  <span class="muted small">Branch name</span>
                  <input type="text" name="name" value="<?= e($b['name']) ?>" maxlength="120" required
                         style="width:100%; padding:6px 10px;">
                </label>

                <label style="display:block;">
                  <span class="muted small">Address <em>(shown to customers when the AI routes them here)</em></span>
                  <input type="text" name="address" value="<?= e((string)($b['address'] ?? '')) ?>" maxlength="500"
                         placeholder="e.g. 12, Jalan Bangsar, 59100 Kuala Lumpur"
                         style="width:100%; padding:6px 10px;">
                </label>

                <label style="grid-column: 1 / -1; display:block;">
                  <span class="muted small">
                    Serves these areas <em>(comma-separated — used by the
                    <strong>Assign to nearest branch</strong> flow node so
                    Claude knows which customer locations map to this outlet)</em>
                  </span>
                  <textarea name="area_keywords" rows="2" maxlength="2000"
                            placeholder="e.g. Bangsar, Bangsar South, Kerinchi, Mid Valley, KL Sentral, Brickfields, Pantai Hillpark, Menara UOA, 59100, 59200"
                            style="width:100%; padding:6px 10px; font-family:inherit;"><?= e((string)($b['area_keywords'] ?? '')) ?></textarea>
                  <small class="muted">
                    Tip: include neighborhood names, common landmarks (LRT stations, malls), and postcodes.
                    The more you list, the smarter the AI matcher gets.
                  </small>
                </label>

                <div style="grid-column: 1 / -1;">
                  <button class="btn btn-primary btn-sm" type="submit">💾 Save</button>
                </div>
              </form>

              <div style="margin-top:10px; padding-top:10px; border-top:1px solid #eef2f7; display:flex; gap:6px; flex-wrap:wrap;">
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
                  <button class="btn btn-sm btn-danger" type="submit">🗑 Delete branch</button>
                </form>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
