/* AiServe Shared WhatsApp Inbox - minimal vanilla JS */
(function () {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

  // Auto-scroll chat to bottom on load
  const stream = document.getElementById('chat-stream');
  if (stream) {
    stream.scrollTop = stream.scrollHeight;
  }

  // ---- Composer (send WhatsApp reply) -----------------------------
  const composer = document.getElementById('composer-form');
  if (composer) {
    composer.addEventListener('submit', async (e) => {
      e.preventDefault();
      const status = document.getElementById('composer-status');
      const ta     = document.getElementById('composer-text');
      const text   = (ta.value || '').trim();
      if (!text) return;

      const btn = composer.querySelector('button[type="submit"]');
      btn.disabled = true;
      if (status) status.textContent = 'Sending…';

      try {
        const fd = new FormData(composer);
        const res = await fetch('/api/send_message.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          if (status) status.textContent = '';
          alert('Send failed: ' + (data.error || ('HTTP ' + res.status)));
          if (data.window_expired) {
            // Reload to re-render composer in expired-window state.
            location.reload();
          }
          btn.disabled = false;
          return;
        }
        // Optimistic append
        appendMessage(text, true);
        ta.value = '';
        if (status) status.textContent = 'Sent';
        setTimeout(() => { if (status) status.textContent = ''; }, 1500);
      } catch (err) {
        if (status) status.textContent = '';
        alert('Network error: ' + err.message);
      } finally {
        btn.disabled = false;
      }
    });
  }

  function appendMessage(text, outgoing) {
    if (!stream) return;
    const wrap = document.createElement('div');
    wrap.className = 'msg ' + (outgoing ? 'msg-out' : 'msg-in') + ' status-sent';
    const bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    const body = document.createElement('div');
    body.className = 'msg-body';
    body.textContent = text;
    bubble.appendChild(body);
    const meta = document.createElement('div');
    meta.className = 'msg-meta';
    const now = new Date();
    meta.textContent = now.toLocaleString();
    bubble.appendChild(meta);
    wrap.appendChild(bubble);
    stream.appendChild(wrap);
    stream.scrollTop = stream.scrollHeight;
  }

  // ---- Conversation actions (assign / status / department / note) ----
  document.querySelectorAll('form.conv-action-form').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      try {
        const fd = new FormData(form);
        const res = await fetch('/api/conversation_action.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          alert(data.error || ('Action failed (HTTP ' + res.status + ')'));
          return;
        }
        if (form.dataset.action === 'add_note') {
          // Append note locally, clear textarea
          const ul = form.parentElement.querySelector('.note-list');
          if (ul) {
            const li = document.createElement('li');
            li.innerHTML = '<div class="note-meta"><strong>You</strong>'
                         + ' <span class="muted small">just now</span></div>'
                         + '<div class="note-body"></div>';
            li.querySelector('.note-body').textContent = fd.get('note_text') || '';
            ul.appendChild(li);
          }
          const ta = form.querySelector('textarea[name="note_text"]');
          if (ta) ta.value = '';
        } else {
          // Reload page to reflect new state in the side panel and chat header.
          location.reload();
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  });
})();
