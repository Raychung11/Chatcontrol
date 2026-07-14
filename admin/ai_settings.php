<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/ai_api.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';

if (is_post()) {
    csrf_check();
    $enabled      = !empty($_POST['ai_enabled']) ? 1 : 0;
    $autoSuggest  = !empty($_POST['ai_auto_suggest']) ? 1 : 0;
    $firstTouch   = !empty($_POST['ai_first_touch'])  ? 1 : 0;
    $alwaysOn     = !empty($_POST['ai_always_on'])    ? 1 : 0;
    $escalation   = trim((string)($_POST['ai_escalation_phrases'] ?? ''));
    $dailyCap     = max(0, (int)($_POST['ai_daily_cap'] ?? 200));
    $model        = trim((string)($_POST['ai_model'] ?? AI_DEFAULT_MODEL)) ?: AI_DEFAULT_MODEL;
    $systemPrompt = trim((string)($_POST['ai_system_prompt'] ?? ''));
    $apiKey       = trim((string)($_POST['ai_api_key'] ?? ''));

    // Business hours fields
    $bhEnabled    = !empty($_POST['business_hours_enabled']) ? 1 : 0;
    $bhTimezone   = trim((string)($_POST['business_hours_timezone'] ?? '')) ?: APP_TIMEZONE;
    $offHoursMsg  = trim((string)($_POST['off_hours_message'] ?? ''));

    // Build schedule JSON from per-day inputs
    $schedule = [];
    foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) {
        $on = !empty($_POST['bh_' . $d . '_open']);
        if ($on) {
            $start = (string)($_POST['bh_' . $d . '_start'] ?? '09:00');
            $end   = (string)($_POST['bh_' . $d . '_end']   ?? '18:00');
            // Validate HH:MM format; reset to defaults if malformed
            $start = preg_match('/^\d{2}:\d{2}$/', $start) ? $start : '09:00';
            $end   = preg_match('/^\d{2}:\d{2}$/', $end)   ? $end   : '18:00';
            $schedule[$d] = [$start, $end];
        } else {
            $schedule[$d] = null;
        }
    }
    $scheduleJson = json_encode($schedule, JSON_UNESCAPED_UNICODE);

    // Keep existing key if field left blank
    if ($apiKey === '') {
        $stmt = $db->prepare('SELECT ai_api_key FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $apiKey = (string)($stmt->fetchColumn() ?: '');
    }

    $db->prepare(
        'UPDATE companies
         SET ai_enabled = ?, ai_provider = "claude", ai_model = ?,
             ai_api_key = ?, ai_system_prompt = ?, ai_auto_suggest = ?,
             ai_first_touch = ?, ai_always_on = ?,
             ai_escalation_phrases = ?, ai_daily_cap = ?,
             business_hours_enabled = ?, business_hours_timezone = ?,
             business_hours_schedule = ?, off_hours_message = ?
         WHERE id = ?'
    )->execute([
        $enabled, $model, $apiKey ?: null, $systemPrompt ?: null, $autoSuggest,
        $firstTouch, $alwaysOn,
        $escalation ?: null, $dailyCap,
        $bhEnabled, $bhTimezone, $scheduleJson, $offHoursMsg ?: null,
        $companyId,
    ]);

    log_activity($companyId, (int)$current_user['id'], 'ai_settings_updated', 'company', $companyId);
    $msg = 'AI settings saved.';
}

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch() ?: [];

$defaultPrompt = ai_default_system_prompt($company);

// Live status readout so the operator can see EXACTLY which requirement
// is met vs missing - the previous "not enabled" toast lumped several
// distinct failure modes into one message.
$hasFlag   = !empty($company['ai_enabled']);
$hasKey    = !empty($company['ai_api_key']) || (bool)getenv('ANTHROPIC_API_KEY');
$readyToGo = $hasFlag && $hasKey;

layout_start($current_user, 'AI Settings', 'ai_settings');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <!-- Live status banner + one-click Anthropic probe -->
  <div class="alert <?= $readyToGo ? 'alert-info' : 'alert-error' ?>"
       style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
    <div>
      <strong>AI status:</strong>
      <?= $hasFlag  ? '✓' : '✗' ?> Enable checkbox ticked
      &nbsp; · &nbsp;
      <?= $hasKey   ? '✓' : '✗' ?> Anthropic API key present
      <?php if (!$readyToGo): ?>
        <br><small class="muted">
          Both boxes above must be ✓ for AI to fire. Save the form after ticking / pasting a key.
        </small>
      <?php else: ?>
        <br><small class="muted">Everything looks configured. Click "Test AI connection" to actually reach Anthropic.</small>
      <?php endif; ?>
    </div>
    <button type="button" class="btn" id="ai-test-btn" <?= $readyToGo ? '' : 'disabled' ?>>
      🧪 Test AI connection
    </button>
  </div>
  <p class="small" id="ai-test-status" style="margin: -6px 0 12px 0;"></p>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>

    <h2>AI reply suggestion</h2>
    <p class="muted small">
      Agents see a Claude-generated draft reply they can edit before sending.
      AI never auto-sends — every message still needs an agent (or your auto-reply bot) to send it.
    </p>

    <label class="check-row">
      <input type="checkbox" name="ai_enabled" value="1" <?= !empty($company['ai_enabled']) ? 'checked' : '' ?>>
      <span><strong>Enable AI suggestions</strong> for this workspace</span>
    </label>

    <label class="check-row">
      <input type="checkbox" name="ai_auto_suggest" value="1" <?= !empty($company['ai_auto_suggest']) ? 'checked' : '' ?>>
      <span><strong>Auto-suggest</strong> a draft as soon as a customer message arrives (still requires agent to click Send)</span>
    </label>

    <h3>First-touch auto-reply</h3>
    <p class="muted small">
      When a NEW customer messages your number, the AI sends them an instant reply
      (grounded in your Knowledge base) without waiting for a human. After the AI
      responds once, your agents take over the conversation as normal.
      <strong>If your provider has its own AI auto-reply enabled, turn theirs off first
      so customers don't get duplicate replies.</strong>
    </p>

    <label class="check-row">
      <input type="checkbox" name="ai_first_touch" value="1" <?= !empty($company['ai_first_touch']) ? 'checked' : '' ?>>
      <span><strong>Enable first-touch auto-reply</strong> — AI handles only the very first message of each new conversation</span>
    </label>

    <label>Escalation phrases <small class="muted">(comma-separated)</small>
      <textarea name="ai_escalation_phrases" rows="3"
                placeholder="<?= e(AI_DEFAULT_ESCALATION_PHRASES) ?>"><?= e((string)($company['ai_escalation_phrases'] ?? '')) ?></textarea>
      <small class="muted">
        If a customer's first message contains any of these phrases, auto-reply is
        skipped and a human handles it. Leave blank to use the built-in default
        (shown as placeholder above).
      </small>
    </label>

    <label>Daily cap <small class="muted">(per workspace, resets at midnight UTC)</small>
      <input type="number" name="ai_daily_cap" min="0" max="9999" required
             value="<?= (int)($company['ai_daily_cap'] ?? 200) ?>">
      <small class="muted">Hard ceiling on AI-sent messages per day. Set 0 to disable the cap.</small>
    </label>

    <?php
      $todayCount = (int)$db->query(
        'SELECT COUNT(*) FROM messages WHERE company_id = ' . $companyId
        . ' AND sender_type = "ai" AND created_at >= CURDATE()'
      )->fetchColumn();
      $cap = (int)($company['ai_daily_cap'] ?? 200);
    ?>
    <div class="alert alert-info">
      <strong>Today so far:</strong> <?= $todayCount ?> AI-sent message<?= $todayCount === 1 ? '' : 's' ?>
      <?php if ($cap > 0): ?>
        of <?= $cap ?> cap (<?= $cap > 0 ? round($todayCount / $cap * 100) : 0 ?>%).
      <?php endif; ?>
    </div>

    <h3>Always-on AI auto-reply</h3>
    <p class="muted small">
      AI replies to <strong>every</strong> customer message — not just the first — until an
      agent assigns themselves to the conversation. Useful for high-volume / off-hours
      coverage. Same guardrails apply (escalation phrases, daily cap, no reply if a human
      is already on it).
    </p>
    <label class="check-row">
      <input type="checkbox" name="ai_always_on" value="1" <?= !empty($company['ai_always_on']) ? 'checked' : '' ?>>
      <span><strong>Enable always-on AI</strong> — AI handles full conversations until an agent takes over</span>
    </label>

    <h3>Business hours mode</h3>
    <p class="muted small">
      When enabled, customers messaging outside business hours get an instant templated
      reply ("we're closed") and the conversation is marked Pending for tomorrow. Inside
      business hours, normal AI / agent flow runs. Off-hours mode takes precedence over
      always-on AI — at night the templated message goes out, not an AI reply.
    </p>

    <label class="check-row">
      <input type="checkbox" name="business_hours_enabled" value="1" <?= !empty($company['business_hours_enabled']) ? 'checked' : '' ?>>
      <span><strong>Enforce business hours</strong></span>
    </label>

    <label>Timezone
      <input type="text" name="business_hours_timezone"
             value="<?= e((string)($company['business_hours_timezone'] ?? APP_TIMEZONE)) ?>"
             placeholder="Asia/Kuala_Lumpur">
    </label>

    <?php
      $schedule = business_hours_schedule($company);
      $days = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
        'thu' => 'Thursday', 'fri' => 'Friday',
        'sat' => 'Saturday', 'sun' => 'Sunday',
      ];
    ?>
    <fieldset class="hours-grid">
      <legend>Schedule</legend>
      <?php foreach ($days as $key => $label):
        $row     = $schedule[$key] ?? null;
        $open    = is_array($row);
        $start   = $open ? $row[0] : '09:00';
        $end     = $open ? $row[1] : '18:00';
      ?>
        <div class="hours-row">
          <label>
            <input type="checkbox" name="bh_<?= e($key) ?>_open" value="1" <?= $open ? 'checked' : '' ?>>
            <strong><?= e($label) ?></strong>
          </label>
          <input type="time" name="bh_<?= e($key) ?>_start" value="<?= e($start) ?>">
          <span class="muted">to</span>
          <input type="time" name="bh_<?= e($key) ?>_end"   value="<?= e($end)   ?>">
        </div>
      <?php endforeach; ?>
    </fieldset>

    <label>Off-hours reply message <small class="muted">(leave blank to use the built-in default)</small>
      <textarea name="off_hours_message" rows="3"
                placeholder="<?= e(OFF_HOURS_DEFAULT_MESSAGE) ?>"><?= e((string)($company['off_hours_message'] ?? '')) ?></textarea>
    </label>

    <?php
      $bhStatus = !empty($company['business_hours_enabled'])
        ? (is_inside_business_hours($company) ? 'Open right now' : 'Closed right now')
        : 'Not enforced — always open';
    ?>
    <div class="alert alert-info">
      <strong>Current status:</strong> <?= e($bhStatus) ?>
    </div>

    <h3>Model</h3>
    <label>Model
      <select name="ai_model">
        <?php foreach ([
            'claude-haiku-4-5'        => 'Claude Haiku 4.5 — fastest, cheapest (recommended)',
            'claude-sonnet-4-6'       => 'Claude Sonnet 4.6 — balanced quality',
            'claude-opus-4-8'         => 'Claude Opus 4.8 — highest quality, slowest',
        ] as $id => $label):
          $current = $company['ai_model'] ?? AI_DEFAULT_MODEL;
        ?>
          <option value="<?= e($id) ?>" <?= $current === $id ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Anthropic API key — leave blank to keep existing
      <input type="password" name="ai_api_key" value="" autocomplete="new-password" placeholder="sk-ant-…">
      <?php if (!empty($company['ai_api_key'])): ?>
        <small class="muted">Currently set: <code><?= e(substr($company['ai_api_key'], 0, 6)) ?>…<?= e(substr($company['ai_api_key'], -4)) ?></code></small>
      <?php endif; ?>
      <small class="muted">Get a key at <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>. Falls back to the portal-wide <code>ANTHROPIC_API_KEY</code> env var if blank.</small>
    </label>

    <h3>System prompt</h3>
    <label>Instructions for the AI <small class="muted">(leave blank for the default below)</small>
      <textarea name="ai_system_prompt" rows="6" placeholder="<?= e($defaultPrompt) ?>"><?= e((string)($company['ai_system_prompt'] ?? '')) ?></textarea>
    </label>

    <details class="muted small">
      <summary>Default prompt (used when blank)</summary>
      <pre style="white-space:pre-wrap"><?= e($defaultPrompt) ?></pre>
    </details>

    <div>
      <button class="btn btn-primary" type="submit">Save AI settings</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Roadmap (not built yet)</h2>
  <ul class="bullet">
    <li><strong>FAQ assistant</strong> — answer using a knowledge base you upload</li>
    <li><strong>Auto summary</strong> — summarize long conversations for handover</li>
    <li><strong>Sentiment detection</strong> — flag angry / positive customers</li>
    <li><strong>Auto tagging</strong> — categorize conversations automatically</li>
  </ul>
</div>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const btn  = document.getElementById('ai-test-btn');
  const out  = document.getElementById('ai-test-status');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    out.textContent = 'Probing Anthropic…';
    out.style.color = '';
    try {
      const fd = new FormData();
      fd.append('_csrf', csrf);
      const res = await fetch('/api/ai_test.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (data.ok) {
        out.textContent = '✓ AI reachable. Model ' + (data.model || '?')
          + ' replied "' + (data.reply || '') + '" (tokens: '
          + (data.usage ? (data.usage.input_tokens + '+' + data.usage.output_tokens) : '?') + ').';
        out.style.color = '#1f7a3f';
      } else {
        out.textContent = '✗ ' + (data.error || ('HTTP ' + res.status));
        out.style.color = '#b3261e';
      }
    } catch (e) {
      out.textContent = '✗ Network error: ' + e.message;
      out.style.color = '#b3261e';
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
<style>
.check-row { display:flex; gap:8px; align-items:flex-start; padding:10px; border:1px solid var(--c-border); border-radius:8px; }
.check-row input { margin-top:3px; }
</style>
<?php layout_end(); ?>
