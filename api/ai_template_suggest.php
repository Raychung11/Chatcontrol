<?php
/**
 * POST /api/ai_template_suggest.php
 *
 * Body: description, language (optional), _csrf
 *
 * Takes the operator's plain-English brief and returns a structured
 * WhatsApp message template suggestion (name, category, language,
 * body_text with {{1}}, {{2}}, ... placeholders, variables_json).
 * The admin reviews it in the edit form before saving - we never
 * auto-create templates.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/provider.php';

$user = require_role(['super_admin']);

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

$companyId   = (int)$user['company_id'];
$description = trim((string)($_POST['description'] ?? ''));
$language    = trim((string)($_POST['language']    ?? 'en'));
if ($description === '') {
    json_response(['ok' => false, 'error' => 'Please describe what the template is for.'], 400);
}
if (mb_strlen($description) > 1000) {
    $description = mb_substr($description, 0, 1000);
}

$company = load_company_settings($companyId) ?: [];
if (!ai_is_configured($company)) {
    json_response(['ok' => false, 'error' => 'AI is not enabled for this workspace. Configure it in Admin → AI Settings.'], 400);
}

$result = ai_suggest_template($company, $description, $language ?: null);

if (!$result['ok']) {
    log_activity($companyId, (int)$user['id'], 'ai_template_suggest_failed', 'company', $companyId,
        substr((string)$result['error'], 0, 300));
    json_response($result, 502);
}

log_activity($companyId, (int)$user['id'], 'ai_template_suggest_generated', 'company', $companyId,
    substr($description, 0, 200));

json_response([
    'ok'         => true,
    'suggestion' => $result['suggestion'],
    'model'      => $result['model'] ?? null,
    'usage'      => $result['usage'] ?? null,
]);
