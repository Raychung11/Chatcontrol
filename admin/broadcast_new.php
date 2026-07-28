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

    // ---------- media attachments (up to 4) ----------
    // Accept: image/*, video/mp4, application/pdf, audio/mpeg, audio/ogg.
    // Saved to uploads/broadcasts/<company_id>/ so the cron worker can
    // read them later. Empty slots are silently skipped, so the operator
    // can fill any 1..4 slots without ordering constraints.
    $maxItems  = 4;
    $mediaItems = [];   // [ ['path','kind','mime','filename'], ... ]
    $kindMap = [
        'image/jpeg'      => 'image',
        'image/png'       => 'image',
        'image/webp'      => 'image',
        'image/gif'       => 'image',   // Meta converts to video, still works
        'video/mp4'       => 'video',
        'video/3gpp'      => 'video',
        'application/pdf' => 'document',
        'audio/mpeg'      => 'audio',
        'audio/ogg'       => 'audio',
        'audio/mp4'       => 'audio',
    ];
    for ($slot = 1; $slot <= $maxItems && !$err; $slot++) {
        $key = 'media_' . $slot;
        if (empty($_FILES[$key])) continue;
        $upErr = (int)($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($upErr === UPLOAD_ERR_NO_FILE) continue;
        if ($upErr !== UPLOAD_ERR_OK) {
            $err = 'Slot ' . $slot . ' upload failed (code ' . $upErr . '). Likely exceeds the server upload limit.';
            break;
        }
        $file      = $_FILES[$key];
        $mimeGuess = function_exists('mime_content_type') ? (string)mime_content_type($file['tmp_name']) : '';
        if (!isset($kindMap[$mimeGuess])) {
            $err = 'Slot ' . $slot . ': unsupported file type (' . ($mimeGuess ?: 'unknown') . '). Allowed: JPG, PNG, WebP, GIF, MP4, PDF, MP3, OGG.';
            break;
        }
        $maxBytes = 16 * 1024 * 1024;  // 16 MB — WA caps images at 5, video 16, doc 100. 16 is a safe MVP ceiling.
        if ((int)$file['size'] > $maxBytes) {
            $err = 'Slot ' . $slot . ': file too big (max 16 MB).';
            break;
        }
        $dir = __DIR__ . '/../uploads/broadcasts/' . $companyId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $err = 'Could not create uploads/broadcasts/ — check permissions.';
            break;
        }
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',
                    'video/mp4'=>'mp4','video/3gpp'=>'3gp','application/pdf'=>'pdf',
                    'audio/mpeg'=>'mp3','audio/ogg'=>'ogg','audio/mp4'=>'m4a'][$mimeGuess] ?? 'bin';
        }
        $fname = 'bcast_' . time() . '_' . $slot . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest  = $dir . '/' . $fname;
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            $err = 'Slot ' . $slot . ': could not save uploaded file.';
            break;
        }
        @chmod($dest, 0644);
        $mediaItems[] = [
            'path'     => $dest,
            'kind'     => $kindMap[$mimeGuess],
            'mime'     => $mimeGuess,
            'filename' => basename((string)$file['name']),
        ];
    }

    if ($saved['name'] === '')               $err = 'Give the broadcast a name.';
    elseif ($saved['message_text'] === '' && !$mediaItems) $err = 'Enter message text or attach at least one file.';
    elseif (mb_strlen($saved['message_text']) > 4000) $err = 'Message is too long (max 4000 chars).';
    elseif (!$ch)                            $err = 'Pick a channel.';

    $waIds = [];
    if (!$err) {
        if ($saved['source'] === 'tag') {
            if ($saved['tag_id'] <= 0) {
                $err = 'Pick a tag.';
            } else {
                // Belt-and-braces workspace scope: filter conversations
                // AND contacts by company_id (in addition to the tag's
                // company_id) so a future stray cross-workspace tag_map
                // row can't leak foreign contacts into the recipient list.
                // ALSO filter by channel_id so a tag on a channel-A
                // conversation doesn't leak into a channel-B blast (the
                // customer may never have opted in on channel B).
                $r = $db->prepare(
                    'SELECT DISTINCT ct.wa_id, ct.display_name
                     FROM conversation_tag_map m
                     JOIN conversations c ON c.id = m.conversation_id
                       AND c.company_id = ? AND c.channel_id = ?
                     JOIN contacts ct     ON ct.id = c.contact_id     AND ct.company_id = ?
                     JOIN conversation_tags t ON t.id = m.tag_id
                     WHERE m.tag_id = ? AND t.company_id = ?'
                );
                $r->execute([$companyId, $saved['channel_id'], $companyId, $saved['tag_id'], $companyId]);
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

        // ----------------------------------------------------------------
        // Restrict recipients to numbers that are ACTUALLY contacts on the
        // selected channel. A "channel contact" = someone who has (or has
        // had) a conversation on this specific channel. This prevents:
        //   - Broadcasting to random typed-in numbers who never opted in
        //     (accidental spam, WhatsApp policy risk).
        //   - Broadcasting on channel B to numbers who only ever talked
        //     to channel A (Cloud API 24h window would reject them
        //     anyway; Baileys would succeed but the customer never gave
        //     us permission on that number).
        //
        // Skipped numbers are counted so the operator can see how many
        // fell out and why.
        $skippedNotChannelContact = 0;
        if (!$err && $waIds) {
            $entered = array_keys($waIds);
            $placeholders = implode(',', array_fill(0, count($entered), '?'));
            $q = $db->prepare(
                "SELECT DISTINCT ct.wa_id
                 FROM contacts ct
                 INNER JOIN conversations c
                    ON c.contact_id = ct.id AND c.channel_id = ? AND c.company_id = ?
                 WHERE ct.company_id = ? AND ct.platform = 'whatsapp'
                   AND ct.wa_id IN ($placeholders)"
            );
            $q->execute(array_merge(
                [$saved['channel_id'], $companyId, $companyId],
                $entered
            ));
            $allowed = array_map(fn($r) => (string)$r['wa_id'], $q->fetchAll());
            $allowedSet = array_flip($allowed);

            $filtered = [];
            foreach ($waIds as $wa => $name) {
                if (isset($allowedSet[$wa])) {
                    $filtered[$wa] = $name;
                } else {
                    $skippedNotChannelContact++;
                }
            }
            $waIds = $filtered;
        }

        if (!$err && !$waIds) {
            $err = $skippedNotChannelContact > 0
                ? 'None of the numbers you entered are contacts on this channel yet. '
                . 'Broadcasts can only be sent to numbers who have already messaged this channel. '
                . 'Ask them to send you a message first, or pick a different channel.'
                : 'No valid recipient numbers found.';
        }
        if (!$err && count($waIds) > 5000) $err = 'Recipient limit is 5000 per blast.';

        // Phase 30: broadcast metering. Check that this workspace's remaining
        // monthly quota can cover the whole recipient list. Block otherwise
        // with an upgrade CTA to the paid plan.
        if (!$err && count($waIds) > 0) {
            $quota = broadcast_quota_for_workspace($companyId);
            if (count($waIds) > $quota['remaining']) {
                $needMore = count($waIds) - $quota['remaining'];
                if ($quota['plan'] === 'free') {
                    $err = 'This broadcast would exceed your free-plan quota by '
                        . number_format($needMore) . ' recipient(s). '
                        . 'You have ' . number_format($quota['remaining']) . ' left this month out of '
                        . number_format($quota['limit']) . '. '
                        . 'Upgrade to the paid plan (' . e($quota['currency']) . ' '
                        . rtrim(rtrim(number_format($quota['price'], 2), '0'), '.')
                        . ' / month for ' . number_format($quota['paid_limit'])
                        . ' recipients) — ask your platform admin to switch this workspace to the paid plan.';
                } else {
                    $err = 'This broadcast would exceed your paid-plan quota by '
                        . number_format($needMore) . ' recipient(s). '
                        . 'You have ' . number_format($quota['remaining']) . ' left this month out of '
                        . number_format($quota['limit']) . '. Quota resets on the 1st of next month.';
                }
            }
        }
    }

    if (!$err) {
        try {
            $db->beginTransaction();
            // First item is mirrored into the legacy broadcasts.media_*
            // columns for backward compat with any code that hasn't been
            // updated to read broadcast_media_items yet.
            $legacyPath = $mediaItems[0]['path']     ?? null;
            $legacyKind = $mediaItems[0]['kind']     ?? null;
            $legacyMime = $mediaItems[0]['mime']     ?? null;
            $legacyFile = $mediaItems[0]['filename'] ?? null;

            $ins = $db->prepare(
                'INSERT INTO broadcasts
                    (company_id, channel_id, created_by_user_id, name, message_text,
                     media_path, media_kind, media_mime_type, media_filename,
                     status, batch_size, batch_interval_min, total_recipients)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $companyId, $saved['channel_id'], (int)$current_user['id'],
                $saved['name'], $saved['message_text'] !== '' ? $saved['message_text'] : null,
                $legacyPath, $legacyKind, $legacyMime, $legacyFile,
                $saved['start_now'] ? 'running' : 'draft',
                $saved['batch_size'], $saved['interval_min'],
                count($waIds),
            ]);
            $bid = (int)$db->lastInsertId();

            // Persist every attachment as a broadcast_media_items row.
            if ($mediaItems) {
                $itemIns = $db->prepare(
                    'INSERT INTO broadcast_media_items
                        (broadcast_id, sequence, media_path, media_kind, media_mime_type, media_filename)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($mediaItems as $idx => $m) {
                    $itemIns->execute([
                        $bid, $idx + 1, $m['path'], $m['kind'], $m['mime'], $m['filename'],
                    ]);
                }
            }

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
                'recipients=' . count($waIds) . ' skipped_not_channel_contact=' . $skippedNotChannelContact
                . ' batch=' . $saved['batch_size']
                . ' interval=' . $saved['interval_min'] . 'min'
                . ' status=' . ($saved['start_now'] ? 'running' : 'draft'));
            $qs = '/admin/broadcast_view.php?id=' . $bid;
            if ($skippedNotChannelContact > 0) {
                $qs .= '&skipped=' . $skippedNotChannelContact;
            }
            redirect($qs);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[AiServe broadcast create] ' . $e->getMessage());
            $err = 'Could not create the broadcast. Check the migration is in (sql/migration_phase15.sql).';
        }
    }
}

$quota = broadcast_quota_for_workspace($companyId);
$quotaPct = $quota['limit'] > 0 ? round(($quota['used'] / $quota['limit']) * 100) : 0;
$quotaBarColor = $quotaPct >= 90 ? '#DC2626' : ($quotaPct >= 70 ? '#F59E0B' : '#25D366');

layout_start($current_user, 'New broadcast', 'broadcasts');
?>

<div class="card" style="margin-bottom: 12px; border-left: 4px solid <?= $quotaBarColor ?>;">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <div>
      <strong><?= $quota['plan'] === 'paid' ? 'Paid broadcast plan' : 'Free broadcast plan' ?></strong>
      <span class="muted small">
        · <?= number_format($quota['used']) ?> / <?= number_format($quota['limit']) ?> recipients used this month
        · <?= number_format($quota['remaining']) ?> remaining
      </span>
    </div>
    <?php if ($quota['plan'] === 'free'): ?>
      <span class="muted small">
        Upgrade to paid: <strong><?= e($quota['currency']) ?> <?= rtrim(rtrim(number_format($quota['price'], 2), '0'), '.') ?> / month</strong>
        for <?= number_format($quota['paid_limit']) ?> recipients.
        Contact your platform admin.
      </span>
    <?php endif; ?>
  </div>
  <div style="height:6px; background:#f6f9fb; border-radius:3px; overflow:hidden; margin-top:6px;">
    <div style="height:100%; width:<?= min(100, $quotaPct) ?>%; background:<?= $quotaBarColor ?>;"></div>
  </div>
</div>

<div class="card">
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="form-grid" enctype="multipart/form-data">
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
    <label>Message text <small class="muted">(optional if you attach a file)</small>
      <textarea name="message_text" rows="6" maxlength="4000"
                placeholder="Hi! This is …"><?= e($saved['message_text']) ?></textarea>
      <small class="muted">Plain text. If you attach an image/video/PDF below, this text becomes the caption WhatsApp shows under the media.</small>
    </label>
    <fieldset style="border:1px solid var(--c-border); border-radius:8px; padding:16px; margin:0;">
      <legend style="padding:0 6px; font-weight:600; font-size:14px;">
        Attachments <small class="muted">(optional, up to 4)</small>
      </legend>
      <p class="muted small" style="margin:0 0 12px;">
        Every recipient receives all attached files in order, followed by
        your message text as the caption on the <strong>last</strong>
        attachment. Images (JPG/PNG/WebP/GIF), video (MP4), PDF, audio
        (MP3/OGG). Max 16&nbsp;MB each. Leave any slot empty to skip it.
      </p>
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:10px;">
        <?php for ($slot = 1; $slot <= 4; $slot++): ?>
          <label style="display:block; font-size:13px;">
            Attachment <?= $slot ?>
            <input type="file" name="media_<?= $slot ?>"
                   accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,application/pdf,audio/mpeg,audio/ogg"
                   style="display:block; margin-top:4px;">
          </label>
        <?php endfor; ?>
      </div>
    </fieldset>

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
      <textarea name="numbers" rows="6" maxlength="200000"
                placeholder="60123456789&#10;60198765432, 60112223333"><?= e($saved['numbers']) ?></textarea>
      <small class="muted">Digits only, with country code (e.g. 60 for Malaysia). Duplicates are removed automatically.</small>
      <small class="muted" style="display:block; margin-top:4px;">
        <strong>Note:</strong> only numbers that are already contacts on the selected channel
        will receive the broadcast. Others are silently skipped and reported after save.
      </small>
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
