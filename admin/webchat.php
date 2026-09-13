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
        $name     = trim((string)($_POST['name']     ?? ''));
        $title    = trim((string)($_POST['title']    ?? ''));
        $greet    = trim((string)($_POST['greeting'] ?? ''));
        $branchId = (int)($_POST['branch_id']         ?? 0);
        if ($branchId <= 0) $branchId = null;
        if ($name === '') { $err = 'Channel name is required.'; }
        else {
            $token = channel_generate_webhook_token();
            $db->prepare(
                'INSERT INTO channels
                    (company_id, name, provider, webhook_token, web_chat_title, web_chat_greeting, branch_id, is_default, status)
                 VALUES (?, ?, "web_chat", ?, ?, ?, ?, 0, "active")'
            )->execute([$companyId, $name, $token, $title ?: null, $greet ?: null, $branchId]);
            $newId = (int)$db->lastInsertId();
            log_activity($companyId, (int)$current_user['id'], 'web_chat_channel_created', 'channel', $newId, $name);
            redirect('/admin/webchat.php?flash=' . rawurlencode('Web chat channel created.'));
        }
    } elseif ($action === 'update' && $chId > 0) {
        $title    = trim((string)($_POST['title']    ?? ''));
        $greet    = trim((string)($_POST['greeting'] ?? ''));
        $branchId = (int)($_POST['branch_id']         ?? 0);
        if ($branchId <= 0) $branchId = null;
        $db->prepare(
            'UPDATE channels
             SET web_chat_title = ?, web_chat_greeting = ?, branch_id = ?
             WHERE id = ? AND company_id = ? AND provider = "web_chat"'
        )->execute([$title ?: null, $greet ?: null, $branchId, $chId, $companyId]);
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
            b.name AS branch_name,
            (SELECT COUNT(*) FROM web_chat_sessions WHERE channel_id = c.id) AS session_count,
            (SELECT COUNT(*) FROM conversations WHERE channel_id = c.id) AS conv_count
     FROM channels c
     LEFT JOIN branches b ON b.id = c.branch_id
     WHERE c.company_id = ? AND c.provider = "web_chat"
     ORDER BY c.id DESC'
);
$channels->execute([$companyId]);
$channels = $channels->fetchAll();

// Branches for the create + edit dropdowns.
$branches = $db->prepare(
    'SELECT id, name FROM branches
     WHERE company_id = ? AND status = "active"
     ORDER BY name'
);
$branches->execute([$companyId]);
$branches = $branches->fetchAll();

$base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));

layout_start($current_user, 'Web chat widgets', 'webchat');
?>

<style>
/* ==========================================================
   Web-chat channel manager — mobile-first redesign.
   Every grid collapses to 1fr under 700 px; copy buttons hit
   a 32 px minimum tap target; long URLs wrap instead of
   overflowing off-screen; per-widget actions stack under
   the widget name so the row never goes past viewport width.
   ========================================================== */
.wc-card { background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
.wc-card h2 { margin: 0 0 12px; font-size: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; }

.wc-item { border: 1px solid #e3e8ee; border-radius: 10px; padding: 16px; margin-bottom: 12px; }
.wc-item-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 12px; flex-wrap: wrap; gap: 10px;
}
.wc-item-head .n { font-weight: 600; font-size: 15px; word-break: break-word; }
.wc-item-meta { color: #64748b; font-size: 12px; margin-top: 4px; }
.wc-item-meta > span { display: inline-block; margin-right: 6px; }
.wc-actions {
  display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap;
}
.wc-actions .btn { min-height: 36px; }

.wc-share {
  display: grid; gap: 16px;
  grid-template-columns: 200px 1fr;
  align-items: start;
}
.wc-qr { background: #fff; padding: 8px; border: 1px solid #e3e8ee; border-radius: 8px; text-align: center; }
.wc-qr img {
  width: 180px; height: 180px; display: block; margin: 0 auto;
  max-width: 100%; height: auto;
}
.wc-qr .muted { font-size: 11.5px; margin-top: 6px; }

/* URL / embed code chip. On desktop it stays one line + scrolls.
   On mobile it wraps so the customer-facing URL is fully visible
   without needing to horizontal-scroll a code block. The copy
   button lives inline BELOW the text on narrow, and top-right
   on desktop — same button, different layout via flex. */
.wc-code {
  background: #f6f9fb; border: 1px solid #e3e8ee; border-radius: 6px;
  padding: 10px 12px; font-family: ui-monospace, Menlo, Consolas, monospace;
  font-size: 12.5px; line-height: 1.5;
  overflow-wrap: anywhere; word-break: break-all;
  margin-bottom: 8px;
  display: flex; align-items: flex-start; gap: 8px;
}
.wc-code .txt { flex: 1; min-width: 0; }
.wc-code button {
  border: 1px solid #d0d7de; background: #fff;
  padding: 6px 12px; border-radius: 6px; cursor: pointer;
  font-size: 12px; min-height: 32px; min-width: 64px; flex-shrink: 0;
  -webkit-tap-highlight-color: rgba(0,0,0,.1);
  touch-action: manipulation;
}
.wc-code button:hover  { border-color: #25D366; }
.wc-code button:active { transform: scale(0.96); }

.wc-code-label {
  font-size: 11px; color: #64748b; text-transform: uppercase;
  letter-spacing: .04em; margin-bottom: 4px;
}

.wc-form label {
  display: block; font-size: 12px; color: #64748b; margin-bottom: 10px;
}
.wc-form label input, .wc-form label textarea, .wc-form label select {
  display: block; width: 100%; padding: 10px 12px; margin-top: 4px;
  border: 1px solid #d0d7de; border-radius: 8px; font-size: 15px;
  background: #fff;
}
.wc-form label input:focus,
.wc-form label textarea:focus,
.wc-form label select:focus {
  outline: 2px solid #25D366; outline-offset: -1px; border-color: transparent;
}
.wc-form-grid { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
.wc-form-grid-3 { display: grid; gap: 12px; grid-template-columns: 1fr 1fr 1fr; }
.wc-form .btn { min-height: 42px; }

/* Small toolbar (Health check + top actions). Stacks on mobile
   so the tap targets stay full-width. */
.wc-toolbar {
  display: flex; justify-content: flex-end; gap: 6px;
  margin-bottom: 12px; flex-wrap: wrap;
}
.wc-toolbar .btn { min-height: 36px; }

/* --- Mobile breakpoint ---
   Collapse every multi-column grid to a single column and
   turn the item-head into a stacked block so widget name +
   meta sits on top and Disable/Delete actions get their own
   full-width row underneath. */
@media (max-width: 700px) {
  .wc-share            { grid-template-columns: 1fr; }
  .wc-form-grid,
  .wc-form-grid-3      { grid-template-columns: 1fr; }
  .wc-item             { padding: 14px; }
  .wc-item-head        { flex-direction: column; align-items: stretch; }
  .wc-actions          { justify-content: flex-start; }
  .wc-actions .btn     { flex: 1; }
  .wc-code             { flex-direction: column; }
  .wc-code button      { align-self: flex-end; }
  .wc-qr img           { width: 220px; height: 220px; }
  .wc-toolbar          { justify-content: stretch; }
  .wc-toolbar .btn     { flex: 1; text-align: center; }
}
</style>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

<div class="wc-toolbar">
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
    <div class="wc-form-grid">
      <label>Internal name
        <input type="text" name="name" maxlength="100" required
               placeholder="e.g. Restaurant dine-in widget">
      </label>
      <label>Widget header title <small class="muted">(shown to customer)</small>
        <input type="text" name="title" maxlength="120"
               placeholder="e.g. Vicky's Nasi Lemak">
      </label>
    </div>
    <div class="wc-form-grid">
      <label>Welcome message <small class="muted">(first thing the customer sees)</small>
        <textarea name="greeting" rows="2" maxlength="500"
                  placeholder="Hi 👋 Welcome! Type 'menu' to see what we're serving today."></textarea>
      </label>
      <label>Branch / location <small class="muted">(for analytics)</small>
        <?php if ($branches): ?>
          <select name="branch_id">
            <option value="">— None —</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <select disabled><option>— No branches yet —</option></select>
          <div class="muted small" style="margin-top:4px;">Add branches in <a href="/admin/branches.php">Admin → Branches</a> first.</div>
        <?php endif; ?>
      </label>
    </div>
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
      <div style="min-width:0; flex:1;">
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
          <span class="n"><?= e($c['name']) ?></span>
          <?= status_badge($c['status']) ?>
          <?php if (!empty($c['branch_name'])): ?>
            <span class="muted small" style="padding:2px 8px; border-radius:999px; background:#eef2ff; color:#3730a3;">
              🏢 <?= e((string)$c['branch_name']) ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="wc-item-meta">
          <span><?= (int)$c['session_count'] ?> session(s)</span>·
          <span><?= (int)$c['conv_count'] ?> conversation(s)</span>
        </div>
      </div>
      <div class="wc-actions">
        <a class="btn btn-sm" href="<?= e($url) ?>" target="_blank" title="Open widget in a new tab">🔗 Open</a>
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
        <div class="muted small">Print + stick on a table / counter</div>
      </div>
      <div>
        <div class="wc-code-label">Direct URL</div>
        <div class="wc-code" id="wc-url-<?= (int)$c['id'] ?>">
          <span class="txt"><?= e($url) ?></span>
          <button type="button" onclick="wcCopy('wc-url-<?= (int)$c['id'] ?>')">Copy</button>
        </div>

        <div class="wc-code-label">Table QR (append &amp;t=…)</div>
        <div class="wc-code" id="wc-tbl-<?= (int)$c['id'] ?>">
          <span class="txt"><?= e($url) ?>&amp;t=Table%205</span>
          <button type="button" onclick="wcCopy('wc-tbl-<?= (int)$c['id'] ?>')">Copy</button>
        </div>

        <div class="wc-code-label">Embed on your website</div>
        <div class="wc-code" id="wc-emb-<?= (int)$c['id'] ?>">
          <span class="txt"><?= e($embed) ?></span>
          <button type="button" onclick="wcCopy('wc-emb-<?= (int)$c['id'] ?>')">Copy</button>
        </div>

        <form method="post" class="wc-form" style="margin-top:12px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
          <div class="wc-form-grid-3">
            <label>Widget title
              <input type="text" name="title" maxlength="120" value="<?= e((string)($c['web_chat_title'] ?? '')) ?>">
            </label>
            <label>Greeting
              <input type="text" name="greeting" maxlength="500" value="<?= e((string)($c['web_chat_greeting'] ?? '')) ?>">
            </label>
            <label>Branch
              <?php $currentBranch = (int)($c['branch_id'] ?? 0); ?>
              <select name="branch_id">
                <option value="">— None —</option>
                <?php foreach ($branches as $b): ?>
                  <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $currentBranch ? 'selected' : '' ?>>
                    <?= e($b['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <button class="btn btn-sm btn-primary" type="submit">Save changes</button>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; endif; ?>

<script>
// Copy the URL / embed snippet to the clipboard. Reads from the
// .txt span so the button label never leaks into the copied text.
// Falls back to a select-and-execCommand path for older browsers
// (some iOS Safari versions still refuse navigator.clipboard from
// a non-secure context — the widget admin is always HTTPS, but
// keeping the fallback avoids silent failure on edge devices).
function wcCopy(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const btn = el.querySelector('button');
  const txt = el.querySelector('.txt');
  const raw = (txt ? txt.textContent : el.textContent).trim();
  const flashOK = () => {
    if (!btn) return;
    const orig = btn.textContent;
    btn.textContent = 'Copied ✓';
    setTimeout(() => { btn.textContent = orig; }, 1500);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(raw).then(flashOK).catch(legacyCopy);
  } else {
    legacyCopy();
  }
  function legacyCopy() {
    const ta = document.createElement('textarea');
    ta.value = raw;
    ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); flashOK(); } catch (e) {}
    document.body.removeChild(ta);
  }
}
</script>

<?php layout_end(); ?>
