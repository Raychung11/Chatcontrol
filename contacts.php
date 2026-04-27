<?php
require_once __DIR__ . '/inc/layout.php';

$current_user = require_login();
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$search = trim((string)($_GET['q'] ?? ''));
$where  = ['company_id = ?'];
$params = [$companyId];
if ($search !== '') {
    $where[] = '(display_name LIKE ? OR profile_name LIKE ? OR phone LIKE ? OR wa_id LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql = 'SELECT * FROM contacts WHERE ' . implode(' AND ', $where)
     . ' ORDER BY last_message_at DESC LIMIT 200';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$contacts = $stmt->fetchAll();

layout_start($current_user, 'Contacts', 'contacts');
?>
<div class="card">
  <form method="get" class="inline-form">
    <input type="search" name="q" placeholder="Search by name / phone…" value="<?= e($search) ?>">
    <button class="btn btn-primary" type="submit">Search</button>
  </form>
  <table class="data-table">
    <thead><tr><th>Name</th><th>WhatsApp ID</th><th>Phone</th><th>Last message</th></tr></thead>
    <tbody>
      <?php if (!$contacts): ?>
        <tr><td colspan="4" class="muted">No contacts yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($contacts as $c): ?>
        <tr>
          <td><?= e($c['display_name'] ?: $c['profile_name'] ?: '—') ?></td>
          <td>+<?= e($c['wa_id']) ?></td>
          <td><?= e($c['phone'] ?? '—') ?></td>
          <td><?= e(fmt_dt($c['last_message_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
