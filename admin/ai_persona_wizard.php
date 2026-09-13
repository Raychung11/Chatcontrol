<?php
/**
 * AI persona builder wizard.
 *
 * A 5-question form the operator fills in — Claude turns the answers
 * into a compact persona (3-6 sentences, ~600 chars) which the operator
 * can edit before saving to companies.ai_persona.
 *
 * Two-step flow:
 *   GET  /admin/ai_persona_wizard.php               → show empty form
 *   POST action=generate                            → run Claude → show generated persona
 *   POST action=save   + persona=<text>             → write to companies.ai_persona → redirect
 *
 * Both AI calls (generate + optional regenerate) are logged in
 * ai_usage_events under feature = 'persona_wizard'.
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/ai_billing.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$compStmt = $db->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$compStmt->execute([$companyId]);
$company  = $compStmt->fetch() ?: [];
$aiOn     = !empty($company['ai_enabled']) && ai_api_key($company) !== '';

$msg = '';
$err = '';

// Form state — sticky across generate → save so the operator can tweak
// the wizard answers and regenerate without retyping.
$answers = [
    'business_type'   => (string)($_POST['business_type']   ?? ''),
    'tone'            => (string)($_POST['tone']            ?? ''),
    'language'        => (string)($_POST['language']        ?? ''),
    'signature_thing' => (string)($_POST['signature_thing'] ?? ''),
    'avoid'           => (string)($_POST['avoid']           ?? ''),
];
$generated = (string)($_POST['persona'] ?? '');

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if (!$aiOn) {
        $err = 'AI isn\'t enabled or the Anthropic key is missing. Configure in Admin → AI Settings first.';
    } elseif ($action === 'generate' || $action === 'regenerate') {
        $r = ai_generate_persona($company, $answers);
        if ($r['ok']) {
            $generated = (string)$r['persona'];
            // Log the generation cost against this workspace.
            ai_log_usage($companyId, null, 'persona_wizard',
                $r['usage'] ?? null, $r['model'] ?? null);
            $msg = 'Persona generated — edit if you want, then save.';
        } else {
            $err = 'Could not generate persona: ' . ($r['error'] ?? 'unknown error');
        }
    } elseif ($action === 'save') {
        $persona = mb_substr(trim($generated), 0, 1000);
        if ($persona === '') {
            $err = 'Persona is empty — generate one or paste text first.';
        } else {
            $db->prepare('UPDATE companies SET ai_persona = ? WHERE id = ?')
               ->execute([$persona, $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'ai_persona_updated_wizard',
                'company', $companyId);
            redirect('/admin/knowledge.php?flash=' . rawurlencode('✅ Persona saved — takes effect on the next AI reply.'));
        }
    }
}

layout_start($current_user, 'AI persona wizard', 'ai_persona_wizard');
?>
<style>
.wz-card {
    background: #fff; border: 1px solid #e3e8ee; border-radius: 12px;
    padding: 18px 22px; margin-bottom: 16px;
}
.wz-card h2 { margin: 0 0 6px; font-size: 16px; }
.wz-card .lead { color: #64748b; font-size: 13px; margin-bottom: 14px; }

.wz-q { margin-bottom: 16px; }
.wz-q label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #0f172a; }
.wz-q .hint { color: #64748b; font-size: 12px; font-weight: 400; margin-bottom: 6px; }
.wz-q input, .wz-q textarea, .wz-q select {
    width: 100%; padding: 8px 10px; border: 1px solid #d0d7de; border-radius: 6px;
    font-size: 13px;
}
.wz-chip-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
.wz-chip {
    display: inline-block; padding: 4px 10px; border-radius: 999px;
    border: 1px solid #d0d7de; background: #f6f9fb; font-size: 12px;
    cursor: pointer; color: #334155;
}
.wz-chip:hover { border-color: #0072B2; color: #0072B2; }

.wz-result {
    background: linear-gradient(180deg, #f7f3ff 0%, #efe9ff 100%);
    border: 1px solid #d6c8f5; border-radius: 10px; padding: 14px 16px;
}
.wz-result h3 { margin: 0 0 8px; color: #5a2eaa; font-size: 14px; }
.wz-result textarea {
    width: 100%; padding: 10px 12px; border: 1px solid #d6c8f5;
    background: #fff; border-radius: 6px; font-size: 13px;
    line-height: 1.5;
}
.wz-result .meta { color: #7c60c4; font-size: 11px; margin-top: 6px; }

.wz-actions { display: flex; gap: 8px; align-items: center; margin-top: 12px; flex-wrap: wrap; }
</style>

<div class="wz-card">
    <h2>🪄 AI persona wizard</h2>
    <p class="lead">
        Answer 5 quick questions. The AI generates a persona for your workspace — a short character
        description that flavours every reply. Edit it, save it, done.
    </p>

    <?php if (!$aiOn): ?>
        <div class="alert alert-error">
            AI isn't enabled or an API key is missing. Configure in
            <a href="/admin/ai_settings.php">Admin → AI Settings</a> first.
        </div>
    <?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate">

        <div class="wz-q">
            <label>1. What kind of business is this?</label>
            <div class="hint">Type your own or click a chip.</div>
            <input type="text" name="business_type" required
                   value="<?= e($answers['business_type']) ?>"
                   placeholder="e.g. Halal nasi lemak restaurant, 2 branches in KL">
            <div class="wz-chip-row">
                <?php foreach ([
                    'F&B — casual restaurant', 'F&B — cafe / drinks', 'Retail — clothing',
                    'Retail — beauty products', 'Salon / hair / nails', 'Clinic — dental',
                    'Clinic — GP', 'Fitness studio / gym', 'Home services (cleaning, repair)',
                    'B2B — SaaS / agency',
                ] as $chip): ?>
                    <span class="wz-chip" onclick="wzFill('business_type', <?= htmlspecialchars(json_encode($chip), ENT_QUOTES) ?>)"><?= e($chip) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="wz-q">
            <label>2. How should the AI feel?</label>
            <select name="tone" required>
                <option value="">— pick one —</option>
                <?php foreach ([
                    'Warm and casual — like a friendly neighbour'    => 'Warm & casual',
                    'Friendly professional — think polished shop staff' => 'Friendly professional',
                    'Crisp and efficient — no fluff, just answers'    => 'Crisp & efficient',
                    'Formal and polished — banking / legal register'  => 'Formal & polished',
                    'Playful and upbeat — light, one emoji max'       => 'Playful & upbeat',
                ] as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $answers['tone'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wz-q">
            <label>3. Language usage</label>
            <select name="language" required>
                <option value="">— pick one —</option>
                <?php foreach ([
                    'English only — international / corporate audience'   => 'English only',
                    'Mostly English with occasional BM (lah, boleh, jom)' => 'English + a bit of BM',
                    'Bilingual — switch fluidly between English and BM based on how the customer wrote' => 'Bilingual',
                    'Mostly Bahasa Malaysia — English only when the customer writes English' => 'Mostly BM',
                    'Chinese-friendly — reply in the language the customer used (EN / BM / ZH)' => 'Multilingual EN/BM/ZH',
                ] as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $answers['language'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wz-q">
            <label>4. One signature thing to always mention when relevant?</label>
            <div class="hint">Optional. A house special, unique service, or catchphrase.</div>
            <input type="text" name="signature_thing" maxlength="200"
                   value="<?= e($answers['signature_thing']) ?>"
                   placeholder="e.g. Our house nasi lemak ayam berempah — recommend it when customers ask what's good">
        </div>

        <div class="wz-q">
            <label>5. Anything the AI must never do or say?</label>
            <div class="hint">Optional. Legal, safety, or brand guardrails.</div>
            <input type="text" name="avoid" maxlength="200"
                   value="<?= e($answers['avoid']) ?>"
                   placeholder="e.g. Never quote a price without confirming with a human; never promise refunds">
        </div>

        <div class="wz-actions">
            <button class="btn btn-primary" type="submit"
                    <?= $aiOn ? '' : 'disabled' ?>
                    onclick="this.form.action.value='<?= $generated ? 'regenerate' : 'generate' ?>'">
                🪄 <?= $generated ? 'Regenerate' : 'Generate persona' ?>
            </button>
            <a class="btn btn-sm" href="/admin/knowledge.php">← Back to knowledge base</a>
        </div>

        <?php if ($generated): ?>
            <div class="wz-result" style="margin-top: 18px;">
                <h3>✨ Generated persona (editable)</h3>
                <textarea name="persona" rows="6"><?= e($generated) ?></textarea>
                <div class="meta"><?= mb_strlen($generated) ?> characters</div>
                <div class="wz-actions">
                    <button type="submit" class="btn btn-primary" onclick="this.form.action.value='save'">
                        ✅ Save as workspace persona
                    </button>
                    <button type="submit" class="btn" onclick="this.form.action.value='regenerate'">
                        🔄 Regenerate
                    </button>
                    <a class="btn btn-sm" href="/admin/ai_persona_ab.php?persona_a=<?= urlencode($generated) ?>">
                        🧪 A/B test this
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </form>
</div>
<script>
function wzFill(name, value) {
    const el = document.querySelector('[name="' + name + '"]');
    if (el) { el.value = value; el.focus(); }
}
</script>
<?php layout_end(); ?>
