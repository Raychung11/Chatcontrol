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
    $escalation   = trim((string)($_POST['ai_escalation_phrases'] ?? ''));
    $dailyCap     = max(0, (int)($_POST['ai_daily_cap'] ?? 200));
    $model        = trim((string)($_POST['ai_model'] ?? AI_DEFAULT_MODEL)) ?: AI_DEFAULT_MODEL;
    $systemPrompt = trim((string)($_POST['ai_system_prompt'] ?? ''));
    $apiKey       = trim((string)($_POST['ai_api_key'] ?? ''));

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
             ai_first_touch = ?, ai_escalation_phrases = ?, ai_daily_cap = ?
         WHERE id = ?'
    )->execute([
        $enabled, $model, $apiKey ?: null, $systemPrompt ?: null, $autoSuggest,
        $firstTouch, $escalation ?: null, $dailyCap,
        $companyId,
    ]);

    log_activity($companyId, (int)$current_user['id'], 'ai_settings_updated', 'company', $companyId);
    $msg = 'AI settings saved.';
}

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch() ?: [];

$defaultPrompt = ai_default_system_prompt($company);

layout_start($current_user, 'AI Settings', 'ai_settings');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

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

<style>
.check-row { display:flex; gap:8px; align-items:flex-start; padding:10px; border:1px solid var(--c-border); border-radius:8px; }
.check-row input { margin-top:3px; }
</style>
<?php layout_end(); ?>
