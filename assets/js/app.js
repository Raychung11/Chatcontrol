/* AiServe Shared WhatsApp Inbox - vanilla JS (live refresh + composer) */
(function () {
  'use strict';

  const csrfMeta  = document.querySelector('meta[name="csrf-token"]');
  const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
  const POLL_MS   = 5000;

  const stream = document.getElementById('chat-stream');
  if (stream) stream.scrollTop = stream.scrollHeight;

  // ---- Notification beep (WebAudio, no asset) ---------------------
  let audioCtx = null;
  function beep() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      const o = audioCtx.createOscillator();
      const g = audioCtx.createGain();
      o.connect(g); g.connect(audioCtx.destination);
      o.type = 'sine';
      o.frequency.value = 660;
      g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.15, audioCtx.currentTime + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.35);
      o.start();
      o.stop(audioCtx.currentTime + 0.36);
    } catch (_) { /* autoplay blocked until first interaction - fine */ }
  }

  function nearBottom(el) {
    return (el.scrollHeight - el.scrollTop - el.clientHeight) < 120;
  }

  // ---- Media preview state ----------------------------------------
  let pendingMedia = null;

  function clearMediaPreview() {
    pendingMedia = null;
    const node = document.getElementById('media-preview-box');
    if (node) node.remove();
    const input = document.getElementById('media-input');
    if (input) input.value = '';
  }

  // ---- Composer (send WhatsApp reply) -----------------------------
  const composer = document.getElementById('composer-form');
  if (composer) {
    composer.addEventListener('submit', async (e) => {
      e.preventDefault();
      const statusEl = document.getElementById('composer-status');
      const ta       = document.getElementById('composer-text');
      const text     = (ta.value || '').trim();
      if (!text && !pendingMedia) return;

      const btn = composer.querySelector('button[type="submit"]');
      btn.disabled = true;
      if (statusEl) statusEl.textContent = 'Sending…';

      try {
        let endpoint = '/api/send_message.php';
        const fd = new FormData(composer);
        if (pendingMedia) {
          endpoint = '/api/send_media.php';
          fd.append('media_id', pendingMedia.id);
          fd.append('mime_type', pendingMedia.mime_type);
          fd.append('kind', pendingMedia.kind);
          fd.append('filename', pendingMedia.filename || '');
          fd.append('local_path', pendingMedia.local_path || '');
          if (text) fd.append('caption', text);
          fd.delete('message_text');
        }
        const res  = await fetch(endpoint, {
          method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          if (statusEl) statusEl.textContent = '';
          alert('Send failed: ' + (data.error || ('HTTP ' + res.status)));
          if (data.window_expired) location.reload();
          btn.disabled = false;
          return;
        }
        ta.value = '';
        clearMediaPreview();
        if (statusEl) statusEl.textContent = 'Sent';
        setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 1500);
        pollChatOnce(); // server-rendered bubble appears (with real ticks)
      } catch (err) {
        if (statusEl) statusEl.textContent = '';
        alert('Network error: ' + err.message);
      } finally {
        btn.disabled = false;
      }
    });
  }

  // ---- Media upload (file picker -> server upload) ----------------
  const mediaInput = document.getElementById('media-input');
  if (mediaInput && composer) {
    mediaInput.addEventListener('change', async () => {
      if (!mediaInput.files || !mediaInput.files[0]) return;
      const file = mediaInput.files[0];
      if (file.size > 16 * 1024 * 1024) {
        alert('File too large. WhatsApp limits most media to 16 MB.');
        mediaInput.value = '';
        return;
      }
      const statusEl = document.getElementById('media-status');
      if (statusEl) statusEl.textContent = 'Uploading…';
      const fd = new FormData();
      fd.append('file', file);
      fd.append('_csrf', csrfToken);
      try {
        const res = await fetch('/api/upload_media.php', { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          alert('Upload failed: ' + (data.error || res.status));
          if (statusEl) statusEl.textContent = '';
          mediaInput.value = '';
          return;
        }
        pendingMedia = {
          id: data.media_id, mime_type: data.mime_type, kind: data.kind,
          filename: data.filename, preview_url: data.preview_url || null,
          local_path: data.local_path || '',
        };
        if (statusEl) statusEl.textContent = '';
        showMediaPreview(pendingMedia);
      } catch (err) {
        alert('Network error: ' + err.message);
        if (statusEl) statusEl.textContent = '';
      }
    });
  }

  function showMediaPreview(media) {
    if (!composer) return;
    let box = document.getElementById('media-preview-box');
    if (!box) {
      box = document.createElement('div');
      box.id = 'media-preview-box';
      box.className = 'media-preview';
      composer.querySelector('textarea').insertAdjacentElement('afterend', box);
    }
    box.innerHTML = '';
    if (media.kind === 'image' && media.preview_url) {
      const img = document.createElement('img');
      img.src = media.preview_url;
      box.appendChild(img);
    }
    const meta = document.createElement('span');
    meta.textContent = (media.kind || 'media').toUpperCase()
      + (media.filename ? ' · ' + media.filename : '');
    box.appendChild(meta);
    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'btn btn-sm';
    x.textContent = 'Remove';
    x.addEventListener('click', clearMediaPreview);
    box.appendChild(x);
  }

  // ---- Template picker --------------------------------------------
  const tplForm     = document.getElementById('template-form');
  const tplPicker   = document.getElementById('template-picker');
  const tplVars     = document.getElementById('template-vars');
  const tplPreview  = document.getElementById('template-preview');
  const openTplBtn  = document.getElementById('open-template-picker');
  const closeTplBtn = document.getElementById('close-template-picker');

  function getTemplates() {
    if (!tplForm) return [];
    try { return JSON.parse(tplForm.dataset.templates || '[]'); }
    catch (_) { return []; }
  }
  function renderTemplateForm() {
    if (!tplPicker || !tplVars) return;
    const id  = parseInt(tplPicker.value || '0', 10);
    const tpl = getTemplates().find(t => t.id === id);
    tplVars.innerHTML = '';
    if (!tpl) { if (tplPreview) tplPreview.textContent = ''; return; }
    for (let i = 1; i <= (tpl.vars || 0); i++) {
      const lbl = document.createElement('label');
      lbl.textContent = 'Variable {{' + i + '}}';
      const inp = document.createElement('input');
      inp.type = 'text'; inp.name = 'var[]'; inp.required = true;
      inp.dataset.idx = String(i);
      inp.addEventListener('input', updateTemplatePreview);
      lbl.appendChild(inp);
      tplVars.appendChild(lbl);
    }
    updateTemplatePreview();
  }
  function updateTemplatePreview() {
    if (!tplPreview || !tplPicker) return;
    const id  = parseInt(tplPicker.value || '0', 10);
    const tpl = getTemplates().find(t => t.id === id);
    if (!tpl) { tplPreview.textContent = ''; return; }
    let body = tpl.body || '';
    tplVars.querySelectorAll('input[name="var[]"]').forEach((inp) => {
      body = body.replaceAll('{{' + inp.dataset.idx + '}}', inp.value || ('{{' + inp.dataset.idx + '}}'));
    });
    tplPreview.textContent = body;
  }
  if (tplPicker) tplPicker.addEventListener('change', renderTemplateForm);
  if (openTplBtn && tplForm && composer) {
    openTplBtn.addEventListener('click', () => {
      tplForm.classList.remove('hidden'); composer.classList.add('hidden');
    });
  }
  if (closeTplBtn && tplForm && composer) {
    closeTplBtn.addEventListener('click', () => {
      tplForm.classList.add('hidden'); composer.classList.remove('hidden');
    });
  }
  if (tplForm) {
    tplForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const statusEl = document.getElementById('template-status');
      const btn      = tplForm.querySelector('button[type="submit"]');
      btn.disabled = true;
      if (statusEl) statusEl.textContent = 'Sending…';
      try {
        const fd  = new FormData(tplForm);
        const res = await fetch('/api/send_template.php', {
          method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          if (statusEl) statusEl.textContent = '';
          alert('Template send failed: ' + (data.error || res.status));
          btn.disabled = false;
          return;
        }
        if (statusEl) statusEl.textContent = 'Sent';
        setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 1500);
        if (composer) {
          tplForm.classList.add('hidden');
          composer.classList.remove('hidden');
        }
        pollChatOnce();
      } catch (err) {
        if (statusEl) statusEl.textContent = '';
        alert('Network error: ' + err.message);
      } finally {
        btn.disabled = false;
      }
    });
  }

  // ---- Tags (chat side panel) -------------------------------------
  const tagAddForm = document.getElementById('tag-add-form');
  const tagList    = document.getElementById('tag-list');
  if (tagAddForm && tagList) {
    tagAddForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const select = tagAddForm.querySelector('select[name="tag_id"]');
      const tagId  = select.value;
      if (!tagId) return;
      const opt   = select.options[select.selectedIndex];
      const color = opt.dataset.color || '#999';
      const name  = opt.dataset.name  || '';
      const fd = new FormData();
      fd.append('action', 'add_tag');
      fd.append('conversation_id', tagAddForm.dataset.conversationId);
      fd.append('tag_id', tagId);
      fd.append('_csrf', csrfToken);
      const res = await fetch('/api/conversation_action.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) { alert(data.error || 'Could not add tag.'); return; }
      const empty = tagList.querySelector('[data-empty]');
      if (empty) empty.remove();
      const chip = document.createElement('span');
      chip.className = 'tag-chip';
      chip.dataset.tagId = tagId;
      chip.style.background = color;
      chip.textContent = name;
      const x = document.createElement('button');
      x.type = 'button'; x.className = 'tag-chip-x';
      x.title = 'Remove'; x.setAttribute('aria-label', 'Remove tag');
      x.textContent = '×';
      chip.appendChild(x);
      tagList.appendChild(chip);
      opt.remove();
      select.value = '';
    });

    tagList.addEventListener('click', async (e) => {
      const btn = e.target.closest('.tag-chip-x');
      if (!btn) return;
      const chip  = btn.closest('.tag-chip');
      const tagId = chip.dataset.tagId;
      const fd = new FormData();
      fd.append('action', 'remove_tag');
      fd.append('conversation_id', tagList.dataset.conversationId);
      fd.append('tag_id', tagId);
      fd.append('_csrf', csrfToken);
      const res = await fetch('/api/conversation_action.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) { alert(data.error || 'Could not remove tag.'); return; }
      if (tagAddForm) {
        const select = tagAddForm.querySelector('select[name="tag_id"]');
        const opt = document.createElement('option');
        opt.value = tagId;
        opt.dataset.color = chip.style.backgroundColor;
        opt.dataset.name  = chip.firstChild.nodeValue.trim();
        opt.textContent   = opt.dataset.name;
        select.appendChild(opt);
      }
      chip.remove();
      if (!tagList.querySelector('.tag-chip')) {
        const empty = document.createElement('span');
        empty.className = 'muted small';
        empty.dataset.empty = '1';
        empty.textContent = 'No tags yet.';
        tagList.appendChild(empty);
      }
    });
  }

  // ---- Conversation actions (assign / status / department / note) ----
  document.querySelectorAll('form.conv-action-form').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      try {
        const fd  = new FormData(form);
        const res = await fetch('/api/conversation_action.php', {
          method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) { alert(data.error || ('Action failed (HTTP ' + res.status + ')')); return; }
        if (form.dataset.action === 'add_note') {
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
          location.reload();
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  });

  // =================================================================
  // AI REPLY SUGGESTION
  // =================================================================
  const aiBtn        = document.getElementById('ai-suggest-btn');
  const aiPanel      = document.getElementById('ai-draft');
  const aiBody       = document.getElementById('ai-draft-body');
  const aiMeta       = document.getElementById('ai-draft-meta');
  const aiUseBtn     = document.getElementById('ai-draft-use');
  const aiRegenBtn   = document.getElementById('ai-draft-regen');
  const aiDismissBtn = document.getElementById('ai-draft-dismiss');
  let aiInflight = false;

  async function fetchAiSuggestion() {
    if (!composer || aiInflight || !composer.dataset.aiEnabled || composer.dataset.aiEnabled !== '1') return;
    const convId = composer.querySelector('input[name="conversation_id"]').value;
    aiInflight = true;
    if (aiPanel) {
      aiPanel.classList.remove('hidden');
      if (aiBody) aiBody.textContent = 'Thinking…';
      if (aiMeta) aiMeta.textContent = '';
    }
    try {
      const fd = new FormData();
      fd.append('conversation_id', convId);
      fd.append('_csrf', csrfToken);
      const res  = await fetch('/api/ai_suggest.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) {
        if (aiBody) aiBody.textContent = '⚠ ' + (data.error || 'AI request failed');
        if (aiMeta) aiMeta.textContent = '';
        return;
      }
      if (aiBody) aiBody.textContent = data.suggestion || '';
      let meta = (data.model || '');
      if (data.usage) meta += ` · ${data.usage.input_tokens || 0} in / ${data.usage.output_tokens || 0} out tokens`;
      if (data.kb_titles && data.kb_titles.length) {
        meta += ` · 📚 ${data.kb_titles.join(', ')}`;
      }
      if (aiMeta) aiMeta.textContent = meta;
    } catch (e) {
      if (aiBody) aiBody.textContent = '⚠ ' + e.message;
    } finally {
      aiInflight = false;
    }
  }

  if (aiBtn) aiBtn.addEventListener('click', fetchAiSuggestion);
  if (aiRegenBtn) aiRegenBtn.addEventListener('click', fetchAiSuggestion);
  if (aiUseBtn) aiUseBtn.addEventListener('click', () => {
    const ta = document.getElementById('composer-text');
    if (ta && aiBody) { ta.value = aiBody.textContent || ''; ta.focus(); }
    if (aiPanel) aiPanel.classList.add('hidden');
  });
  if (aiDismissBtn) aiDismissBtn.addEventListener('click', () => {
    if (aiPanel) aiPanel.classList.add('hidden');
  });

  // =================================================================
  // LIVE REFRESH
  // =================================================================

  const hidden = () => document.visibilityState === 'hidden';

  // ---- Chat live refresh ------------------------------------------
  let chatPolling = false;
  async function pollChatOnce() {
    if (!stream || chatPolling) return;
    const shell = document.querySelector('.chat-shell');
    if (!shell) return;
    const convId = shell.getAttribute('data-conversation-id');
    if (!convId) return;
    chatPolling = true;
    try {
      const afterId = stream.getAttribute('data-last-msg-id') || '0';
      const res = await fetch('/api/poll.php?scope=chat&conversation_id='
        + encodeURIComponent(convId) + '&after_id=' + encodeURIComponent(afterId),
        { headers: { 'X-CSRF-Token': csrfToken } });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) return;

      const wasNearBottom = nearBottom(stream);

      if (data.messages_html && data.messages_html.trim() !== '') {
        stream.insertAdjacentHTML('beforeend', data.messages_html);
        stream.setAttribute('data-last-msg-id', String(data.last_msg_id));
        if (wasNearBottom) stream.scrollTop = stream.scrollHeight;
        if (data.new_inbound) {
          beep();
          // Auto-suggest a draft if the workspace has it enabled.
          if (composer && composer.dataset.aiAuto === '1') {
            fetchAiSuggestion();
          }
        }
      }

      // Update delivery ticks on existing outgoing bubbles
      if (data.statuses) {
        Object.keys(data.statuses).forEach((id) => {
          const node = stream.querySelector('.msg[data-msg-id="' + id + '"]');
          if (!node) return;
          const want = 'status-' + data.statuses[id];
          if (!node.classList.contains(want)) {
            node.className = node.className.replace(/status-\w+/, want);
          }
        });
      }
    } catch (_) { /* network blip - try again next tick */ }
    finally { chatPolling = false; }
  }

  // ---- Inbox live refresh -----------------------------------------
  const inboxShell = document.querySelector('.inbox-shell[data-poll-scope="inbox"]');
  let inboxPolling = false;
  async function pollInboxOnce() {
    if (!inboxShell || inboxPolling || hidden()) return;
    inboxPolling = true;
    try {
      const qs = new URLSearchParams({
        scope: 'inbox',
        filter: inboxShell.dataset.filter || 'all',
        q: inboxShell.dataset.q || '',
        department_id: inboxShell.dataset.departmentId || '0',
        tag_id: inboxShell.dataset.tagId || '0',
      });
      const res  = await fetch('/api/poll.php?' + qs.toString(),
        { headers: { 'X-CSRF-Token': csrfToken } });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) return;

      const list = document.getElementById('inbox-list');
      if (list && typeof data.rows_html === 'string') {
        // Only repaint if content actually changed (avoid scroll jump).
        if (list.dataset.sig !== data.rows_html.length + ':' + (data.counts.open_total || 0)) {
          list.innerHTML = data.rows_html;
          list.dataset.sig = data.rows_html.length + ':' + (data.counts.open_total || 0);
        }
      }
      document.querySelectorAll('.inbox-quickfilters .count[data-count]').forEach((el) => {
        const k = el.getAttribute('data-count');
        if (data.counts && k in data.counts) el.textContent = data.counts[k];
      });
    } catch (_) { /* ignore */ }
    finally { inboxPolling = false; }
  }

  if (stream || inboxShell) {
    setInterval(() => {
      if (stream) pollChatOnce();
      if (inboxShell) pollInboxOnce();
    }, POLL_MS);
    // Refresh promptly when the tab regains focus
    document.addEventListener('visibilitychange', () => {
      if (!hidden()) { pollChatOnce(); pollInboxOnce(); }
    });
  }
})();
