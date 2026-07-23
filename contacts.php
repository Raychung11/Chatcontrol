<?php
require_once __DIR__ . '/inc/layout.php';

$current_user = require_login();
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$search      = trim((string)($_GET['q'] ?? ''));
$branchId    = (int)($_GET['branch_id'] ?? 0);

$where  = ['c.company_id = ?'];
$params = [$companyId];
if ($search !== '') {
    $where[] = '(c.display_name LIKE ? OR c.profile_name LIKE ? OR c.phone LIKE ? OR c.wa_id LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($branchId > 0) {
    $where[]  = 'c.branch_id = ?';
    $params[] = $branchId;
}

$sql = 'SELECT c.*, b.name AS branch_name
        FROM contacts c
        LEFT JOIN branches b ON b.id = c.branch_id
        WHERE ' . implode(' AND ', $where)
     . ' ORDER BY c.last_message_at DESC LIMIT 200';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$contacts = $stmt->fetchAll();

// Branches for the filter dropdown.
$bstmt = $db->prepare(
    'SELECT id, name FROM branches WHERE company_id = ? AND status = "active" ORDER BY name'
);
$bstmt->execute([$companyId]);
$branches = $bstmt->fetchAll();

layout_start($current_user, 'Contacts', 'contacts');
?>
<div class="card">
  <div class="card-head">
    <h2>Contacts <small class="muted">(<?= count($contacts) ?><?= count($contacts) >= 200 ? '+' : '' ?>)</small></h2>
    <?php if (in_array($current_user['role'] ?? 'agent', ['super_admin', 'manager'], true)): ?>
      <div style="display:flex; gap:6px;">
        <a class="btn btn-sm btn-primary" href="/contact_import.php">📄 Import CSV</a>
      </div>
    <?php endif; ?>
  </div>
  <form method="get" class="inline-form" style="display:flex; gap:8px; flex-wrap:wrap;">
    <input type="search" name="q" placeholder="Search by name / phone…" value="<?= e($search) ?>" style="min-width:220px;">
    <select name="branch_id" onchange="this.form.submit()">
      <option value="0">All branches</option>
      <?php foreach ($branches as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>>
          <?= e($b['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Search</button>
  </form>
  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>WhatsApp ID</th>
        <th>Phone</th>
        <th>Branch</th>
        <th>Last message</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$contacts): ?>
        <tr><td colspan="5" class="muted">No contacts match this view.</td></tr>
      <?php endif; ?>
      <?php foreach ($contacts as $c): ?>
        <tr>
          <td><?= e($c['display_name'] ?: $c['profile_name'] ?: '—') ?></td>
          <td>+<?= e($c['wa_id']) ?></td>
          <td><?= e($c['phone'] ?? '—') ?></td>
          <td class="muted small"><?= e($c['branch_name'] ?: '—') ?></td>
          <td><?= e(fmt_dt($c['last_message_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
