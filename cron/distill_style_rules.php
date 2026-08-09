<?php
/**
 * cron/distill_style_rules.php — weekly, per workspace.
 *
 * Reads ai_edit_examples with processed_at IS NULL, picks the top ~20
 * per workspace by edit_distance (biggest edits = biggest signal),
 * sends them to Claude with a prompt to extract 3-5 team-style rules,
 * and appends the result to (or updates) a
 *   [Auto] Team style rules — updated <timestamp>
 * KB article for that workspace. Marks examples processed.
 *
 * Cron entry:
 *   30 3 * * 1 php /var/www/aiserve/cron/distill_style_rules.php >/dev/null 2>&1
 * (Every Monday at 03:30 — Sunday's learn-from-history cron already
 *  runs at 03:00, so this one comes a few minutes later.)
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/ai_billing.php';

const STYLE_RULES_TITLE      = '[Auto] Team style rules';
const STYLE_MAX_EXAMPLES     = 20;
const STYLE_MIN_EDIT_DIST    = 15;   // skip tiny/no edits — no signal

$db = aiserve_db();

// Group unprocessed examples by workspace.
$ws = $db->query(
    "SELECT DISTINCT company_id FROM ai_edit_examples
     WHERE processed_at IS NULL AND edit_distance >= " . STYLE_MIN_EDIT_DIST
)->fetchAll();

if (!$ws) {
    echo "No unprocessed edit examples.\n";
    exit(0);
}

foreach ($ws as $wsRow) {
    $companyId = (int)$wsRow['company_id'];

    // Load workspace so ai_suggest_reply-style helpers work.
    require_once __DIR__ . '/../inc/whatsapp_api.php';
    $company = load_company_settings($companyId);
    if (!$company || empty($company['ai_enabled']) || ai_api_key($company) === '') {
        echo "workspace={$companyId} skipped — AI not configured.\n";
        continue;
    }

    // Top-N unprocessed examples by edit distance.
    $s = $db->prepare(
        'SELECT id, customer_message, ai_draft, agent_sent
         FROM ai_edit_examples
         WHERE company_id = ? AND processed_at IS NULL AND edit_distance >= ?
         ORDER BY edit_distance DESC, id ASC
         LIMIT ' . (int)STYLE_MAX_EXAMPLES
    );
    $s->execute([$companyId, STYLE_MIN_EDIT_DIST]);
    $examples = $s->fetchAll();
    if (!$examples) continue;

    // Build the distillation prompt.
    $blocks = [];
    foreach ($examples as $i => $ex) {
        $blocks[] = "Example " . ($i + 1) . ":\n"
                  . "Customer said: " . mb_substr((string)$ex['customer_message'], 0, 500) . "\n"
                  . "AI drafted:   " . mb_substr((string)$ex['ai_draft'],         0, 500) . "\n"
                  . "Agent sent:   " . mb_substr((string)$ex['agent_sent'],       0, 500);
    }
    $userPrompt =
        "Below are " . count($examples) . " recent examples where our team edited the AI's drafted reply "
      . "before sending it. Extract 3-8 concise rules the AI should follow to sound more like how our "
      . "team actually replies. Focus on patterns you see across MULTIPLE examples — voice, formatting, "
      . "phrases we prefer, phrases we avoid, common facts we add. Skip anything you only see once.\n\n"
      . "Return the rules as a plain markdown list (\"- ...\"), no preamble.\n\n"
      . implode("\n\n", $blocks);

    $model = ai_model_for_feature($company, 'kb_distill');
    $payload = [
        'model'      => $model,
        'max_tokens' => 800,
        'messages'   => [['role' => 'user', 'content' => $userPrompt]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . ai_api_key($company),
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $rules = trim((string)($data['content'][0]['text'] ?? ''));
    if ($rules === '') {
        echo "workspace={$companyId} — model returned no rules, skipping.\n";
        continue;
    }

    // Log the cost.
    ai_log_usage($companyId, null, 'kb_distill', $data['usage'] ?? null, (string)($data['model'] ?? $model));

    // Upsert the auto KB article.
    $ids = implode(',', array_map(fn($e) => (int)$e['id'], $examples));
    $body = "_Auto-generated from " . count($examples) . " team-edited replies as of " . date('Y-m-d') . "._\n\n"
          . $rules
          . "\n\n_Examples processed (ids):_ " . $ids;
    $chars = mb_strlen($body);
    $find = $db->prepare('SELECT id FROM knowledge_base WHERE company_id = ? AND title = ? LIMIT 1');
    $find->execute([$companyId, STYLE_RULES_TITLE]);
    $existingId = (int)($find->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $db->prepare(
            'UPDATE knowledge_base
             SET content_text = ?, content_chars = ?, status = "active",
                 last_auto_updated_at = NOW()
             WHERE id = ?'
        )->execute([$body, $chars, $existingId]);
    } else {
        $db->prepare(
            'INSERT INTO knowledge_base
                (company_id, title, content_text, content_chars, status, auto_generated, last_auto_updated_at)
             VALUES (?, ?, ?, ?, "active", 1, NOW())'
        )->execute([$companyId, STYLE_RULES_TITLE, $body, $chars]);
    }

    // Mark examples processed.
    $upIds = array_map(fn($e) => (int)$e['id'], $examples);
    $ph    = implode(',', array_fill(0, count($upIds), '?'));
    $db->prepare("UPDATE ai_edit_examples SET processed_at = NOW() WHERE id IN ($ph)")
       ->execute($upIds);

    echo "workspace={$companyId} — distilled " . count($examples) . " examples into style rules.\n";
}
