<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_login();
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}
if (is_impersonating()) {
    redirect('/dashboard.php');
}

$db = aiserve_db();

// Provider comes from the DEFAULT CHANNEL, not the companies.provider
// column (which is now dead weight since the multi-channel refactor
// moved provider config to channels). Reading c.provider showed stale
// data on this list whenever the operator changed a channel's provider
// without touching the workspace's Settings page - the workspaces list
// stayed at whatever companies.provider was seeded to on register.
// Fallback if no channel exists yet: show "(no channel)".
$stmt = $db->query(
    'SELECT c.id, c.name, c.slug, c.plan, c.created_at,
            (SELECT provider FROM channels
              WHERE company_id = c.id AND is_default = 1 LIMIT 1)
              AS provider,
            (SELECT COUNT(*) FROM users WHERE company_id = c.id AND status = "active") AS active_users,
            (SELECT COUNT(*) FROM conversations WHERE company_id = c.id) AS conversations,
            (SELECT MAX(created_at) FROM messages WHERE company_id = c.id) AS last_message_at,
            (SELECT COUNT(*) FROM channels WHERE company_id = c.id) AS channel_count
     FROM companies c
     WHERE c.status = "active"
     ORDER BY c.created_at DESC'
);
$rows = $stmt->fetchAll();

$archivedId = (int)($_GET['archived'] ?? 0);
$archivedName = '';
if ($archivedId > 0) {
    $s = $db->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
    $s->execute([$archivedId]);
    $archivedName = (string)($s->fetchColumn() ?: '');
}

layout_start($current_user, 'Workspaces', 'workspaces');
?>
<div class="card">
  <h2>All workspaces</h2>
  <p class="muted small">
    Sign in as the super admin of any workspace to set up their provider, AI, knowledge base, or
    users on their behalf. The customer doesn't need to share their password — every action is
    logged in their activity log.
  </p>

  <?php if ($archivedName !== ''): ?>
    <div class="alert alert-success" style="margin: 8px 0 14px;">
      Workspace <strong><?= e($archivedName) ?></strong> archived. It's hidden from this list but
      the data is preserved — flip <code>companies.status</code> back to <code>active</code> in the
      database to restore.
    </div>
  <?php endif; ?>

  <table class="data-table">
    <thead>
      <tr>
        <th>Workspace</th>
        <th>Slug</th>
        <th>Plan</th>
        <th>Provider</th>
        <th>Users</th>
        <th>Conversations</th>
        <th>Last message</th>
        <th>Created</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="muted">No active workspaces yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e((string)$r['name']) ?></strong></td>
          <td><code><?= e((string)$r['slug']) ?></code></td>
          <td><?= e(ucfirst((string)$r['plan'])) ?></td>
          <td>
            <?php if (!empty($r['provider'])): ?>
              <?= e((string)$r['provider']) ?>
              <?php if ((int)$r['channel_count'] > 1): ?>
                <small class="muted">· +<?= (int)$r['channel_count'] - 1 ?> more</small>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted small">(no channel)</span>
            <?php endif; ?>
          </td>
          <td><?= (int)$r['active_users'] ?> / <?= (int)plan_seat_limit((string)$r['plan']) ?></td>
          <td><?= (int)$r['conversations'] ?></td>
          <td class="muted small"><?= e(fmt_dt($r['last_message_at']) ?: '—') ?></td>
          <td class="muted small"><?= e(fmt_dt($r['created_at'])) ?></td>
          <td class="actions">
            <?php if ((int)$r['id'] !== (int)$current_user['company_id']): ?>
              <form method="post" action="/api/impersonate.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-primary" type="submit">Sign in as super admin</button>
              </form>
              <form method="post" action="/api/workspace_action.php" style="display:inline"
                    onsubmit="return confirm('Archive workspace &quot;<?= e(addslashes((string)$r['name'])) ?>&quot;?\n\nIt will be hidden from this list. All data (conversations, messages, users) is preserved. You can restore it later from the database.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit" style="margin-left:6px;">Archive</button>
              </form>
            <?php else: ?>
              <span class="muted small">your workspace</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
