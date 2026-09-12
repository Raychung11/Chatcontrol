<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

// Load the most recent cached analysis for the default period so the page
// has something to show on first render.
$stmt = $db->prepare(
    'SELECT * FROM topic_analyses
     WHERE company_id = ? AND period_days = 30
     ORDER BY id DESC LIMIT 1'
);
$stmt->execute([$companyId]);
$initial = $stmt->fetch();

layout_start($current_user, 'Topics analytics', 'topics');
?>
<div class="card">
  <div class="card-head">
    <h2>What customers are talking about</h2>
    <div class="topics-controls">
      <select id="topics-period">
        <option value="7">Last 7 days</option>
        <option value="30" selected>Last 30 days</option>
        <option value="90">Last 90 days</option>
      </select>
      <button class="btn btn-primary" id="topics-run">Analyze</button>
      <button class="btn btn-sm" id="topics-refresh" title="Force regenerate (skips 24h cache)">Refresh</button>
    </div>
  </div>
  <p class="muted small">
    Claude reads the first three customer messages of each conversation in the period
    and groups them into common discussion topics. Cached for 24 hours per period;
    click <strong>Refresh</strong> to regenerate sooner.
  </p>

  <div id="topics-status" class="muted small" style="margin-bottom: 12px;"></div>
  <div id="topics-result"></div>
</div>

<?php if ($initial):
    $topics = json_decode((string)$initial['topics_json'], true) ?: [];
?>
<script>
window.__initialTopics = {
  topics: <?= json_encode($topics, JSON_UNESCAPED_UNICODE) ?>,
  generated_at: <?= json_encode($initial['created_at']) ?>,
  conversation_count: <?= (int)$initial['conversation_count'] ?>,
  message_count:      <?= (int)$initial['message_count'] ?>,
  period_days:        <?= (int)$initial['period_days'] ?>,
  model:              <?= json_encode($initial['model']) ?>,
  cached:             true
};
</script>
<?php endif; ?>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const periodEl  = document.getElementById('topics-period');
  const runBtn    = document.getElementById('topics-run');
  const refreshBtn= document.getElementById('topics-refresh');
  const statusEl  = document.getElementById('topics-status');
  const resultEl  = document.getElementById('topics-result');

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  function render(data) {
    if (!data || !data.topics || !data.topics.length) {
      resultEl.innerHTML = '<p class="muted">No topics returned. Try a longer period or refresh.</p>';
      return;
    }
    const maxCount = Math.max(...data.topics.map(t => Number(t.count) || 1));
    const meta = [];
    if (data.cached)         meta.push('cached');
    if (data.generated_at)   meta.push('generated ' + data.generated_at);
    if (data.conversation_count) meta.push(data.conversation_count + ' conversations');
    if (data.message_count)  meta.push(data.message_count + ' messages');
    if (data.model)          meta.push('model: ' + data.model);
    statusEl.textContent = meta.join(' · ');

    let html = '<div class="topic-list">';
    data.topics.forEach((t, idx) => {
      const count = Number(t.count) || 0;
      const pct = Math.round((count / maxCount) * 100);
      const ids = Array.isArray(t.conversation_ids) ? t.conversation_ids : [];
      html += '<div class="topic-row" data-topic-idx="' + idx + '">';
      html +=   '<div class="topic-head">';
      html +=     '<span class="topic-name">' + escapeHtml(t.topic || 'Untitled') + '</span>';
      html +=     '<span class="topic-count">' + count + '</span>';
      html +=   '</div>';
      html +=   '<div class="topic-bar"><span class="topic-bar-fill" style="width:' + pct + '%"></span></div>';
      if (t.summary) {
        html += '<p class="topic-summary muted small">' + escapeHtml(t.summary) + '</p>';
      }
      if (ids.length) {
        html += '<div class="topic-actions">';
        html +=   '<button type="button" class="btn btn-sm topic-tag-btn" '
              +       'data-topic="' + escapeHtml(t.topic || '') + '" '
              +       'data-ids="' + escapeHtml(JSON.stringify(ids)) + '">'
              +     '🏷 Apply as tag to ' + ids.length + ' conversations'
              +   '</button>';
        html +=   '<span class="topic-action-status muted small"></span>';
        html += '</div>';
      }
      if (Array.isArray(t.examples) && t.examples.length) {
        html += '<details class="topic-examples"><summary>Example messages</summary><ul>';
        t.examples.slice(0, 5).forEach(ex => {
          html += '<li>' + escapeHtml(ex) + '</li>';
        });
        html += '</ul></details>';
      }
      html += '</div>';
    });
    html += '</div>';
    resultEl.innerHTML = html;
    bindTagButtons();
  }

  function bindTagButtons() {
    resultEl.querySelectorAll('.topic-tag-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const status = btn.parentElement.querySelector('.topic-action-status');
        btn.disabled = true;
        if (status) { status.textContent = 'Tagging…'; status.style.color = ''; }
        try {
          const fd = new FormData();
          fd.append('topic', btn.dataset.topic);
          fd.append('conversation_ids', btn.dataset.ids);
          fd.append('_csrf', csrf);
          const res = await fetch('/api/topic_tag_apply.php', { method: 'POST', body: fd });
          const data = await res.json().catch(() => ({}));
          if (!data.ok) {
            if (status) { status.textContent = '✗ ' + (data.error || 'Failed'); status.style.color = '#b3261e'; }
            btn.disabled = false;
            return;
          }
          if (status) {
            status.innerHTML = '✓ Tagged ' + data.newly_tagged + ' new + ' +
              (data.valid - data.newly_tagged) + ' already tagged · ' +
              '<a href="' + escapeHtml(data.filter_url) + '">View in inbox</a>';
            status.style.color = '#1f7a3f';
          }
          btn.textContent = '✓ Tag applied';
        } catch (e) {
          if (status) { status.textContent = '✗ ' + e.message; status.style.color = '#b3261e'; }
          btn.disabled = false;
        }
      });
    });
  }

  async function run(refresh) {
    runBtn.disabled = true; refreshBtn.disabled = true;
    statusEl.textContent = 'Analyzing…';
    resultEl.innerHTML = '<p class="muted">This usually takes 5-20 seconds.</p>';
    try {
      const fd = new FormData();
      fd.append('period_days', periodEl.value);
      if (refresh) fd.append('refresh', '1');
      fd.append('_csrf', csrf);
      const res = await fetch('/api/ai_topics.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) {
        statusEl.textContent = '';
        resultEl.innerHTML = '<p class="alert alert-error">' + escapeHtml(data.error || 'Failed') + '</p>';
        return;
      }
      render(data);
    } catch (e) {
      statusEl.textContent = '';
      resultEl.innerHTML = '<p class="alert alert-error">Network error: ' + escapeHtml(e.message) + '</p>';
    } finally {
      runBtn.disabled = false; refreshBtn.disabled = false;
    }
  }

  runBtn.addEventListener('click', () => run(false));
  refreshBtn.addEventListener('click', () => run(true));

  if (window.__initialTopics) {
    periodEl.value = String(window.__initialTopics.period_days);
    render(window.__initialTopics);
  }
})();
</script>
<?php layout_end(); ?>
