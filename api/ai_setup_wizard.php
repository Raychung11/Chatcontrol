<?php
/**
 * POST /api/ai_setup_wizard.php
 *
 * Body: description, language, _csrf
 *
 * Takes a one-sentence business description and returns a starter pack
 * of 5 templates + 5 keyword auto-replies. Admin reviews the preview
 * before hitting Apply on /admin/setup_wizard.php.
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
    json_response(['ok' => false, 'error' => 'Please describe your business in one sentence.'], 400);
}
if (mb_strlen($description) > 1000) {
    $description = mb_substr($description, 0, 1000);
}

$company = load_company_settings($companyId) ?: [];
if (!ai_is_configured($company)) {
    json_response(['ok' => false, 'error' => 'AI is not enabled for this workspace. Configure it in Admin → AI Settings.'], 400);
}

$result = ai_generate_setup_pack($company, $description, $language);
if (!$result['ok']) {
    log_activity($companyId, (int)$user['id'], 'ai_setup_wizard_failed', 'company', $companyId,
        substr((string)$result['error'], 0, 300));
    json_response($result, 502);
}

log_activity($companyId, (int)$user['id'], 'ai_setup_wizard_generated', 'company', $companyId,
    'templates=' . count($result['suggestion']['templates'])
    . ' auto_replies=' . count($result['suggestion']['auto_replies']));

json_response([
    'ok'         => true,
    'suggestion' => $result['suggestion'],
    'model'      => $result['model'] ?? null,
    'usage'      => $result['usage'] ?? null,
]);
