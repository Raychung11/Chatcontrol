<?php
/**
 * A/B compare two AI personas side-by-side.
 *
 * Operator pastes a sample customer message, picks Persona A and
 * Persona B (with optional model overrides), hits Run — page fires two
 * Claude calls in parallel and renders both replies in a compare view.
 *
 * Uses the workspace's active knowledge base + API key. Both calls are
 * logged to ai_usage_events under feature = 'persona_ab_test' so the
 * spend shows up in billing.
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/ai_billing.php';
require_once __DIR__ . '/../inc/knowledge_base.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

// Load company row so we can build the same prompt shape ai_suggest_reply uses.
$compStmt = $db->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$compStmt->execute([$companyId]);
$company = $compStmt->fetch() ?: [];

$aiOn = !empty($company['ai_enabled']) && ai_api_key($company) !== '';

$results  = null;
$formErr  = '';
$personaA = (string)($_POST['persona_a'] ?? (string)($company['ai_persona'] ?? ''));
$personaB = (string)($_POST['persona_b'] ?? '');
$tierA    = (string)($_POST['tier_a']    ?? '');
$tierB    = (string)($_POST['tier_b']    ?? '');
$testMsg  = (string)($_POST['test_msg']  ?? '');

if (is_post()) {
    csrf_check();
    if (!$aiOn) {
        $formErr = 'AI is not enabled or API key missing — configure in Admin → AI Settings first.';
    } elseif (trim($testMsg) === '') {
        $formErr = 'Paste a sample customer message.';
    } elseif (trim($personaA) === '' && trim($personaB) === '') {
        $formErr = 'Give at least one persona to test.';
    } else {
        $tierMap = ['haiku' => 'claude-haiku-4-5', 'sonnet' => 'claude-sonnet-5', 'opus' => 'claude-opus-5'];
        $modelA = $tierMap[$tierA] ?? ai_model_for_feature($company, 'suggest_reply');
        $modelB = $tierMap[$tierB] ?? ai_model_for_feature($company, 'suggest_reply');

        // Build ephemeral company arrays with each persona and run
        // ai_suggest_reply against a synthetic single-message history.
        $conv     = ['id' => 0, 'contact_id' => 0, 'wa_id' => '0', 'display_name' => 'Test customer'];
        $messages = [[
            'direction'    => 'incoming',
            'sender_type'  => 'customer',
            'message_text' => trim($testMsg),
            'created_at'   => date('Y-m-d H:i:s'),
        ]];

        $runOne = function (string $persona, string $model) use ($company, $conv, $messages, $companyId): array {
            $cCopy = $company;
            $cCopy['ai_persona'] = trim($persona);
            $cCopy['ai_model']   = $model;
            $t0 = microtime(true);
            $r = ai_suggest_reply($cCopy, $conv, $messages);
            $r['duration_ms'] = (int)((microtime(true) - $t0) * 1000);
            if ($r['ok']) {
                // Log usage against the workspace so A/B testing shows up in billing.
                ai_log_usage($companyId, null, 'persona_ab_test',
                    $r['usage'] ?? null, $r['model'] ?? $model);
            }
            return $r;
        };

        $results = [
            'a' => trim($personaA) !== '' ? $runOne($personaA, $modelA) : null,
            'b' => trim($personaB) !== '' ? $runOne($personaB, $modelB) : null,
        ];
    }
}

// Pull the last N customer messages so the operator can grab a real one
// as the test message rather than inventing something.
$sampleMsgs = [];
try {
    $s = $db->prepare(
        "SELECT DISTINCT m.message_text
         FROM messages m
         WHERE m.company_id = ? AND m.direction = 'incoming'
           AND m.message_text IS NOT NULL AND m.message_text <> ''
           AND CHAR_LENGTH(m.message_text) BETWEEN 10 AND 200
         ORDER BY m.id DESC LIMIT 8"
    );
    $s->execute([$companyId]);
    $sampleMsgs = array_column($s->fetchAll(), 'message_text');
} catch (Throwable $e) { /* ok */ }

layout_start($current_user, 'A/B test AI personas', 'ai_persona_ab');
?>
<style>
.abx-grid { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
@media (max-width: 900px) { .abx-grid { grid-template-columns: 1fr; } }
.abx-card { background: #fff; border: 1px solid #e3e8ee; border-radius: 10px; padding: 14px; }
.abx-card h4 { margin: 0 0 8px; font-size: 13px; }
.abx-card textarea, .abx-card input, .abx-card select {
  width: 100%; padding: 8px 10px; font-size: 13px;
  border: 1px solid #d0d7de; border-radius: 6px;
}
.abx-a-head { color: #0072B2; }
.abx-b-head { color: #D55E00; }
.abx-reply {
  background: #fafbfc; border-left: 3px solid; padding: 10px 12px; border-radius: 6px;
  font-size: 13px; white-space: pre-wrap; margin-top: 10px; min-height: 60px;
}
.abx-reply.a { border-color: #0072B2; }
.abx-reply.b { border-color: #D55E00; }
.abx-meta   { color: #64748b; font-size: 11px; margin-top: 8px; display:flex; gap: 12px; flex-wrap: wrap; }
.abx-sample {
  display: inline-block; background: #f1f5f9; color: #334155; padding: 4px 8px;
  border-radius: 999px; margin: 3px 3px 0 0; cursor: pointer; font-size: 11.5px;
  border: 1px solid transparent;
}
.abx-sample:hover { background: #e0f2fe; border-color: #7dd3fc; }
</style>

<div class="card">
  <h2>🧪 A/B test two AI personas</h2>
  <p class="muted small">
    Paste a customer message and two different personas. The same message runs against
    both — you see the replies side by side and pick a winner. Both calls hit your live
    Anthropic key and knowledge base, and both are logged in
    <a href="/admin/ai_usage.php">AI usage</a> under <code>persona_ab_test</code>.
  </p>

  <?php if (!$aiOn): ?>
    <div class="alert alert-error">
      AI isn't enabled or an API key is missing. Configure in
      <a href="/admin/ai_settings.php">Admin → AI Settings</a> first.
    </div>
  <?php endif; ?>
  <?php if ($formErr): ?><div class="alert alert-error"><?= e($formErr) ?></div><?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>

    <label style="display:block; margin-bottom: 10px;">
      <strong>Sample customer message</strong>
      <textarea name="test_msg" rows="2" placeholder="Paste the kind of message you want to test replies on…"
                style="width:100%; padding:8px 10px; border:1px solid #d0d7de; border-radius:6px; font-size:13px;"><?= e($testMsg) ?></textarea>
    </label>

    <?php if ($sampleMsgs): ?>
      <div class="muted small" style="margin-bottom: 12px;">
        Or click a real recent customer message:
        <?php foreach ($sampleMsgs as $sm):
          $short = mb_strlen($sm) > 80 ? mb_substr($sm, 0, 78) . '…' : $sm;
        ?>
          <span class="abx-sample" onclick="document.querySelector('[name=test_msg]').value = <?= htmlspecialchars(json_encode($sm), ENT_QUOTES) ?>;"><?= e($short) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="abx-grid">
      <div class="abx-card">
        <h4 class="abx-a-head">🅰 Persona A</h4>
        <textarea name="persona_a" rows="5" placeholder="Persona description…"><?= e($personaA) ?></textarea>
        <label class="muted small" style="display:block; margin-top:8px;">Model tier</label>
        <select name="tier_a">
          <option value=""       <?= $tierA === ''       ? 'selected' : '' ?>>— workspace default —</option>
          <option value="haiku"  <?= $tierA === 'haiku'  ? 'selected' : '' ?>>Haiku 4.5</option>
          <option value="sonnet" <?= $tierA === 'sonnet' ? 'selected' : '' ?>>Sonnet 5</option>
          <option value="opus"   <?= $tierA === 'opus'   ? 'selected' : '' ?>>Opus 5</option>
        </select>
      </div>
      <div class="abx-card">
        <h4 class="abx-b-head">🅱 Persona B</h4>
        <textarea name="persona_b" rows="5" placeholder="Second persona description…"><?= e($personaB) ?></textarea>
        <label class="muted small" style="display:block; margin-top:8px;">Model tier</label>
        <select name="tier_b">
          <option value=""       <?= $tierB === ''       ? 'selected' : '' ?>>— workspace default —</option>
          <option value="haiku"  <?= $tierB === 'haiku'  ? 'selected' : '' ?>>Haiku 4.5</option>
          <option value="sonnet" <?= $tierB === 'sonnet' ? 'selected' : '' ?>>Sonnet 5</option>
          <option value="opus"   <?= $tierB === 'opus'   ? 'selected' : '' ?>>Opus 5</option>
        </select>
      </div>
    </div>

    <button class="btn btn-primary" type="submit" style="margin-top: 14px;">🚀 Run comparison</button>
  </form>
</div>

<?php if ($results): ?>
<div class="card">
  <h3>Results</h3>
  <div class="abx-grid">
    <div>
      <h4 class="abx-a-head">🅰 Persona A</h4>
      <?php if (!$results['a']): ?>
        <div class="muted small">(no persona A provided)</div>
      <?php elseif (!$results['a']['ok']): ?>
        <div class="alert alert-error">
          <?= e((string)($results['a']['error'] ?? 'unknown error')) ?>
        </div>
      <?php else: ?>
        <div class="abx-reply a"><?= e((string)$results['a']['suggestion']) ?></div>
        <div class="abx-meta">
          <span><strong>Model:</strong> <?= e((string)($results['a']['model'] ?? '?')) ?></span>
          <span><strong>Time:</strong> <?= (int)$results['a']['duration_ms'] ?> ms</span>
          <span><strong>Tokens:</strong>
            <?= (int)($results['a']['usage']['input_tokens'] ?? 0) ?> in ·
            <?= (int)($results['a']['usage']['output_tokens'] ?? 0) ?> out
          </span>
        </div>
      <?php endif; ?>
    </div>
    <div>
      <h4 class="abx-b-head">🅱 Persona B</h4>
      <?php if (!$results['b']): ?>
        <div class="muted small">(no persona B provided)</div>
      <?php elseif (!$results['b']['ok']): ?>
        <div class="alert alert-error">
          <?= e((string)($results['b']['error'] ?? 'unknown error')) ?>
        </div>
      <?php else: ?>
        <div class="abx-reply b"><?= e((string)$results['b']['suggestion']) ?></div>
        <div class="abx-meta">
          <span><strong>Model:</strong> <?= e((string)($results['b']['model'] ?? '?')) ?></span>
          <span><strong>Time:</strong> <?= (int)$results['b']['duration_ms'] ?> ms</span>
          <span><strong>Tokens:</strong>
            <?= (int)($results['b']['usage']['input_tokens'] ?? 0) ?> in ·
            <?= (int)($results['b']['usage']['output_tokens'] ?? 0) ?> out
          </span>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <p class="muted small" style="margin-top:14px;">
    Prefer one? Copy its persona into
    <a href="/admin/knowledge.php">Knowledge base → AI personality</a> and save.
    Both calls above were logged in AI usage under <code>persona_ab_test</code>.
  </p>
</div>
<?php endif; ?>

<?php layout_end(); ?>
