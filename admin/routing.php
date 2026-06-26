<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$err = '';
$msg = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $ruleId = (int)   ($_POST['rule_id'] ?? 0);

    if ($action === 'create' || $action === 'update') {
        $matchType  = (string)($_POST['match_type']  ?? 'contains');
        $matchValue = trim((string)($_POST['match_value'] ?? ''));
        $deptId     = (int)   ($_POST['department_id'] ?? 0);
        $assignTo   = $_POST['assigned_user_id'] ?? '';
        $assignTo   = ($assignTo === '' || $assignTo === '0') ? null : (int)$assignTo;
        $priority   = max(1, (int)($_POST['priority'] ?? 100));
        $status     = (string)($_POST['status'] ?? 'active');

        if (!in_array($matchType, ['contains','starts_with','equals','regex'], true)) {
            $err = 'Invalid match type.';
        } elseif ($matchValue === '' || $deptId <= 0) {
            $err = 'Match value and department are required.';
        } elseif (!in_array($status, ['active','inactive'], true)) {
            $err = 'Invalid status.';
        } else {
            // Verify dept + user belong to this company
            $check = $db->prepare('SELECT id FROM departments WHERE id = ? AND company_id = ?');
            $check->execute([$deptId, $companyId]);
            if (!$check->fetchColumn()) {
                $err = 'Department invalid.';
            }
            if (!$err && $assignTo) {
                $check = $db->prepare('SELECT id FROM users WHERE id = ? AND company_id = ? AND status = "active"');
                $check->execute([$assignTo, $companyId]);
                if (!$check->fetchColumn()) $assignTo = null;
            }

            if (!$err && $matchType === 'regex') {
                if (@preg_match('/' . str_replace('/', '\\/', $matchValue) . '/iu', '') === false) {
                    $err = 'Invalid regex pattern.';
                }
            }

            if (!$err) {
                if ($action === 'create') {
                    $db->prepare(
                        'INSERT INTO routing_rules
                            (company_id, priority, match_type, match_value, department_id, assigned_user_id, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$companyId, $priority, $matchType, $matchValue, $deptId, $assignTo, $status]);
                    log_activity($companyId, (int)$current_user['id'], 'routing_rule_created',
                        'rule', (int)$db->lastInsertId(), $matchValue);
                    $msg = 'Rule created.';
                } else {
                    $db->prepare(
                        'UPDATE routing_rules
                         SET priority=?, match_type=?, match_value=?, department_id=?, assigned_user_id=?, status=?
                         WHERE id=? AND company_id=?'
                    )->execute([$priority, $matchType, $matchValue, $deptId, $assignTo, $status, $ruleId, $companyId]);
                    log_activity($companyId, (int)$current_user['id'], 'routing_rule_updated', 'rule', $ruleId);
                    $msg = 'Rule updated.';
                }
            }
        }
    } elseif ($action === 'delete' && $ruleId > 0) {
        $db->prepare('DELETE FROM routing_rules WHERE id = ? AND company_id = ?')
           ->execute([$ruleId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'routing_rule_deleted', 'rule', $ruleId);
        $msg = 'Rule deleted.';
    } elseif ($action === 'toggle' && $ruleId > 0) {
        $db->prepare(
            'UPDATE routing_rules SET status = IF(status = "active","inactive","active")
             WHERE id = ? AND company_id = ?'
        )->execute([$ruleId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'routing_rule_toggled', 'rule', $ruleId);
    }
}

$rules = $db->prepare(
    'SELECT r.*, d.name AS department_name, u.name AS user_name
     FROM routing_rules r
     LEFT JOIN departments d ON d.id = r.department_id
     LEFT JOIN users       u ON u.id = r.assigned_user_id
     WHERE r.company_id = ? ORDER BY r.priority ASC, r.id ASC'
);
$rules->execute([$companyId]);
$rules = $rules->fetchAll();

$dstmt = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$dstmt->execute([$companyId]);
$departments = $dstmt->fetchAll();

$ustmt = $db->prepare(
    'SELECT id, name, role FROM users WHERE company_id = ? AND status = "active" ORDER BY name'
);
$ustmt->execute([$companyId]);
$users = $ustmt->fetchAll();

layout_start($current_user, 'Routing rules', 'routing');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <p class="muted small">
    Rules run in <strong>priority order</strong> (lower number = higher priority) on the first
    inbound text from a new conversation. The first matching rule sets the conversation's
    department (and optional default agent). If no rule matches, the company's default
    department in <a href="/admin/settings.php">Settings</a> is used.
  </p>

  <h2>Add rule</h2>

  <div class="ai-rule-builder" style="background:#f4f9f6;border:1px dashed #c6e0d0;border-radius:8px;padding:14px 16px;margin-bottom:18px;">
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;">
      <strong>Describe in plain English</strong>
      <span class="muted small">AI fills the form below for you to review.</span>
    </div>
    <p class="muted small" style="margin:6px 0 8px 0;">
      e.g. <em>"send refund and return questions to Customer Service"</em>
      · <em>"route orders starting with BUY- to Sales, assign to Sarah"</em>
    </p>
    <div style="display:flex;gap:8px;">
      <input type="text" id="ai-rule-desc" placeholder="What should this rule do?" style="flex:1;">
      <button type="button" class="btn btn-primary" id="ai-rule-suggest-btn">Suggest rule</button>
    </div>
    <div id="ai-rule-status" class="small" style="margin-top:8px;"></div>
  </div>

  <form method="post" class="form-grid" id="rule-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Priority
      <input type="number" name="priority" id="f-priority" value="100" min="1" max="9999">
    </label>
    <label>Match type
      <select name="match_type" id="f-match-type">
        <option value="contains">contains</option>
        <option value="starts_with">starts_with</option>
        <option value="equals">equals</option>
        <option value="regex">regex</option>
      </select>
    </label>
    <label>Match value
      <input type="text" name="match_value" id="f-match-value" required placeholder="e.g. invoice, refund, BUY-…">
    </label>
    <label>Send to department
      <select name="department_id" id="f-dept" required>
        <option value="">— select department —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Default assigned agent (optional)
      <select name="assigned_user_id" id="f-user">
        <option value="0">— none —</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> (<?= e(role_label($u['role'])) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn btn-primary" type="submit">Add rule</button>
  </form>
</div>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const desc   = document.getElementById('ai-rule-desc');
  const btn    = document.getElementById('ai-rule-suggest-btn');
  const status = document.getElementById('ai-rule-status');

  function setStatus(text, color) {
    status.textContent = text;
    status.style.color = color || '';
  }

  async function suggest() {
    const value = desc.value.trim();
    if (!value) {
      setStatus('Type what you want this rule to do first.', '#b3261e');
      desc.focus();
      return;
    }
    btn.disabled = true;
    setStatus('Asking AI…', '');
    try {
      const fd = new FormData();
      fd.append('description', value);
      fd.append('_csrf', csrf);
      const res = await fetch('/api/ai_routing_suggest.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) {
        setStatus('✗ ' + (data.error || 'Failed'), '#b3261e');
        return;
      }
      const s = data.suggestion;
      document.getElementById('f-priority').value     = s.priority || 100;
      document.getElementById('f-match-type').value   = s.match_type || 'contains';
      document.getElementById('f-match-value').value  = s.match_value || '';
      document.getElementById('f-dept').value         = s.department_id || '';
      document.getElementById('f-user').value         = s.assigned_user_id || '0';

      const parts = [];
      parts.push('✓ Filled below.');
      if (s.explanation) parts.push(s.explanation);
      parts.push('Review and click Add rule to save.');
      setStatus(parts.join(' '), '#1f7a3f');
      document.getElementById('f-match-value').focus();
    } catch (e) {
      setStatus('✗ Network error: ' + e.message, '#b3261e');
    } finally {
      btn.disabled = false;
    }
  }

  btn.addEventListener('click', suggest);
  desc.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); suggest(); }
  });
})();
</script>

<div class="card">
  <h2>Existing rules</h2>
  <table class="data-table">
    <thead>
      <tr><th>Priority</th><th>Match</th><th>Department</th><th>Assign to</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (!$rules): ?>
        <tr><td colspan="6" class="muted">No rules yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rules as $r): ?>
        <tr>
          <td><?= (int)$r['priority'] ?></td>
          <td><code><?= e($r['match_type']) ?></code> <?= e($r['match_value']) ?></td>
          <td><?= e($r['department_name'] ?? '—') ?></td>
          <td><?= e($r['user_name'] ?? '—') ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="actions">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" type="submit">
                <?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?>
              </button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this rule?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
