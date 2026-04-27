<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$err = '';
$msg = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $tagId  = (int)   ($_POST['tag_id'] ?? 0);
    $name   = trim((string)($_POST['name']  ?? ''));
    $color  = trim((string)($_POST['color'] ?? '#999999')) ?: '#999999';

    if ($action === 'create' && $name !== '') {
        try {
            $db->prepare('INSERT INTO conversation_tags (company_id, name, color) VALUES (?, ?, ?)')
               ->execute([$companyId, $name, $color]);
            log_activity($companyId, (int)$current_user['id'], 'tag_created', 'tag',
                (int)$db->lastInsertId(), $name);
            $msg = 'Tag created.';
        } catch (PDOException $e) {
            $err = ((int)$e->errorInfo[1] === 1062)
                ? 'A tag with that name already exists.'
                : 'Could not create tag.';
        }
    } elseif ($action === 'rename' && $tagId > 0 && $name !== '') {
        $db->prepare('UPDATE conversation_tags SET name = ?, color = ? WHERE id = ? AND company_id = ?')
           ->execute([$name, $color, $tagId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'tag_updated', 'tag', $tagId, $name);
        $msg = 'Tag updated.';
    } elseif ($action === 'delete' && $tagId > 0) {
        $db->prepare('DELETE FROM conversation_tags WHERE id = ? AND company_id = ?')
           ->execute([$tagId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'tag_deleted', 'tag', $tagId);
        $msg = 'Tag deleted.';
    }
}

$stmt = $db->prepare(
    'SELECT t.*,
            (SELECT COUNT(*) FROM conversation_tag_map m WHERE m.tag_id = t.id) AS used_count
     FROM conversation_tags t
     WHERE t.company_id = ? ORDER BY t.name'
);
$stmt->execute([$companyId]);
$tags = $stmt->fetchAll();

layout_start($current_user, 'Tags', 'tags');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <input type="text"  name="name"  placeholder="New tag name" required>
    <input type="color" name="color" value="#25D366" title="Tag color">
    <button class="btn btn-primary" type="submit">Create</button>
  </form>

  <table class="data-table">
    <thead><tr><th>Tag</th><th>Color</th><th>Used by</th><th></th></tr></thead>
    <tbody>
      <?php if (!$tags): ?>
        <tr><td colspan="4" class="muted">No tags yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($tags as $t): ?>
        <tr>
          <td>
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="tag_id" value="<?= (int)$t['id'] ?>">
              <input type="text"  name="name"  value="<?= e($t['name']) ?>" required>
              <input type="color" name="color" value="<?= e($t['color']) ?>">
              <button class="btn btn-sm" type="submit">Save</button>
            </form>
          </td>
          <td><span class="tag-chip" style="background: <?= e($t['color']) ?>"><?= e($t['name']) ?></span></td>
          <td><?= (int)$t['used_count'] ?> conversations</td>
          <td>
            <form method="post" class="inline-form" onsubmit="return confirm('Delete this tag? It will be removed from all conversations.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="tag_id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
