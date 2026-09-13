<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$err = '';
$applied = ['templates' => 0, 'auto_replies' => 0];

if (is_post() && ($_POST['action'] ?? '') === 'apply') {
    csrf_check();
    $pack = json_decode((string)($_POST['pack'] ?? ''), true);
    if (!is_array($pack)) {
        $err = 'Invalid pack payload.';
    } else {
        $db->beginTransaction();
        try {
            // Templates.
            $tplIns = $db->prepare(
                'INSERT IGNORE INTO message_templates
                    (company_id, template_name, category, language, body_text, variables_json, status)
                 VALUES (?, ?, ?, ?, ?, ?, "draft")'
            );
            foreach ((array)($pack['templates'] ?? []) as $t) {
                $tplIns->execute([
                    $companyId,
                    (string)$t['template_name'],
                    (string)$t['category'],
                    (string)$t['language'],
                    (string)$t['body_text'],
                    (string)$t['variables_json'],
                ]);
                if ($tplIns->rowCount() > 0) $applied['templates']++;
            }
            // Auto-replies.
            $arIns = $db->prepare(
                'INSERT INTO auto_replies
                    (company_id, name, match_type, match_value, reply_text, media_kind,
                     priority, cooldown_min, status, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, "none", ?, 60, "inactive", ?)'
            );
            foreach ((array)($pack['auto_replies'] ?? []) as $r) {
                $arIns->execute([
                    $companyId,
                    (string)$r['name'],
                    (string)$r['match_type'],
                    (string)$r['match_value'],
                    (string)$r['reply_text'],
                    (int)$r['priority'],
                    (int)$current_user['id'],
                ]);
                $applied['auto_replies']++;
            }
            $db->commit();
            log_activity($companyId, (int)$current_user['id'], 'setup_wizard_applied', 'company', $companyId,
                'templates=' . $applied['templates'] . ' auto_replies=' . $applied['auto_replies']);
            redirect('/admin/setup_wizard.php?done=1&t=' . $applied['templates'] . '&r=' . $applied['auto_replies']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[AiServe setup_wizard] ' . $e->getMessage());
            $err = 'Could not apply the pack. Nothing saved. Try again or contact support.';
        }
    }
}

$done  = isset($_GET['done']);
$doneT = (int)($_GET['t'] ?? 0);
$doneR = (int)($_GET['r'] ?? 0);

// Show current counts so operator sees whether the workspace is truly empty.
$countTpl = (int)$db->query('SELECT COUNT(*) FROM message_templates WHERE company_id = ' . $companyId)->fetchColumn();
$countAR  = (int)$db->query('SELECT COUNT(*) FROM auto_replies WHERE company_id = ' . $companyId)->fetchColumn();

layout_start($current_user, 'Quick setup wizard', 'setup_wizard');
?>
<div class="card">
  <h2>🚀 Quick setup wizard</h2>
  <p class="muted small">
    New to AiServe Inbox? Describe your business in one sentence — AI
    will generate a starter pack of 5 message templates + 5 keyword
    auto-replies tailored to your industry. Review the preview, then
    click <strong>Apply</strong> to save. Everything lands as
    <strong>drafts</strong> (templates) or <strong>inactive</strong>
    (auto-replies) so nothing goes live until you activate it.
  </p>

  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
  <?php if ($done): ?>
    <div class="alert alert-success">
      Added <strong><?= (int)$doneT ?></strong> template<?= $doneT === 1 ? '' : 's' ?>
      and <strong><?= (int)$doneR ?></strong> auto-reply rule<?= $doneR === 1 ? '' : 's' ?>.
      <br>Next steps:
      <ul style="margin: 8px 0 0 20px;">
        <li>Go to <a href="/admin/templates.php">Templates</a>, review each draft, edit if needed, then submit to Meta for approval.</li>
        <li>Go to <a href="/admin/auto_replies.php">Auto replies</a>, review each rule, upload any media (menu.pdf, price_list.pdf, etc.), then click <strong>Enable</strong> to turn it on.</li>
      </ul>
    </div>
  <?php endif; ?>

  <div class="alert alert-info">
    Current workspace: <strong><?= (int)$countTpl ?></strong> template<?= $countTpl === 1 ? '' : 's' ?>
    · <strong><?= (int)$countAR ?></strong> auto-reply rule<?= $countAR === 1 ? '' : 's' ?>.
    <?php if ($countTpl + $countAR === 0): ?>
      Empty workspace — perfect time to run the wizard.
    <?php else: ?>
      You already have some — the wizard will ADD to what's there, not replace.
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;">
    <label style="flex:1;min-width:260px;">
      <span style="display:block;font-size:13px;color:var(--color-text-muted);margin-bottom:4px;">Describe your business in one sentence</span>
      <input type="text" id="wiz-desc" required
             placeholder="e.g. Boat tour operator on Tioman Island offering snorkeling packages and beachfront chalet stays"
             style="width:100%;">
    </label>
    <label style="min-width:150px;">
      <span style="display:block;font-size:13px;color:var(--color-text-muted);margin-bottom:4px;">Main language</span>
      <select id="wiz-lang" style="width:100%;">
        <option value="en">English</option>
        <option value="ms">Bahasa Malaysia</option>
        <option value="zh">中文</option>
        <option value="ta">தமிழ்</option>
      </select>
    </label>
    <button type="button" class="btn btn-primary" id="wiz-generate-btn" style="flex-shrink:0;">✨ Generate pack</button>
  </div>
  <p id="wiz-status" class="small" style="margin-top:8px;"></p>
</div>

<div id="wiz-preview" style="display:none;">
  <div class="card">
    <div class="card-head">
      <h2>Preview</h2>
      <div style="display:flex;gap:8px;">
        <button type="button" class="btn" id="wiz-regen-btn">Re-generate</button>
        <form method="post" style="display:inline;" id="wiz-apply-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="apply">
          <input type="hidden" name="pack" id="wiz-pack-hidden">
          <button type="submit" class="btn btn-primary">✓ Apply everything</button>
        </form>
      </div>
    </div>
    <p class="muted small" id="wiz-summary"></p>

    <h3 style="font-size:14px;margin-top:16px;">Message templates (submit to Meta after saving)</h3>
    <table class="data-table">
      <thead><tr><th>Name</th><th>Category</th><th>Body</th></tr></thead>
      <tbody id="wiz-templates-tbody"></tbody>
    </table>

    <h3 style="font-size:14px;margin-top:24px;">Keyword auto-replies (activate in Admin → Auto replies)</h3>
    <table class="data-table">
      <thead><tr><th>Name</th><th>When customer types…</th><th>Auto reply</th><th>Media hint</th></tr></thead>
      <tbody id="wiz-autoreplies-tbody"></tbody>
    </table>
  </div>
</div>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const desc = document.getElementById('wiz-desc');
  const lang = document.getElementById('wiz-lang');
  const btn  = document.getElementById('wiz-generate-btn');
  const regenBtn = document.getElementById('wiz-regen-btn');
  const st   = document.getElementById('wiz-status');
  const preview = document.getElementById('wiz-preview');
  const summary = document.getElementById('wiz-summary');
  const tplTbody = document.getElementById('wiz-templates-tbody');
  const arTbody  = document.getElementById('wiz-autoreplies-tbody');
  const hidden = document.getElementById('wiz-pack-hidden');

  function setStatus(text, color) { st.textContent = text; st.style.color = color || ''; }
  function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  async function generate() {
    const value = desc.value.trim();
    if (!value) { setStatus('Type a one-sentence business description first.', '#b3261e'); desc.focus(); return; }
    btn.disabled = true; if (regenBtn) regenBtn.disabled = true;
    setStatus('Asking AI to generate your starter pack — takes ~15 seconds…', '');
    preview.style.display = 'none';
    try {
      const fd = new FormData();
      fd.append('description', value);
      fd.append('language', lang.value);
      fd.append('_csrf', csrf);
      const res = await fetch('/api/ai_setup_wizard.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) { setStatus('✗ ' + (data.error || 'Failed'), '#b3261e'); return; }
      renderPreview(data.suggestion);
      setStatus('✓ Pack generated. Review below and click Apply to save.', '#1f7a3f');
    } catch (e) {
      setStatus('✗ Network error: ' + e.message, '#b3261e');
    } finally {
      btn.disabled = false; if (regenBtn) regenBtn.disabled = false;
    }
  }

  function renderPreview(s) {
    summary.textContent = s.summary || '';
    tplTbody.innerHTML = (s.templates || []).map(t => `
      <tr>
        <td><code>${esc(t.template_name)}</code></td>
        <td>${esc(t.category)}</td>
        <td class="muted small" style="white-space:pre-wrap;">${esc(t.body_text)}</td>
      </tr>
    `).join('') || '<tr><td colspan="3" class="muted">No templates generated.</td></tr>';
    arTbody.innerHTML = (s.auto_replies || []).map(r => `
      <tr>
        <td><strong>${esc(r.name)}</strong><br><span class="muted small">priority ${esc(String(r.priority))}</span></td>
        <td><code>${esc(r.match_type)}</code> "${esc(r.match_value)}"</td>
        <td class="muted small">${esc(r.reply_text)}</td>
        <td class="muted small">${esc(r.media_hint || 'none')}</td>
      </tr>
    `).join('') || '<tr><td colspan="4" class="muted">No auto-replies generated.</td></tr>';
    hidden.value = JSON.stringify(s);
    preview.style.display = '';
    preview.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  btn.addEventListener('click', generate);
  if (regenBtn) regenBtn.addEventListener('click', generate);
  desc.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); generate(); } });
})();
</script>
<?php layout_end(); ?>
