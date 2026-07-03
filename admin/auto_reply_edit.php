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

  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>

    <h2>Rule</h2>
    <label>Name <small class="muted">(internal, e.g. "Menu request")</small>
      <input type="text" name="name" required maxlength="150"
             value="<?= e($row['name'] ?? ($_POST['name'] ?? '')) ?>">
    </label>

    <label>Priority <small class="muted">(lower = higher priority; first match wins)</small>
      <input type="number" name="priority" min="1" max="9999"
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
      <select name="match_type">
        <?php foreach (['contains','starts_with','equals','regex'] as $t): ?>
          <option value="<?= e($t) ?>" <?= ($row['match_type'] ?? 'contains') === $t ? 'selected' : '' ?>>
            <?= e($t) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Keyword / pattern
      <input type="text" name="match_value" required maxlength="500"
             value="<?= e($row['match_value'] ?? '') ?>"
             placeholder="menu   or   ^(hi|hello)  for regex">
      <small class="muted">Matching is case-insensitive. For regex, write without slashes — <code>i</code> and <code>u</code> flags applied automatically.</small>
    </label>
    <label>Cooldown (minutes)
      <input type="number" name="cooldown_min" min="0" max="1440"
             value="<?= (int)($row['cooldown_min'] ?? 60) ?>">
      <small class="muted">Per-conversation. 0 = no cooldown (fire every time).</small>
    </label>

    <h2>Reply</h2>
    <label>Message text
      <textarea name="reply_text" rows="4" maxlength="4000"
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
<?php layout_end(); ?>
