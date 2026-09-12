<?php
/**
 * Start a new conversation with a customer who has not messaged us yet.
 *
 * The composer inside chat.php only shows once a customer has messaged
 * first — the shared inbox is fundamentally reactive. This page is the
 * one place agents can proactively reach out (e.g. a lead from a form,
 * a customer who called and asked for a WhatsApp follow-up).
 *
 * Provider gotcha:
 *   - Meta Cloud API cannot send free text to a new number. You MUST
 *     send an approved template first; the customer's reply opens the
 *     24-hour window and unlocks free-text replies.
 *   - Evolution + AiServe Chatbot have no such rule — any text works.
 *
 * This page picks the right mode based on the selected channel.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/provider.php';

$current_user = require_login();
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$channels = $db->prepare(
    'SELECT id, name, display_phone, provider FROM channels
     WHERE company_id = ? AND status = "active"
     ORDER BY is_default DESC, name'
);
$channels->execute([$companyId]);
$channels = $channels->fetchAll();

if (!$channels) {
    layout_start($current_user, 'New chat', 'inbox');
    echo '<div class="card"><h2>No channels yet</h2>'
       . '<p class="muted">Ask your platform admin to connect a WhatsApp number in '
       . '<a href="/admin/channels.php">Admin → Channels</a> before starting new chats.</p></div>';
    layout_end();
    exit;
}

// Templates for the Cloud API path — same query chat.php uses.
$templates = $db->prepare(
    'SELECT id, template_name, language, body_text, variables_json, status
     FROM message_templates
     WHERE company_id = ? AND status = "approved"
     ORDER BY template_name'
);
$templates->execute([$companyId]);
$templates = $templates->fetchAll();

layout_start($current_user, 'New chat', 'inbox');
?>
<div class="card">
  <div class="card-head">
    <h2>Start a new chat</h2>
    <a class="btn" href="/inbox/index.php">← Back to inbox</a>
  </div>

  <p class="muted small">
    Reach out to a customer who hasn't messaged you yet. Their number will be
    saved as a contact and the conversation will appear in your inbox after
    you send the first message.
  </p>

  <form class="form-grid" id="new-chat-form">
    <?= csrf_field() ?>

    <label>Send from channel
      <select name="channel_id" id="nc-channel" required>
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int)$c['id'] ?>" data-provider="<?= e($c['provider']) ?>">
            <?= e($c['name']) ?>
            <?php if ($c['display_phone']): ?>· <?= e($c['display_phone']) ?><?php endif; ?>
            (<?= e($c['provider']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Customer's WhatsApp number
      <input type="text" name="wa_id" id="nc-wa-id" required inputmode="numeric"
             placeholder="60123456789"
             pattern="[0-9\-\+\s]{6,20}">
      <small class="muted">Digits only with country code. e.g. <code>60</code> for Malaysia,
        <code>65</code> for Singapore, <code>62</code> for Indonesia. No <code>+</code> needed.</small>
    </label>

    <label>Customer name <small class="muted">(optional)</small>
      <input type="text" name="display_name" maxlength="150"
             placeholder="e.g. Vicky Tan">
      <small class="muted">Shown in your inbox. You can also rename them later from the chat.</small>
    </label>

    <!-- Free-text mode (Evolution, AiServe Chatbot) -->
    <div id="nc-free-text-mode">
      <label>Message
        <textarea name="message_text" rows="5" maxlength="4000"
                  placeholder="Hi! This is …"></textarea>
      </label>
    </div>

    <!-- Template mode (Cloud API) -->
    <div id="nc-template-mode" style="display:none;">
      <div class="alert alert-info" style="margin: 0 0 12px;">
        <strong>Meta Cloud API rule:</strong> the first message to a number
        that hasn't chatted with you must use an <strong>approved template</strong>.
        Their reply then opens the 24-hour window and unlocks free text.
      </div>
      <?php if (!$templates): ?>
        <div class="alert alert-error">
          No approved templates yet. Add one in
          <a href="/admin/templates.php">Admin → Templates</a>, get Meta to approve it,
          then come back here.
        </div>
      <?php else: ?>
        <label>Template
          <select name="template_id" id="nc-template">
            <option value="0">— pick a template —</option>
            <?php foreach ($templates as $t): ?>
              <option value="<?= (int)$t['id'] ?>"
                      data-body="<?= e((string)$t['body_text']) ?>"
                      data-lang="<?= e((string)$t['language']) ?>">
                <?= e($t['template_name']) ?> (<?= e($t['language']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <div id="nc-template-body" class="muted small" style="margin: 8px 0; white-space:pre-wrap;"></div>
        <div id="nc-template-vars" style="display:none;">
          <label>Variables <small class="muted">(fill each <code>{{n}}</code>)</small></label>
          <div id="nc-vars-list" style="display:grid; gap:6px;"></div>
        </div>
      <?php endif; ?>
    </div>

    <div id="nc-error" class="alert alert-error" style="display:none;"></div>

    <div style="display:flex; gap:8px;">
      <button type="submit" class="btn btn-primary" id="nc-send">Start chat →</button>
      <a class="btn" href="/inbox/index.php">Cancel</a>
    </div>
  </form>
</div>

<script>
(function () {
  const form      = document.getElementById('new-chat-form');
  const channel   = document.getElementById('nc-channel');
  const freeMode  = document.getElementById('nc-free-text-mode');
  const tplMode   = document.getElementById('nc-template-mode');
  const tplSel    = document.getElementById('nc-template');
  const tplBody   = document.getElementById('nc-template-body');
  const varsWrap  = document.getElementById('nc-template-vars');
  const varsList  = document.getElementById('nc-vars-list');
  const btn       = document.getElementById('nc-send');
  const errBox    = document.getElementById('nc-error');
  const csrfToken = document.querySelector('input[name="_csrf"]').value;

  function currentProvider() {
    const opt = channel.options[channel.selectedIndex];
    return opt ? (opt.dataset.provider || 'cloud_api') : 'cloud_api';
  }

  function refreshMode() {
    const isCloud = currentProvider() === 'cloud_api';
    tplMode.style.display  = isCloud ? '' : 'none';
    freeMode.style.display = isCloud ? 'none' : '';
  }

  function refreshTemplate() {
    if (!tplSel) return;
    const opt = tplSel.options[tplSel.selectedIndex];
    const body = opt ? (opt.dataset.body || '') : '';
    tplBody.textContent = body;
    // Count {{1}}..{{n}} vars.
    const matches = body.match(/\{\{(\d+)\}\}/g) || [];
    const uniq = [...new Set(matches.map(m => m.replace(/\D/g, '')))].sort();
    varsList.innerHTML = '';
    uniq.forEach(n => {
      const wrap = document.createElement('div');
      wrap.innerHTML = '<span class="muted small" style="display:inline-block; width:60px;">{{' + n + '}}</span>'
                    + '<input type="text" name="var_' + n + '" style="min-width:200px;" required>';
      varsList.appendChild(wrap);
    });
    varsWrap.style.display = uniq.length > 0 ? '' : 'none';
  }

  channel.addEventListener('change', refreshMode);
  if (tplSel) tplSel.addEventListener('change', refreshTemplate);
  refreshMode();

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errBox.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Sending…';

    try {
      const fd = new FormData(form);
      const res = await fetch('/api/conversation_new.php', {
        method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken },
      });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) {
        errBox.textContent = data.error || ('Send failed (HTTP ' + res.status + ')');
        errBox.style.display = '';
        return;
      }
      window.location.href = '/inbox/chat.php?id=' + encodeURIComponent(data.conversation_id);
    } catch (err) {
      errBox.textContent = 'Network error: ' + err.message;
      errBox.style.display = '';
    } finally {
      btn.disabled = false;
      btn.textContent = 'Start chat →';
    }
  });
})();
</script>
<?php layout_end(); ?>
