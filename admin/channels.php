<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/channels.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();
$isPlatform   = is_platform_admin();

$msg = '';

// All mutating actions (toggle, delete, make_default) and the edit/create
// pages are platform-admin only - workspace owners get a read-only list.
if ($isPlatform && is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id > 0) {
        $db->prepare(
            'UPDATE channels
             SET status = IF(status = "active","inactive","active")
             WHERE id = ? AND company_id = ?'
        )->execute([$id, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'channel_toggled', 'channel', $id);
        redirect('/admin/channels.php');
    }

    if ($action === 'delete' && $id > 0) {
        // Cannot delete a default channel without picking a new default first.
        $c = $db->prepare('SELECT is_default FROM channels WHERE id = ? AND company_id = ?');
        $c->execute([$id, $companyId]);
        $row = $c->fetch();
        if ($row && !$row['is_default']) {
            $db->prepare('DELETE FROM channels WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'channel_deleted', 'channel', $id);
            $msg = 'Channel deleted.';
        }
        redirect('/admin/channels.php');
    }

    if ($action === 'make_default' && $id > 0) {
        $check = $db->prepare('SELECT id FROM channels WHERE id = ? AND company_id = ? AND status = "active"');
        $check->execute([$id, $companyId]);
        if ($check->fetchColumn()) {
            $db->prepare('UPDATE channels SET is_default = 0 WHERE company_id = ?')->execute([$companyId]);
            $db->prepare('UPDATE channels SET is_default = 1 WHERE id = ?')->execute([$id]);
            log_activity($companyId, (int)$current_user['id'], 'channel_default_changed', 'channel', $id);
        }
        redirect('/admin/channels.php');
    }
}

$rows = $db->prepare(
    'SELECT c.*,
            (SELECT COUNT(*) FROM conversations WHERE channel_id = c.id) AS conversation_count,
            (SELECT COUNT(*) FROM messages       WHERE channel_id = c.id) AS message_count
     FROM channels c
     WHERE c.company_id = ?
     ORDER BY c.is_default DESC, c.id ASC'
);
$rows->execute([$companyId]);
$channels = $rows->fetchAll();

layout_start($current_user, 'Channels', 'channels');
?>
<div class="card">
  <div class="card-head">
    <h2>WhatsApp channels</h2>
    <?php if ($isPlatform): ?>
      <a class="btn btn-primary" href="/admin/channel_edit.php">+ New channel</a>
    <?php endif; ?>
  </div>
  <p class="muted small">
    Each channel = one WhatsApp number your workspace is connected to.
    <?php if ($isPlatform): ?>
      The <strong>default channel</strong> is used when an incoming webhook arrives without
      an explicit channel token (it's also what unassigned outbound replies use).
    <?php else: ?>
      To add a new number or change a connection, contact your platform administrator.
    <?php endif; ?>
  </p>

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>

  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Phone</th>
        <?php if ($isPlatform): ?><th>Provider</th><?php endif; ?>
        <th>Conversations</th>
        <th>Messages</th>
        <th>Default</th>
        <th>Status</th>
        <?php if ($isPlatform): ?><th></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php $colSpan = $isPlatform ? 8 : 6; ?>
      <?php if (!$channels): ?>
        <tr><td colspan="<?= $colSpan ?>" class="muted">
          <?= $isPlatform ? 'No channels yet — click "+ New channel" to add one.' : 'No channels yet. Contact your platform administrator to connect a WhatsApp number.' ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($channels as $c): ?>
        <tr>
          <td>
            <?php if ($isPlatform): ?>
              <a href="/admin/channel_edit.php?id=<?= (int)$c['id'] ?>"><strong><?= e($c['name']) ?></strong></a>
            <?php else: ?>
              <strong><?= e($c['name']) ?></strong>
            <?php endif; ?>
          </td>
          <td><?= e($c['display_phone'] ?? '—') ?></td>
          <?php if ($isPlatform): ?><td><?= e($c['provider']) ?></td><?php endif; ?>
          <td><?= (int)$c['conversation_count'] ?></td>
          <td><?= (int)$c['message_count'] ?></td>
          <td><?= !empty($c['is_default']) ? '<span class="badge badge-open">Default</span>' : '' ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <?php if ($isPlatform): ?>
          <td class="actions">
            <a class="btn btn-sm" href="/admin/channel_edit.php?id=<?= (int)$c['id'] ?>">Edit</a>
            <?php if (empty($c['is_default']) && $c['status'] === 'active'): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="make_default">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-sm">Make default</button>
              </form>
            <?php endif; ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" class="btn btn-sm">
                <?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?>
              </button>
            </form>
            <?php if (empty($c['is_default'])): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this channel? Its conversations stay but become unassigned to a channel.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
              </form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
