<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/broadcasts.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$channels = $db->prepare(
    'SELECT id, name, display_phone, provider FROM channels
     WHERE company_id = ? AND status = "active" ORDER BY is_default DESC, name'
);
$channels->execute([$companyId]);
$channels = $channels->fetchAll();

$tags = $db->prepare(
    'SELECT id, name, color FROM conversation_tags WHERE company_id = ? ORDER BY name'
);
$tags->execute([$companyId]);
$tags = $tags->fetchAll();

$err   = '';
$saved = [
    'name'         => '',
    'channel_id'   => $channels[0]['id'] ?? 0,
    'message_text' => '',
    'batch_size'   => 5,
    'interval_min' => 3,
    'source'       => 'paste',
    'numbers'      => '',
    'tag_id'       => 0,
    'start_now'    => 1,
];

if (is_post()) {
    csrf_check();
    $saved['name']         = trim((string)($_POST['name']         ?? ''));
    $saved['channel_id']   = (int)($_POST['channel_id'] ?? 0);
    $saved['message_text'] = trim((string)($_POST['message_text'] ?? ''));
    $saved['batch_size']   = max(1, min(50, (int)($_POST['batch_size']   ?? 5)));
    $saved['interval_min'] = max(1, min(60, (int)($_POST['interval_min'] ?? 3)));
    $saved['source']       = (string)($_POST['source']  ?? 'paste');
    $saved['numbers']      = (string)($_POST['numbers'] ?? '');
    $saved['tag_id']       = (int)($_POST['tag_id'] ?? 0);
    $saved['start_now']    = !empty($_POST['start_now']) ? 1 : 0;

    // Verify channel belongs to this workspace.
    $ch = null;
    foreach ($channels as $c) {
        if ((int)$c['id'] === $saved['channel_id']) { $ch = $c; break; }
    }

    if ($saved['name'] === '')               $err = 'Give the broadcast a name.';
    elseif ($saved['message_text'] === '')   $err = 'Enter the message text.';
    elseif (mb_strlen($saved['message_text']) > 4000) $err = 'Message is too long (max 4000 chars).';
    elseif (!$ch)                            $err = 'Pick a channel.';

    $waIds = [];
    if (!$err) {
        if ($saved['source'] === 'tag') {
            if ($saved['tag_id'] <= 0) {
                $err = 'Pick a tag.';
            } else {
                $r = $db->prepare(
                    'SELECT DISTINCT ct.wa_id, ct.display_name
                     FROM conversation_tag_map m
                     JOIN conversations c ON c.id = m.conversation_id
                     JOIN contacts ct     ON ct.id = c.contact_id
                     JOIN conversation_tags t ON t.id = m.tag_id
                     WHERE m.tag_id = ? AND t.company_id = ?'
                );
                $r->execute([$saved['tag_id'], $companyId]);
                foreach ($r->fetchAll() as $row) {
                    $wa = broadcast_normalize_wa((string)$row['wa_id']);
                    if (strlen($wa) >= 8) $waIds[$wa] = $row['display_name'] ?: $wa;
                }
            }
        } else {
            foreach (broadcast_parse_numbers($saved['numbers']) as $wa) {
                $waIds[$wa] = $wa;
            }
        }

        if (!$err && !$waIds) $err = 'No valid recipient numbers found.';
        if (!$err && count($waIds) > 5000) $err = 'Recipient limit is 5000 per blast.';
    }

    if (!$err) {
        try {
            $db->beginTransaction();
            $ins = $db->prepare(
                'INSERT INTO broadcasts
                    (company_id, channel_id, created_by_user_id, name, message_text,
                     status, batch_size, batch_interval_min, total_recipients)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $companyId, $saved['channel_id'], (int)$current_user['id'],
                $saved['name'], $saved['message_text'],
                $saved['start_now'] ? 'running' : 'draft',
                $saved['batch_size'], $saved['interval_min'],
                count($waIds),
            ]);
            $bid = (int)$db->lastInsertId();

            $rins = $db->prepare(
                'INSERT IGNORE INTO broadcast_recipients
                    (broadcast_id, wa_id, display_name, status)
                 VALUES (?, ?, ?, "queued")'
            );
            foreach ($waIds as $wa => $name) {
                $rins->execute([$bid, $wa, $name]);
            }

            $db->commit();
            log_activity($companyId, (int)$current_user['id'], 'broadcast_created',
                'broadcast', $bid,
                'recipients=' . count($waIds) . ' batch=' . $saved['batch_size']
                . ' interval=' . $saved['interval_min'] . 'min'
                . ' status=' . ($saved['start_now'] ? 'running' : 'draft'));
            redirect('/admin/broadcast_view.php?id=' . $bid);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[AiServe broadcast create] ' . $e->getMessage());
            $err = 'Could not create the broadcast. Check the migration is in (sql/migration_phase15.sql).';
        }
    }
}

layout_start($current_user, 'New broadcast', 'broadcasts');
?>
<div class="card">
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>

    <h2>Message</h2>
    <label>Internal name <small class="muted">(for your reference)</small>
      <input type="text" name="name" required maxlength="150" value="<?= e($saved['name']) ?>"
             placeholder="e.g. December promo · Boat tour customers">
    </label>
    <label>Send from channel
      <select name="channel_id" required>
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $saved['channel_id'] === (int)$c['id'] ? 'selected' : '' ?>>
            <?= e($c['name']) ?>
            <?php if ($c['display_phone']): ?>· <?= e($c['display_phone']) ?><?php endif; ?>
            (<?= e($c['provider']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Message text
      <textarea name="message_text" rows="6" required maxlength="4000"
                placeholder="Hi! This is …"><?= e($saved['message_text']) ?></textarea>
      <small class="muted">Plain text only in this version. Personalization tokens (e.g. {{name}}) coming later.</small>
    </label>

    <h2>Recipients</h2>
    <div class="bcast-source">
      <label class="plan-radio">
        <input type="radio" name="source" value="paste" <?= $saved['source'] === 'paste' ? 'checked' : '' ?>>
        <span><strong>Paste numbers</strong> — one per line, comma, or space</span>
      </label>
      <label class="plan-radio">
        <input type="radio" name="source" value="tag" <?= $saved['source'] === 'tag' ? 'checked' : '' ?>>
        <span><strong>All contacts with a tag</strong></span>
      </label>
    </div>

    <label data-source="paste">Numbers
      <textarea name="numbers" rows="6"
                placeholder="60123456789&#10;60198765432, 60112223333"><?= e($saved['numbers']) ?></textarea>
      <small class="muted">Digits only, with country code (e.g. 60 for Malaysia). Duplicates are removed automatically.</small>
    </label>

    <label data-source="tag">Tag
      <select name="tag_id">
        <option value="0">— pick a tag —</option>
        <?php foreach ($tags as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $saved['tag_id'] === (int)$t['id'] ? 'selected' : '' ?>>
            <?= e($t['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small class="muted">All contacts whose conversations carry this tag will be added.</small>
    </label>

    <h2>Cadence</h2>
    <label>Batch size
      <input type="number" name="batch_size" min="1" max="50" value="<?= (int)$saved['batch_size'] ?>">
      <small class="muted">How many messages each batch sends (default 5).</small>
    </label>
    <label>Interval (minutes)
      <input type="number" name="interval_min" min="1" max="60" value="<?= (int)$saved['interval_min'] ?>">
      <small class="muted">Wait this many minutes between batches (default 3).</small>
    </label>

    <label class="check-row">
      <input type="checkbox" name="start_now" value="1" <?= $saved['start_now'] ? 'checked' : '' ?>>
      <span>Start the blast as soon as I click save</span>
      <small class="muted">Leave unticked to save as draft — you can review recipients and start later from the blast detail page.</small>
    </label>

    <div class="alert alert-info">
      <strong>How it works:</strong> the cron job at <code>cron/process_broadcasts.php</code>
      runs once a minute. With batch&nbsp;size <code>N</code> and interval <code>M</code>,
      every <code>M</code> minutes it picks the next <code>N</code> queued recipients on
      this blast and sends. For 100 recipients at 5 per 3&nbsp;min, the whole blast
      finishes in about 57&nbsp;minutes.
      <br><br>
      Recipients with Meta Cloud API channels need an open 24-hour window to
      receive plain text. For first-touch blasts on Cloud API use templates
      instead (coming soon). Evolution and AiServe Chatbot gateways have no
      24-hour window.
    </div>

    <button class="btn btn-primary" type="submit">Save broadcast</button>
    <a class="btn" href="/admin/broadcasts.php">Cancel</a>
  </form>
</div>

<script>
(function () {
  function refreshSource() {
    const which = (document.querySelector('input[name="source"]:checked') || {}).value || 'paste';
    document.querySelectorAll('[data-source]').forEach(el => {
      el.style.display = (el.getAttribute('data-source') === which) ? '' : 'none';
    });
  }
  document.querySelectorAll('input[name="source"]').forEach(r => r.addEventListener('change', refreshSource));
  refreshSource();
})();
</script>

<style>
.bcast-source { display: grid; gap: 8px; margin-bottom: 4px; }
</style>
<?php layout_end(); ?>
