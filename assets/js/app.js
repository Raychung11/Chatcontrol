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
  let pendingMedia = null; // { id, mime_type, kind, filename, preview_url }

  if (composer) {
    composer.addEventListener('submit', async (e) => {
      e.preventDefault();
      const status = document.getElementById('composer-status');
      const ta     = document.getElementById('composer-text');
      const text   = (ta.value || '').trim();
      if (!text && !pendingMedia) return;

      const btn = composer.querySelector('button[type="submit"]');
      btn.disabled = true;
      if (status) status.textContent = 'Sending…';

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
        const res = await fetch(endpoint, {
          method: 'POST',
          body: fd,
          headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          if (status) status.textContent = '';
          alert('Send failed: ' + (data.error || ('HTTP ' + res.status)));
          if (data.window_expired) location.reload();
          btn.disabled = false;
          return;
        }
        if (pendingMedia) {
          appendMediaMessage(pendingMedia, text);
          clearMediaPreview();
        } else {
          appendMessage(text, true);
        }
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

  function appendMediaMessage(media, caption) {
    if (!stream) return;
    const wrap = document.createElement('div');
    wrap.className = 'msg msg-out status-sent';
    const bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    const tag = document.createElement('div');
    tag.className = 'msg-type-tag';
    tag.textContent = (media.kind || 'media').toUpperCase()
      + (media.filename ? ' · ' + media.filename : '');
    bubble.appendChild(tag);
    if (media.preview_url && media.kind === 'image') {
      const wrapMedia = document.createElement('div');
      wrapMedia.className = 'msg-media';
      const img = document.createElement('img');
      img.src = media.preview_url;
      wrapMedia.appendChild(img);
      bubble.appendChild(wrapMedia);
    }
    if (caption) {
      const body = document.createElement('div');
      body.className = 'msg-body';
      body.textContent = caption;
      bubble.appendChild(body);
    }
    const meta = document.createElement('div');
    meta.className = 'msg-meta';
    meta.textContent = new Date().toLocaleString();
    bubble.appendChild(meta);
    wrap.appendChild(bubble);
    stream.appendChild(wrap);
    stream.scrollTop = stream.scrollHeight;
  }

  function clearMediaPreview() {
    pendingMedia = null;
    const node = document.getElementById('media-preview-box');
    if (node) node.remove();
    const input = document.getElementById('media-input');
    if (input) input.value = '';
  }

  // ---- Media upload (file picker -> server upload -> Meta media_id) ----
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

      const status = document.getElementById('media-status');
      if (status) status.textContent = 'Uploading…';

      const fd = new FormData();
      fd.append('file', file);
      fd.append('_csrf', csrfToken);
      try {
        const res = await fetch('/api/upload_media.php', { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          alert('Upload failed: ' + (data.error || res.status));
          if (status) status.textContent = '';
          mediaInput.value = '';
          return;
        }
        pendingMedia = {
          id:        data.media_id,
          mime_type: data.mime_type,
          kind:      data.kind,
          filename:  data.filename,
          preview_url: data.preview_url || null,
          local_path:  data.local_path  || '',
        };
        if (status) status.textContent = '';
        showMediaPreview(pendingMedia);
      } catch (err) {
        alert('Network error: ' + err.message);
        if (status) status.textContent = '';
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
  const tplForm    = document.getElementById('template-form');
  const tplPicker  = document.getElementById('template-picker');
  const tplVars    = document.getElementById('template-vars');
  const tplPreview = document.getElementById('template-preview');
  const openTplBtn = document.getElementById('open-template-picker');
  const closeTplBtn= document.getElementById('close-template-picker');

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
      inp.type = 'text';
      inp.name = 'var[]';
      inp.required = true;
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
      const idx = inp.dataset.idx;
      body = body.replaceAll('{{' + idx + '}}', inp.value || ('{{' + idx + '}}'));
    });
    tplPreview.textContent = body;
  }

  if (tplPicker) tplPicker.addEventListener('change', renderTemplateForm);

  if (openTplBtn && tplForm && composer) {
    openTplBtn.addEventListener('click', () => {
      tplForm.classList.remove('hidden');
      composer.classList.add('hidden');
    });
  }
  if (closeTplBtn && tplForm && composer) {
    closeTplBtn.addEventListener('click', () => {
      tplForm.classList.add('hidden');
      composer.classList.remove('hidden');
    });
  }

  if (tplForm) {
    tplForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const status = document.getElementById('template-status');
      const btn    = tplForm.querySelector('button[type="submit"]');
      btn.disabled = true;
      if (status) status.textContent = 'Sending…';
      try {
        const fd = new FormData(tplForm);
        const res = await fetch('/api/send_template.php', {
          method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          if (status) status.textContent = '';
          alert('Template send failed: ' + (data.error || res.status));
          btn.disabled = false;
          return;
        }
        appendMessage(data.preview || '[template sent]', true);
        if (status) status.textContent = 'Sent';
        setTimeout(() => { if (status) status.textContent = ''; }, 1500);
        if (composer) {
          tplForm.classList.add('hidden');
          composer.classList.remove('hidden');
        } else {
          // Window-expired view -> reload so timeline stays accurate
          setTimeout(() => location.reload(), 600);
        }
      } catch (err) {
        if (status) status.textContent = '';
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
      x.type = 'button';
      x.className = 'tag-chip-x';
      x.title = 'Remove';
      x.setAttribute('aria-label', 'Remove tag');
      x.textContent = '×';
      chip.appendChild(x);
      tagList.appendChild(chip);
      opt.remove();
      select.value = '';
    });

    tagList.addEventListener('click', async (e) => {
      const btn = e.target.closest('.tag-chip-x');
      if (!btn) return;
      const chip = btn.closest('.tag-chip');
      const tagId = chip.dataset.tagId;
      const fd = new FormData();
      fd.append('action', 'remove_tag');
      fd.append('conversation_id', tagList.dataset.conversationId);
      fd.append('tag_id', tagId);
      fd.append('_csrf', csrfToken);
      const res = await fetch('/api/conversation_action.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) { alert(data.error || 'Could not remove tag.'); return; }

      // Add back to picker dropdown
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
