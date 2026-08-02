<?php
/**
 * Web chat channel manager.
 *
 * Lists existing web_chat channels for the workspace + lets the admin
 * create a new one. For each, shows:
 *   - Direct URL (share via SMS / print on receipt)
 *   - QR code image (print, stick on tables)
 *   - Embed snippet (paste into a website)
 *   - Editable greeting + widget title
 *
 * QR is rendered via a public zero-dep API (api.qrserver.com) for MVP.
 * When you want self-hosted, swap the img src for a local encoder.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/channels.php';
require_once __DIR__ . '/../inc/fnb_helpers.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = ''; $err = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $chId   = (int)($_POST['channel_id'] ?? 0);

    if ($action === 'create') {
        $name    = trim((string)($_POST['name']    ?? ''));
        $title   = trim((string)($_POST['title']   ?? ''));
        $greet   = trim((string)($_POST['greeting']?? ''));
        if ($name === '') { $err = 'Channel name is required.'; }
        else {
            $token = channel_generate_webhook_token();
            $db->prepare(
                'INSERT INTO channels
                    (company_id, name, provider, webhook_token, web_chat_title, web_chat_greeting, is_default, status)
                 VALUES (?, ?, "web_chat", ?, ?, ?, 0, "active")'
            )->execute([$companyId, $name, $token, $title ?: null, $greet ?: null]);
            $newId = (int)$db->lastInsertId();
            log_activity($companyId, (int)$current_user['id'], 'web_chat_channel_created', 'channel', $newId, $name);
            redirect('/admin/webchat.php?flash=' . rawurlencode('Web chat channel created.'));
        }
    } elseif ($action === 'update' && $chId > 0) {
        $title = trim((string)($_POST['title']    ?? ''));
        $greet = trim((string)($_POST['greeting'] ?? ''));
        $db->prepare(
            'UPDATE channels
             SET web_chat_title = ?, web_chat_greeting = ?
             WHERE id = ? AND company_id = ? AND provider = "web_chat"'
        )->execute([$title ?: null, $greet ?: null, $chId, $companyId]);
        redirect('/admin/webchat.php?flash=' . rawurlencode('Widget updated.'));
    } elseif ($action === 'toggle' && $chId > 0) {
        $db->prepare(
            'UPDATE channels SET status = IF(status = "active","inactive","active")
             WHERE id = ? AND company_id = ? AND provider = "web_chat"'
        )->execute([$chId, $companyId]);
        redirect('/admin/webchat.php');
    } elseif ($action === 'delete' && $chId > 0) {
        // Sessions cascade via FK; conversations survive (channel_id -> NULL).
        $db->prepare('DELETE FROM channels WHERE id = ? AND company_id = ? AND provider = "web_chat"')
           ->execute([$chId, $companyId]);
        redirect('/admin/webchat.php?flash=' . rawurlencode('Widget deleted.'));
    } elseif ($action === 'quick_setup_bot' && fnb_module_active($companyId)) {
        // One-click: seed the F&B starter flow AND ship it live with a
        // new_conversation trigger. After this, the widget replies on
        // the customer's very first message.
        try {
            $fid = fnb_seed_starter_flow($db, $companyId, (int)$current_user['id'], true);
            log_activity($companyId, (int)$current_user['id'], 'widget_quick_setup', 'flow', $fid);
            redirect('/admin/webchat.php?flash=' . rawurlencode('✅ Bot is live — scan a QR to test.'));
        } catch (Throwable $e) {
            $err = 'Quick-setup failed: ' . $e->getMessage();
        }
    }
}

$botLive = fnb_has_reachable_active_flow($db, $companyId);

if (!$msg) $msg = (string)($_GET['flash'] ?? '');

$channels = $db->prepare(
    'SELECT c.*,
            (SELECT COUNT(*) FROM web_chat_sessions WHERE channel_id = c.id) AS session_count,
            (SELECT COUNT(*) FROM conversations WHERE channel_id = c.id) AS conv_count
     FROM channels c
     WHERE c.company_id = ? AND c.provider = "web_chat"
     ORDER BY c.id DESC'
);
$channels->execute([$companyId]);
$channels = $channels->fetchAll();

$base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));

layout_start($current_user, 'Web chat widgets', 'webchat');
?>

<style>
.wc-card { background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
.wc-card h2 { margin: 0 0 12px; font-size: 15px; display: flex; justify-content: space-between; align-items: center; }
.wc-item { border: 1px solid #e3e8ee; border-radius: 10px; padding: 16px; margin-bottom: 12px; }
.wc-item-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px; }
.wc-item-head .n { font-weight: 600; font-size: 15px; }
.wc-share {
  display: grid; gap: 16px;
  grid-template-columns: 200px 1fr;
  align-items: start;
}
@media (max-width: 700px) { .wc-share { grid-template-columns: 1fr; } }
.wc-qr { background: #fff; padding: 8px; border: 1px solid #e3e8ee; border-radius: 8px; text-align: center; }
.wc-qr img { width: 180px; height: 180px; display: block; margin: 0 auto; }
.wc-code {
  background: #f6f9fb; border: 1px solid #e3e8ee; border-radius: 6px;
  padding: 8px 12px; font-family: monospace; font-size: 12.5px;
  overflow-x: auto; white-space: nowrap; margin-bottom: 6px;
  position: relative;
}
.wc-code button {
  position: absolute; top: 4px; right: 4px;
  border: 1px solid #d0d7de; background: #fff; padding: 2px 8px;
  border-radius: 4px; cursor: pointer; font-size: 11px;
}
.wc-form label { display: block; font-size: 12px; color: #64748b; margin-bottom: 8px; }
.wc-form label input, .wc-form label textarea {
  display: block; width: 100%; padding: 6px 8px; margin-top: 4px;
  border: 1px solid #e3e8ee; border-radius: 6px; font-size: 14px;
}
</style>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

<div style="text-align: right; margin-bottom: 12px;">
  <a class="btn btn-sm" href="/admin/webchat_debug.php" title="Run a full health check on the widget stack">🩺 Health check</a>
</div>

<?php if ($botLive): ?>
  <div class="wc-card" style="border-color:#c8f0d6; background:#f2fbf5;">
    <div style="display:flex; align-items:center; gap:8px;">
      <span style="font-size:18px;">✅</span>
      <div>
        <strong>Bot is live.</strong>
        <span class="muted small">At least one active flow will reply to widget messages.
          <a href="/admin/flows.php">Manage flows →</a></span>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="wc-card" style="border-color:#f4c9b0; background:#fff7f0;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div style="display:flex; gap:10px; align-items:flex-start;">
        <span style="font-size:20px;">⚠️</span>
        <div>
          <strong>No bot is answering yet.</strong>
          <div class="muted small">
            Messages will land in the inbox, but nothing will reply.
            <?php if (fnb_module_active($companyId)): ?>
              One click below seeds a working F&amp;B ordering flow and ships it live.
            <?php else: ?>
              Enable the F&amp;B module or build a flow at <a href="/admin/flows.php">Message flows</a>.
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php if (fnb_module_active($companyId)): ?>
        <form method="post" onsubmit="return confirm('Seed a starter F&B ordering flow AND set it live now?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="quick_setup_bot">
          <button class="btn btn-primary" type="submit">🚀 One-click bot setup</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="wc-card">
  <h2>+ Create a new widget</h2>
  <p class="muted small">
    A "widget" is a URL customers can visit (via QR, link, or website embed)
    to chat with your workspace. Every widget = one channel row, so it plugs
    into the same inbox, message flows, and F&amp;B ordering as any WhatsApp
    channel. Customers don't need to install anything.
  </p>
  <form method="post" class="wc-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div style="display:grid; gap:8px; grid-template-columns: 1fr 1fr;">
      <label>Internal name
        <input type="text" name="name" maxlength="100" required
               placeholder="e.g. Restaurant dine-in widget">
      </label>
      <label>Widget header title <small class="muted">(shown to customer)</small>
        <input type="text" name="title" maxlength="120"
               placeholder="e.g. Vicky's Nasi Lemak">
      </label>
    </div>
    <label>Welcome message <small class="muted">(first thing the customer sees)</small>
      <textarea name="greeting" rows="2" maxlength="500"
                placeholder="Hi 👋 Welcome! Type 'menu' to see what we're serving today."></textarea>
    </label>
    <button class="btn btn-primary" type="submit">Create widget</button>
  </form>
</div>

<?php if (!$channels): ?>
  <div class="wc-card">
    <p class="muted">No widgets yet — create your first one above.</p>
  </div>
<?php else: foreach ($channels as $c):
  $token = (string)$c['webhook_token'];
  $url   = $base . '/chat.php?c=' . $token;
  $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?data='
         . urlencode($url) . '&size=360x360&margin=8';
  $embed = '<iframe src="' . $url . '" style="width:100%;height:600px;border:1px solid #e3e8ee;border-radius:8px;"></iframe>';
?>
  <div class="wc-item">
    <div class="wc-item-head">
      <div>
        <span class="n"><?= e($c['name']) ?></span>
        <?= status_badge($c['status']) ?>
        <span class="muted small" style="margin-left:8px;">
          · <?= (int)$c['session_count'] ?> session(s)
          · <?= (int)$c['conv_count'] ?> conversation(s)
        </span>
      </div>
      <div style="display:flex; gap:6px;">
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-sm" type="submit"><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this widget? The URL will stop working.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">Delete</button>
        </form>
      </div>
    </div>

    <div class="wc-share">
      <div class="wc-qr">
        <img src="<?= e($qrUrl) ?>" alt="QR code for <?= e($c['name']) ?>">
        <div class="muted small" style="margin-top:6px;">Print + stick on a table / counter</div>
      </div>
      <div>
        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">Direct URL</div>
        <div class="wc-code" id="wc-url-<?= (int)$c['id'] ?>">
          <?= e($url) ?>
          <button type="button" onclick="wcCopy('wc-url-<?= (int)$c['id'] ?>')">Copy</button>
        </div>

        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">Table QR (append &amp;t=…)</div>
        <div class="wc-code" id="wc-tbl-<?= (int)$c['id'] ?>">
          <?= e($url) ?>&amp;t=Table%205
          <button type="button" onclick="wcCopy('wc-tbl-<?= (int)$c['id'] ?>')">Copy</button>
        </div>

        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">Embed on your website</div>
        <div class="wc-code" id="wc-emb-<?= (int)$c['id'] ?>">
          <?= e($embed) ?>
          <button type="button" onclick="wcCopy('wc-emb-<?= (int)$c['id'] ?>')">Copy</button>
        </div>

        <form method="post" class="wc-form" style="margin-top:12px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
          <div style="display:grid; gap:8px; grid-template-columns: 1fr 1fr;">
            <label>Widget title
              <input type="text" name="title" maxlength="120" value="<?= e((string)($c['web_chat_title'] ?? '')) ?>">
            </label>
            <label>Greeting
              <input type="text" name="greeting" maxlength="500" value="<?= e((string)($c['web_chat_greeting'] ?? '')) ?>">
            </label>
          </div>
          <button class="btn btn-sm" type="submit">Save changes</button>
          <a class="btn btn-sm" href="<?= e($url) ?>" target="_blank">🔗 Open widget</a>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; endif; ?>

<script>
function wcCopy(id) {
  const el = document.getElementById(id);
  // Get the text without the button label
  const btn = el.querySelector('button');
  const btnText = btn ? btn.textContent : '';
  const raw = el.textContent.replace(btnText, '').trim();
  navigator.clipboard.writeText(raw).then(() => {
    if (btn) { btn.textContent = 'Copied ✓'; setTimeout(() => btn.textContent = btnText, 1500); }
  });
}
</script>

<?php layout_end(); ?>
