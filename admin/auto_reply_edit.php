<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/channels.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$id  = (int)($_GET['id'] ?? 0);
$row = null;
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM auto_replies WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$id, $companyId]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); exit('Rule not found.'); }
}

$channels = $db->prepare(
    'SELECT id, name, display_phone FROM channels
     WHERE company_id = ? AND status = "active" ORDER BY is_default DESC, name'
);
$channels->execute([$companyId]);
$channels = $channels->fetchAll();

$err = '';

if (is_post()) {
    csrf_check();
    $name        = trim((string)($_POST['name'] ?? ''));
    $matchType   = (string)($_POST['match_type'] ?? 'contains');
    if (!in_array($matchType, ['contains','starts_with','equals','regex'], true)) $matchType = 'contains';
    $matchValue  = trim((string)($_POST['match_value'] ?? ''));
    $replyText   = trim((string)($_POST['reply_text'] ?? ''));
    $priority    = max(1, min(9999, (int)($_POST['priority'] ?? 100)));
    $cooldownMin = max(0, min(1440, (int)($_POST['cooldown_min'] ?? 60)));
    $channelId   = (int)($_POST['channel_id'] ?? 0);
    $channelId   = $channelId > 0 ? $channelId : null;
    $status      = (string)($_POST['status'] ?? 'active');
    if (!in_array($status, ['active','inactive'], true)) $status = 'active';

    if ($name === '')       $err = 'Name is required.';
    elseif ($matchValue === '') $err = 'Match value (keyword) is required.';

    // Validate regex early so we don't save a broken pattern.
    if (!$err && $matchType === 'regex') {
        if (@preg_match('/' . str_replace('/', '\\/', $matchValue) . '/iu', '') === false) {
            $err = 'Invalid regex pattern.';
        }
    }

    // If a channel was picked, verify it belongs to this workspace.
    if (!$err && $channelId !== null) {
        $check = $db->prepare('SELECT id FROM channels WHERE id = ? AND company_id = ? LIMIT 1');
        $check->execute([$channelId, $companyId]);
        if (!$check->fetchColumn()) { $err = 'Invalid channel.'; }
    }

    // Handle optional media upload.
    $mediaKind = (string)($row['media_kind'] ?? 'none');
    $mediaPath = (string)($row['media_path'] ?? '');
    $mediaName = (string)($row['media_filename'] ?? '');
    $mediaMime = (string)($row['media_mime'] ?? '');

    if (!$err && !empty($_POST['remove_media'])) {
        // Delete file on disk when the user unchecks the attach box.
        if ($mediaPath && file_exists($mediaPath)) @unlink($mediaPath);
        $mediaKind = 'none'; $mediaPath = ''; $mediaName = ''; $mediaMime = '';
    }

    // Explicitly surface PHP-level upload rejections (INI or form size cap)
    // - without this the form silently saves with no media when the client
    // uploads more than upload_max_filesize.
    if (!$err && !empty($_FILES['media']['name'])
        && ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        && $_FILES['media']['error'] !== UPLOAD_ERR_NO_FILE) {
        $sizeCap = ini_get('upload_max_filesize') ?: 'the server cap';
        switch ($_FILES['media']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $err = 'Upload too large — server limit is ' . $sizeCap . '.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $err = 'Upload was interrupted. Try again.';
                break;
            default:
                $err = 'Upload failed (code ' . (int)$_FILES['media']['error'] . ').';
        }
    }
    if (!$err && !empty($_FILES['media']['tmp_name']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
        $tmp    = $_FILES['media']['tmp_name'];
        $origNm = (string)($_FILES['media']['name'] ?? 'upload');
        $mime   = mime_content_type($tmp) ?: (string)$_FILES['media']['type'];
        $size   = (int)$_FILES['media']['size'];
        if ($size > 25 * 1024 * 1024) {
            $err = 'File too large (max 25 MB).';
        } else {
            $kindMap = [
                'image/jpeg' => 'image', 'image/png' => 'image', 'image/webp' => 'image', 'image/gif' => 'image',
                'video/mp4'  => 'video', 'video/quicktime' => 'video',
                'application/pdf' => 'document',
                'application/msword' => 'document',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
                'application/vnd.ms-excel' => 'document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'document',
                'application/vnd.ms-powerpoint' => 'document',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'document',
                'text/plain' => 'document',
            ];
            $kind = $kindMap[$mime] ?? null;
            if (!$kind) {
                $err = 'Unsupported media type: ' . $mime;
            } else {
                $baseDir = __DIR__ . '/../uploads/' . $companyId . '/auto_reply';
                if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
                    $err = 'Could not create upload directory.';
                } else {
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $origNm) ?: 'catalog';
                    $safe = substr($safe, 0, 80);
                    $fn   = bin2hex(random_bytes(6)) . '_' . $safe;
                    $dest = $baseDir . '/' . $fn;
                    if (!move_uploaded_file($tmp, $dest)) {
                        $err = 'Could not save uploaded file.';
                    } else {
                        @chmod($dest, 0640);
                        // Replace any old file this rule was pointing at.
                        if ($mediaPath && file_exists($mediaPath) && $mediaPath !== $dest) @unlink($mediaPath);
                        $mediaKind = $kind;
                        $mediaPath = $dest;
                        $mediaName = $origNm;
                        $mediaMime = $mime;
                    }
                }
            }
        }
    }

    if (!$err) {
        if ($row) {
            $db->prepare(
                'UPDATE auto_replies SET
                    channel_id = ?, name = ?, match_type = ?, match_value = ?, reply_text = ?,
                    media_kind = ?, media_path = ?, media_filename = ?, media_mime = ?,
                    priority = ?, cooldown_min = ?, status = ?
                 WHERE id = ? AND company_id = ?'
            )->execute([
                $channelId, $name, $matchType, $matchValue, $replyText,
                $mediaKind, $mediaPath ?: null, $mediaName ?: null, $mediaMime ?: null,
                $priority, $cooldownMin, $status,
                $id, $companyId,
            ]);
            log_activity($companyId, (int)$current_user['id'], 'auto_reply_updated', 'auto_reply', $id, $name);
        } else {
            $db->prepare(
                'INSERT INTO auto_replies
                    (company_id, channel_id, name, match_type, match_value, reply_text,
                     media_kind, media_path, media_filename, media_mime,
                     priority, cooldown_min, status, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $companyId, $channelId, $name, $matchType, $matchValue, $replyText,
                $mediaKind, $mediaPath ?: null, $mediaName ?: null, $mediaMime ?: null,
                $priority, $cooldownMin, $status, (int)$current_user['id'],
            ]);
            $id = (int)$db->lastInsertId();
            log_activity($companyId, (int)$current_user['id'], 'auto_reply_created', 'auto_reply', $id, $name);
        }
        redirect('/admin/auto_reply_edit.php?id=' . $id . '&saved=1');
    }
}

// Re-read after save
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM auto_replies WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$id, $companyId]);
    $row = $stmt->fetch() ?: $row;
}

$saved = !empty($_GET['saved']);
layout_start($current_user, $row ? ('Rule · ' . $row['name']) : 'New auto reply', 'auto_replies');
?>
<div class="card">
  <?php if ($saved): ?><div class="alert alert-success">Rule saved.</div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <?php if (!$row): ?>
    <!-- AI-assist box only on NEW rules - editing existing rules should not
         accidentally clobber hand-crafted text with a fresh AI draft. -->
    <div class="ai-ar-builder" style="background:#f4f9f6;border:1px dashed #c6e0d0;border-radius:8px;padding:14px 16px;margin-bottom:18px;">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
        <strong>Describe in plain English</strong>
        <span class="muted small">AI drafts the rule for you to review.</span>
      </div>
      <p class="muted small" style="margin:6px 0 8px 0;">
        e.g. <em>"send our menu when customer asks about food"</em> ·
        <em>"reply with location when they type alamat or location"</em> ·
        <em>"answer opening hours when they ask when we're open"</em>
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <input type="text" id="ai-ar-desc" placeholder="What should this rule do?" style="flex:1;min-width:220px;">
        <select id="ai-ar-lang" style="max-width:120px;">
          <option value="en">English</option>
          <option value="ms">Bahasa Malaysia</option>
          <option value="zh">中文</option>
          <option value="ta">தமிழ்</option>
        </select>
        <button type="button" class="btn btn-primary" id="ai-ar-suggest-btn">Suggest</button>
      </div>
      <div id="ai-ar-status" class="small" style="margin-top:8px;"></div>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="form-grid" id="ar-form">
    <?= csrf_field() ?>

    <h2>Rule</h2>
    <label>Name <small class="muted">(internal, e.g. "Menu request")</small>
      <input type="text" name="name" id="f-ar-name" required maxlength="150"
             value="<?= e($row['name'] ?? ($_POST['name'] ?? '')) ?>">
    </label>

    <label>Priority <small class="muted">(lower = higher priority; first match wins)</small>
      <input type="number" name="priority" id="f-ar-priority" min="1" max="9999"
             value="<?= (int)($row['priority'] ?? 100) ?>">
    </label>

    <label>Channel
      <select name="channel_id">
        <option value="0">— All active channels —</option>
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int)$c['id'] ?>"
                  <?= ((int)($row['channel_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
            <?= e($c['name']) ?><?php if ($c['display_phone']): ?> · <?= e($c['display_phone']) ?><?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <h2>Match</h2>
    <label>Match type
      <select name="match_type" id="f-ar-match-type">
        <?php foreach (['contains','starts_with','equals','regex'] as $t): ?>
          <option value="<?= e($t) ?>" <?= ($row['match_type'] ?? 'contains') === $t ? 'selected' : '' ?>>
            <?= e($t) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Keyword / pattern
      <input type="text" name="match_value" id="f-ar-match-value" required maxlength="500"
             value="<?= e($row['match_value'] ?? '') ?>"
             placeholder="menu   or   ^(hi|hello)  for regex">
      <small class="muted">Matching is case-insensitive. For regex, write without slashes — <code>i</code> and <code>u</code> flags applied automatically.</small>
    </label>
    <label>Cooldown (minutes)
      <input type="number" name="cooldown_min" id="f-ar-cooldown" min="0" max="1440"
             value="<?= (int)($row['cooldown_min'] ?? 60) ?>">
      <small class="muted">Per-conversation. 0 = no cooldown (fire every time).</small>
    </label>

    <h2>Reply</h2>
    <label>Message text
      <textarea name="reply_text" id="f-ar-reply" rows="4" maxlength="4000"
                placeholder="Here's our menu — let me know what you'd like to order!"><?= e($row['reply_text'] ?? '') ?></textarea>
      <small class="muted">If a media file is attached, this text becomes its caption.</small>
    </label>

    <label>Catalog / media file <small class="muted">(optional — image, video, PDF, docs; max 25 MB)</small>
      <input type="file" name="media"
             accept="image/*,video/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,text/plain">
      <?php if (!empty($row['media_path'])): ?>
        <div class="muted small" style="margin-top:6px;">
          Currently attached: <strong><?= e($row['media_filename'] ?? basename($row['media_path'])) ?></strong>
          <span class="muted">(<?= e($row['media_kind']) ?>, <?= e($row['media_mime']) ?>)</span>
          <br>
          <label style="margin-top:4px;">
            <input type="checkbox" name="remove_media" value="1">
            Remove attachment on save
          </label>
        </div>
      <?php endif; ?>
    </label>

    <label>Status
      <select name="status">
        <option value="active"   <?= ($row['status'] ?? 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= ($row['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </label>

    <div>
      <button class="btn btn-primary" type="submit"><?= $row ? 'Save rule' : 'Create rule' ?></button>
      <a class="btn" href="/admin/auto_replies.php">Cancel</a>
    </div>
  </form>
</div>

<?php if ($row && $row['trigger_count'] > 0): ?>
<div class="card">
  <h2>Recent fires</h2>
  <p class="muted small">
    Fired <strong><?= (int)$row['trigger_count'] ?></strong> times.
    Last: <?= e(fmt_dt($row['last_triggered_at'])) ?: '—' ?>
  </p>
  <?php
    $fires = $db->prepare(
      'SELECT f.*, ct.display_name, ct.wa_id
       FROM auto_reply_fires f
       LEFT JOIN conversations c ON c.id = f.conversation_id
       LEFT JOIN contacts     ct ON ct.id = c.contact_id
       WHERE f.auto_reply_id = ?
       ORDER BY f.id DESC LIMIT 30'
    );
    $fires->execute([$id]);
    $fires = $fires->fetchAll();
  ?>
  <?php if ($fires): ?>
    <table class="data-table">
      <thead>
        <tr><th>Fired at</th><th>Contact</th><th>Matched text</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($fires as $f):
          $name = $f['display_name'] ?: ('+' . $f['wa_id']);
        ?>
          <tr>
            <td><?= e(fmt_dt($f['fired_at'])) ?></td>
            <td><?= e($name) ?></td>
            <td><?= e(mb_strimwidth((string)$f['matched_text'], 0, 80, '…')) ?></td>
            <td>
              <a class="btn btn-sm" href="/inbox/chat.php?id=<?= (int)$f['conversation_id'] ?>">Open chat</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted">No fires recorded yet.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$row): ?>
<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const desc = document.getElementById('ai-ar-desc');
  const lang = document.getElementById('ai-ar-lang');
  const btn  = document.getElementById('ai-ar-suggest-btn');
  const st   = document.getElementById('ai-ar-status');
  if (!btn) return;

  function setStatus(text, color) { st.textContent = text; st.style.color = color || ''; }

  async function suggest() {
    const value = desc.value.trim();
    if (!value) { setStatus('Type what this rule should do first.', '#b3261e'); desc.focus(); return; }
    btn.disabled = true;
    setStatus('Asking AI…', '');
    try {
      const fd = new FormData();
      fd.append('description', value);
      fd.append('language', lang.value);
      fd.append('_csrf', csrf);
      const res = await fetch('/api/ai_auto_reply_suggest.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) { setStatus('✗ ' + (data.error || 'Failed'), '#b3261e'); return; }
      const s = data.suggestion;
      document.getElementById('f-ar-name').value        = s.name || '';
      document.getElementById('f-ar-priority').value    = s.priority || 100;
      document.getElementById('f-ar-match-type').value  = s.match_type || 'contains';
      document.getElementById('f-ar-match-value').value = s.match_value || '';
      document.getElementById('f-ar-cooldown').value    = s.cooldown_min ?? 60;
      document.getElementById('f-ar-reply').value       = s.reply_text || '';
      const parts = ['✓ Filled below.'];
      if (s.explanation) parts.push(s.explanation);
      if (s.media_hint && s.media_hint !== 'none') {
        parts.push('AI suggests attaching a file like ' + s.media_hint + ' — upload it in the Catalog / media file field.');
      }
      parts.push('Review and click Create rule to save.');
      setStatus(parts.join(' '), '#1f7a3f');
      document.getElementById('f-ar-reply').focus();
    } catch (e) {
      setStatus('✗ Network error: ' + e.message, '#b3261e');
    } finally { btn.disabled = false; }
  }

  btn.addEventListener('click', suggest);
  desc.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); suggest(); } });
})();
</script>
<?php endif; ?>
<?php layout_end(); ?>
