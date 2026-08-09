<?php
/**
 * /admin/kb_coverage.php — customer questions AI struggled with.
 *
 * Rows from ai_coverage_gaps populated by cron/detect_coverage_gaps.php.
 * Per row: open the conversation, dismiss, or one-click "Draft a KB
 * article" — sends the customer question to Claude to generate a
 * starter article the operator can review/edit/save.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/knowledge_base.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/ai_billing.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';
$drafted = null;

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['gap_id']   ?? 0);

    if ($action === 'dismiss' && $id > 0) {
        $db->prepare('UPDATE ai_coverage_gaps SET dismissed_at = NOW() WHERE id = ? AND company_id = ?')
           ->execute([$id, $companyId]);
        $msg = 'Dismissed.';
    } elseif ($action === 'draft' && $id > 0 && user_can_edit_settings($current_user)) {
        $g = $db->prepare('SELECT * FROM ai_coverage_gaps WHERE id = ? AND company_id = ? LIMIT 1');
        $g->execute([$id, $companyId]);
        $gap = $g->fetch();
        if ($gap) {
            $drafted = kb_coverage_draft_article($companyId, (string)$gap['customer_message']);
            if ($drafted && !empty($drafted['title'])) {
                $msg = 'Draft ready — review and save below.';
            } else {
                $err = 'Could not draft — try again in a moment.';
            }
        }
    } elseif ($action === 'save_draft' && user_can_edit_settings($current_user)) {
        $title = trim((string)($_POST['title']        ?? ''));
        $body  = trim((string)($_POST['content_text'] ?? ''));
        $gapId = (int)($_POST['gap_id_save']          ?? 0);
        if ($title === '' || $body === '') {
            $err = 'Title and body are both required.';
        } else {
            $ins = $db->prepare(
                'INSERT INTO knowledge_base
                    (company_id, title, content_text, content_chars, status, created_by)
                 VALUES (?, ?, ?, ?, "active", ?)'
            );
            $ins->execute([$companyId, $title, $body, mb_strlen($body), (int)$current_user['id']]);
            $newId = (int)$db->lastInsertId();
            if ($gapId > 0) {
                $db->prepare('UPDATE ai_coverage_gaps SET resolved_kb_id = ? WHERE id = ? AND company_id = ?')
                   ->execute([$newId, $gapId, $companyId]);
            }
            log_activity($companyId, (int)$current_user['id'], 'kb_from_gap', 'knowledge_base', $newId, $title);
            $msg = 'Article saved — the AI will use it on the next reply.';
        }
    }
}

// Load open gaps.
$fReason = (string)($_GET['reason'] ?? 'all');
$where   = ['company_id = ?', 'resolved_kb_id IS NULL', 'dismissed_at IS NULL'];
$params  = [$companyId];
if (in_array($fReason, ['no_answer','fast_escalation','low_confidence','manual'], true)) {
    $where[]  = 'reason = ?';
    $params[] = $fReason;
}
$sql = 'SELECT * FROM ai_coverage_gaps WHERE ' . implode(' AND ', $where)
     . ' ORDER BY id DESC LIMIT 100';
$s = $db->prepare($sql);
$s->execute($params);
$rows = $s->fetchAll();

// KPIs
$kpi = ['open' => 0, 'resolved' => 0, 'dismissed' => 0];
try {
    $k = $db->prepare(
        "SELECT
            SUM(CASE WHEN resolved_kb_id IS NULL AND dismissed_at IS NULL THEN 1 ELSE 0 END) AS open_n,
            SUM(CASE WHEN resolved_kb_id IS NOT NULL THEN 1 ELSE 0 END) AS resolved_n,
            SUM(CASE WHEN dismissed_at   IS NOT NULL THEN 1 ELSE 0 END) AS dismissed_n
         FROM ai_coverage_gaps WHERE company_id = ?"
    );
    $k->execute([$companyId]);
    $kpiRow = $k->fetch() ?: [];
    $kpi = [
        'open'      => (int)($kpiRow['open_n']      ?? 0),
        'resolved'  => (int)($kpiRow['resolved_n']  ?? 0),
        'dismissed' => (int)($kpiRow['dismissed_n'] ?? 0),
    ];
} catch (Throwable $e) { /* pre-phase47 = zeros */ }

layout_start($current_user, 'KB coverage gaps', 'kb_coverage');
?>
<style>
.cov-kpi-row { display:grid; gap:10px; grid-template-columns: repeat(3,1fr); margin-bottom:14px; }
.cov-kpi { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px 14px; text-align:center; }
.cov-kpi .val { font-size:22px; font-weight:700; }
.cov-kpi .lbl { color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
.cov-reason-chip { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
.cov-noans   { background:#fee2e2; color:#7f1d1d; }
.cov-fast    { background:#fef3c7; color:#78350f; }
.cov-manual  { background:#e0e7ff; color:#3730a3; }
</style>

<div class="card">
  <h2>🎯 Coverage gaps</h2>
  <p class="muted small">
    Customer questions where the AI struggled — either the AI said it didn't know, or a human agent
    corrected the AI within 30 seconds of its reply. Turn each one into a KB article and next time
    the AI answers instead of dodging.
  </p>

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <div class="cov-kpi-row">
    <div class="cov-kpi"><div class="lbl">Open</div><div class="val" style="color:#DC2626;"><?= (int)$kpi['open'] ?></div></div>
    <div class="cov-kpi"><div class="lbl">Resolved (→ article)</div><div class="val" style="color:#16A34A;"><?= (int)$kpi['resolved'] ?></div></div>
    <div class="cov-kpi"><div class="lbl">Dismissed</div><div class="val" style="color:#64748b;"><?= (int)$kpi['dismissed'] ?></div></div>
  </div>

  <?php if ($drafted): ?>
    <div class="card" style="background:#faf5ff; border:1px solid #e9d5ff; margin-bottom:14px;">
      <h3 style="margin-top:0;">✨ AI-drafted starter article</h3>
      <p class="muted small">Edit anything before saving — the AI just gives a first draft based on the customer's question.</p>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action"      value="save_draft">
        <input type="hidden" name="gap_id_save" value="<?= (int)($drafted['gap_id'] ?? 0) ?>">
        <label>Title
          <input type="text" name="title" required maxlength="200" value="<?= e((string)$drafted['title']) ?>">
        </label>
        <label>Article body
          <textarea name="content_text" rows="10"><?= e((string)$drafted['body']) ?></textarea>
        </label>
        <div>
          <button class="btn btn-primary" type="submit">💾 Save as KB article</button>
          <a class="btn" href="/admin/kb_coverage.php">Cancel</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <form method="get" style="display:flex; gap:8px; margin-bottom:12px;">
    <select name="reason" onchange="this.form.submit()">
      <option value="all"              <?= $fReason==='all' ? 'selected':'' ?>>All reasons</option>
      <option value="no_answer"        <?= $fReason==='no_answer' ? 'selected':'' ?>>AI said "don't know"</option>
      <option value="fast_escalation"  <?= $fReason==='fast_escalation' ? 'selected':'' ?>>Human corrected within 30s</option>
      <option value="manual"           <?= $fReason==='manual' ? 'selected':'' ?>>Manually flagged</option>
    </select>
    <span class="muted small" style="margin-left:auto;"><?= count($rows) ?> gap(s) shown</span>
  </form>

  <table class="data-table">
    <thead>
      <tr>
        <th>Customer question</th>
        <th>Reason</th>
        <th>When</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="muted" style="text-align:center; padding:24px;">
          🎉 No open coverage gaps. Either your KB is complete or the cron hasn't run yet.
        </td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $reasonMap = [
            'no_answer' => ['label' => 'AI said "don\'t know"', 'cls' => 'cov-noans'],
            'fast_escalation' => ['label' => 'Human corrected fast', 'cls' => 'cov-fast'],
            'manual' => ['label' => 'Flagged manually', 'cls' => 'cov-manual'],
            'low_confidence' => ['label' => 'Low confidence', 'cls' => 'cov-fast'],
        ];
        $rm = $reasonMap[$r['reason']] ?? ['label' => (string)$r['reason'], 'cls' => 'cov-manual'];
      ?>
        <tr>
          <td><?= e((string)$r['customer_message']) ?></td>
          <td><span class="cov-reason-chip <?= e($rm['cls']) ?>"><?= e($rm['label']) ?></span></td>
          <td class="muted small"><?= e(fmt_dt($r['created_at'])) ?></td>
          <td style="text-align:right; white-space:nowrap;">
            <?php if ($r['conversation_id']): ?>
              <a class="btn btn-sm" href="/inbox/chat.php?id=<?= (int)$r['conversation_id'] ?>" target="_blank" title="Open conversation">💬 View</a>
            <?php endif; ?>
            <?php if (user_can_edit_settings($current_user)): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="draft">
                <input type="hidden" name="gap_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-primary" type="submit" title="AI drafts a starter KB article">✨ Draft article</button>
              </form>
            <?php endif; ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="dismiss">
              <input type="hidden" name="gap_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" type="submit" title="Not worth an article">Dismiss</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="muted small" style="margin-top: 14px;">
    Detection runs nightly via <code>cron/detect_coverage_gaps.php</code>.
    Flag manually from any inbox chat with the ⚠️ button (soon).
  </p>
</div>
<?php layout_end(); ?>

<?php
/**
 * Ask Claude to draft a starter KB article that answers this customer
 * question. Returns [title, body, gap_id] or null on failure.
 */
function kb_coverage_draft_article(int $companyId, string $customerQuestion): ?array
{
    require_once __DIR__ . '/../inc/whatsapp_api.php';
    $company = load_company_settings($companyId);
    if (!$company || !ai_is_configured($company)) return null;
    $apiKey = ai_api_key($company);
    if ($apiKey === '') return null;

    $brand = trim((string)($company['name'] ?? 'our business'));
    $model = ai_model_for_feature($company, 'kb_distill');
    $systemPrompt =
        "You draft short knowledge-base FAQ articles for {$brand}, a customer-service business. "
      . "Given a customer question that the AI struggled to answer, produce a short article that would "
      . "let the AI answer next time. Return ONLY a single JSON object:\n"
      . '{ "title": "short 4-8 word title", "body": "the article as concise markdown — 4-8 sentences, no fluff" }' . "\n"
      . "Rules:\n"
      . "- Body should be answerable facts, not filler. If a real answer needs pricing/policy the operator would fill in, use ALL CAPS placeholders like [PLACEHOLDER: your delivery fee].\n"
      . "- Match the customer's language (English / Bahasa Malaysia / mix).\n"
      . "- Never claim a fact you weren't told.";
    $payload = [
        'model'      => $model,
        'max_tokens' => 600,
        'system'     => [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]],
        'messages'   => [['role' => 'user', 'content' => 'Customer asked: ' . $customerQuestion]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $raw  = trim((string)($data['content'][0]['text'] ?? ''));
    if ($raw === '') return null;

    if (!empty($data['usage'])) {
        ai_log_usage($companyId, null, 'kb_gap_draft', $data['usage'], (string)($data['model'] ?? $model));
    }
    if (str_starts_with($raw, '```')) {
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw);
    }
    $parsed = json_decode((string)$raw, true);
    if (!is_array($parsed) || empty($parsed['title']) || empty($parsed['body'])) return null;

    return [
        'title'  => (string)$parsed['title'],
        'body'   => (string)$parsed['body'],
        'gap_id' => (int)($_POST['gap_id'] ?? 0),
    ];
}
