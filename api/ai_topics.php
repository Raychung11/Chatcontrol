<?php
/**
 * POST /api/ai_topics.php
 *
 * Body: period_days (7|30|90), _csrf
 *
 * Pulls the last <period_days> of customer messages for the user's workspace,
 * asks Claude to group them into top discussion topics, persists the result
 * in topic_analyses for reuse, returns the parsed topics.
 *
 * Cached: if a topic_analyses row for the same (company, period) exists less
 * than 24 hours old AND the request omits refresh=1, we serve it without
 * spending tokens.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/provider.php';

$user = require_role(['super_admin', 'manager']);

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

$companyId  = (int)$user['company_id'];
$periodDays = (int)($_POST['period_days'] ?? 30);
if (!in_array($periodDays, [7, 30, 90], true)) $periodDays = 30;
$refresh    = !empty($_POST['refresh']);

$db = aiserve_db();

// Return cached result if recent and not asked to refresh.
if (!$refresh) {
    $stmt = $db->prepare(
        'SELECT * FROM topic_analyses
         WHERE company_id = ? AND period_days = ? AND created_at > NOW() - INTERVAL 24 HOUR
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$companyId, $periodDays]);
    $cached = $stmt->fetch();
    if ($cached) {
        $topics = json_decode((string)$cached['topics_json'], true);
        if (is_array($topics)) {
            json_response([
                'ok'                 => true,
                'topics'             => $topics,
                'cached'             => true,
                'generated_at'       => $cached['created_at'],
                'conversation_count' => (int)$cached['conversation_count'],
                'message_count'      => (int)$cached['message_count'],
                'model'              => $cached['model'],
                'period_days'        => $periodDays,
            ]);
        }
    }
}

$company = load_company_settings($companyId) ?: [];
if (!ai_is_configured($company)) {
    json_response(['ok' => false, 'error' => 'AI is not enabled for this workspace. Configure it in Admin → AI Settings.'], 400);
}

// Pull recent customer messages. We use the first 3 customer messages per
// conversation - that's the strongest topic signal and keeps the token
// budget predictable.
$mstmt = $db->prepare(
    'SELECT m.conversation_id, m.message_text, m.created_at
     FROM messages m
     WHERE m.company_id = ?
       AND m.direction = "incoming"
       AND m.sender_type = "customer"
       AND m.message_text IS NOT NULL AND m.message_text <> ""
       AND m.message_type IN ("text","")
       AND m.created_at > NOW() - INTERVAL ' . $periodDays . ' DAY
     ORDER BY m.conversation_id, m.id ASC
     LIMIT 5000'
);
$mstmt->execute([$companyId]);
$rows = $mstmt->fetchAll();

if (!$rows) {
    json_response(['ok' => false, 'error' => "No customer messages in the last {$periodDays} days yet."], 400);
}

$perConv = [];
$conversations = 0;
foreach ($rows as $r) {
    $cid = (int)$r['conversation_id'];
    $perConv[$cid] = $perConv[$cid] ?? [];
    if (count($perConv[$cid]) >= 3) continue;
    $perConv[$cid][] = mb_substr(trim((string)$r['message_text']), 0, 400);
}
$samples = [];
foreach ($perConv as $cid => $msgs) {
    foreach ($msgs as $m) {
        $samples[] = $m;
    }
}
$conversations = count($perConv);

$result = ai_analyze_topics($company, $samples, $periodDays);
if (!$result['ok']) {
    log_activity($companyId, (int)$user['id'], 'ai_topics_failed', 'company', $companyId,
        substr((string)$result['error'], 0, 200));
    json_response($result, 502);
}

// Cache the result.
$db->prepare(
    'INSERT INTO topic_analyses
        (company_id, period_days, conversation_count, message_count, model, topics_json,
         input_tokens, output_tokens, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $companyId, $periodDays, $conversations, count($samples),
    (string)($result['model'] ?? ''),
    json_encode($result['topics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    (int)($result['usage']['input_tokens']  ?? 0),
    (int)($result['usage']['output_tokens'] ?? 0),
    (int)$user['id'],
]);

log_activity($companyId, (int)$user['id'], 'ai_topics_generated', 'company', $companyId,
    'period=' . $periodDays . 'd convs=' . $conversations . ' samples=' . count($samples));

json_response([
    'ok'                 => true,
    'topics'             => $result['topics'],
    'cached'             => false,
    'generated_at'       => date('Y-m-d H:i:s'),
    'conversation_count' => $conversations,
    'message_count'      => count($samples),
    'model'              => $result['model'] ?? null,
    'usage'              => $result['usage'] ?? null,
    'period_days'        => $periodDays,
]);
