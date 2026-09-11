/* AiServe Shared WhatsApp Inbox - vanilla JS (live refresh + composer) */
(function () {
  'use strict';

  const csrfMeta  = document.querySelector('meta[name="csrf-token"]');
  const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
  const POLL_MS   = 5000;

  // ---- Mobile drawers (sidebar + chat side panel) -----------------
  const sidebar       = document.querySelector('.sidebar');
  const sidebarOv     = document.getElementById('sidebar-overlay');
  const navToggle     = document.getElementById('nav-toggle');
  const chatSide      = document.getElementById('chat-side');
  const chatSideOpen  = document.getElementById('chat-side-toggle');
  const chatSideClose = document.getElementById('chat-side-close');

  function openSidebar()  { if (sidebar)  sidebar.classList.add('open');  if (sidebarOv) sidebarOv.hidden = false; }
  function closeSidebar() { if (sidebar)  sidebar.classList.remove('open'); if (sidebarOv) sidebarOv.hidden = true;  }
  function openChatSide()  { if (chatSide) chatSide.classList.add('open');  if (sidebarOv) sidebarOv.hidden = false; }
  function closeChatSide() { if (chatSide) chatSide.classList.remove('open'); if (sidebarOv) sidebarOv.hidden = true;  }
  function closeAllDrawers() { closeSidebar(); closeChatSide(); }

  if (navToggle)     navToggle.addEventListener('click', openSidebar);
  if (chatSideOpen)  chatSideOpen.addEventListener('click', openChatSide);
  if (chatSideClose) chatSideClose.addEventListener('click', closeChatSide);
  if (sidebarOv)     sidebarOv.addEventListener('click', closeAllDrawers);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllDrawers(); });

  // Wrap admin data-tables so wide ones scroll horizontally on phones
  // (rather than overflowing the card or shrinking columns to nothing).
  document.querySelectorAll('.data-table').forEach((t) => {
    const parent = t.parentElement;
    if (!parent || parent.classList.contains('table-wrap')) return;
    const wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    parent.insertBefore(wrap, t);
    wrap.appendChild(t);
  });

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
        // Reset the ai_draft hidden field so subsequent manual sends
        // aren't recorded as edited AI drafts.
        const aiDraftField = composer.querySelector('input[name="ai_draft"]');
        if (aiDraftField) aiDraftField.value = '';
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

  // ---- Voice recording (agent → customer voice note) --------------
  //
  // MediaRecorder captures browser mic → Blob (WebM/Opus on Chrome/
  // Firefox, MP4/AAC on iOS Safari). We upload the Blob to the same
  // /api/upload_media.php the file-attach flow uses, then let the
  // existing composer submit + pendingMedia path do the send. So a
  // recorded voice note goes through EXACTLY the same provider
  // dispatch, DB writes, and delivery-tick tracking as any attach.
  const voiceBtn = document.getElementById('voice-record-btn');
  let mediaRecorder = null;
  let chunks = [];
  let recStartTs = 0;
  let recTimer = null;
  let recBar = null;

  function fmtRecTime(sec) {
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60).toString().padStart(2, '0');
    return m + ':' + s;
  }

  function showRecBar() {
    if (recBar) return;
    if (!composer) return;
    recBar = document.createElement('div');
    recBar.className = 'voice-rec-bar';
    recBar.innerHTML =
      '<span class="voice-rec-dot"></span>' +
      '<span>Recording…</span>' +
      '<span class="voice-rec-time" id="voice-rec-time">0:00</span>' +
      '<button type="button" class="btn btn-sm" id="voice-rec-stop">⏹ Stop &amp; use</button>' +
      '<button type="button" class="btn btn-sm" id="voice-rec-cancel">✕ Cancel</button>';
    composer.querySelector('textarea').insertAdjacentElement('afterend', recBar);
    document.getElementById('voice-rec-stop').addEventListener('click', () => stopRecording(false));
    document.getElementById('voice-rec-cancel').addEventListener('click', () => stopRecording(true));
    recStartTs = Date.now();
    recTimer = setInterval(() => {
      const t = document.getElementById('voice-rec-time');
      if (t) t.textContent = fmtRecTime((Date.now() - recStartTs) / 1000);
    }, 200);
  }

  function hideRecBar() {
    if (recTimer) { clearInterval(recTimer); recTimer = null; }
    if (recBar)   { recBar.remove(); recBar = null; }
  }

  async function startRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') return;
    if (!navigator.mediaDevices || !window.MediaRecorder) {
      alert('Voice recording is not supported in this browser. Try Chrome or Safari.');
      return;
    }
    let stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (err) {
      alert('Microphone access denied. Grant permission and try again.');
      return;
    }
    // Pick the best supported audio MIME for WhatsApp. Prefer OGG/Opus
    // (native WhatsApp voice-note format) if the browser supports it.
    const prefs = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus',
                   'audio/webm', 'audio/mp4', 'audio/aac'];
    let picked = '';
    for (const t of prefs) {
      if (window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t)) {
        picked = t; break;
      }
    }
    try {
      mediaRecorder = picked
        ? new MediaRecorder(stream, { mimeType: picked })
        : new MediaRecorder(stream);
    } catch (_) {
      mediaRecorder = new MediaRecorder(stream);
    }
    chunks = [];
    mediaRecorder.addEventListener('dataavailable', e => {
      if (e.data && e.data.size > 0) chunks.push(e.data);
    });
    mediaRecorder.addEventListener('stop', async () => {
      stream.getTracks().forEach(t => t.stop());
      if (mediaRecorder.__cancelled) return;
      const type = mediaRecorder.mimeType || 'audio/webm';
      const blob = new Blob(chunks, { type });
      await uploadRecording(blob, type);
    });
    mediaRecorder.start();
    showRecBar();
  }

  function stopRecording(cancel) {
    if (!mediaRecorder || mediaRecorder.state !== 'recording') return;
    mediaRecorder.__cancelled = !!cancel;
    hideRecBar();
    mediaRecorder.stop();
  }

  async function uploadRecording(blob, mimeType) {
    const statusEl = document.getElementById('media-status');
    if (statusEl) statusEl.textContent = 'Uploading voice note…';
    // Extension for filename - Meta+partner gateways sniff by content
    // anyway, but a clean extension helps everyone.
    const ext = mimeType.includes('ogg')  ? 'ogg'
              : mimeType.includes('mp4')  ? 'm4a'
              : mimeType.includes('webm') ? 'webm'
              : mimeType.includes('aac')  ? 'aac'
              : 'audio';
    const filename = 'voice_' + Date.now() + '.' + ext;
    const fd = new FormData();
    fd.append('file', blob, filename);
    fd.append('_csrf', csrfToken);
    try {
      const res = await fetch('/api/upload_media.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) {
        alert('Upload failed: ' + (data.error || res.status));
        if (statusEl) statusEl.textContent = '';
        return;
      }
      pendingMedia = {
        id: data.media_id, mime_type: data.mime_type, kind: data.kind,
        filename: data.filename || filename,
        preview_url: data.preview_url || null,
        local_path: data.local_path || '',
      };
      if (statusEl) statusEl.textContent = '';
      showMediaPreview(pendingMedia);
    } catch (e) {
      alert('Network error: ' + e.message);
      if (statusEl) statusEl.textContent = '';
    }
  }

  if (voiceBtn) {
    voiceBtn.addEventListener('click', () => {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        stopRecording(false);
      } else {
        startRecording();
      }
    });
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

  // ---- Contact branch selector (side panel Branch field) ----
  const branchForm = document.getElementById('contact-branch-form');
  if (branchForm) {
    const bBtn  = document.getElementById('contact-branch-save');
    const bHint = document.getElementById('contact-branch-hint');
    const originalBranchHint = bHint ? bHint.textContent : '';
    branchForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (bBtn) { bBtn.disabled = true; bBtn.textContent = 'Saving…'; }
      try {
        const fd  = new FormData(branchForm);
        const res = await fetch('/api/contact_set_branch.php', {
          method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) { alert(data.error || ('Branch save failed (HTTP ' + res.status + ')')); return; }
        if (bHint) {
          bHint.textContent = data.branch_name
            ? 'Owned by ' + data.branch_name + '.'
            : 'No branch assigned.';
        }
        if (bBtn) {
          bBtn.textContent = 'Saved ✓';
          setTimeout(() => {
            bBtn.textContent = 'Save';
            if (bHint) bHint.textContent = originalBranchHint;
          }, 2500);
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      } finally {
        if (bBtn) bBtn.disabled = false;
      }
    });
  }

  // ---- Contact rename (side panel Name field) ----
  const renameForm = document.getElementById('contact-rename-form');
  if (renameForm) {
    const input = document.getElementById('contact-rename-input');
    const btn   = document.getElementById('contact-rename-save');
    const hint  = document.getElementById('contact-rename-hint');
    const headerName = document.getElementById('chat-header-name');
    const originalHint = hint ? hint.innerHTML : '';

    renameForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; btn.classList.remove('saved'); }
      try {
        const fd  = new FormData(renameForm);
        const res = await fetch('/api/contact_rename.php', {
          method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          alert(data.error || ('Rename failed (HTTP ' + res.status + ')'));
          return;
        }
        if (headerName && data.display_name) headerName.textContent = data.display_name;
        if (hint) {
          hint.textContent = data.was_reset
            ? 'Reset — showing WhatsApp profile name.'
            : 'Saved. Agents will see this name from now on.';
        }
        if (btn) {
          btn.textContent = 'Saved ✓';
          btn.classList.add('saved');
          setTimeout(() => {
            btn.textContent = 'Save';
            btn.classList.remove('saved');
            if (hint) hint.innerHTML = originalHint;
          }, 2500);
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  }

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
    if (ta && aiBody) {
      ta.value = aiBody.textContent || '';
      ta.focus();
      // Stash the AI's original draft in a hidden field so the send
      // endpoint can capture (draft, sent) pairs for the KB learning
      // loop. The composer's FormData(...) picks it up automatically.
      let hidden = composer.querySelector('input[name="ai_draft"]');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'ai_draft';
        composer.appendChild(hidden);
      }
      hidden.value = aiBody.textContent || '';
    }
    if (aiPanel) aiPanel.classList.add('hidden');
  });
  if (aiDismissBtn) aiDismissBtn.addEventListener('click', () => {
    if (aiPanel) aiPanel.classList.add('hidden');
  });

  // =================================================================
  // AI HANDOVER SUMMARY
  // =================================================================
  const sumBtn       = document.getElementById('summarize-btn');
  const sumBox       = document.getElementById('summary-box');
  const sumText      = document.getElementById('summary-text');
  const sumMeta      = document.getElementById('summary-meta');
  const sumSave      = document.getElementById('summary-save');
  const sumCopy      = document.getElementById('summary-copy');
  const sumRegen     = document.getElementById('summary-regen');
  const sumDismiss   = document.getElementById('summary-dismiss');
  let sumInflight = false;

  async function generateSummary() {
    if (!sumBtn || sumInflight) return;
    const convId = sumBtn.dataset.conversationId;
    sumInflight = true;
    if (sumBox)  sumBox.classList.remove('hidden');
    if (sumText) sumText.textContent = 'Generating handover summary…';
    if (sumMeta) sumMeta.textContent = '';
    if (sumSave) sumSave.disabled = true;
    try {
      const fd = new FormData();
      fd.append('mode', 'generate');
      fd.append('conversation_id', convId);
      fd.append('_csrf', csrfToken);
      const res  = await fetch('/api/ai_summarize.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) {
        if (sumText) sumText.textContent = '⚠ ' + (data.error || 'Summarization failed');
        if (sumSave) sumSave.disabled = true;
        return;
      }
      if (sumText) sumText.textContent = data.summary || '';
      if (sumMeta) sumMeta.textContent = (data.model || '') +
        (data.usage ? ` · ${data.usage.input_tokens || 0} in / ${data.usage.output_tokens || 0} out tokens` : '');
      if (sumSave) sumSave.disabled = false;
    } catch (e) {
      if (sumText) sumText.textContent = '⚠ ' + e.message;
    } finally {
      sumInflight = false;
    }
  }

  if (sumBtn)   sumBtn.addEventListener('click', generateSummary);
  if (sumRegen) sumRegen.addEventListener('click', generateSummary);

  if (sumDismiss) sumDismiss.addEventListener('click', () => {
    if (sumBox) sumBox.classList.add('hidden');
    if (sumText) sumText.textContent = '';
    if (sumMeta) sumMeta.textContent = '';
  });

  if (sumCopy) sumCopy.addEventListener('click', async () => {
    if (!sumText || !sumText.textContent) return;
    try {
      await navigator.clipboard.writeText(sumText.textContent);
      const old = sumCopy.textContent;
      sumCopy.textContent = '✓ Copied';
      setTimeout(() => { sumCopy.textContent = old; }, 1500);
    } catch (e) {
      // Fallback for browsers that block clipboard outside HTTPS
      const ta = document.createElement('textarea');
      ta.value = sumText.textContent;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (_) {}
      ta.remove();
    }
  });

  if (sumSave) sumSave.addEventListener('click', async () => {
    if (!sumBtn || !sumText || !sumText.textContent) return;
    const convId = sumBtn.dataset.conversationId;
    sumSave.disabled = true;
    const original = sumSave.textContent;
    sumSave.textContent = 'Saving…';
    try {
      const fd = new FormData();
      fd.append('mode', 'save_note');
      fd.append('conversation_id', convId);
      fd.append('summary_text', sumText.textContent);
      fd.append('_csrf', csrfToken);
      const res  = await fetch('/api/ai_summarize.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) {
        alert(data.error || 'Could not save summary as note.');
        sumSave.disabled = false;
        sumSave.textContent = original;
        return;
      }
      // Append the new note to the existing notes list so the user sees it
      // without a reload.
      const ul = document.querySelector('.note-list');
      if (ul) {
        const li = document.createElement('li');
        li.innerHTML = '<div class="note-meta"><strong>You</strong> '
                     + '<span class="muted small">just now</span></div>'
                     + '<div class="note-body"></div>';
        li.querySelector('.note-body').textContent =
          '[AI handover summary - ' + new Date().toLocaleString() + ']\n\n' + sumText.textContent;
        ul.appendChild(li);
        const empty = ul.querySelector('.muted.small');
        if (empty && empty.textContent && empty.textContent.includes('No notes')) empty.remove();
      }
      sumSave.textContent = '✓ Saved as note';
      setTimeout(() => { sumSave.textContent = original; sumSave.disabled = false; }, 1500);
    } catch (e) {
      alert('Network error: ' + e.message);
      sumSave.disabled = false;
      sumSave.textContent = original;
    }
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

      // Media hydration refresh: cron/evolution_media_sync.php fills
      // in media_local_path a minute or two after a voice note / photo
      // first arrives. Without this the bubble stays stuck on the
      // "media not synced" placeholder until a full page reload. The
      // server hands us re-rendered bubble HTML keyed by message id;
      // swap the existing node's outerHTML in place so the audio
      // player appears without disturbing scroll position.
      if (data.refresh_html && typeof data.refresh_html === 'object') {
        Object.keys(data.refresh_html).forEach((id) => {
          const node = stream.querySelector('.msg[data-msg-id="' + id + '"]');
          if (!node) return;
          // Skip if the bubble is already hydrated — a real .msg-media
          // block without the .msg-media-missing modifier means the
          // audio player / img / video is already mounted, and
          // repainting it would reset playback mid-play or refetch
          // a big image for nothing.
          const mediaBlock = node.querySelector('.msg-media');
          if (mediaBlock && !mediaBlock.classList.contains('msg-media-missing')) return;
          node.outerHTML = data.refresh_html[id];
        });
      }
    } catch (_) { /* network blip - try again next tick */ }
    finally { chatPolling = false; }
  }

  // ---- Inbox live refresh -----------------------------------------
  const inboxShell = document.querySelector('.inbox-shell[data-poll-scope="inbox"]');
  let inboxPolling = false;
  // Track awaiting count across polls so we can beep + flash the tab title
  // the moment a new customer message lands without anyone replying yet.
  let lastAwaitingCount = null;
  const baseTitle = document.title;

  function setAwaitingTitle(n) {
    document.title = n > 0 ? '(' + n + ') ' + baseTitle : baseTitle;
  }

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
        assignee_id: inboxShell.dataset.assigneeId || '0',
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

      // Awaiting-reply notification: beep when the count climbs, update tab title.
      const awaiting = (data.counts && data.counts.awaiting) || 0;
      if (lastAwaitingCount !== null && awaiting > lastAwaitingCount) beep();
      lastAwaitingCount = awaiting;
      setAwaitingTitle(awaiting);
    } catch (_) { /* ignore */ }
    finally { inboxPolling = false; }
  }

  // Seed the title from server-rendered counts on first paint so the
  // indicator is correct before the first poll completes.
  if (inboxShell) {
    const seed = document.querySelector('.inbox-quickfilters .count[data-count="awaiting"]');
    if (seed) {
      const n = parseInt(seed.textContent || '0', 10) || 0;
      lastAwaitingCount = n;
      setAwaitingTitle(n);
    }
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

  // ============================================================
  // IMAGE LIGHTBOX (chat view)
  // ============================================================
  // Replaces the old <a target="_blank"> approach on chat images.
  // On installed PWAs (iOS/Android Add-to-Home-Screen) the raw
  // new-tab open had no close button and no back gesture, hanging
  // the whole app until the user force-quit and relaunched.
  //
  // Now: tap the image -> full-screen overlay opens over the chat.
  // Close cleanly via the X button, tapping the dark backdrop,
  // Escape key, or the browser back button.
  (function () {
    let overlay = null;
    let currentSrc = null;

    function ensureOverlay() {
      if (overlay) return overlay;
      overlay = document.createElement('div');
      overlay.className = 'img-lightbox';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.innerHTML =
        '<button type="button" class="img-lightbox-close" aria-label="Close">✕</button>' +
        '<a class="img-lightbox-download" aria-label="Download" download>⬇︎</a>' +
        '<img class="img-lightbox-img" alt="">';
      document.body.appendChild(overlay);

      const imgEl = overlay.querySelector('.img-lightbox-img');
      const closeBtn = overlay.querySelector('.img-lightbox-close');
      const dlBtn = overlay.querySelector('.img-lightbox-download');

      closeBtn.addEventListener('click', close);
      // Tap on the backdrop (but NOT on the image itself) closes.
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) close();
      });
      imgEl.addEventListener('click', (e) => e.stopPropagation());
      dlBtn.addEventListener('click', (e) => e.stopPropagation());
      return overlay;
    }

    function open(src) {
      const ov = ensureOverlay();
      const imgEl = ov.querySelector('.img-lightbox-img');
      const dlBtn = ov.querySelector('.img-lightbox-download');
      // Only push a new history entry if the lightbox wasn't already
      // open - rapid taps used to stack N entries and required N back
      // presses to actually leave the chat page.
      const wasOpen = ov.classList.contains('open');
      imgEl.src = src;
      dlBtn.href = src;
      currentSrc = src;
      ov.classList.add('open');
      document.body.classList.add('img-lightbox-locked');
      if (!wasOpen) {
        try { history.pushState({ lightbox: true }, ''); } catch (_) {}
      }
    }

    function close() {
      if (!overlay) return;
      overlay.classList.remove('open');
      document.body.classList.remove('img-lightbox-locked');
      // If we pushed a history entry, pop it so the URL bar is clean.
      if (history.state && history.state.lightbox) {
        try { history.back(); } catch (_) {}
      }
      const imgEl = overlay.querySelector('.img-lightbox-img');
      imgEl.src = '';
      currentSrc = null;
    }

    // Delegate: any image button anywhere on the page opens the lightbox.
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.msg-image-btn');
      if (!btn) return;
      e.preventDefault();
      const src = btn.getAttribute('data-media-src');
      if (src) open(src);
    });

    // Escape closes.
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && overlay && overlay.classList.contains('open')) close();
    });

    // Browser back / PWA back gesture closes.
    window.addEventListener('popstate', () => {
      if (overlay && overlay.classList.contains('open')) {
        // We're already back one step - just hide the overlay without a
        // second history manipulation.
        overlay.classList.remove('open');
        document.body.classList.remove('img-lightbox-locked');
        const imgEl = overlay.querySelector('.img-lightbox-img');
        imgEl.src = '';
        currentSrc = null;
      }
    });
  })();

  // ============================================================
  // CLICK-TO-TRANSLATE (per message)
  // ============================================================
  // Delegated click handler so it also picks up messages appended
  // by the live-refresh poll without any extra wiring.
  (function () {
    document.addEventListener('click', async (e) => {
      const btn = e.target.closest('.msg-translate-btn');
      if (!btn) return;
      const wrap = btn.closest('.msg-translate');
      if (!wrap) return;
      const msgId  = wrap.getAttribute('data-msg-id');
      const status = wrap.querySelector('.msg-translate-status');
      const out    = wrap.querySelector('.msg-translate-out');
      if (!msgId) return;
      btn.disabled = true;
      if (status) { status.textContent = 'Translating…'; status.style.color = ''; }
      try {
        const fd = new FormData();
        fd.append('message_id', msgId);
        fd.append('_csrf', csrfToken);
        const res = await fetch('/api/ai_translate.php', { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          if (status) { status.textContent = '✗ ' + (data.error || 'Failed'); status.style.color = '#b3261e'; }
          btn.disabled = false;
          return;
        }
        // Replace the button + status with the translated block.
        const flag = document.createElement('span');
        flag.className = 'msg-translate-flag';
        flag.textContent = '🌐 ' + (data.target_lang || '');
        const body = document.createElement('div');
        body.className = 'msg-translate-out';
        body.setAttribute('data-lang', data.target_lang || '');
        body.appendChild(flag);
        body.appendChild(document.createTextNode(' ' + (data.text || '')));
        wrap.innerHTML = '';
        wrap.appendChild(body);
      } catch (err) {
        if (status) { status.textContent = '✗ Network error: ' + err.message; status.style.color = '#b3261e'; }
        btn.disabled = false;
      }
    });
  })();

  // ============================================================
  // PER-MESSAGE CONTEXT MENU (⋯) — Forward / Copy / Reply / Note / Delete
  // ============================================================
  // Delegated so it also picks up bubbles the poll appends. The kebab
  // trigger is in every .msg-bubble via chat_render.php; clicking it
  // opens the shared floating menu positioned at the trigger. Copy and
  // Reply run purely client-side; Note prefills the internal-note
  // textarea; Forward opens a modal that lists open conversations;
  // Delete calls /api/message_delete.php then removes the bubble.
  (function () {
    var menuEl = null;      // singleton floating menu
    var overlayEl = null;   // singleton forward-modal overlay
    var currentMsg = null;  // .msg element the menu was opened for

    function ensureMenu() {
      if (menuEl) return menuEl;
      menuEl = document.createElement('div');
      menuEl.className = 'msg-menu';
      menuEl.innerHTML =
          '<button type="button" data-act="forward">Forward</button>'
        + '<button type="button" data-act="copy">Copy</button>'
        + '<button type="button" data-act="reply">Reply</button>'
        + '<button type="button" data-act="note">Note</button>'
        + '<button type="button" data-act="delete">Delete</button>';
      document.body.appendChild(menuEl);
      menuEl.addEventListener('click', handleAction);
      return menuEl;
    }

    function positionMenu(trigger) {
      var m = ensureMenu();
      var r = trigger.getBoundingClientRect();
      m.style.top  = (r.bottom + window.scrollY + 4) + 'px';
      // Anchor menu right-edge under the trigger's right-edge so it doesn't
      // clip off-screen on outgoing bubbles.
      m.style.left = 'auto';
      m.style.right = (window.innerWidth - r.right - window.scrollX) + 'px';
      m.style.display = 'block';
    }

    function closeMenu() {
      if (menuEl) menuEl.style.display = 'none';
      currentMsg = null;
    }

    document.addEventListener('click', function (e) {
      var trig = e.target.closest('.msg-menu-trigger');
      if (trig) {
        e.stopPropagation();
        currentMsg = trig.closest('.msg');
        positionMenu(trig);
        return;
      }
      if (!e.target.closest('.msg-menu') && !e.target.closest('.fwd-overlay')) {
        closeMenu();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { closeMenu(); closeForwardModal(); }
    });

    async function handleAction(e) {
      var btn = e.target.closest('button[data-act]');
      if (!btn || !currentMsg) return;
      var act    = btn.dataset.act;
      var msgId  = currentMsg.dataset.msgId;
      var text   = currentMsg.dataset.msgText || '';
      var msg    = currentMsg;   // capture — closeMenu clears currentMsg
      closeMenu();

      switch (act) {
        case 'copy':
          try {
            await navigator.clipboard.writeText(text);
            toast('✓ Copied');
          } catch (_) {
            // Fallback for older browsers / non-HTTPS local dev.
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta);
            ta.select(); document.execCommand('copy'); ta.remove();
            toast('✓ Copied');
          }
          break;

        case 'reply':
          insertReply(text);
          break;

        case 'note':
          insertNote(msgId, text);
          break;

        case 'forward':
          openForwardModal(msgId, text);
          break;

        case 'delete':
          if (!confirm('Delete this message?\n\nIt stays in the DB for audit but disappears from the inbox. This cannot be undone from the UI.')) return;
          await deleteMsg(msgId, msg);
          break;
      }
    }

    function insertReply(text) {
      var composer = document.getElementById('composer-text');
      if (!composer) { toast('⚠ Composer not on this page'); return; }
      var quoted = '> ' + String(text).replace(/\n/g, '\n> ').slice(0, 500) + '\n\n';
      composer.value = quoted + composer.value;
      composer.focus();
      composer.setSelectionRange(quoted.length, quoted.length);
      composer.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }

    function insertNote(msgId, text) {
      var noteForm = document.querySelector('form.conv-action-form[data-action="add_note"]');
      if (!noteForm) { toast('⚠ Notes panel not on this page'); return; }
      var ta = noteForm.querySelector('textarea[name="note_text"]');
      if (!ta) return;
      var snippet = String(text).slice(0, 120).replace(/\n/g, ' ');
      var ref = 'Re: message #' + msgId + ' — «' + snippet + '»\n\n';
      ta.value = ref + ta.value;
      noteForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
      ta.focus();
      ta.setSelectionRange(ref.length, ref.length);
    }

    async function deleteMsg(msgId, msgEl) {
      var fd = new FormData();
      fd.append('message_id', msgId);
      fd.append('_csrf', csrfToken);
      try {
        var res = await fetch('/api/message_delete.php', { method: 'POST', body: fd });
        var d = await res.json().catch(function () { return { ok: false, error: 'Bad response' }; });
        if (d.ok) {
          if (msgEl && msgEl.parentNode) msgEl.parentNode.removeChild(msgEl);
          toast('✓ Deleted');
        } else {
          alert('Delete failed: ' + (d.error || 'unknown'));
        }
      } catch (err) {
        alert('Delete failed: ' + err.message);
      }
    }

    // -------------------- Forward modal --------------------
    var fwdMsgId = null;
    var fwdSearchTimer = null;

    function ensureForwardModal() {
      if (overlayEl) return overlayEl;
      overlayEl = document.createElement('div');
      overlayEl.className = 'fwd-overlay';
      overlayEl.innerHTML =
          '<div class="fwd-modal">'
        + '  <h3>Forward to another conversation</h3>'
        + '  <div class="muted small" id="fwd-source"></div>'
        + '  <input type="search" placeholder="Search by name or phone…" id="fwd-search" autocomplete="off">'
        + '  <div class="fwd-list" id="fwd-list"><div class="muted small">Loading…</div></div>'
        + '  <div style="display:flex; justify-content:flex-end;">'
        + '    <button type="button" class="btn btn-sm" id="fwd-cancel">Cancel</button>'
        + '  </div>'
        + '</div>';
      document.body.appendChild(overlayEl);
      overlayEl.addEventListener('click', function (e) {
        if (e.target === overlayEl) closeForwardModal();
      });
      overlayEl.querySelector('#fwd-cancel').addEventListener('click', closeForwardModal);
      overlayEl.querySelector('#fwd-search').addEventListener('input', function (e) {
        clearTimeout(fwdSearchTimer);
        fwdSearchTimer = setTimeout(function () { loadForwardTargets(e.target.value); }, 200);
      });
      overlayEl.querySelector('#fwd-list').addEventListener('click', function (e) {
        var row = e.target.closest('.fwd-row');
        if (!row) return;
        var targetId = row.dataset.convId;
        var name     = row.querySelector('.fwd-name').textContent;
        submitForward(fwdMsgId, targetId, name);
      });
      return overlayEl;
    }

    function openForwardModal(msgId, srcText) {
      ensureForwardModal();
      fwdMsgId = msgId;
      overlayEl.querySelector('#fwd-source').textContent =
        'Message: «' + String(srcText).slice(0, 80) + (srcText.length > 80 ? '…' : '') + '»';
      overlayEl.querySelector('#fwd-search').value = '';
      overlayEl.classList.add('open');
      loadForwardTargets('');
      setTimeout(function () { overlayEl.querySelector('#fwd-search').focus(); }, 50);
    }

    function closeForwardModal() {
      if (overlayEl) overlayEl.classList.remove('open');
      fwdMsgId = null;
    }

    async function loadForwardTargets(q) {
      var listEl = overlayEl.querySelector('#fwd-list');
      listEl.innerHTML = '<div class="muted small">Loading…</div>';
      try {
        var url = '/api/forward_targets.php' + (q ? '?q=' + encodeURIComponent(q) : '');
        var res = await fetch(url);
        var d = await res.json().catch(function () { return { ok: false }; });
        if (!d.ok || !d.items) {
          listEl.innerHTML = '<div class="muted small">Could not load conversations.</div>';
          return;
        }
        if (!d.items.length) {
          listEl.innerHTML = '<div class="muted small">No matching open conversations.</div>';
          return;
        }
        listEl.innerHTML = d.items.map(function (c) {
          var when = c.last_message_at ? c.last_message_at.slice(0, 16) : '';
          return '<div class="fwd-row" data-conv-id="' + c.id + '">'
              + '<div>'
              + '  <div class="fwd-name">' + escapeHtml(c.contact_name) + '</div>'
              + '  <div class="fwd-meta">+' + escapeHtml(c.wa_id) + ' · ' + escapeHtml(c.channel_name) + '</div>'
              + '</div>'
              + '<div class="fwd-meta">' + escapeHtml(when) + '</div>'
              + '</div>';
        }).join('');
      } catch (err) {
        listEl.innerHTML = '<div class="muted small">Error: ' + err.message + '</div>';
      }
    }

    async function submitForward(msgId, targetId, targetName) {
      if (!confirm('Forward this message to ' + targetName + '?')) return;
      var fd = new FormData();
      fd.append('message_id', msgId);
      fd.append('target_conversation_id', targetId);
      fd.append('_csrf', csrfToken);
      try {
        var res = await fetch('/api/message_forward.php', { method: 'POST', body: fd });
        var d = await res.json().catch(function () { return { ok: false, error: 'Bad response' }; });
        closeForwardModal();
        if (d.ok) {
          toast('✓ Forwarded to ' + targetName);
        } else {
          alert('Forward failed: ' + (d.error || 'unknown'));
        }
      } catch (err) {
        alert('Forward failed: ' + err.message);
      }
    }

    function escapeHtml(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function toast(msg) {
      var t = document.createElement('div');
      t.className = 'msg-toast';
      t.textContent = msg;
      document.body.appendChild(t);
      requestAnimationFrame(function () { t.classList.add('on'); });
      setTimeout(function () {
        t.classList.remove('on');
        setTimeout(function () { t.remove(); }, 300);
      }, 1600);
    }
  })();

  // ============================================================
  // MERGE CONTACT MODAL — pick a target contact, move everything into it
  // ============================================================
  (function () {
    var btn = document.getElementById('merge-contact-btn');
    if (!btn) return;   // only on chat page

    var overlay = null;

    btn.addEventListener('click', function () {
      openMergeModal(btn.dataset.sourceId, btn.dataset.sourceName || 'this contact');
    });

    function openMergeModal(sourceId, sourceName) {
      if (!overlay) buildOverlay();
      overlay.querySelector('#mrg-source').textContent =
        'Merging: ' + sourceName + ' (id ' + sourceId + ')';
      overlay.dataset.sourceId = sourceId;
      overlay.querySelector('#mrg-search').value = '';
      overlay.querySelector('#mrg-list').innerHTML = '<div class="muted small">Loading…</div>';
      overlay.classList.add('open');
      setTimeout(function () { overlay.querySelector('#mrg-search').focus(); }, 50);
      loadTargets('');
    }

    function buildOverlay() {
      overlay = document.createElement('div');
      overlay.className = 'fwd-overlay';   // reuse existing overlay css
      overlay.innerHTML =
          '<div class="fwd-modal">'
        + '  <h3>🔀 Merge this contact into another</h3>'
        + '  <div class="muted small" id="mrg-source"></div>'
        + '  <div class="muted small" style="padding:8px 10px; background:#fef3c7; border-radius:6px;">'
        + '    ⚠ All messages, conversations, tags, and internal notes on THIS contact will be moved into the target. This contact will then be deleted. <strong>Cannot be undone.</strong>'
        + '  </div>'
        + '  <input type="search" placeholder="Search target by name or phone…" id="mrg-search" autocomplete="off">'
        + '  <div class="fwd-list" id="mrg-list"></div>'
        + '  <div style="display:flex; justify-content:flex-end;">'
        + '    <button type="button" class="btn btn-sm" id="mrg-cancel">Cancel</button>'
        + '  </div>'
        + '</div>';
      document.body.appendChild(overlay);
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeMerge();
      });
      overlay.querySelector('#mrg-cancel').addEventListener('click', closeMerge);
      var searchTimer;
      overlay.querySelector('#mrg-search').addEventListener('input', function (e) {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { loadTargets(e.target.value); }, 200);
      });
      overlay.querySelector('#mrg-list').addEventListener('click', function (e) {
        var row = e.target.closest('.fwd-row');
        if (!row) return;
        var targetId = row.dataset.contactId;
        var name     = row.querySelector('.fwd-name').textContent;
        submitMerge(targetId, name);
      });
    }

    function closeMerge() { if (overlay) overlay.classList.remove('open'); }

    async function loadTargets(q) {
      var listEl = overlay.querySelector('#mrg-list');
      listEl.innerHTML = '<div class="muted small">Loading…</div>';
      try {
        var url = '/api/contact_search.php?q=' + encodeURIComponent(q)
                + '&exclude=' + encodeURIComponent(overlay.dataset.sourceId);
        var res = await fetch(url);
        var d = await res.json().catch(function () { return { ok: false }; });
        if (!d.ok || !d.items) {
          listEl.innerHTML = '<div class="muted small">Could not load contacts.</div>';
          return;
        }
        if (!d.items.length) {
          listEl.innerHTML = '<div class="muted small">No matching contacts. Try a different search.</div>';
          return;
        }
        listEl.innerHTML = d.items.map(function (c) {
          var lidBadge = c.is_lid
            ? '<span style="background:#fef3c7;color:#78350f;padding:1px 6px;border-radius:999px;font-size:10px;margin-left:6px;">🔒 LID</span>'
            : '';
          return '<div class="fwd-row" data-contact-id="' + c.id + '">'
              + '<div>'
              + '  <div class="fwd-name">' + escHtml(c.display_name) + lidBadge + '</div>'
              + '  <div class="fwd-meta">+' + escHtml(c.wa_id) + (c.phone && c.phone !== c.wa_id ? ' · ' + escHtml(c.phone) : '') + '</div>'
              + '</div>'
              + '</div>';
        }).join('');
      } catch (err) {
        listEl.innerHTML = '<div class="muted small">Error: ' + err.message + '</div>';
      }
    }

    async function submitMerge(targetId, targetName) {
      if (!confirm('Merge into ' + targetName + '?\n\nEverything on this contact will move to that one, then this contact is deleted. This cannot be undone.')) return;
      var fd = new FormData();
      fd.append('source_id', overlay.dataset.sourceId);
      fd.append('target_id', targetId);
      fd.append('_csrf', csrfToken);
      try {
        var res = await fetch('/api/contact_merge.php', { method: 'POST', body: fd });
        var d = await res.json().catch(function () { return { ok: false, error: 'Bad response' }; });
        if (d.ok) {
          alert('✓ Merged. Redirecting to the target contact…');
          window.location.href = '/inbox/';  // safe fallback — current conversation was moved
        } else {
          alert('Merge failed: ' + (d.error || 'unknown'));
        }
      } catch (err) {
        alert('Merge failed: ' + err.message);
      }
    }

    function escHtml(s) {
      return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
  })();

  // ============================================================
  // VOICE-NOTE DURATION HINT
  // ============================================================
  // Reads audio.duration once the browser has the metadata and shows
  // "0:34" beside the play button so agents can scan voice notes at a
  // glance without hitting play. IntersectionObserver lazy-loads only
  // players that scroll into view - a chat with dozens of voice notes
  // doesn't blast bandwidth on load.
  (function () {
    function fmt(s) {
      if (!isFinite(s) || s < 0) return '';
      const m = Math.floor(s / 60);
      const r = Math.floor(s % 60).toString().padStart(2, '0');
      return m + ':' + r;
    }

    function hydrateAudio(audio) {
      if (audio.__durationHydrated) return;
      audio.__durationHydrated = true;
      // Ask the browser for JUST the header.
      audio.setAttribute('preload', 'metadata');
      const holder = audio.parentElement && audio.parentElement.querySelector('.msg-audio-duration');
      const paint = () => {
        if (holder && isFinite(audio.duration)) {
          holder.textContent = '🎙 ' + fmt(audio.duration);
        }
      };
      audio.addEventListener('loadedmetadata', paint, { once: true });
      audio.addEventListener('durationchange', paint);
      // If browser already has metadata cached (BFCache), paint now.
      if (audio.readyState >= 1) paint();
    }

    // Prefer IntersectionObserver so we don't request metadata for players
    // that are hundreds of messages up the scroll. Fall back to hydrate-all
    // on browsers that lack it.
    const supportsIO = typeof IntersectionObserver !== 'undefined';
    let io = null;
    if (supportsIO) {
      io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
          if (e.isIntersecting) {
            hydrateAudio(e.target);
            io.unobserve(e.target);
          }
        });
      }, { rootMargin: '200px 0px' });
    }

    function observeAll(root) {
      (root || document).querySelectorAll('audio[data-lazy-meta]:not([data-hydrating])')
        .forEach(a => {
          a.setAttribute('data-hydrating', '1');
          if (io) io.observe(a);
          else    hydrateAudio(a);
        });
    }

    observeAll(document);

    // Live-refresh appends new message bubbles. MutationObserver on the
    // chat stream picks them up automatically without hooking the poll
    // path.
    const s = document.getElementById('chat-stream');
    if (s && typeof MutationObserver !== 'undefined') {
      new MutationObserver(() => observeAll(s)).observe(s, { childList: true, subtree: true });
    }
  })();

  // ============================================================
  // ALERTS BELL — workspace channel-health notification widget
  // ============================================================
  // Polls /api/alerts_ping.php on the same POLL_MS cadence as the
  // inbox/chat pollers so bell count + toasts + browser desktop
  // notifications all stay in near-realtime with what the detectors
  // find (Evolution disconnect, silent inbound, stuck media).
  //
  // Three delivery legs:
  //   inapp   — bell count + dropdown list. Always on for a logged-in
  //             session, no permission needed.
  //   toast   — a red slide-in banner the first time we ever see an
  //             alert id while the tab is visible. Auto-dismisses in
  //             10s. Suppressed on the tab that dismisses so an
  //             agent who closes a toast doesn't get it back on the
  //             next poll.
  //   browser — Web Notification API. Fires ONCE per alert id per
  //             browser, tracked in localStorage. Requires the agent
  //             to grant permission (asked on first open of the
  //             bell). Notification.onclick focuses the tab and
  //             navigates to the fix URL — perfect for an agent
  //             running the inbox in a background tab.
  //
  // The "seen" set is local to this browser; a fresh browser will
  // re-toast open alerts once. Backend-side dispatch bookkeeping
  // (email fanout) is separate and lives in inc/alerts.php.
  const bellRoot = document.getElementById('alerts-bell');
  const bellBtn  = document.getElementById('alerts-bell-btn');
  const bellCnt  = document.getElementById('alerts-bell-count');
  const toastSlot= document.getElementById('alerts-toast-slot');

  const SEEN_KEY = 'aiserve.alerts.seen.v1';
  let seenIds = new Set();
  try {
    seenIds = new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]'));
  } catch (_) { /* private mode / cleared storage — start fresh */ }
  const markSeen = (id) => {
    seenIds.add(id);
    try { localStorage.setItem(SEEN_KEY, JSON.stringify([...seenIds])); } catch (_) {}
  };

  let alertsPanel = null;
  let alertsPolling = false;
  let latestAlerts = [];

  function ensurePanel() {
    if (alertsPanel) return alertsPanel;
    alertsPanel = document.createElement('div');
    alertsPanel.className = 'alerts-panel';
    alertsPanel.hidden = true;
    bellRoot.appendChild(alertsPanel);
    // Click outside → close.
    document.addEventListener('click', (e) => {
      if (!alertsPanel || alertsPanel.hidden) return;
      if (bellRoot.contains(e.target)) return;
      closePanel();
    });
    return alertsPanel;
  }
  function openPanel() {
    ensurePanel();
    renderPanel();
    alertsPanel.hidden = false;
    bellBtn.setAttribute('aria-expanded', 'true');
    // First open is a great moment to ask for desktop notification
    // permission — the agent is looking straight at the bell so the
    // context is clear.
    requestBrowserNotifPermission();
  }
  function closePanel() {
    if (!alertsPanel) return;
    alertsPanel.hidden = true;
    bellBtn.setAttribute('aria-expanded', 'false');
  }
  function renderPanel() {
    if (!alertsPanel) return;
    if (!latestAlerts.length) {
      // Empty state doubles as the discovery point for the push test.
      // If the browser has permission we offer a "Send test push"
      // link that fires api/push_test.php; a real notification
      // arriving on the agent's phone proves the whole pipeline
      // (VAPID + service worker + push service + phone) works.
      const push = ('Notification' in window && Notification.permission === 'granted');
      alertsPanel.innerHTML =
        '<div class="alerts-panel-empty">No open alerts. Channels look healthy.'
        + (push
            ? '<div style="margin-top:10px;"><a href="#" class="alerts-test-push" role="button">Send a test push to my devices</a></div>'
            : '')
        + '</div>';
      const testLink = alertsPanel.querySelector('.alerts-test-push');
      if (testLink) {
        testLink.addEventListener('click', async (e) => {
          e.preventDefault();
          testLink.textContent = 'Sending…';
          try {
            const r = await fetch('/api/push_test.php',
              { method: 'POST', headers: { 'X-CSRF-Token': csrfToken } });
            const d = await r.json().catch(() => ({}));
            testLink.textContent = d.hint || (d.ok ? 'Sent.' : 'Failed.');
          } catch (_) {
            testLink.textContent = 'Failed — try again in a moment.';
          }
        });
      }
      return;
    }
    alertsPanel.innerHTML = latestAlerts.map((a) => {
      const sev = (a.severity || 'warn').replace(/[^a-z]/g, '');
      return '<div class="alerts-item alerts-sev-' + sev + '" data-alert-id="' + a.id + '">'
           + '<button type="button" class="alerts-item-dismiss" '
           +   'aria-label="Dismiss">&times;</button>'
           + '<div class="alerts-item-title">' + escapeHtml(a.title) + '</div>'
           + (a.body ? '<div class="alerts-item-body">' + escapeHtml(a.body) + '</div>' : '')
           + (a.href ? '<a class="alerts-item-fix" href="' + escapeHtml(a.href)
                     + '">Open →</a>' : '')
           + '</div>';
    }).join('');
    alertsPanel.querySelectorAll('.alerts-item-dismiss').forEach((btn) => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const wrap = btn.closest('.alerts-item');
        const id   = wrap && wrap.getAttribute('data-alert-id');
        if (!id) return;
        wrap.remove();
        try {
          const fd = new FormData();
          fd.append('_csrf', csrfToken);
          fd.append('dismiss', id);
          await fetch('/api/alerts_ping.php?dismiss=' + encodeURIComponent(id),
            { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd });
        } catch (_) {}
      });
    });
  }
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, (c) => ({
      '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
    }[c]));
  }

  function showToast(alert) {
    if (!toastSlot) return;
    const el = document.createElement('div');
    el.className = 'alerts-toast alerts-sev-' + (alert.severity || 'warn').replace(/[^a-z]/g, '');
    el.innerHTML =
        '<button type="button" class="alerts-toast-close" aria-label="Dismiss">&times;</button>'
      + '<div class="alerts-toast-title">' + escapeHtml(alert.title) + '</div>'
      + (alert.href ? '<a class="alerts-toast-fix" href="' + escapeHtml(alert.href)
                    + '">Open →</a>' : '');
    toastSlot.appendChild(el);
    el.querySelector('.alerts-toast-close').addEventListener('click', () => el.remove());
    setTimeout(() => el.remove(), 10000);
    beep();
  }

  function requestBrowserNotifPermission() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'default') {
      // Fire and forget — modern browsers require this from a user
      // gesture; the bell click qualifies. Chain into the PWA push
      // subscribe as soon as we get 'granted' so an agent who wanted
      // desktop notifications also gets phone/home-screen pushes for
      // free — one permission covers both surfaces.
      try {
        Notification.requestPermission().then((perm) => {
          if (perm === 'granted') registerPushSubscription();
        }).catch(() => {});
      } catch (_) {}
    } else if (Notification.permission === 'granted') {
      registerPushSubscription();
    }
  }

  // ---- Web Push subscription ----
  // Called once we have a granted Notification permission. Subscribes
  // the SW push manager and hands the endpoint + keys to the backend.
  // Idempotent — a repeat call re-registers the SAME endpoint, which
  // upserts the row on the backend rather than duplicating it.
  let pushSubscribing = false;
  async function registerPushSubscription() {
    if (pushSubscribing) return;
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    if (Notification.permission !== 'granted') return;
    pushSubscribing = true;
    try {
      const reg = await navigator.serviceWorker.ready;
      let sub = await reg.pushManager.getSubscription();
      if (!sub) {
        // Fetch the VAPID public key. Same endpoint GET returns it —
        // safe to expose publicly, that's the whole point of VAPID.
        const keyRes = await fetch('/api/push_subscribe.php',
          { headers: { 'X-CSRF-Token': csrfToken } });
        const keyData = await keyRes.json().catch(() => ({}));
        if (!keyData.ok || !keyData.application_server_key) return;
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlB64ToUint8(keyData.application_server_key),
        });
      }
      // Hand the subscription to the backend so inc/push.php can send.
      await fetch('/api/push_subscribe.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(sub.toJSON()),
      });
    } catch (e) {
      // Common on iOS Safari before the user has "Added to Home Screen":
      // pushManager.subscribe throws NotAllowedError. Silent — the
      // agent will see the install banner and can retry after that.
      console.warn('push subscribe skipped:', e && e.message ? e.message : e);
    } finally {
      pushSubscribing = false;
    }
  }

  // VAPID applicationServerKey must be a Uint8Array, not the b64url
  // string the server returned.
  function urlB64ToUint8(b64u) {
    const pad = '='.repeat((4 - b64u.length % 4) % 4);
    const s   = (b64u + pad).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(s);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  // Kick off subscription on load for any agent who already granted
  // permission on an earlier session — otherwise they'd have to click
  // the bell every visit to re-arm push. Runs after the SW registers.
  if ('serviceWorker' in navigator && Notification.permission === 'granted') {
    // Delay slightly so we don't race the SW registration in pwa.js.
    setTimeout(registerPushSubscription, 1500);
  }
  function showBrowserNotif(alert) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      const n = new Notification(alert.title, {
        body: alert.body || '',
        icon: '/assets/img/icon-192.png',
        tag:  'aiserve-alert-' + alert.id, // replace prior notif for same alert
      });
      n.onclick = () => {
        window.focus();
        if (alert.href) window.location.href = alert.href;
        n.close();
      };
      // Server-side bookkeeping so email fanout knows this leg fired.
      const fd = new FormData();
      fd.append('_csrf', csrfToken);
      fd.append('ack', alert.id);
      fd.append('leg', 'browser');
      fetch('/api/alerts_ping.php?ack=' + alert.id + '&leg=browser',
        { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd }).catch(() => {});
    } catch (_) {}
  }

  async function pollAlertsOnce() {
    if (!bellRoot || alertsPolling) return;
    alertsPolling = true;
    try {
      const res = await fetch('/api/alerts_ping.php',
        { headers: { 'X-CSRF-Token': csrfToken } });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) return;
      latestAlerts = data.alerts || [];
      const n = latestAlerts.length;
      bellRoot.hidden = false;
      bellCnt.textContent = n > 99 ? '99+' : String(n);
      bellRoot.classList.toggle('has-alerts', n > 0);

      // First-sighting side-effects: toast (only if tab visible)
      // and browser notification (always).
      latestAlerts.forEach((a) => {
        if (seenIds.has(a.id)) return;
        markSeen(a.id);
        if (!hidden()) showToast(a);
        showBrowserNotif(a);
      });

      // Repaint an open panel to reflect fresh data.
      if (alertsPanel && !alertsPanel.hidden) renderPanel();
    } catch (_) { /* network blip — try again next tick */ }
    finally { alertsPolling = false; }
  }

  if (bellBtn) {
    bellBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (alertsPanel && !alertsPanel.hidden) closePanel();
      else openPanel();
    });
    // Kick off the poll loop. Reuses POLL_MS so cadence matches the
    // rest of the app.
    pollAlertsOnce();
    setInterval(pollAlertsOnce, POLL_MS);
    document.addEventListener('visibilitychange', () => {
      if (!hidden()) pollAlertsOnce();
    });
  }
})();
