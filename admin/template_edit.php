<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$id   = (int)($_GET['id'] ?? 0);
$tpl  = null;
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM message_templates WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$id, $companyId]);
    $tpl = $stmt->fetch();
    if (!$tpl) { http_response_code(404); exit('Template not found.'); }
}

$err = '';
if (is_post()) {
    csrf_check();
    $name     = trim((string)($_POST['template_name'] ?? ''));
    $category = trim((string)($_POST['category']      ?? ''));
    $language = trim((string)($_POST['language']      ?? 'en'));
    $body     = trim((string)($_POST['body_text']     ?? ''));
    $status   = (string)($_POST['status'] ?? 'draft');
    $vars     = trim((string)($_POST['variables_json'] ?? ''));

    if (!preg_match('/^[a-z0-9_]{3,120}$/', $name)) {
        $err = 'Template name must use lowercase letters, numbers, and underscores only.';
    } elseif ($body === '') {
        $err = 'Body text is required.';
    } elseif (!in_array($status, ['draft','approved','rejected','paused'], true)) {
        $err = 'Invalid status.';
    } else {
        try {
            if ($tpl) {
                $stmt = $db->prepare(
                    'UPDATE message_templates SET template_name=?, category=?, language=?, body_text=?, variables_json=?, status=?
                     WHERE id=? AND company_id=?'
                );
                $stmt->execute([$name, $category ?: null, $language ?: 'en', $body, $vars ?: null, $status, $id, $companyId]);
                log_activity($companyId, (int)$current_user['id'], 'template_updated', 'template', $id);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO message_templates (company_id, template_name, category, language, body_text, variables_json, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$companyId, $name, $category ?: null, $language ?: 'en', $body, $vars ?: null, $status]);
                log_activity($companyId, (int)$current_user['id'], 'template_created', 'template', (int)$db->lastInsertId());
            }
            redirect('/admin/templates.php');
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                $err = 'A template with that name and language already exists.';
            } else {
                error_log($e->getMessage());
                $err = 'Could not save template.';
            }
        }
    }
}

layout_start($current_user, $tpl ? 'Edit template' : 'New template', 'templates');
?>
<div class="card">
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <?php if (!$tpl): ?>
    <div class="ai-tpl-builder" style="background:#f4f9f6;border:1px dashed #c6e0d0;border-radius:8px;padding:14px 16px;margin-bottom:18px;">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
        <strong>Describe in plain English</strong>
        <span class="muted small">AI drafts the template + variables for you to review.</span>
      </div>
      <p class="muted small" style="margin:6px 0 8px 0;">
        e.g. <em>"remind a customer their invoice is overdue"</em> ·
        <em>"greet a new customer with our shop hours"</em> ·
        <em>"confirm a spa booking with date and time"</em>
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <input type="text" id="ai-tpl-desc" placeholder="What is this template for?" style="flex:1;min-width:220px;">
        <select id="ai-tpl-lang" style="max-width:120px;">
          <option value="en">English</option>
          <option value="ms">Bahasa Malaysia</option>
          <option value="zh">中文</option>
          <option value="ta">தமிழ்</option>
        </select>
        <button type="button" class="btn btn-primary" id="ai-tpl-suggest-btn">Suggest</button>
      </div>
      <div id="ai-tpl-status" class="small" style="margin-top:8px;"></div>
    </div>
  <?php endif; ?>

  <form method="post" class="form-grid" id="tpl-form">
    <?= csrf_field() ?>
    <label>Template name (lowercase, underscores)
      <input type="text" name="template_name" id="f-name" required pattern="[a-z0-9_]{3,120}"
             value="<?= e($tpl['template_name'] ?? ($_POST['template_name'] ?? '')) ?>">
    </label>
    <label>Category
      <input type="text" name="category" id="f-cat" placeholder="MARKETING / UTILITY / AUTHENTICATION"
             value="<?= e($tpl['category'] ?? ($_POST['category'] ?? '')) ?>">
    </label>
    <label>Language
      <input type="text" name="language" id="f-lang" value="<?= e($tpl['language'] ?? ($_POST['language'] ?? 'en')) ?>">
    </label>
    <label>Status
      <select name="status">
        <?php foreach (['draft','approved','rejected','paused'] as $s): ?>
          <option value="<?= $s ?>" <?= ($tpl['status'] ?? 'draft') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Body
      <textarea name="body_text" id="f-body" rows="5" required><?= e($tpl['body_text'] ?? ($_POST['body_text'] ?? '')) ?></textarea>
      <small class="muted">Use <code>{{1}}</code>, <code>{{2}}</code>, ... for variables. Don't start or end the body with a variable — Meta rejects those.</small>
    </label>
    <label>Variables (optional JSON, e.g. {"1":"customer_name"})
      <textarea name="variables_json" id="f-vars" rows="3"><?= e($tpl['variables_json'] ?? ($_POST['variables_json'] ?? '')) ?></textarea>
    </label>
    <div>
      <button class="btn btn-primary" type="submit"><?= $tpl ? 'Save' : 'Create' ?></button>
      <a class="btn" href="/admin/templates.php">Cancel</a>
    </div>
  </form>
</div>

<?php if (!$tpl): ?>
<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const desc = document.getElementById('ai-tpl-desc');
  const lang = document.getElementById('ai-tpl-lang');
  const btn  = document.getElementById('ai-tpl-suggest-btn');
  const st   = document.getElementById('ai-tpl-status');

  function setStatus(text, color) { st.textContent = text; st.style.color = color || ''; }

  async function suggest() {
    const value = desc.value.trim();
    if (!value) { setStatus('Type what the template is for first.', '#b3261e'); desc.focus(); return; }
    btn.disabled = true;
    setStatus('Asking AI…', '');
    try {
      const fd = new FormData();
      fd.append('description', value);
      fd.append('language', lang.value);
      fd.append('_csrf', csrf);
      const res = await fetch('/api/ai_template_suggest.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) { setStatus('✗ ' + (data.error || 'Failed'), '#b3261e'); return; }
      const s = data.suggestion;
      document.getElementById('f-name').value = s.template_name || '';
      document.getElementById('f-cat').value  = s.category      || '';
      document.getElementById('f-lang').value = s.language      || 'en';
      document.getElementById('f-body').value = s.body_text     || '';
      document.getElementById('f-vars').value = s.variables_json || '';
      const parts = ['✓ Filled below.'];
      if (s.explanation) parts.push(s.explanation);
      parts.push('Review, tweak, then click Create to save as a draft. Then submit to Meta for approval.');
      setStatus(parts.join(' '), '#1f7a3f');
      document.getElementById('f-body').focus();
    } catch (e) {
      setStatus('✗ Network error: ' + e.message, '#b3261e');
    } finally {
      btn.disabled = false;
    }
  }

  btn.addEventListener('click', suggest);
  desc.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); suggest(); } });
})();
</script>
<?php endif; ?>
<?php layout_end(); ?>
