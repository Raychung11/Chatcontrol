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
    } elseif ($action === 'add_url' && user_can_edit_settings($current_user)) {
        $url   = trim((string)($_POST['url']   ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $err = 'Enter a valid http(s) URL.';
        } else {
            $newId = kb_upsert_from_url($companyId, (int)$current_user['id'], $url, $title ?: null);
            if ($newId) {
                log_activity($companyId, (int)$current_user['id'], 'kb_url_added', 'knowledge_base', $newId, $url);
                $msg = 'Fetched and saved. Refresh anytime from the article row.';
            } else {
                $err = 'Could not fetch that URL — it may be JS-rendered, blocked, or unreachable from our server.';
            }
        }
    } elseif ($action === 'refresh_url' && $id > 0 && user_can_edit_settings($current_user)) {
        $u = $db->prepare('SELECT source_url FROM knowledge_base WHERE id = ? AND company_id = ?');
        $u->execute([$id, $companyId]);
        $srcUrl = (string)($u->fetchColumn() ?: '');
        if ($srcUrl === '') {
            $err = 'This article has no source URL to refresh.';
        } else {
            $newId = kb_upsert_from_url($companyId, (int)$current_user['id'], $srcUrl);
            $msg = $newId ? 'Refreshed from URL.' : 'Refresh failed — URL may be unreachable.';
            if (!$newId) $err = $msg;
        }
    } elseif ($action === 'qa_add' && user_can_edit_settings($current_user)) {
        $q   = mb_substr(trim((string)($_POST['question'] ?? '')), 0, 500);
        $a   = trim((string)($_POST['answer'] ?? ''));
        $tag = mb_substr(trim((string)($_POST['tag'] ?? '')), 0, 60);
        if ($q === '' || $a === '') {
            $err = 'Both question and answer are required.';
        } else {
            $db->prepare(
                'INSERT INTO kb_qa_pairs (company_id, question, answer, tag, created_by)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$companyId, $q, $a, $tag ?: null, (int)$current_user['id']]);
            log_activity($companyId, (int)$current_user['id'], 'kb_qa_added', 'kb_qa_pairs', (int)$db->lastInsertId());
            $msg = 'Q&A pair saved.';
        }
    } elseif ($action === 'qa_delete' && $id > 0 && user_can_edit_settings($current_user)) {
        $db->prepare('DELETE FROM kb_qa_pairs WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
        $msg = 'Q&A pair deleted.';
    } elseif ($action === 'qa_toggle' && $id > 0 && user_can_edit_settings($current_user)) {
        $db->prepare(
            'UPDATE kb_qa_pairs SET status = IF(status = "active","inactive","active")
             WHERE id = ? AND company_id = ?'
        )->execute([$id, $companyId]);
    } elseif ($action === 'add_image' && user_can_edit_settings($current_user)) {
        // 🖼 Image → Claude Vision → extract text + describe → save as
        // KB article. Great for a photo of a printed price list / menu
        // board / promo poster.
        $haveFile = !empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
        $title    = trim((string)($_POST['title'] ?? ''));
        if (!$haveFile) {
            $err = 'Upload an image (JPG or PNG) first.';
        } elseif (!in_array((string)$_FILES['image']['type'], ['image/jpeg','image/png','image/webp','image/gif'], true)) {
            $err = 'Only JPG / PNG / WebP / GIF supported.';
        } elseif ((int)$_FILES['image']['size'] > 5 * 1024 * 1024) {
            $err = 'Image over 5 MB — please compress.';
        } else {
            $bin  = (string)file_get_contents($_FILES['image']['tmp_name']);
            $mime = (string)$_FILES['image']['type'];
            $r    = kb_extract_from_image($companyId, $bin, $mime);
            if (!$r['ok']) {
                $err = 'Vision extract failed: ' . ($r['error'] ?? 'unknown');
            } else {
                $useTitle = $title !== '' ? $title : ($r['title'] ?: 'Extracted from image');
                $ins = $db->prepare(
                    'INSERT INTO knowledge_base
                        (company_id, title, source_filename, mime_type, content_text, content_chars, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, "active", ?)'
                );
                $ins->execute([$companyId, $useTitle, $_FILES['image']['name'], $mime,
                              $r['text'], mb_strlen($r['text']), (int)$current_user['id']]);
                $newId = (int)$db->lastInsertId();
                log_activity($companyId, (int)$current_user['id'], 'kb_image_added', 'knowledge_base', $newId, $useTitle);
                $msg = 'Image extracted (' . mb_strlen($r['text']) . ' characters).';
            }
        }
    } elseif ($action === 'add_csv' && user_can_edit_settings($current_user)) {
        // 📊 CSV → many Q&A pairs. First row is header; expects
        // "question,answer[,tag]" columns. Skips malformed rows.
        $haveFile = !empty($_FILES['csv']) && ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
        if (!$haveFile) {
            $err = 'Upload a .csv file first.';
        } else {
            $fh = @fopen((string)$_FILES['csv']['tmp_name'], 'r');
            if (!$fh) {
                $err = 'Could not open the CSV.';
            } else {
                $header = fgetcsv($fh) ?: [];
                $lower  = array_map(fn($h) => mb_strtolower(trim((string)$h)), $header);
                $qCol   = array_search('question', $lower, true);
                $aCol   = array_search('answer',   $lower, true);
                $tCol   = array_search('tag',      $lower, true);
                if ($qCol === false || $aCol === false) {
                    $err = 'CSV must have a header row with columns: question, answer (tag is optional).';
                } else {
                    $imported = 0;
                    $ins = $db->prepare(
                        'INSERT INTO kb_qa_pairs (company_id, question, answer, tag, created_by)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    while (($row = fgetcsv($fh)) !== false) {
                        $q = trim((string)($row[$qCol] ?? ''));
                        $a = trim((string)($row[$aCol] ?? ''));
                        $t = $tCol !== false ? mb_substr(trim((string)($row[$tCol] ?? '')), 0, 60) : '';
                        if ($q === '' || $a === '') continue;
                        $ins->execute([$companyId, mb_substr($q, 0, 500), $a, $t ?: null, (int)$current_user['id']]);
                        $imported++;
                    }
                    log_activity($companyId, (int)$current_user['id'], 'kb_csv_imported', 'kb_qa_pairs', 0, (string)$imported);
                    $msg = 'Imported ' . $imported . ' Q&A pair(s) from CSV.';
                }
                fclose($fh);
            }
        }
    } elseif ($action === 'save_model_by_feature' && user_can_edit_settings($current_user)) {
        // Per-feature model override — one dropdown per feature. Empty
        // value means "fall back to workspace default", stored as
        // missing key in the JSON.
        $tierMap = [
            'haiku'  => 'claude-haiku-4-5',
            'sonnet' => 'claude-sonnet-5',
            'opus'   => 'claude-opus-5',
        ];
        $features = ['first_touch', 'always_on', 'suggest_reply', 'fnb_cart_parse'];
        $out = [];
        foreach ($features as $f) {
            $t = (string)($_POST['tier_' . $f] ?? '');
            if (isset($tierMap[$t])) $out[$f] = $tierMap[$t];
        }
        $json = $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
        $db->prepare('UPDATE companies SET ai_model_by_feature = ? WHERE id = ?')
           ->execute([$json, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'ai_model_by_feature_updated', 'company', $companyId);
        $msg = 'Per-feature model preferences saved.';
    }
}

// Company state for all cards on this page.
$compStmt = $db->prepare(
    'SELECT learn_from_history_enabled, ai_persona, ai_model, ai_model_by_feature
     FROM companies WHERE id = ? LIMIT 1'
);
$compStmt->execute([$companyId]);
$compRow  = $compStmt->fetch() ?: [];
$learnOn  = (int)($compRow['learn_from_history_enabled'] ?? 0) === 1;
$curPersona = (string)($compRow['ai_persona'] ?? '');
$curModel   = (string)($compRow['ai_model']   ?? 'claude-haiku-4-5');
$idToTier = function (string $mid): string {
    return match (true) {
        str_contains($mid, 'opus')   => 'opus',
        str_contains($mid, 'sonnet') || str_contains($mid, 'fable') => 'sonnet',
        default                      => 'haiku',
    };
};
$curTier = $idToTier($curModel);

// Per-feature current tiers (fall back to workspace default when unset).
$byFeature = [];
$rawByFeat = trim((string)($compRow['ai_model_by_feature'] ?? ''));
if ($rawByFeat !== '') {
    $decoded = json_decode($rawByFeat, true);
    if (is_array($decoded)) $byFeature = $decoded;
}
$featureTier = [];
foreach (['first_touch', 'always_on', 'suggest_reply', 'fnb_cart_parse'] as $f) {
    $featureTier[$f] = isset($byFeature[$f]) ? $idToTier((string)$byFeature[$f]) : '';
}

$stmt = $db->prepare(
    'SELECT k.*, u.name AS author_name
     FROM knowledge_base k
     LEFT JOIN users u ON u.id = k.created_by
     WHERE k.company_id = ?
     ORDER BY k.id DESC'
);
$stmt->execute([$companyId]);
$articles = $stmt->fetchAll();

// Q&A pairs — silently skip if the phase-47 table isn't in yet.
$qaPairs = [];
try {
    $qs = $db->prepare(
        'SELECT * FROM kb_qa_pairs WHERE company_id = ? ORDER BY id DESC LIMIT 200'
    );
    $qs->execute([$companyId]);
    $qaPairs = $qs->fetchAll();
} catch (Throwable $e) { /* pre-phase47 = empty */ }

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
    <textarea id="ai-persona-field" name="ai_persona" rows="4" maxlength="1000"
              placeholder="e.g. You are Ali, the friendly server at Vicky's Nasi Lemak. Speak casual Malaysian English with the occasional Bahasa Melayu word (lah, boleh, jom). Warm, helpful, never pushy. Remember our house special is nasi lemak ayam berempah — mention it if the customer asks for recommendations."
              style="width:100%; padding:8px 10px; border:1px solid #d0d7de; border-radius:6px; font-size:13px;"><?= e($curPersona) ?></textarea>
    <div style="margin-top: 8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <button class="btn btn-primary btn-sm" type="submit">Save persona</button>
      <a class="btn btn-primary btn-sm" href="/admin/ai_persona_wizard.php" style="background:#a855f7; border-color:#a855f7;">🪄 Build with wizard</a>
      <a class="btn btn-sm" href="/admin/ai_persona_ab.php">🧪 A/B test two personas →</a>
      <span class="muted small"><?= mb_strlen($curPersona) ?> / 1000 chars</span>
    </div>
  </form>

  <!-- Preset gallery — click a preset to fill the textarea (doesn't save yet). -->
  <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid #eef2f7;">
    <div class="muted small" style="margin-bottom:6px;">
      <strong>💡 Presets</strong> — click one to load into the textarea above (then edit + save).
    </div>
    <div style="display:grid; gap:6px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
      <?php foreach (AI_PERSONA_PRESETS as $key => $preset): ?>
        <button type="button" onclick="wsApplyPreset(<?= htmlspecialchars(json_encode($preset['text']), ENT_QUOTES) ?>)"
                style="text-align:left; background:#faf5ff; border:1px solid #e9d5ff; border-radius:8px; padding:8px 10px; cursor:pointer; font-size:12.5px; line-height:1.3;">
          <div style="font-weight:600; color:#6b21a8; margin-bottom:2px;"><?= e($preset['label']) ?></div>
          <div style="color:#64748b;"><?= e(mb_substr($preset['text'], 0, 90)) ?>…</div>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
function wsApplyPreset(text) {
  const el = document.getElementById('ai-persona-field');
  if (!el) return;
  el.value = text;
  el.focus();
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>

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

  <!-- =====================================================
       PER-FEATURE MODEL OVERRIDE
       ===================================================== -->
  <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid #eef2f7;">
    <div style="font-size: 14px; font-weight: 600; margin-bottom: 4px;">🎛 Per-feature override</div>
    <p class="muted small" style="margin-bottom: 8px;">
      Route each AI feature to a different model. Cheap mechanical calls (F&amp;B cart parse)
      can run on Haiku while customer-facing chat stays on Sonnet. Leave "— default —"
      to fall back to the workspace-wide model above.
    </p>
    <form method="post" style="margin-top: 6px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_model_by_feature">
      <?php
        $featureLabels = [
          'first_touch'    => ['🤖 First-touch auto-reply',      'AI auto-sends the first reply on new conversations.'],
          'always_on'      => ['💬 Always-on AI chat',            'AI handles every message until an agent joins.'],
          'suggest_reply'  => ['✍️ Agent-composer suggest',      'Drafts the AI proposes to agents in the inbox composer.'],
          'fnb_cart_parse' => ['🍜 F&amp;B AI cart parse',        'Structured extraction — cheapest tier usually fine.'],
        ];
      ?>
      <div style="display: grid; gap: 8px; grid-template-columns: 1fr 1fr;">
        <?php foreach ($featureLabels as $key => [$label, $desc]):
          $cur = $featureTier[$key] ?? '';
        ?>
          <label style="display:block; padding:10px 12px; border:1px solid #e3e8ee; border-radius:8px; background:#fafbfc;">
            <div style="font-weight:600; font-size:13px;"><?= $label ?></div>
            <div class="muted small" style="margin: 3px 0 6px;"><?= $desc ?></div>
            <select name="tier_<?= $key ?>" style="width:100%; padding:5px 8px; font-size:12.5px; border:1px solid #d0d7de; border-radius:5px;">
              <option value=""       <?= $cur === ''       ? 'selected' : '' ?>>— use workspace default (<?= e(ucfirst($curTier)) ?>) —</option>
              <option value="haiku"  <?= $cur === 'haiku'  ? 'selected' : '' ?>>Haiku 4.5 · cheapest</option>
              <option value="sonnet" <?= $cur === 'sonnet' ? 'selected' : '' ?>>Sonnet 5 · balanced</option>
              <option value="opus"   <?= $cur === 'opus'   ? 'selected' : '' ?>>Opus 5 · best quality</option>
            </select>
          </label>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-primary btn-sm" style="margin-top: 10px;">Save per-feature overrides</button>
    </form>
  </div>
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

<!-- =====================================================
     🌐 Add from URL — one-click ingest of a webpage or PDF URL
     ===================================================== -->
<?php if (user_can_edit_settings($current_user)): ?>
<div class="card" style="border-left:3px solid #0ea5e9;">
  <h3>🌐 Add from URL <small class="muted">(webpage or PDF)</small></h3>
  <p class="muted small">
    Paste a URL — your business website, a blog post, a shipping-policy PDF —
    and we fetch, extract clean text, and save it as a knowledge base article.
    Refresh anytime from the article row if the source page changes.
  </p>
  <form method="post" class="form-grid" style="grid-template-columns: 1fr 1fr 120px; gap: 8px; align-items: end;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_url">
    <label>URL
      <input type="url" name="url" required placeholder="https://your-business.com/faq">
    </label>
    <label>Title <small class="muted">(optional — auto-detected)</small>
      <input type="text" name="title" placeholder="e.g. Shipping FAQ">
    </label>
    <div>
      <button class="btn btn-primary" type="submit">🌐 Fetch + save</button>
    </div>
  </form>
</div>

<!-- =====================================================
     🖼 Add from image — Claude Vision OCR
     ===================================================== -->
<?php if (user_can_edit_settings($current_user)): ?>
<div class="card" style="border-left:3px solid #ec4899;">
  <h3>🖼 Add from image <small class="muted">(printed menu / poster / price list photo)</small></h3>
  <p class="muted small">
    Snap or upload a photo of your printed menu, promo poster, or price list. Claude Vision reads
    every price / item / time and saves it as a KB article. JPG / PNG / WebP up to 5 MB.
  </p>
  <form method="post" enctype="multipart/form-data" class="form-grid" style="grid-template-columns: 1fr 1fr 140px; gap: 8px; align-items: end;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_image">
    <label>Image
      <input type="file" name="image" required accept="image/jpeg,image/png,image/webp,image/gif">
    </label>
    <label>Title <small class="muted">(optional — AI names it)</small>
      <input type="text" name="title" placeholder="e.g. Wall menu, June 2026">
    </label>
    <div>
      <button class="btn btn-primary" type="submit">🖼 Extract + save</button>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- =====================================================
     📊 Import Q&A from CSV
     ===================================================== -->
<?php if (user_can_edit_settings($current_user)): ?>
<div class="card" style="border-left:3px solid #64748b;">
  <h3>📊 Import Q&amp;A from CSV <small class="muted">(bulk load)</small></h3>
  <p class="muted small">
    CSV file with columns <code>question,answer,tag</code> (tag optional). First row is the header.
    Each valid row becomes one Q&amp;A pair.
  </p>
  <form method="post" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:end;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_csv">
    <input type="file" name="csv" required accept=".csv,text/csv">
    <button class="btn btn-primary" type="submit">📊 Import CSV</button>
  </form>
</div>
<?php endif; ?>

<!-- =====================================================
     🎯 Q&A pairs — high-signal short-form entries
     ===================================================== -->
<div class="card" style="border-left:3px solid #f59e0b;">
  <h3>🎯 Q&amp;A pairs <small class="muted">(high-signal FAQ entries)</small></h3>
  <p class="muted small">
    Short question + answer pairs the AI treats as gold. Use these for the
    top ~30 questions your team gets every day (delivery cost, hours, address,
    return policy). AI prompts inject Q&amp;A pairs BEFORE longer articles so
    the exact answer is right on top.
  </p>
  <form method="post" class="form-grid" style="grid-template-columns: 1fr 2fr 120px 120px; gap: 8px; align-items: end;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="qa_add">
    <label>Question
      <input type="text" name="question" required maxlength="500"
             placeholder="e.g. Berapa harga delivery ke Sabah?">
    </label>
    <label>Answer
      <input type="text" name="answer" required
             placeholder="e.g. RM 15 for East Malaysia. Free above RM 200.">
    </label>
    <label>Tag <small class="muted">(optional)</small>
      <input type="text" name="tag" maxlength="60" placeholder="shipping">
    </label>
    <div>
      <button class="btn btn-primary" type="submit">+ Add</button>
    </div>
  </form>

  <?php if ($qaPairs): ?>
    <table class="data-table" style="margin-top: 14px; font-size: 13px;">
      <thead>
        <tr><th>Question</th><th>Answer</th><th>Tag</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($qaPairs as $qa): ?>
          <tr <?= $qa['status'] !== 'active' ? 'style="opacity:0.55;"' : '' ?>>
            <td><strong><?= e((string)$qa['question']) ?></strong></td>
            <td class="muted small"><?= e(mb_substr((string)$qa['answer'], 0, 200)) ?><?= mb_strlen((string)$qa['answer']) > 200 ? '…' : '' ?></td>
            <td><?php if ($qa['tag']): ?><span style="background:#eef2ff; color:#3730a3; padding:2px 8px; border-radius:999px; font-size:11px;"><?= e((string)$qa['tag']) ?></span><?php endif; ?></td>
            <td><?= status_badge($qa['status']) ?></td>
            <td class="actions">
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="qa_toggle">
                <input type="hidden" name="id" value="<?= (int)$qa['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $qa['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this Q&A?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="qa_delete">
                <input type="hidden" name="id" value="<?= (int)$qa['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted small" style="margin-top:10px;">No Q&A pairs yet — add your first above.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
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
              <?php if ($isAuto): ?>
                auto-distilled
              <?php elseif (!empty($a['source_url'])): ?>
                <span title="<?= e((string)$a['source_url']) ?>">🌐 <?= e(mb_substr((string)parse_url((string)$a['source_url'], PHP_URL_HOST), 0, 24)) ?></span>
                <?php if (!empty($a['source_last_fetched_at'])): ?>
                  <br><small>fetched <?= e(fmt_dt($a['source_last_fetched_at'])) ?></small>
                <?php endif; ?>
              <?php else: ?>
                <?= e($a['source_filename'] ?? 'pasted') ?>
              <?php endif; ?>
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
              <?php if (!empty($a['source_url']) && user_can_edit_settings($current_user)): ?>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Re-fetch this article from its source URL? Any local edits will be overwritten.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="refresh_url">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-sm" type="submit" title="Re-fetch from source URL">↻ Refresh</button>
                </form>
              <?php endif; ?>
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
