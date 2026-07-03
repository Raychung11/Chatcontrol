<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $own = $db->prepare('SELECT id FROM auto_replies WHERE id = ? AND company_id = ?');
        $own->execute([$id, $companyId]);
        if ($own->fetchColumn()) {
            if ($action === 'toggle') {
                $db->prepare(
                    'UPDATE auto_replies
                     SET status = IF(status = "active","inactive","active")
                     WHERE id = ?'
                )->execute([$id]);
                log_activity($companyId, (int)$current_user['id'], 'auto_reply_toggled', 'auto_reply', $id);
            } elseif ($action === 'delete') {
                $db->prepare('DELETE FROM auto_replies WHERE id = ?')->execute([$id]);
                log_activity($companyId, (int)$current_user['id'], 'auto_reply_deleted', 'auto_reply', $id);
            }
        }
    }
    redirect('/admin/auto_replies.php');
}

$rows = $db->prepare(
    'SELECT r.*, ch.name AS channel_name
     FROM auto_replies r
     LEFT JOIN channels ch ON ch.id = r.channel_id
     WHERE r.company_id = ?
     ORDER BY r.priority ASC, r.id ASC'
);
$rows->execute([$companyId]);
$rows = $rows->fetchAll();

layout_start($current_user, 'Auto replies', 'auto_replies');
?>
<div class="card">
  <div class="card-head">
    <h2>Keyword auto replies</h2>
    <a class="btn btn-primary" href="/admin/auto_reply_edit.php">+ New rule</a>
  </div>
  <p class="muted small">
    Send a canned message + optional catalog file the moment a customer
    types a matching keyword. Rules are checked in <strong>priority
    order</strong> — the first match wins and stops further rules.
    Each rule has a per-conversation cooldown so a repeat keyword does
    not spam.
  </p>

  <table class="data-table">
    <thead>
      <tr>
        <th>Priority</th>
        <th>Rule</th>
        <th>Match</th>
        <th>Channel</th>
        <th>Media</th>
        <th>Fires</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="muted">No auto-reply rules yet. Click "+ New rule" to add one — e.g. keyword "menu" → send menu.pdf.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['priority'] ?></td>
          <td><a href="/admin/auto_reply_edit.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['name']) ?></strong></a></td>
          <td><code><?= e($r['match_type']) ?></code> "<?= e(mb_strimwidth((string)$r['match_value'], 0, 40, '…')) ?>"</td>
          <td><?= e($r['channel_name'] ?? '— all channels —') ?></td>
          <td>
            <?php if ($r['media_kind'] === 'none' || !$r['media_path']): ?>
              <span class="muted small">text only</span>
            <?php else: ?>
              <?= e($r['media_kind']) ?><?php if ($r['media_filename']): ?>
                <br><small class="muted"><?= e(mb_strimwidth((string)$r['media_filename'], 0, 26, '…')) ?></small>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?= (int)$r['trigger_count'] ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="actions">
            <a class="btn btn-sm" href="/admin/auto_reply_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" type="submit">
                <?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?>
              </button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this rule? Uploaded media file is not removed.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
