<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/knowledge_base.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'create') {
        if (!user_can_edit_settings($current_user)) {
            $err = 'Only Super Admins can add knowledge base articles.';
        } else {
            $title = trim((string)($_POST['title'] ?? ''));
            $pasted = trim((string)($_POST['content_text'] ?? ''));
            $haveFile = !empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

            if ($title === '') {
                $err = 'Title is required.';
            } elseif ($pasted === '' && !$haveFile) {
                $err = 'Upload a file or paste the article text.';
            } else {
                $text = $pasted;
                $sourceName = null;
                $mime = null;

                if ($haveFile) {
                    $tmp = (string)$_FILES['file']['tmp_name'];
                    $sourceName = (string)$_FILES['file']['name'];
                    $mime = '';
                    if (function_exists('finfo_open')) {
                        $fi = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = (string)finfo_file($fi, $tmp);
                        finfo_close($fi);
                    }
                    $mime = $mime ?: (string)($_FILES['file']['type'] ?? '');
                    $res = kb_extract_text($tmp, $mime, $sourceName);
                    if (!$res['ok']) {
                        $err = 'Could not read file: ' . ($res['error'] ?? 'unknown');
                    } else {
                        $text = trim($res['text'] . ($pasted !== '' ? "\n\n" . $pasted : ''));
                    }
                }

                if ($err === '' && $text !== '') {
                    $stmt = $db->prepare(
                        'INSERT INTO knowledge_base
                            (company_id, title, source_filename, mime_type, content_text, content_chars, status, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, "active", ?)'
                    );
                    $stmt->execute([$companyId, $title, $sourceName, $mime, $text, mb_strlen($text), (int)$current_user['id']]);
                    $newId = (int)$db->lastInsertId();
                    log_activity($companyId, (int)$current_user['id'], 'kb_added', 'knowledge_base', $newId, $title);
                    $msg = 'Article saved (' . mb_strlen($text) . ' characters).';
                }
            }
        }
    } elseif ($action === 'toggle' && $id > 0) {
        $db->prepare(
            'UPDATE knowledge_base SET status = IF(status = "active","inactive","active")
             WHERE id = ? AND company_id = ?'
        )->execute([$id, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'kb_toggled', 'knowledge_base', $id);
    } elseif ($action === 'delete' && $id > 0 && user_can_edit_settings($current_user)) {
        // Don't allow deleting an auto-generated article - the cron would
        // just recreate it next Sunday, and the operator's intent is
        // probably "turn learning off", not "delete this row for 6 days".
        $chk = $db->prepare('SELECT auto_generated FROM knowledge_base WHERE id = ? AND company_id = ?');
        $chk->execute([$id, $companyId]);
        $auto = (int)($chk->fetchColumn() ?: 0);
        if ($auto === 1) {
            $err = 'Auto-generated articles can\'t be deleted here. Turn off "Learn from history" below to stop it from coming back.';
        } else {
            $db->prepare('DELETE FROM knowledge_base WHERE id = ? AND company_id = ?')
               ->execute([$id, $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'kb_deleted', 'knowledge_base', $id);
            $msg = 'Article deleted.';
        }
    } elseif ($action === 'set_learn' && user_can_edit_settings($current_user)) {
        $on = !empty($_POST['enabled']) ? 1 : 0;
        $db->prepare('UPDATE companies SET learn_from_history_enabled = ? WHERE id = ?')
           ->execute([$on, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'learn_from_history_toggled',
            'company', $companyId, $on ? 'enabled' : 'disabled');
        $msg = $on
            ? 'Learn from history enabled. First distillation runs in the next weekly cron.'
            : 'Learn from history disabled. The auto-generated article stays but is no longer refreshed.';
    } elseif ($action === 'save_persona' && user_can_edit_settings($current_user)) {
        // Short persona / voice — up to 1000 chars. Injected into every
        // AI system prompt (first-touch, always-on, suggest, F&B parse).
        $persona = mb_substr(trim((string)($_POST['ai_persona'] ?? '')), 0, 1000);
        $db->prepare('UPDATE companies SET ai_persona = ? WHERE id = ?')
           ->execute([$persona ?: null, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'ai_persona_updated', 'company', $companyId);
        $msg = 'AI persona saved. It takes effect on the next AI reply.';
    } elseif ($action === 'save_model' && user_can_edit_settings($current_user)) {
        // Model tier picker. Anthropic's family + specific model IDs
        // change over time — validate against the tier keys and map
        // to canonical ids so operators pick "quality" not "SKU".
        $tier = (string)($_POST['model_tier'] ?? '');
        $map = [
            'haiku'  => 'claude-haiku-4-5',    // fastest + cheapest
            'sonnet' => 'claude-sonnet-5',     // balanced default
            'opus'   => 'claude-opus-5',       // highest quality
        ];
        if (!isset($map[$tier])) {
            $err = 'Pick Haiku / Sonnet / Opus.';
        } else {
            $db->prepare('UPDATE companies SET ai_model = ? WHERE id = ?')
               ->execute([$map[$tier], $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'ai_model_updated', 'company', $companyId, $tier);
            $msg = 'AI model set to ' . ucfirst($tier) . '. It takes effect on the next AI reply.';
        }
    }
}

// Company state for the toggle panel + AI persona + model tier.
$compStmt = $db->prepare(
    'SELECT learn_from_history_enabled, ai_persona, ai_model
     FROM companies WHERE id = ? LIMIT 1'
);
$compStmt->execute([$companyId]);
$compRow  = $compStmt->fetch() ?: [];
$learnOn  = (int)($compRow['learn_from_history_enabled'] ?? 0) === 1;
$curPersona = (string)($compRow['ai_persona'] ?? '');
$curModel   = (string)($compRow['ai_model']   ?? 'claude-haiku-4-5');
// Reverse-map current model id → tier for the radio group.
$curTier = match (true) {
    str_contains($curModel, 'opus')                        => 'opus',
    str_contains($curModel, 'sonnet') || str_contains($curModel, 'fable') => 'sonnet',
    default                                                 => 'haiku',
};

$stmt = $db->prepare(
    'SELECT k.*, u.name AS author_name
     FROM knowledge_base k
     LEFT JOIN users u ON u.id = k.created_by
     WHERE k.company_id = ?
     ORDER BY k.id DESC'
);
$stmt->execute([$companyId]);
$articles = $stmt->fetchAll();

$totalActiveChars = 0;
foreach ($articles as $a) {
    if ($a['status'] === 'active') $totalActiveChars += (int)$a['content_chars'];
}

layout_start($current_user, 'AI Knowledge base', 'knowledge');
?>
<div class="card">
  <h2>AI Knowledge base</h2>
  <p class="muted small">
    Upload PDFs, Word docs, or paste plain text. Active articles are injected into
    the AI's system prompt so it can answer customer questions using your company's
    facts (FAQs, pricing, opening hours, policies). Prompt caching makes repeat
    requests cheap.
  </p>
  <div class="alert alert-info">
    <strong>Cap:</strong> up to <?= number_format(KB_MAX_TOTAL_CHARS) ?> characters
    total in one AI request. Currently active: <strong><?= number_format($totalActiveChars) ?></strong> chars
    across <?= count(array_filter($articles, fn($a) => $a['status'] === 'active')) ?> article(s).
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
</div>

<?php if (user_can_edit_settings($current_user)): ?>
<!-- =====================================================
     LEARN FROM HISTORY - auto-KB from replied conversations
     ===================================================== -->
<div class="card" style="border-left:3px solid #25D366;">
  <h3>🧠 Learn from history <small class="muted">(auto-KB)</small></h3>
  <p class="muted small">
    When enabled, a weekly cron reads the last 45 days of replied
    conversations, sends them to Claude, and distills them into a
    team-response guide saved as an <strong>[Auto] Learned team
    responses</strong> knowledge article. The AI drafts you see on
    every new message start using this guide automatically — so the
    bot answers in the team's own voice, with the team's own numbers
    and phrasing, without you writing anything.
  </p>
  <p class="muted small" style="margin-top:6px;">
    <strong>Privacy note:</strong> customer conversation text (last 45
    days) is sent to Claude as part of the distillation prompt. Only
    workspaces on Anthropic's default no-training tier should enable
    this (your workspace API key controls this — check your Anthropic
    org settings).
  </p>

  <form method="post" style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_learn">
    <?php if ($learnOn): ?>
      <strong style="color:#1f7a3f;">✓ Learning from history is ON.</strong>
      <input type="hidden" name="enabled" value="0">
      <button type="submit" class="btn">Turn off</button>
    <?php else: ?>
      <strong>Learning from history is OFF.</strong>
      <input type="hidden" name="enabled" value="1">
      <button type="submit" class="btn btn-primary">✨ Turn on auto-learning</button>
    <?php endif; ?>
    <span class="muted small">Cron runs every Sunday at 3 AM.</span>
  </form>
</div>
<?php endif; ?>

<?php if (user_can_edit_settings($current_user)): ?>
<!-- =====================================================
     AI PERSONALITY - short voice / character description
     ===================================================== -->
<style>
.ai-tier-row { display: grid; gap: 8px; grid-template-columns: repeat(3, 1fr); margin-top: 6px; }
@media (max-width: 700px) { .ai-tier-row { grid-template-columns: 1fr; } }
.ai-tier-pill {
  border: 1px solid #e3e8ee; border-radius: 10px; padding: 12px 14px;
  cursor: pointer; background: #fff; display: block;
  transition: all 0.15s ease;
}
.ai-tier-pill:hover { border-color: #0072B2; }
.ai-tier-pill input[type=radio] { position: absolute; opacity: 0; }
.ai-tier-pill.selected { border-color: #0072B2; background: #eff6ff; box-shadow: 0 0 0 2px #dbeafe; }
.ai-tier-pill .n     { font-weight: 700; color: #0f172a; }
.ai-tier-pill .price { font-size: 12px; color: #64748b; margin-top: 4px; }
.ai-tier-pill .use   { font-size: 12px; color: #475569; margin-top: 6px; }
.ai-tier-pill .tag {
  display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 10px;
  font-weight: 600; margin-left: 6px;
}
.ai-tier-pill .tag-cheap { background: #dcfce7; color: #14532d; }
.ai-tier-pill .tag-best  { background: #fef3c7; color: #78350f; }
.ai-tier-pill .tag-fast  { background: #dbeafe; color: #1e3a8a; }
</style>

<div class="card" style="border-left:3px solid #a855f7;">
  <h3>🎭 AI personality</h3>
  <p class="muted small">
    A short description of the voice, tone, and character the AI should adopt in every reply.
    Kept separate from the (advanced) full system prompt override in AI Settings — most
    workspaces only need this. It flavours <strong>every</strong> AI call —
    first-touch replies, always-on chat, agent-composer drafts, and F&amp;B cart parsing.
  </p>
  <form method="post" style="margin-top:10px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_persona">
    <label class="muted small">Persona (max 1000 characters)</label>
    <textarea name="ai_persona" rows="4" maxlength="1000"
              placeholder="e.g. You are Ali, the friendly server at Vicky's Nasi Lemak. Speak casual Malaysian English with the occasional Bahasa Melayu word (lah, boleh, jom). Warm, helpful, never pushy. Remember our house special is nasi lemak ayam berempah — mention it if the customer asks for recommendations."
              style="width:100%; padding:8px 10px; border:1px solid #d0d7de; border-radius:6px; font-size:13px;"><?= e($curPersona) ?></textarea>
    <div style="margin-top: 8px; display:flex; gap:8px; align-items:center;">
      <button class="btn btn-primary btn-sm" type="submit">Save persona</button>
      <span class="muted small"><?= mb_strlen($curPersona) ?> / 1000 chars</span>
    </div>
  </form>
</div>

<!-- =====================================================
     AI MODEL TIER - speed vs quality vs cost
     ===================================================== -->
<div class="card" style="border-left:3px solid #0072B2;">
  <h3>⚡ AI model tier</h3>
  <p class="muted small">
    Pick which Claude model handles every AI reply. Cheaper models are faster and
    sharper for simple FAQ replies; the top tier is worth it if answers need real
    reasoning (multi-step questions, structured extraction, sensitive replies).
    Billing scales with model — check
    <a href="/admin/ai_usage.php">AI usage</a> for current spend.
  </p>
  <form method="post" style="margin-top:10px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_model">
    <div class="ai-tier-row">
      <?php
        $tiers = [
          'haiku'  => ['name' => 'Haiku 4.5',  'price' => '$1 / $5 per 1M tok',   'use' => 'Simple FAQs, greetings, cart parse', 'tag' => 'cheap', 'tagLabel' => 'CHEAPEST'],
          'sonnet' => ['name' => 'Sonnet 5',   'price' => '$3 / $15 per 1M tok',  'use' => 'Most conversations · recommended',   'tag' => 'best', 'tagLabel' => 'BALANCED'],
          'opus'   => ['name' => 'Opus 5',     'price' => '$15 / $75 per 1M tok', 'use' => 'Complex reasoning, high-stakes replies', 'tag' => 'fast', 'tagLabel' => 'BEST QUALITY'],
        ];
      ?>
      <?php foreach ($tiers as $key => $t):
        $selected = $curTier === $key;
      ?>
        <label class="ai-tier-pill <?= $selected ? 'selected' : '' ?>">
          <input type="radio" name="model_tier" value="<?= $key ?>" <?= $selected ? 'checked' : '' ?>
                 onchange="this.form.submit()">
          <div class="n">
            <?= e($t['name']) ?>
            <span class="tag tag-<?= $t['tag'] ?>"><?= e($t['tagLabel']) ?></span>
          </div>
          <div class="price">💰 <?= e($t['price']) ?> input / output</div>
          <div class="use"><?= e($t['use']) ?></div>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="muted small" style="margin-top:8px;">
      Currently selected: <strong><?= e(ucfirst($curTier)) ?></strong>
      (<code><?= e($curModel) ?></code>).
      Click any tier to switch — auto-saves.
    </div>
  </form>
</div>

<div class="card">
  <h3>Add an article</h3>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Title
      <input type="text" name="title" required maxlength="200" placeholder="e.g. Pricing &amp; payment FAQ">
    </label>
    <label>Upload file <small class="muted">(TXT, MD, PDF, DOCX — max 5 MB)</small>
      <input type="file" name="file" accept=".txt,.md,.csv,.pdf,.docx,text/plain,text/markdown,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
    </label>
    <label>…or paste the text directly <small class="muted">(adds to the file content if both supplied)</small>
      <textarea name="content_text" rows="6" placeholder="Paste FAQs, policies, opening hours…"></textarea>
    </label>
    <div>
      <button class="btn btn-primary" type="submit">Add article</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Articles</h3>
  <?php if (!$articles): ?>
    <p class="muted">No articles yet. Add one above and the AI will use it on the next reply suggestion.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr><th>Title</th><th>Source</th><th>Chars</th><th>Status</th><th>Added by</th><th>Created</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($articles as $a):
          $isAuto = (int)($a['auto_generated'] ?? 0) === 1;
        ?>
          <tr <?= $isAuto ? 'style="background:#f6fff9;"' : '' ?>>
            <td>
              <?= e($a['title']) ?>
              <?php if ($isAuto): ?>
                <br><span class="badge badge-open" style="font-size:10px;">🧠 Auto</span>
                <?php if (!empty($a['last_auto_updated_at'])): ?>
                  <small class="muted"> · updated <?= e(fmt_dt($a['last_auto_updated_at'])) ?></small>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="muted small">
              <?= $isAuto ? 'auto-distilled' : e($a['source_filename'] ?? 'pasted') ?>
            </td>
            <td><?= number_format((int)$a['content_chars']) ?></td>
            <td><?= status_badge($a['status']) ?></td>
            <td class="muted small"><?= $isAuto ? '<em>Learn cron</em>' : e($a['author_name'] ?? '—') ?></td>
            <td class="muted small"><?= e(fmt_dt($a['created_at'])) ?></td>
            <td class="actions">
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $a['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
              </form>
              <?php if (user_can_edit_settings($current_user) && !$isAuto): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this article?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              <?php elseif ($isAuto): ?>
                <span class="muted small" title="Auto-generated - turn off learning to remove">🔒 Auto</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php layout_end(); ?>
