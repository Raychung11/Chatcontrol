<?php
/**
 * POST /api/ai_routing_suggest.php
 *
 * Body: description, _csrf
 *
 * Takes the operator's plain-English routing intent and returns a structured
 * suggestion (match_type, match_value, department_id, assigned_user_id,
 * priority). The admin reviews it in the routing form before saving - we
 * never auto-create rules.
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
if ($description === '') {
    json_response(['ok' => false, 'error' => 'Please describe what you want to route.'], 400);
}
if (mb_strlen($description) > 1000) {
    $description = mb_substr($description, 0, 1000);
}

$company = load_company_settings($companyId) ?: [];
if (!ai_is_configured($company)) {
    json_response(['ok' => false, 'error' => 'AI is not enabled for this workspace. Configure it in Admin → AI Settings.'], 400);
}

$db = aiserve_db();

$dstmt = $db->prepare(
    'SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name'
);
$dstmt->execute([$companyId]);
$departments = $dstmt->fetchAll();

$ustmt = $db->prepare(
    'SELECT id, name, role FROM users WHERE company_id = ? AND status = "active" ORDER BY name'
);
$ustmt->execute([$companyId]);
$users = $ustmt->fetchAll();

$result = ai_suggest_routing_rule($company, $description, $departments, $users);

if (!$result['ok']) {
    log_activity($companyId, (int)$user['id'], 'ai_routing_suggest_failed', 'company', $companyId,
        substr((string)$result['error'], 0, 300));
    json_response($result, 502);
}

log_activity($companyId, (int)$user['id'], 'ai_routing_suggest_generated', 'company', $companyId,
    substr($description, 0, 200));

json_response([
    'ok'         => true,
    'suggestion' => $result['suggestion'],
    'model'      => $result['model'] ?? null,
    'usage'      => $result['usage'] ?? null,
]);
