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
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<!-- Per-channel PWA: install as this workspace's own app on the home screen. -->
<link rel="manifest" href="/widget_manifest.php?c=<?= e($channelToken) ?>">
<meta name="theme-color" content="<?= e($brand) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e($title) ?>">
<link rel="apple-touch-icon" href="/assets/img/company_logo.php?company_id=<?= (int)$channel['company_id'] ?>&size=180">
<link rel="icon" type="image/png" href="/assets/img/company_logo.php?company_id=<?= (int)$channel['company_id'] ?>&size=192">
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

/* Quick-reply pills rendered under bot messages that contain a numbered
   list. Tap = auto-send that option's number. Bypasses the keyboard/
   send-button entirely so a customer can order without typing at all. */
.wc-quick {
  display: flex; flex-wrap: wrap; gap: 6px;
  margin: 6px 0 4px; align-self: flex-start; max-width: 90%;
}
.wc-quick button {
  background: #fff; color: #0a5c2a; border: 1px solid <?= e($brand) ?>;
  padding: 8px 14px; border-radius: 999px; font-size: 13.5px;
  font-weight: 600; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,.06);
  -webkit-tap-highlight-color: rgba(0,0,0,.1); touch-action: manipulation;
}
.wc-quick button:active { transform: scale(0.94); background: #f0fdf4; }

/* Photo attach button — sits before the textarea. Tap opens the OS
   image picker (or the camera on mobile via the "capture" attribute).
   Same visual language as the send button but neutral fill so it
   doesn't compete for attention. */
.wc-attach {
  width: 40px; height: 40px; border-radius: 50%; border: none;
  background: #e9edf1; color: #4b5563; font-size: 20px;
  cursor: pointer; flex-shrink: 0;
  -webkit-tap-highlight-color: rgba(0,0,0,.15);
  touch-action: manipulation;
}
.wc-attach:active { transform: scale(0.94); }
.wc-attach.busy   { background: #cbd5e1; color: #94a3b8; cursor: wait; }
.wc-file          { display: none; }

/* Bubbles that carry a photo. Image scales down to fit the bubble
   width, keeps aspect ratio, and rounds off to match the bubble. The
   caption sits under the image, same rules as text bubbles. Tap the
   image to open it full-size in a new tab. */
.wc-bubble.has-image { padding: 4px; overflow: hidden; }
.wc-bubble.has-image img {
  max-width: 100%; height: auto; display: block;
  border-radius: 8px; cursor: zoom-in;
}
.wc-bubble.has-image .wc-cap {
  padding: 6px 8px 2px; white-space: pre-wrap;
}
.wc-bubble.has-image .wc-time { padding: 0 8px 4px; }
/* A subtle uploading spinner overlay while the customer's just-picked
   photo is still climbing to the server. */
.wc-bubble.uploading { position: relative; opacity: 0.75; }
.wc-bubble.uploading::after {
  content: '⏳ uploading…'; position: absolute; inset: auto 0 8px 0;
  text-align: center; color: #fff; font-size: 11px;
  background: rgba(0,0,0,.45); padding: 3px 0;
}
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
  <button id="wc-restart" type="button" title="Start a fresh chat"
          style="margin-left:auto; background:transparent; border:1px solid rgba(255,255,255,0.35);
                 color:#fff; padding:6px 10px; border-radius:8px; font-size:12px; cursor:pointer;">
    Restart
  </button>
</div>

<div class="wc-stream" id="wc-stream">
  <div class="wc-loading" id="wc-loading">Connecting…</div>
</div>

<div class="wc-composer">
  <button id="wc-attach" class="wc-attach" type="button" title="Send a photo" aria-label="Attach photo">📎</button>
  <!-- accept only images; on mobile Safari/Chrome tapping this offers
       both the OS gallery and the camera. -->
  <input type="file" id="wc-file" class="wc-file" accept="image/*" capture="environment">
  <textarea id="wc-input" placeholder="Type a message…" rows="1"></textarea>
  <button id="wc-btn" type="button">➤</button>
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

  const stream    = document.getElementById('wc-stream');
  const input     = document.getElementById('wc-input');
  const btn       = document.getElementById('wc-btn');
  const attachBtn = document.getElementById('wc-attach');
  const fileInput = document.getElementById('wc-file');
  const status    = document.getElementById('wc-status');
  const loader    = document.getElementById('wc-loading');

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

  // renderBubble now understands photo messages via the optional
  // opts.mediaUrl. When present, the bubble embeds the image and puts
  // any text underneath as a caption. Kept back-compatible so every
  // existing caller — history render, polling loop, own-sent text —
  // continues to work by passing just (text, direction, createdAt).
  function renderBubble(text, direction, createdAt, prepend, opts) {
    opts = opts || {};
    const bub = document.createElement('div');
    bub.className = 'wc-bubble ' + (direction === 'incoming' ? 'out' : 'in');

    if (opts.mediaUrl) {
      bub.classList.add('has-image');
      if (opts.uploading) bub.classList.add('uploading');
      const img = document.createElement('img');
      img.src = opts.mediaUrl;
      img.alt = 'photo';
      img.loading = 'lazy';
      img.addEventListener('click', () => window.open(opts.mediaUrl, '_blank', 'noopener'));
      bub.appendChild(img);
      if (text) {
        const cap = document.createElement('div');
        cap.className = 'wc-cap';
        cap.textContent = text;
        bub.appendChild(cap);
      }
    } else {
      bub.textContent = text;
    }

    if (createdAt) {
      const t = document.createElement('div');
      t.className = 'wc-time';
      t.textContent = hhmm(createdAt);
      bub.appendChild(t);
    }
    if (prepend) stream.insertBefore(bub, stream.firstChild);
    else         stream.appendChild(bub);

    // For bot / agent text messages, auto-detect numbered options and
    // render tap-to-reply pills. Skip for photo bubbles — a menu photo
    // shouldn't sprout numbered buttons.
    if (direction !== 'incoming' && !prepend && !opts.mediaUrl) {
      stream.querySelectorAll('.wc-quick').forEach(el => el.remove());
      const optsQR = detectQuickReplies(text);
      if (optsQR.length) renderQuickReplies(optsQR);
    }

    return bub;   // caller can flip .uploading off once the real URL lands
  }

  function detectQuickReplies(text) {
    // Match lines starting with "N." or "N)" — 1-9 to keep tap targets
    // sane on mobile. Skip anything more than 8 options to avoid a wall
    // of buttons.
    const opts = [];
    const seenNums = new Set();
    for (const raw of String(text).split('\n')) {
      const m = raw.trim().match(/^\*?(\d{1,2})[\.)]\s+(.+?)\*?$/);
      if (!m) continue;
      const num = parseInt(m[1], 10);
      if (num < 1 || num > 20 || seenNums.has(num)) continue;
      seenNums.add(num);
      // Strip a trailing "— RM 9.90" price if present (keeps the pill
      // label short + focuses on the item name).
      let label = m[2].replace(/[—–-]\s*RM\s*\d+(\.\d+)?\s*$/i, '').trim();
      if (label.length > 30) label = label.slice(0, 28) + '…';
      opts.push({ num, label });
      if (opts.length >= 8) break;
    }
    return opts;
  }

  function renderQuickReplies(opts) {
    const row = document.createElement('div');
    row.className = 'wc-quick';
    for (const o of opts) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = o.num + ' · ' + o.label;
      b.onclick = () => sendMessage(String(o.num));
      row.appendChild(b);
    }
    stream.appendChild(row);
    scrollBottom();
  }

  // Extracted the actual send so both wcSend() (button/enter) and quick
  // replies use the same code path.
  async function sendMessage(text) {
    text = String(text || '').trim();
    if (!text) return;
    if (!sessionToken) {
      status.textContent = 'Connecting…';
      await widgetStart();
      if (!sessionToken) { status.textContent = 'Still offline — try again'; return; }
      status.textContent = 'Online';
    }
    markBusy(true);
    const now = new Date();
    renderBubble(text, 'incoming', now.toISOString().replace('T', ' ').slice(0, 19), false);
    // Remove any lingering quick-reply row after customer replies.
    stream.querySelectorAll('.wc-quick').forEach(el => el.remove());
    scrollBottom();

    const fd = new FormData();
    fd.append('session_token', sessionToken);
    fd.append('text', text);
    try {
      const res = await fetch('/api/widget_send.php', { method: 'POST', body: fd });
      let data;
      try { data = await res.json(); }
      catch (parseErr) {
        const raw = await res.text().catch(() => '(no body)');
        throw new Error('Server error (HTTP ' + res.status + '): ' + raw.substring(0, 300));
      }
      if (!data.ok) throw new Error(data.error || ('send failed (HTTP ' + res.status + ')'));
      if (data.message_id) lastMsgId = Math.max(lastMsgId, data.message_id);
      pollOnce();
    } catch (err) {
      status.textContent = 'Message failed';
      showError('Message failed to send', err && err.message ? err.message : String(err));
    } finally {
      markBusy(false);
    }
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
        data.history.forEach(m => {
          const opts = m.media_url
                     ? { mediaUrl: m.media_url, mediaMime: m.media_mime, mediaName: m.media_name }
                     : undefined;
          renderBubble(m.text, m.direction, m.created_at, false, opts);
        });
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

  // Thin wrapper for the send button + Enter key — actual send logic
  // lives in sendMessage() so quick-reply buttons share the exact
  // same path.
  async function wcSend() {
    const text = input.value.trim();
    if (!text) { input.focus(); return; }
    input.value = '';
    await sendMessage(text);
  }

  // Photo upload path — mirrors sendMessage() but multipart. Renders a
  // local-preview bubble instantly (so the customer sees "sent" before
  // the round-trip), then swaps in the server-issued media_url on
  // success. Any current text in the composer travels along as caption.
  async function sendPhoto(file) {
    if (!file) return;
    if (!/^image\//.test(file.type || '')) {
      showError('Not a photo', 'Only image files can be sent through the chat.');
      return;
    }
    // Client-side size guard mirrors the server's 8 MB cap — cheaper
    // than uploading first only to be rejected.
    if (file.size > 8 * 1024 * 1024) {
      showError('Photo too large', 'Max 8 MB. Please compress and try again.');
      return;
    }
    if (!sessionToken) {
      status.textContent = 'Connecting…';
      await widgetStart();
      if (!sessionToken) { status.textContent = 'Still offline — try again'; return; }
      status.textContent = 'Online';
    }

    const caption = input.value.trim();
    input.value = '';

    // Local preview so the bubble appears the instant they tap send.
    const previewUrl = URL.createObjectURL(file);
    const now = new Date();
    const bub = renderBubble(caption, 'incoming',
                             now.toISOString().replace('T', ' ').slice(0, 19),
                             false,
                             { mediaUrl: previewUrl, uploading: true });
    stream.querySelectorAll('.wc-quick').forEach(el => el.remove());
    scrollBottom();

    attachBtn.classList.add('busy');
    const fd = new FormData();
    fd.append('session_token', sessionToken);
    fd.append('photo',   file);
    if (caption) fd.append('caption', caption);

    try {
      const res = await fetch('/api/widget_send_media.php', { method: 'POST', body: fd });
      let data;
      try { data = await res.json(); }
      catch (parseErr) {
        const raw = await res.text().catch(() => '(no body)');
        throw new Error('Server error (HTTP ' + res.status + '): ' + raw.substring(0, 300));
      }
      if (!data.ok) throw new Error(data.error || ('upload failed (HTTP ' + res.status + ')'));
      // Swap the ObjectURL preview for the real server URL so the
      // photo survives a page reload (and the browser can drop the
      // blob).
      if (bub) {
        const img = bub.querySelector('img');
        if (img && data.media_url) img.src = data.media_url;
        bub.classList.remove('uploading');
      }
      URL.revokeObjectURL(previewUrl);
      if (data.message_id) lastMsgId = Math.max(lastMsgId, data.message_id);
      pollOnce();
    } catch (err) {
      // Leave the local preview in place but flip its state — the
      // customer can retry from the OS picker.
      if (bub) {
        bub.classList.remove('uploading');
        bub.classList.add('wc-error');
      }
      showError('Photo failed to send', err && err.message ? err.message : String(err));
    } finally {
      attachBtn.classList.remove('busy');
    }
  }

  // Bind BOTH click (desktop) and touchend (iOS Safari) inside the IIFE.
  // Previously the button used inline onclick="wcSend()" but wcSend lives
  // inside this IIFE, not window — so desktop clicks silently threw
  // "wcSend is not defined" and no message ever left the widget.
  btn.addEventListener('click', function (e) { e.preventDefault(); wcSend(); });
  btn.addEventListener('touchend', function (e) { e.preventDefault(); wcSend(); }, { passive: false });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); wcSend(); }
  });

  // Attach button → open hidden file picker → sendPhoto on selection.
  // We reset .value at the start so picking the SAME file twice still
  // fires the change event (browsers otherwise dedupe).
  attachBtn.addEventListener('click', function (e) {
    e.preventDefault();
    if (attachBtn.classList.contains('busy')) return;
    fileInput.value = '';
    fileInput.click();
  });
  attachBtn.addEventListener('touchend', function (e) {
    e.preventDefault();
    if (attachBtn.classList.contains('busy')) return;
    fileInput.value = '';
    fileInput.click();
  }, { passive: false });
  fileInput.addEventListener('change', function () {
    const f = fileInput.files && fileInput.files[0];
    if (f) sendPhoto(f);
  });

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
          // Pass media info if the operator sent an image/video/doc.
          // widget_poll.php only sets media_url when the file is on
          // disk; text messages come through as before.
          const opts = m.media_url
                     ? { mediaUrl: m.media_url, mediaMime: m.media_mime, mediaName: m.media_name }
                     : undefined;
          renderBubble(m.text, m.direction, m.created_at, false, opts);
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

  // "Restart" — clear localStorage session + wipe the on-screen stream,
  // then reload. The next widgetStart() mints a fresh session and a
  // fresh conversation, so flows with a new_conversation trigger fire
  // again and any stale/completed flow_instance no longer blocks a
  // re-trigger. Essential for testing.
  document.getElementById('wc-restart').addEventListener('click', function () {
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    location.reload();
  });

  widgetStart();
})();
</script>

<!-- ============ PWA install banner + service worker ============ -->
<style>
.wc-install-banner {
    position: fixed; left: 12px; right: 12px; bottom: 12px;
    max-width: 480px; margin: 0 auto;
    background: #1a2431; color: #e2e8f0;
    border-radius: 12px; padding: 12px 14px;
    display: flex; align-items: center; gap: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25); z-index: 9999;
    font-size: 13px; line-height: 1.35;
    animation: wc-install-slide 0.25s ease-out;
}
@keyframes wc-install-slide { from { transform: translateY(20px); opacity: 0; } to { transform: none; opacity: 1; } }
.wc-install-banner .wc-install-icon { font-size: 26px; line-height: 1; }
.wc-install-banner .wc-install-text { flex: 1; }
.wc-install-banner .wc-install-text strong { color: #fff; }
.wc-install-banner button {
    border: 0; border-radius: 6px; cursor: pointer;
    padding: 8px 12px; font-size: 12.5px; font-weight: 600;
}
.wc-install-banner .wc-install-primary { background: <?= e($brand) ?>; color: #fff; }
.wc-install-banner .wc-install-close {
    background: transparent; color: #94a3b8; padding: 4px 8px; font-size: 18px;
}
</style>
<script>
(function () {
    'use strict';
    const CHANNEL_KEY = <?= json_encode($channelToken) ?>;
    const APP_TITLE   = <?= json_encode($title) ?>;
    const DISMISS_KEY = 'wc_pwa_dismissed_' + CHANNEL_KEY;

    // Register the widget-specific service worker with its own scope
    // so it doesn't fight the operator app's SW.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker
                .register('/widget-sw.js', { scope: '/chat.php' })
                .catch((e) => console.warn('Widget SW register failed:', e));
        });
    }

    const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
                     || window.navigator.standalone === true;

    // Silence when already installed OR user previously dismissed on
    // this widget (per-channel key so dismissing "Kopetro" doesn't
    // silence the prompt for "Kedai Ali").
    if (isStandalone) return;
    try { if (localStorage.getItem(DISMISS_KEY) === '1') return; } catch (e) {}

    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        // Wait 8 seconds so the customer sees the chat first before
        // being asked to install — nothing kills a widget faster than
        // an install prompt on page load.
        setTimeout(() => showBanner(false), 8000);
    });

    // iOS Safari never fires beforeinstallprompt — surface a Share
    // instruction instead. Same 8 s delay for the same reason.
    if (isIos) setTimeout(() => showBanner(true), 8000);

    window.addEventListener('appinstalled', () => {
        const b = document.getElementById('wc-install-banner');
        if (b) b.remove();
        try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
    });

    function showBanner(ios) {
        if (document.getElementById('wc-install-banner')) return;

        const bar = document.createElement('div');
        bar.id = 'wc-install-banner';
        bar.className = 'wc-install-banner';

        const icon = document.createElement('div');
        icon.className = 'wc-install-icon';
        icon.textContent = '📱';
        bar.appendChild(icon);

        const txt = document.createElement('div');
        txt.className = 'wc-install-text';
        txt.innerHTML = ios
            ? 'Add <strong>' + escapeHtml(APP_TITLE) + '</strong> to your home screen — tap ' +
              '<strong>Share</strong> ⬆ → <strong>Add to Home Screen</strong>'
            : 'Install <strong>' + escapeHtml(APP_TITLE) + '</strong> on your home screen for faster access';
        bar.appendChild(txt);

        if (!ios) {
            const install = document.createElement('button');
            install.type = 'button';
            install.className = 'wc-install-primary';
            install.textContent = 'Install';
            install.addEventListener('click', async () => {
                if (!deferredPrompt) return;
                install.disabled = true;
                deferredPrompt.prompt();
                try { await deferredPrompt.userChoice; } catch (_) {}
                deferredPrompt = null;
                bar.remove();
            });
            bar.appendChild(install);
        }

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'wc-install-close';
        close.textContent = '×';
        close.setAttribute('aria-label', 'Dismiss');
        close.addEventListener('click', () => {
            bar.remove();
            try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
        });
        bar.appendChild(close);

        document.body.appendChild(bar);
    }

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, (c) => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        })[c]);
    }
})();
</script>

</body>
</html>
