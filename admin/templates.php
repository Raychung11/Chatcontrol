<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

if (is_post() && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    if (!user_can_edit_settings($current_user)) {
        http_response_code(403); exit('Forbidden.');
    }
    $tid = (int)($_POST['id'] ?? 0);
    $db->prepare('DELETE FROM message_templates WHERE id = ? AND company_id = ?')
       ->execute([$tid, $companyId]);
    log_activity($companyId, (int)$current_user['id'], 'template_deleted', 'template', $tid);
    redirect('/admin/templates.php');
}

$stmt = $db->prepare('SELECT * FROM message_templates WHERE company_id = ? ORDER BY template_name');
$stmt->execute([$companyId]);
$templates = $stmt->fetchAll();

layout_start($current_user, 'Message templates', 'templates');
?>
<div class="card">
  <div class="card-head">
    <h2>WhatsApp message templates</h2>
    <?php if (user_can_edit_settings($current_user)): ?>
      <a class="btn btn-primary" href="/admin/template_edit.php">+ New template</a>
    <?php endif; ?>
  </div>
  <p class="muted small">
    Templates are pre-approved by Meta and required when replying outside the 24-hour window.
    Save the approved template name and body here so agents can reference them.
  </p>

  <table class="data-table">
    <thead><tr><th>Name</th><th>Category</th><th>Language</th><th>Body</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (!$templates): ?>
        <tr><td colspan="6" class="muted">No templates yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($templates as $t): ?>
        <tr>
          <td><code><?= e($t['template_name']) ?></code></td>
          <td><?= e($t['category'] ?? '—') ?></td>
          <td><?= e($t['language']) ?></td>
          <td><?= e(mb_strimwidth((string)$t['body_text'], 0, 100, '…')) ?></td>
          <td><?= status_badge($t['status']) ?></td>
          <td>
            <?php if (user_can_edit_settings($current_user)): ?>
              <a class="btn btn-sm" href="/admin/template_edit.php?id=<?= (int)$t['id'] ?>">Edit</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this template?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
