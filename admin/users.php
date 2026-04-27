<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

if (is_post() && ($_POST['action'] ?? '') === 'toggle_status') {
    csrf_check();
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid > 0 && $uid !== (int)$current_user['id']) {
        $stmt = $db->prepare(
            'UPDATE users
             SET status = IF(status = "active", "inactive", "active")
             WHERE id = ? AND company_id = ?'
        );
        $stmt->execute([$uid, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'user_status_toggled', 'user', $uid);
    }
    redirect('/admin/users.php');
}

$stmt = $db->prepare(
    'SELECT u.*, d.name AS department_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.company_id = ?
     ORDER BY u.role, u.name'
);
$stmt->execute([$companyId]);
$users = $stmt->fetchAll();

layout_start($current_user, 'Users', 'users');
?>
<div class="card">
  <div class="card-head">
    <h2>Portal users</h2>
    <a class="btn btn-primary" href="/admin/user_edit.php">+ New user</a>
  </div>
  <table class="data-table">
    <thead>
      <tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Last login</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><?= e(role_label($u['role'])) ?></td>
          <td><?= e($u['department_name'] ?? '—') ?></td>
          <td><?= status_badge($u['status']) ?></td>
          <td><?= e(fmt_dt($u['last_login_at'])) ?: '—' ?></td>
          <td class="actions">
            <a class="btn btn-sm" href="/admin/user_edit.php?id=<?= (int)$u['id'] ?>">Edit</a>
            <?php if ((int)$u['id'] !== (int)$current_user['id']): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm" type="submit">
                  <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
