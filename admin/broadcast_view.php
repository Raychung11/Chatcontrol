<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/broadcasts.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$bid = (int)($_GET['id'] ?? 0);
if ($bid <= 0) redirect('/admin/broadcasts.php');

$stmt = $db->prepare(
    'SELECT b.*, ch.name AS channel_name, ch.display_phone AS channel_phone, ch.provider,
            u.name AS created_by_name
     FROM broadcasts b
     LEFT JOIN channels ch ON ch.id = b.channel_id
     LEFT JOIN users    u  ON u.id  = b.created_by_user_id
     WHERE b.id = ? AND b.company_id = ? LIMIT 1'
);
$stmt->execute([$bid, $companyId]);
$b = $stmt->fetch();
if (!$b) { http_response_code(404); exit('Broadcast not found.'); }

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'start' && in_array($b['status'], ['draft', 'paused'], true)) {
        $db->prepare('UPDATE broadcasts SET status = "running" WHERE id = ?')->execute([$bid]);
        log_activity($companyId, (int)$current_user['id'], 'broadcast_started', 'broadcast', $bid);
    } elseif ($action === 'pause' && $b['status'] === 'running') {
        $db->prepare('UPDATE broadcasts SET status = "paused" WHERE id = ?')->execute([$bid]);
        log_activity($companyId, (int)$current_user['id'], 'broadcast_paused', 'broadcast', $bid);
    } elseif ($action === 'cancel' && in_array($b['status'], ['draft','running','paused'], true)) {
        $db->prepare('UPDATE broadcasts SET status = "cancelled", completed_at = NOW() WHERE id = ?')->execute([$bid]);
        log_activity($companyId, (int)$current_user['id'], 'broadcast_cancelled', 'broadcast', $bid);
    }
    redirect('/admin/broadcast_view.php?id=' . $bid);
}

$recipients = $db->prepare(
    'SELECT r.*, ct.display_name AS contact_display, ct.profile_name
     FROM broadcast_recipients r
     LEFT JOIN contacts ct ON ct.id = r.contact_id
     WHERE r.broadcast_id = ?
     ORDER BY (r.status = "queued") DESC, r.id ASC
     LIMIT 1000'
);
$recipients->execute([$bid]);
$recipients = $recipients->fetchAll();

$queued = $sent = $failed = 0;
foreach ($recipients as $r) {
    if ($r['status'] === 'queued') $queued++;
    elseif ($r['status'] === 'sent') $sent++;
    elseif ($r['status'] === 'failed') $failed++;
}
$total   = (int)$b['total_recipients'];
$pct     = $total > 0 ? min(100, (int)round((($sent + $failed) / $total) * 100)) : 0;
$etaMin  = broadcast_eta_minutes($queued, (int)$b['batch_size'], (int)$b['batch_interval_min']);

$skippedNotChannelContact = (int)($_GET['skipped'] ?? 0);

layout_start($current_user, 'Broadcast · ' . $b['name'], 'broadcasts');
?>
<div class="card">
  <div class="card-head">
    <h2><?= e($b['name']) ?></h2>
    <?= status_badge($b['status']) ?>
  </div>

  <?php if ($skippedNotChannelContact > 0): ?>
    <div class="alert alert-info">
      <strong><?= (int)$skippedNotChannelContact ?> number(s) skipped.</strong>
      They aren't contacts on <em><?= e((string)$b['channel_name']) ?></em> yet —
      broadcasts only go to numbers that have already messaged this channel.
      Ask them to send a message first, or pick a different channel and try again.
    </div>
  <?php endif; ?>

  <div class="bcast-meta muted small">
    <strong>Channel:</strong> <?= e($b['channel_name']) ?> (<?= e($b['provider']) ?>)
    · <strong>Cadence:</strong> <?= (int)$b['batch_size'] ?> recipients every <?= (int)$b['batch_interval_min'] ?> min
    · <strong>Created by:</strong> <?= e($b['created_by_name'] ?? '?') ?>
    <?php if ($b['started_at']): ?>· <strong>Started:</strong> <?= e(fmt_dt($b['started_at'])) ?><?php endif; ?>
    <?php if ($b['completed_at']): ?>· <strong>Completed:</strong> <?= e(fmt_dt($b['completed_at'])) ?><?php endif; ?>
  </div>

  <div style="margin: 16px 0;">
    <div class="progress-bar"
         style="height: 14px; background:#eef2f6; border-radius:8px; overflow:hidden; border:1px solid #d8dee5;">
      <div style="width: <?= (int)$pct ?>%; height: 100%; background:#25D366;"></div>
    </div>
    <p class="small" style="margin: 6px 0 0 0;">
      <strong><?= (int)$sent ?></strong> sent
      · <strong><?= (int)$failed ?></strong> failed
      · <strong><?= (int)$queued ?></strong> queued
      / <?= (int)$total ?> total (<?= (int)$pct ?>%)
      <?php if ($queued > 0 && $b['status'] === 'running'): ?>
        <span class="muted">· ETA: ~<?= (int)$etaMin ?> min remaining</span>
      <?php endif; ?>
    </p>
  </div>

  <form method="post" style="display:inline">
    <?= csrf_field() ?>
    <?php if (in_array($b['status'], ['draft','paused'], true)): ?>
      <input type="hidden" name="action" value="start">
      <button type="submit" class="btn btn-primary">Start sending</button>
    <?php elseif ($b['status'] === 'running'): ?>
      <input type="hidden" name="action" value="pause">
      <button type="submit" class="btn">Pause</button>
    <?php endif; ?>
  </form>
  <?php if (in_array($b['status'], ['draft','running','paused'], true)): ?>
    <form method="post" style="display:inline"
          onsubmit="return confirm('Cancel this blast? Already-sent messages stay; queued recipients are dropped.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="btn btn-danger">Cancel blast</button>
    </form>
  <?php endif; ?>
  <a class="btn" href="/admin/broadcasts.php">Back to list</a>
</div>

<?php
  // Fetch attachments. Fall back to the legacy single-column shape if
  // no items rows exist (broadcast created before phase 25).
  $bmItems = $db->prepare(
      'SELECT sequence, media_path, media_kind, media_mime_type, media_filename
       FROM broadcast_media_items WHERE broadcast_id = ? ORDER BY sequence ASC'
  );
  $bmItems->execute([$bid]);
  $bmItems = $bmItems->fetchAll();
  if (!$bmItems && !empty($b['media_path'])) {
      $bmItems = [[
          'sequence' => 1,
          'media_path' => (string)$b['media_path'],
          'media_kind' => (string)$b['media_kind'],
          'media_mime_type' => (string)$b['media_mime_type'],
          'media_filename' => (string)$b['media_filename'],
      ]];
  }
?>
<div class="card">
  <h2>Message text <?php if ($bmItems): ?><small class="muted">(sent as caption under the last attachment)</small><?php endif; ?></h2>
  <pre style="background:#f6f9fb; border:1px solid #e3e8ee; border-radius:8px; padding:12px; white-space:pre-wrap; margin:0;"><?= e($b['message_text'] ?? '') ?></pre>

  <?php if ($bmItems): ?>
    <h3 style="margin-top: 20px;">Attachments (<?= count($bmItems) ?>)</h3>
    <div style="display:grid; gap:8px;">
      <?php foreach ($bmItems as $it): ?>
        <?php $kind = (string)($it['media_kind'] ?? ''); ?>
        <div style="display:flex; gap:14px; align-items:center; padding:12px; background:#f6f9fb; border:1px solid #e3e8ee; border-radius:8px;">
          <div style="min-width:32px; text-align:center; font-weight:700; color:var(--c-muted);">
            #<?= (int)$it['sequence'] ?>
          </div>
          <div style="font-size:24px;">
            <?= ['image'=>'🖼️','video'=>'🎬','document'=>'📄','audio'=>'🎵'][$kind] ?? '📎' ?>
          </div>
          <div style="flex:1; min-width:0;">
            <div><strong><?= e((string)($it['media_filename'] ?: basename((string)$it['media_path']))) ?></strong></div>
            <div class="muted small">
              <?= e(strtoupper($kind ?: '—')) ?>
              <?php if (!empty($it['media_mime_type'])): ?> · <?= e((string)$it['media_mime_type']) ?><?php endif; ?>
              <?php if (is_file((string)$it['media_path'])): ?>
                · <?= e(number_format(filesize((string)$it['media_path']) / 1024, 1)) ?> KB
              <?php else: ?>
                · <span style="color:#c33;">file missing on disk</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Recipients (<?= count($recipients) ?><?= count($recipients) >= 1000 ? '+' : '' ?>)</h2>
  <table class="data-table">
    <thead>
      <tr><th>WA ID</th><th>Name</th><th>Status</th><th>Sent at</th><th>Error</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($recipients as $r):
        $name = $r['contact_display'] ?: $r['display_name'] ?: $r['profile_name'] ?: '—';
      ?>
        <tr>
          <td><code><?= e($r['wa_id']) ?></code></td>
          <td><?= e($name) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td><?= e(fmt_dt($r['sent_at'])) ?: '—' ?></td>
          <td class="muted small"><?= e($r['error_message'] ?? '') ?></td>
          <td>
            <?php if ($r['conversation_id']): ?>
              <a class="btn btn-sm" href="/inbox/chat.php?id=<?= (int)$r['conversation_id'] ?>">Open chat</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
