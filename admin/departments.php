<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$err = '';
$msg = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $deptId = (int)($_POST['department_id'] ?? 0);
    $name   = trim((string)($_POST['name'] ?? ''));

    if ($action === 'create' && $name !== '') {
        try {
            $db->prepare('INSERT INTO departments (company_id, name, status) VALUES (?, ?, "active")')
               ->execute([$companyId, $name]);
            log_activity($companyId, (int)$current_user['id'], 'department_created', 'department', (int)$db->lastInsertId(), $name);
            $msg = 'Department created.';
        } catch (PDOException $e) {
            $err = 'Could not create department.';
        }
    } elseif ($action === 'rename' && $deptId > 0 && $name !== '') {
        $db->prepare('UPDATE departments SET name = ? WHERE id = ? AND company_id = ?')
           ->execute([$name, $deptId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'department_renamed', 'department', $deptId, $name);
        $msg = 'Department updated.';
    } elseif ($action === 'toggle' && $deptId > 0) {
        $db->prepare('UPDATE departments SET status = IF(status = "active", "inactive", "active")
                      WHERE id = ? AND company_id = ?')
           ->execute([$deptId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'department_toggled', 'department', $deptId);
    }
}

$stmt = $db->prepare('SELECT * FROM departments WHERE company_id = ? ORDER BY name');
$stmt->execute([$companyId]);
$departments = $stmt->fetchAll();

layout_start($current_user, 'Departments', 'departments');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <input type="text" name="name" placeholder="New department name" required>
    <button class="btn btn-primary" type="submit">Create</button>
  </form>

  <table class="data-table">
    <thead><tr><th>Name</th><th>Status</th><th>Created</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($departments as $d): ?>
        <tr>
          <td>
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="department_id" value="<?= (int)$d['id'] ?>">
              <input type="text" name="name" value="<?= e($d['name']) ?>">
              <button class="btn btn-sm" type="submit">Save</button>
            </form>
          </td>
          <td><?= status_badge($d['status']) ?></td>
          <td><?= e(fmt_dt($d['created_at'])) ?></td>
          <td>
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="department_id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-sm" type="submit">
                <?= $d['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
