<?php
/**
 * Web chat widget — the customer-facing page a scanned QR opens.
 *
 * URL: /chat.php?c=<channel_token>&t=<optional context>
 *
 * The channel_token identifies which workspace + channel this widget
 * belongs to (a channel row with provider='web_chat'). The optional
 * context (?t=table5, ?t=branch3, whatever) rides along and gets
 * stamped on the session so agents see it and the F&B flow can use it.
 *
 * The page is self-contained (no external CDN, no framework) so it
 * loads instantly on mobile data and fits the CSP.
 */

require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/channels.php';

$channelToken = trim((string)($_GET['c'] ?? ''));
$context      = trim((string)($_GET['t'] ?? ''));

if ($channelToken === '') {
    http_response_code(400);
    exit('Missing channel token.');
}

$channel = channel_by_token($channelToken);
if (!$channel || $channel['provider'] !== 'web_chat' || $channel['status'] !== 'active') {
    http_response_code(404);
    exit('Chat widget not found or has been disabled.');
}

$db = aiserve_db();
$cs = $db->prepare('SELECT name, logo, brand_color FROM companies WHERE id = ? LIMIT 1');
$cs->execute([(int)$channel['company_id']]);
$company = $cs->fetch() ?: [];

$brand   = trim((string)($company['brand_color'] ?? '#25D366')) ?: '#25D366';
$title   = trim((string)($channel['web_chat_title'] ?? '')) ?: (string)($company['name'] ?? 'Chat');
$greet   = trim((string)($channel['web_chat_greeting'] ?? ''))
        ?: "Hi 👋 Welcome! Type a message below and we'll get right back to you.";
$logoUrl = !empty($company['logo'])
    ? '/assets/img/company_logo.php?company_id=' . (int)$channel['company_id']
    : '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= e($title) ?></title>
<style>
* { box-sizing: border-box; }
html, body { margin:0; padding:0; height: 100%; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
body { background: #f0f2f5; color: #111; display: flex; flex-direction: column; }
.wc-header {
  background: <?= e($brand) ?>; color: #fff; padding: 12px 16px;
  display: flex; align-items: center; gap: 12px; flex-shrink: 0;
}
.wc-header .logo {
  width: 40px; height: 40px; border-radius: 8px; background: #fff;
  overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
}
.wc-header .logo img { width: 100%; height: 100%; object-fit: contain; }
.wc-header .titles { flex: 1; }
.wc-header .titles .n { font-weight: 600; font-size: 15px; }
.wc-header .titles .s { font-size: 12px; opacity: .85; }

.wc-stream {
  flex: 1; overflow-y: auto; padding: 12px;
  background: #efeae2; /* WhatsApp-ish canvas */
  display: flex; flex-direction: column; gap: 4px;
}
.wc-bubble {
  max-width: 78%; padding: 8px 12px; border-radius: 12px;
  font-size: 14.5px; line-height: 1.4; word-wrap: break-word;
  white-space: pre-wrap;
  box-shadow: 0 1px 0.5px rgba(0,0,0,.13);
}
.wc-bubble.in  { background: #fff; align-self: flex-start; border-top-left-radius: 4px; }
.wc-bubble.out { background: #dcf8c6; align-self: flex-end;   border-top-right-radius: 4px; }
.wc-time { font-size: 10.5px; color: #667; margin-top: 2px; }
.wc-bubble.out .wc-time { text-align: right; }

.wc-composer {
  padding: 8px; background: #f0f2f5; border-top: 1px solid #d1d7db;
  display: flex; gap: 8px; align-items: center; flex-shrink: 0;
}
.wc-composer textarea {
  flex: 1; resize: none; border-radius: 20px; border: 1px solid #d1d7db;
  padding: 10px 14px; font-family: inherit; font-size: 14.5px;
  min-height: 40px; max-height: 100px; line-height: 1.3;
  background: #fff;
}
.wc-composer textarea:focus { outline: 2px solid <?= e($brand) ?>; outline-offset: -1px; }
.wc-composer button {
  width: 44px; height: 44px; border-radius: 50%; border: none;
  background: <?= e($brand) ?>; color: #fff; font-size: 20px;
  cursor: pointer; flex-shrink: 0;
  /* Ensure the tap target is above anything, and touch works reliably on iOS */
  -webkit-tap-highlight-color: rgba(0,0,0,.15);
  touch-action: manipulation;
}
.wc-composer button.busy { background: #9ca3af; }
.wc-composer button:active { transform: scale(0.94); }

.wc-loading {
  text-align: center; color: #667; font-size: 12px;
  padding: 8px; opacity: .6;
}
.wc-error {
  background: #FEE2E2; color: #7F1D1D; padding: 10px 14px;
  margin: 8px 0; border-radius: 8px; font-size: 13px;
  border: 1px solid #FCA5A5; word-break: break-word;
}
.wc-error strong { display: block; margin-bottom: 3px; }
</style>
</head>
<body>

<div class="wc-header">
  <div class="logo">
    <?php if ($logoUrl): ?>
      <img src="<?= e($logoUrl) ?>" alt="">
    <?php else: ?>
      <div style="font-size:22px;">💬</div>
    <?php endif; ?>
  </div>
  <div class="titles">
    <div class="n"><?= e($title) ?></div>
    <div class="s"><span id="wc-status">Online</span></div>
  </div>
</div>

<div class="wc-stream" id="wc-stream">
  <div class="wc-loading" id="wc-loading">Connecting…</div>
</div>

<div class="wc-composer">
  <textarea id="wc-input" placeholder="Type a message…" rows="1"
            onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault();wcSend();}"></textarea>
  <button id="wc-btn" type="button" onclick="wcSend()">➤</button>
</div>

<script>
(function () {
  const CHANNEL_TOKEN = <?= json_encode($channelToken) ?>;
  const CONTEXT       = <?= json_encode($context) ?>;
  const GREETING      = <?= json_encode($greet) ?>;
  const STORAGE_KEY   = 'wc_session_' + CHANNEL_TOKEN;
  const POLL_MS       = 3000;

  let sessionToken = null;
  let lastMsgId    = 0;
  let polling      = false;

  const stream = document.getElementById('wc-stream');
  const input  = document.getElementById('wc-input');
  const btn    = document.getElementById('wc-btn');
  const status = document.getElementById('wc-status');
  const loader = document.getElementById('wc-loading');

  // Visible error banner inside the chat stream so the customer / tester
  // can see what actually broke without opening dev tools.
  function showError(title, detail) {
    const box = document.createElement('div');
    box.className = 'wc-error';
    box.innerHTML = '<strong>' + escapeHtml(title) + '</strong>'
                  + (detail ? escapeHtml(detail) : '');
    stream.appendChild(box);
    stream.scrollTop = stream.scrollHeight;
  }
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[c]);
  }

  // Visual only — the button stays always-clickable so a mistap can't
  // leave the customer unable to send. We show a "busy" tint during
  // an in-flight send instead of disabling.
  function markBusy(on) {
    if (on) btn.classList.add('busy'); else btn.classList.remove('busy');
  }

  function pad(n) { return n < 10 ? '0' + n : n; }
  function hhmm(dstr) {
    try { const d = new Date(dstr.replace(' ', 'T')); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    catch (e) { return ''; }
  }

  function scrollBottom() { stream.scrollTop = stream.scrollHeight; }

  function renderBubble(text, direction, createdAt, prepend) {
    const bub = document.createElement('div');
    bub.className = 'wc-bubble ' + (direction === 'incoming' ? 'out' : 'in');
    bub.textContent = text;
    if (createdAt) {
      const t = document.createElement('div');
      t.className = 'wc-time';
      t.textContent = hhmm(createdAt);
      bub.appendChild(t);
    }
    if (prepend) stream.insertBefore(bub, stream.firstChild);
    else         stream.appendChild(bub);
  }

  async function widgetStart() {
    // Reuse existing session if we have one, else create a new one.
    let existing = null;
    try { existing = localStorage.getItem(STORAGE_KEY); } catch (e) {}
    const fd = new FormData();
    fd.append('channel_token', CHANNEL_TOKEN);
    if (existing) fd.append('session_token', existing);
    if (CONTEXT)  fd.append('context', CONTEXT);
    try {
      const res = await fetch('/api/widget_start.php', { method: 'POST', body: fd });
      let data;
      try { data = await res.json(); }
      catch (parseErr) {
        const raw = await res.text().catch(() => '(no body)');
        throw new Error('Server error (HTTP ' + res.status + '): '
                      + raw.substring(0, 300));
      }
      if (!data.ok) throw new Error(data.error || ('start failed (HTTP ' + res.status + ')'));
      sessionToken = data.session_token;
      lastMsgId    = data.last_msg_id || 0;
      try { localStorage.setItem(STORAGE_KEY, sessionToken); } catch (e) {}

      if (loader) loader.remove();

      // Render prior conversation history (if returning), else greeting.
      if (data.history && data.history.length) {
        data.history.forEach(m => renderBubble(m.text, m.direction, m.created_at, false));
        scrollBottom();
      } else if (GREETING) {
        renderBubble(GREETING, 'outgoing', new Date().toISOString().replace('T', ' ').slice(0, 19), false);
      }
      startPolling();
    } catch (err) {
      status.textContent = 'Offline — tap Send to retry';
      if (loader) loader.textContent = 'Could not connect. Tap the send button to retry.';
      showError('Could not start chat', err && err.message ? err.message : String(err));
    }
  }

  async function wcSend() {
    const text = input.value.trim();
    if (!text) {
      input.focus();
      return;
    }
    // If the initial handshake never completed (or failed), retry it
    // right here so the customer doesn't get stuck with a dead widget.
    if (!sessionToken) {
      status.textContent = 'Connecting…';
      await widgetStart();
      if (!sessionToken) {
        status.textContent = 'Still offline — try again in a moment';
        return;
      }
      status.textContent = 'Online';
    }
    markBusy(true);
    // Optimistic render
    const now = new Date();
    renderBubble(text, 'incoming', now.toISOString().replace('T', ' ').slice(0, 19), false);
    input.value = '';
    scrollBottom();

    const fd = new FormData();
    fd.append('session_token', sessionToken);
    fd.append('text', text);
    try {
      const res = await fetch('/api/widget_send.php', { method: 'POST', body: fd });
      // Try to parse JSON. If the server crashed with an HTML error page,
      // res.json() throws — surface the raw text so we know what died.
      let data;
      try { data = await res.json(); }
      catch (parseErr) {
        const raw = await res.text().catch(() => '(no body)');
        throw new Error('Server error (HTTP ' + res.status + '): '
                      + raw.substring(0, 300));
      }
      if (!data.ok) {
        throw new Error(data.error || ('send failed (HTTP ' + res.status + ')'));
      }
      if (data.message_id) lastMsgId = Math.max(lastMsgId, data.message_id);
      // Immediately poll so any bot reply lands fast
      pollOnce();
    } catch (err) {
      status.textContent = 'Message failed';
      showError('Message failed to send', err && err.message ? err.message : String(err));
    } finally {
      markBusy(false);
    }
  }

  async function pollOnce() {
    if (polling || !sessionToken) return;
    polling = true;
    try {
      const url = '/api/widget_poll.php?token=' + encodeURIComponent(sessionToken)
                + '&since=' + encodeURIComponent(lastMsgId);
      const res = await fetch(url);
      const data = await res.json();
      if (data.ok && Array.isArray(data.messages)) {
        for (const m of data.messages) {
          renderBubble(m.text, m.direction, m.created_at, false);
          if (m.id > lastMsgId) lastMsgId = m.id;
        }
        if (data.messages.length) scrollBottom();
      }
    } catch (e) {} finally { polling = false; }
  }

  function startPolling() {
    setInterval(pollOnce, POLL_MS);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollOnce();
    });
  }

  widgetStart();
})();
</script>

</body>
</html>
