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
    'SELECT c.id, c.name, c.slug, c.plan, c.broadcast_plan, c.broadcast_billing_cycle, c.created_at,
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

  <?php $planChanged = (string)($_GET['plan_changed'] ?? ''); if ($planChanged !== ''): ?>
    <div class="alert alert-success" style="margin: 8px 0 14px;">
      Plan updated: <?= e($planChanged) ?>.
    </div>
  <?php endif; ?>
  <?php $planError = (string)($_GET['plan_error'] ?? ''); if ($planError !== ''): ?>
    <div class="alert alert-error" style="margin: 8px 0 14px;">
      <?= e($planError) ?>
    </div>
  <?php endif; ?>

  <table class="data-table">
    <thead>
      <tr>
        <th>Workspace</th>
        <th>Slug</th>
        <th>Plan</th>
        <th>Broadcast plan</th>
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
        <tr><td colspan="10" class="muted">No active workspaces yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e((string)$r['name']) ?></strong></td>
          <td><code><?= e((string)$r['slug']) ?></code></td>
          <td>
            <form method="post" action="/api/workspace_action.php" style="display:flex; gap:4px; align-items:center;"
                  onsubmit="return confirm('Change plan for &quot;<?= e(addslashes((string)$r['name'])) ?>&quot; from <?= e(ucfirst((string)$r['plan'])) ?> to ' + this.plan.options[this.plan.selectedIndex].text + '?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="change_plan">
              <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
              <select name="plan" style="padding:2px 4px; font-size:12px;">
                <?php foreach (['starter' => 'Starter', 'growth' => 'Growth', 'enterprise' => 'Enterprise'] as $key => $label): ?>
                  <option value="<?= $key ?>" <?= (string)$r['plan'] === $key ? 'selected' : '' ?>>
                    <?= $label ?> (<?= (int)plan_seat_limit($key) === 9999 ? '∞' : (int)plan_seat_limit($key) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm" style="padding:2px 6px;" title="Change plan">✓</button>
            </form>
          </td>
          <td>
            <?php
              $bcastPlan = (string)($r['broadcast_plan'] ?? 'free');
              $bcastQuota = broadcast_quota_for_workspace((int)$r['id']);
            ?>
            <?php $curCycle = (string)($r['broadcast_billing_cycle'] ?? 'monthly'); ?>
            <form method="post" action="/api/workspace_action.php" style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;"
                  onsubmit="return confirm('Change broadcast plan for &quot;<?= e(addslashes((string)$r['name'])) ?>&quot; to ' + this.broadcast_plan.options[this.broadcast_plan.selectedIndex].text + '?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="change_broadcast_plan">
              <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
              <select name="broadcast_plan" style="padding:2px 4px; font-size:12px;">
                <option value="free" <?= $bcastPlan === 'free' ? 'selected' : '' ?>>Free (<?= number_format($bcastQuota['free_limit']) ?>)</option>
                <option value="paid" <?= $bcastPlan === 'paid' ? 'selected' : '' ?>>Paid (<?= number_format($bcastQuota['paid_limit']) ?>)</option>
                <option value="payg" <?= $bcastPlan === 'payg' ? 'selected' : '' ?>>PAYG (∞)</option>
              </select>
              <select name="broadcast_billing_cycle" style="padding:2px 4px; font-size:12px;">
                <option value="monthly" <?= $curCycle === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="yearly"  <?= $curCycle === 'yearly'  ? 'selected' : '' ?>>Yearly (<?= (int)$bcastQuota['yearly_discount'] ?>% off)</option>
              </select>
              <button type="submit" class="btn btn-sm" style="padding:2px 6px;" title="Change broadcast plan">✓</button>
            </form>
            <div class="muted small" style="margin-top:2px;">
              <?php if ($bcastPlan === 'payg'): ?>
                <?= number_format($bcastQuota['used']) ?> sent · accrued
                <?= e($bcastQuota['currency']) ?> <?= number_format($bcastQuota['payg_accrued'], 2) ?>
              <?php else: ?>
                <?= number_format($bcastQuota['used']) ?> / <?= number_format($bcastQuota['limit']) ?> used
              <?php endif; ?>
            </div>
          </td>
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
