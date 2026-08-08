<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/broadcasts.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $bid    = (int)($_POST['id'] ?? 0);
    if ($bid > 0) {
        // Verify the broadcast belongs to this workspace.
        $own = $db->prepare('SELECT id FROM broadcasts WHERE id = ? AND company_id = ?');
        $own->execute([$bid, $companyId]);
        if ($own->fetchColumn()) {
            if ($action === 'pause') {
                $db->prepare('UPDATE broadcasts SET status = "paused" WHERE id = ?')->execute([$bid]);
                log_activity($companyId, (int)$current_user['id'], 'broadcast_paused', 'broadcast', $bid);
            } elseif ($action === 'resume') {
                $db->prepare('UPDATE broadcasts SET status = "running" WHERE id = ?')->execute([$bid]);
                log_activity($companyId, (int)$current_user['id'], 'broadcast_resumed', 'broadcast', $bid);
            } elseif ($action === 'cancel') {
                $db->prepare('UPDATE broadcasts SET status = "cancelled", completed_at = NOW() WHERE id = ?')->execute([$bid]);
                log_activity($companyId, (int)$current_user['id'], 'broadcast_cancelled', 'broadcast', $bid);
            }
        }
    }
    redirect('/admin/broadcasts.php');
}

$stmt = $db->prepare(
    'SELECT b.*, ch.name AS channel_name, u.name AS created_by_name
     FROM broadcasts b
     LEFT JOIN channels ch ON ch.id = b.channel_id
     LEFT JOIN users    u  ON u.id  = b.created_by_user_id
     WHERE b.company_id = ?
     ORDER BY b.id DESC
     LIMIT 200'
);
$stmt->execute([$companyId]);
$rows = $stmt->fetchAll();

$quota = broadcast_quota_for_workspace($companyId);
if (!$quota['unlimited']) {
    $quotaPct = $quota['limit'] > 0 ? round(($quota['used'] / $quota['limit']) * 100) : 0;
    $quotaBarColor = $quotaPct >= 90 ? '#DC2626' : ($quotaPct >= 70 ? '#F59E0B' : '#25D366');
} else {
    $quotaPct = 0;
    $quotaBarColor = '#0891B2';
}

layout_start($current_user, 'Broadcasts', 'broadcasts');
?>

<div class="card" style="margin-bottom: 12px; border-left: 4px solid <?= $quotaBarColor ?>;">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <div>
      <strong>
        <?php if ($quota['plan'] === 'payg'): ?>⚡ Pay-as-you-go
        <?php elseif ($quota['plan'] === 'paid'): ?>💎 Paid broadcast plan
        <?php else: ?>Free broadcast plan<?php endif; ?>
      </strong>
      <?php if ($quota['plan'] === 'payg'): ?>
        <span class="muted small">
          · <?= number_format($quota['used']) ?> recipient(s) sent this month
          · accrued: <strong><?= e($quota['currency']) ?> <?= number_format($quota['payg_accrued'], 2) ?></strong>
        </span>
      <?php else: ?>
        <span class="muted small">
          · <?= number_format($quota['used']) ?> / <?= number_format($quota['limit']) ?> recipients used this month
          · <?= number_format($quota['remaining']) ?> remaining
        </span>
      <?php endif; ?>
    </div>
    <?php if ($quota['plan'] === 'free'): ?>
      <a class="btn btn-primary btn-sm" href="/admin/plan.php"
         title="Upgrade to Paid: <?= number_format($quota['paid_limit']) ?> recipients / month">
        ✨ Upgrade — <?= e($quota['currency']) ?> <?= rtrim(rtrim(number_format($quota['price'], 2), '0'), '.') ?>/mo
      </a>
    <?php elseif ($quota['plan'] === 'paid' && $quota['billing_cycle'] === 'yearly'): ?>
      <span class="muted small">Yearly billing (<?= (int)$quota['yearly_discount'] ?>% off)
        · <a href="/admin/plan.php">manage</a></span>
    <?php else: ?>
      <a href="/admin/plan.php" class="muted small">Manage plan →</a>
    <?php endif; ?>
  </div>
  <?php if (!$quota['unlimited']): ?>
    <div style="height:6px; background:#f6f9fb; border-radius:3px; overflow:hidden; margin-top:6px;">
      <div style="height:100%; width:<?= min(100, $quotaPct) ?>%; background:<?= $quotaBarColor ?>;"></div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <h2>WhatsApp broadcasts</h2>
    <a class="btn btn-primary" href="/admin/broadcast_new.php">+ New broadcast</a>
  </div>
  <p class="muted small">
    Trickle a message to many recipients in small batches so the WhatsApp
    provider doesn't flag your number as a spammer. Each blast sends
    <em>batch&nbsp;size</em> messages every <em>interval</em> minutes via a
    cron job. Pause, resume, or cancel a blast at any time — already-sent
    messages stay sent.
  </p>

  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Channel</th>
        <th>Cadence</th>
        <th>Progress</th>
        <th>Status</th>
        <th>Created</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted">No broadcasts yet. Click "+ New broadcast" to create one.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $b): ?>
        <tr>
          <td><a href="/admin/broadcast_view.php?id=<?= (int)$b['id'] ?>"><strong><?= e($b['name']) ?></strong></a></td>
          <td><?= e($b['channel_name'] ?? '—') ?></td>
          <td><?= (int)$b['batch_size'] ?> / <?= (int)$b['batch_interval_min'] ?> min</td>
          <td><?= e(broadcast_progress_label($b)) ?></td>
          <td><?= status_badge($b['status']) ?></td>
          <td><?= e(fmt_dt($b['created_at'])) ?: '—' ?><br><span class="muted small">by <?= e($b['created_by_name'] ?? '?') ?></span></td>
          <td class="actions">
            <?php if (in_array($b['status'], ['running','paused','draft'], true)): ?>
              <a class="btn btn-sm" href="/admin/broadcast_view.php?id=<?= (int)$b['id'] ?>">Open</a>
            <?php endif; ?>
            <?php if ($b['status'] === 'running'): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pause">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn btn-sm" type="submit">Pause</button>
              </form>
            <?php elseif ($b['status'] === 'paused'): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resume">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn btn-sm" type="submit">Resume</button>
              </form>
            <?php endif; ?>
            <?php if (in_array($b['status'], ['running','paused','draft'], true)): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Cancel this blast? Already-sent messages stay; queued recipients are dropped.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Cancel</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
