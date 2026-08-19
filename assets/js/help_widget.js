/*
 * assets/js/help_widget.js
 *
 * In-app AI help widget for operators. Renders a floating "💬 Help"
 * bubble bottom-right on every admin page. Clicking opens a chat
 * panel that POSTs to /api/help_widget.php.
 *
 * History (last 20 msgs) persisted in localStorage per browser, so
 * a page reload doesn't wipe the conversation.
 */
(function () {
  'use strict';

  // Only render on authed pages — if there's no CSRF meta tag, the user
  // isn't logged in (login page, public widget, error page) and the
  // help widget would just 401 anyway.
  var csrfEl = document.querySelector('meta[name="csrf-token"]');
  if (!csrfEl) return;

  // Skip on the customer-facing chat widget itself so the two chatboxes
  // don't collide.
  if (/^\/widget(\/|$|\?)/.test(location.pathname)) return;

  var CSRF   = csrfEl.getAttribute('content') || '';
  var LS_KEY = 'aiserve_help_widget_msgs_v1';

  // ---------- Styles (injected once) ----------
  var css = ''
    + '.hw-btn{position:fixed;right:20px;bottom:20px;z-index:9998;'
    +   'background:linear-gradient(135deg,#0072B2,#005a8a);color:#fff;'
    +   'border:0;border-radius:999px;padding:12px 18px;font-size:14px;'
    +   'font-weight:600;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.18);'
    +   'display:flex;align-items:center;gap:8px;transition:transform .15s;font-family:inherit;}'
    + '.hw-btn:hover{transform:translateY(-2px);}'
    + '.hw-btn .hw-badge{background:#22c55e;width:8px;height:8px;border-radius:50%;'
    +   'box-shadow:0 0 0 2px rgba(34,197,94,.3);animation:hw-pulse 2s infinite;}'
    + '@keyframes hw-pulse{0%,100%{opacity:1}50%{opacity:.5}}'
    + '.hw-panel{position:fixed;right:20px;bottom:80px;z-index:9999;width:380px;'
    +   'max-width:calc(100vw - 40px);height:560px;max-height:calc(100vh - 120px);'
    +   'background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.24);'
    +   'display:none;flex-direction:column;overflow:hidden;font-family:inherit;'
    +   'border:1px solid #e5e7eb;}'
    + '.hw-panel.open{display:flex;}'
    + '.hw-head{background:linear-gradient(135deg,#0072B2,#005a8a);color:#fff;'
    +   'padding:12px 16px;display:flex;align-items:center;justify-content:space-between;}'
    + '.hw-head strong{font-size:14px;}'
    + '.hw-head small{opacity:.75;font-size:11px;display:block;margin-top:2px;}'
    + '.hw-head-btns{display:flex;gap:6px;}'
    + '.hw-head-btns button{background:rgba(255,255,255,.15);border:0;color:#fff;'
    +   'width:26px;height:26px;border-radius:6px;cursor:pointer;font-size:14px;line-height:1;}'
    + '.hw-head-btns button:hover{background:rgba(255,255,255,.28);}'
    + '.hw-msgs{flex:1;overflow-y:auto;padding:14px;background:#f8fafc;}'
    + '.hw-msg{margin-bottom:10px;line-height:1.45;font-size:13.5px;}'
    + '.hw-msg .hw-b{display:inline-block;padding:8px 12px;border-radius:14px;max-width:85%;word-wrap:break-word;}'
    + '.hw-msg.user{text-align:right;}'
    + '.hw-msg.user .hw-b{background:#0072B2;color:#fff;border-bottom-right-radius:4px;}'
    + '.hw-msg.assistant .hw-b{background:#fff;color:#0f172a;border:1px solid #e5e7eb;border-bottom-left-radius:4px;}'
    + '.hw-msg.assistant .hw-b a{color:#0072B2;font-weight:600;}'
    + '.hw-msg.error .hw-b{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}'
    + '.hw-msg .hw-b p{margin:0 0 6px;} .hw-msg .hw-b p:last-child{margin:0;}'
    + '.hw-msg .hw-b ul,.hw-msg .hw-b ol{margin:4px 0 4px 20px;padding:0;}'
    + '.hw-msg .hw-b code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:12px;'
    +   'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}'
    + '.hw-typing{display:inline-block;padding:8px 12px;border-radius:14px;background:#fff;'
    +   'border:1px solid #e5e7eb;color:#94a3b8;font-size:13px;}'
    + '.hw-typing span{display:inline-block;width:6px;height:6px;background:#94a3b8;border-radius:50%;'
    +   'margin:0 2px;animation:hw-bounce 1.4s infinite ease-in-out both;}'
    + '.hw-typing span:nth-child(1){animation-delay:-.32s;} .hw-typing span:nth-child(2){animation-delay:-.16s;}'
    + '@keyframes hw-bounce{0%,80%,100%{transform:scale(0)}40%{transform:scale(1)}}'
    + '.hw-form{border-top:1px solid #e5e7eb;padding:10px;display:flex;gap:8px;background:#fff;}'
    + '.hw-form textarea{flex:1;resize:none;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;'
    +   'font-size:13px;font-family:inherit;height:38px;max-height:120px;transition:border .15s;outline:none;}'
    + '.hw-form textarea:focus{border-color:#0072B2;}'
    + '.hw-form button{background:#0072B2;color:#fff;border:0;border-radius:8px;padding:0 14px;'
    +   'font-weight:600;cursor:pointer;font-size:13px;}'
    + '.hw-form button:disabled{opacity:.5;cursor:not-allowed;}'
    + '.hw-suggest{padding:8px 14px 4px;display:flex;gap:6px;flex-wrap:wrap;background:#fff;border-top:1px solid #f1f5f9;}'
    + '.hw-suggest button{background:#eff6ff;border:1px solid #dbeafe;color:#0072B2;font-size:12px;'
    +   'padding:5px 10px;border-radius:999px;cursor:pointer;font-family:inherit;}'
    + '.hw-suggest button:hover{background:#dbeafe;}'
    + '@media (max-width:520px){'
    +   '.hw-panel{width:calc(100vw - 20px);right:10px;bottom:70px;height:calc(100vh - 90px);border-radius:10px;}'
    +   '.hw-btn{right:12px;bottom:12px;padding:10px 14px;font-size:13px;}'
    +   // Hide on the /inbox chat page in mobile view — the bubble
    +   // was overlapping the composer + Attach/Record footer buttons.
    +   // Operators can still open the widget on any other admin page.
    +   'body.page-inbox .hw-btn,body.page-inbox .hw-panel{display:none !important;}'
    + '}';
  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  // ---------- DOM ----------
  var btn = document.createElement('button');
  btn.className = 'hw-btn';
  btn.setAttribute('aria-label', 'Open help');
  btn.innerHTML = '<span class="hw-badge"></span>💬 Help';

  var panel = document.createElement('div');
  panel.className = 'hw-panel';
  panel.innerHTML = ''
    + '<div class="hw-head">'
    +   '<div><strong>💬 AiServe Guide</strong><small>Ask anything about the platform</small></div>'
    +   '<div class="hw-head-btns">'
    +     '<button class="hw-clear" title="Clear conversation">↺</button>'
    +     '<button class="hw-close" title="Close">✕</button>'
    +   '</div>'
    + '</div>'
    + '<div class="hw-msgs" role="log" aria-live="polite"></div>'
    + '<div class="hw-suggest"></div>'
    + '<form class="hw-form" autocomplete="off">'
    +   '<textarea placeholder="How do I send a broadcast?" rows="1" required maxlength="2000"></textarea>'
    +   '<button type="submit">Send</button>'
    + '</form>';

  document.body.appendChild(btn);
  document.body.appendChild(panel);

  var msgsEl    = panel.querySelector('.hw-msgs');
  var suggestEl = panel.querySelector('.hw-suggest');
  var formEl    = panel.querySelector('.hw-form');
  var taEl      = formEl.querySelector('textarea');
  var sendBtn   = formEl.querySelector('button');
  var closeBtn  = panel.querySelector('.hw-close');
  var clearBtn  = panel.querySelector('.hw-clear');

  // ---------- State ----------
  function loadHistory() {
    try {
      var raw = localStorage.getItem(LS_KEY);
      if (!raw) return [];
      var arr = JSON.parse(raw);
      return Array.isArray(arr) ? arr.slice(-20) : [];
    } catch (e) { return []; }
  }
  function saveHistory(arr) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(arr.slice(-20))); }
    catch (e) { /* quota / private mode — ignore */ }
  }
  var history = loadHistory();

  // ---------- Render ----------
  function renderAll() {
    msgsEl.innerHTML = '';
    if (!history.length) {
      appendMsg({ role: 'assistant', content:
        'Hi! I\'m your in-app guide.\n\nAsk me about **broadcasts**, **flows**, **AI settings**, **F&B orders**, or anything else in the portal.' });
    } else {
      history.forEach(appendMsg);
    }
    renderSuggestions();
    scrollToBottom();
  }

  function appendMsg(m, opts) {
    opts = opts || {};
    var row = document.createElement('div');
    row.className = 'hw-msg ' + (m.role === 'user' ? 'user' : (opts.error ? 'error' : 'assistant'));
    var bubble = document.createElement('div');
    bubble.className = 'hw-b';
    bubble.innerHTML = m.role === 'assistant'
      ? renderMarkdown(m.content)
      : escapeHtml(m.content);
    row.appendChild(bubble);
    msgsEl.appendChild(row);
    scrollToBottom();
    return row;
  }

  function renderSuggestions() {
    // Only show quick-start chips before the first user message.
    var hasUser = history.some(function (m) { return m.role === 'user'; });
    if (hasUser) { suggestEl.innerHTML = ''; return; }
    var suggestions = [
      'How do I send a broadcast?',
      'Set up a new WhatsApp channel',
      'Why is my flow not firing?',
      'What are Q&A pairs?',
    ];
    suggestEl.innerHTML = suggestions.map(function (s) {
      return '<button type="button">' + escapeHtml(s) + '</button>';
    }).join('');
    Array.from(suggestEl.querySelectorAll('button')).forEach(function (b) {
      b.addEventListener('click', function () {
        taEl.value = b.textContent || '';
        formEl.dispatchEvent(new Event('submit', { cancelable: true }));
      });
    });
  }

  function scrollToBottom() {
    requestAnimationFrame(function () { msgsEl.scrollTop = msgsEl.scrollHeight; });
  }

  // Minimal Markdown -> HTML: bold, code, links, paragraphs, lists.
  // Deliberately small — enough for the platform-help voice, no XSS holes.
  function renderMarkdown(txt) {
    // 1. Escape everything first so any raw HTML is inert.
    var s = escapeHtml(txt);
    // 2. Inline code — `code`
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    // 3. Bold — **text**
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    // 4. Markdown links — [text](url) — only allow /path or http(s)://
    s = s.replace(/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/g,
      function (_, t, u) { return '<a href="' + u + '" target="_blank" rel="noopener">' + t + '</a>'; });
    // 5. Bullet lists — lines starting with "- "
    s = s.replace(/(?:^|\n)((?:- .+(?:\n|$))+)/g, function (_, block) {
      var items = block.trim().split(/\n/).map(function (l) {
        return '<li>' + l.replace(/^-\s*/, '') + '</li>';
      }).join('');
      return '\n<ul>' + items + '</ul>';
    });
    // 6. Paragraphs — split on blank lines.
    s = s.split(/\n{2,}/).map(function (p) {
      return /^\s*<(ul|ol|pre|p)/.test(p) ? p : '<p>' + p.replace(/\n/g, '<br>') + '</p>';
    }).join('');
    return s;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // ---------- Send ----------
  var sending = false;
  formEl.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (sending) return;
    var text = (taEl.value || '').trim();
    if (!text) return;

    history.push({ role: 'user', content: text });
    saveHistory(history);
    appendMsg({ role: 'user', content: text });
    suggestEl.innerHTML = '';
    taEl.value = '';
    taEl.style.height = '38px';

    // Typing indicator.
    var typingRow = document.createElement('div');
    typingRow.className = 'hw-msg assistant';
    typingRow.innerHTML = '<span class="hw-typing"><span></span><span></span><span></span></span>';
    msgsEl.appendChild(typingRow);
    scrollToBottom();

    sending = true;
    sendBtn.disabled = true;

    try {
      var res = await fetch('/api/help_widget.php', {
        method:  'POST',
        headers: {
          'Content-Type':  'application/json',
          'X-CSRF-Token':  CSRF,
        },
        body: JSON.stringify({
          messages: history,
          context:  {
            path:       location.pathname + location.search,
            page_title: document.title,
          },
        }),
      });
      // Read as text first so we can surface HTML error pages (PHP
      // fatals return text/html, not JSON) — the previous "Bad response"
      // fallback hid the real cause. Show status + first 200 chars of
      // the body so a fatal is visible right in the widget.
      var raw  = await res.text();
      var data = null;
      try { data = JSON.parse(raw); } catch (e) { /* not JSON */ }
      typingRow.remove();

      if (!data || !data.ok) {
        var msg;
        if (data && data.error) {
          msg = '⚠ ' + data.error;
        } else if (!res.ok) {
          // HTTP-level failure — show the status + a preview of the body
          // so operator / dev can spot a "Undefined function foo()" line
          // straight from the widget.
          var preview = raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
          msg = '⚠ Server error (HTTP ' + res.status + ')' + (preview ? ': ' + preview : '');
        } else {
          msg = '⚠ Unexpected response (not JSON): ' + raw.slice(0, 200);
        }
        appendMsg({ role: 'assistant', content: msg }, { error: true });
        return;
      }
      history.push({ role: 'assistant', content: data.reply });
      saveHistory(history);
      appendMsg({ role: 'assistant', content: data.reply });
    } catch (e) {
      typingRow.remove();
      appendMsg({ role: 'assistant', content: '⚠ Network error: ' + e.message }, { error: true });
    } finally {
      sending = false;
      sendBtn.disabled = false;
      taEl.focus();
    }
  });

  // Enter to send, Shift+Enter for newline. Auto-grow textarea.
  taEl.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey) {
      ev.preventDefault();
      formEl.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  });
  taEl.addEventListener('input', function () {
    taEl.style.height = 'auto';
    taEl.style.height = Math.min(120, taEl.scrollHeight) + 'px';
  });

  // ---------- Open / close / clear ----------
  function openPanel() {
    panel.classList.add('open');
    btn.style.display = 'none';
    if (!msgsEl.children.length) renderAll();
    setTimeout(function () { taEl.focus(); }, 100);
  }
  function closePanel() {
    panel.classList.remove('open');
    btn.style.display = '';
  }
  btn.addEventListener('click', openPanel);
  closeBtn.addEventListener('click', closePanel);
  clearBtn.addEventListener('click', function () {
    if (!confirm('Clear this help conversation?')) return;
    history = [];
    saveHistory(history);
    renderAll();
  });

  // Prime the first render so the greeting is there before the operator opens.
  renderAll();
})();
