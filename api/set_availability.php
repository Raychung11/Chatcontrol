<?php
/**
 * POST /api/set_availability.php
 *
 * Body: availability = available | busy | away
 *
 * Self-serve status toggle. Any logged-in user can flip their own
 * availability — used by the sidebar chip and (later) a keyboard
 * shortcut. Managers CANNOT flip other people's status from this
 * endpoint (that would need a separate admin action).
 */

require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();

if (!is_post()) {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST required.']));
}
csrf_check();

$value = (string)($_POST['availability'] ?? '');
if (!in_array($value, ['available', 'busy', 'away'], true)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid availability.']));
}

$db = aiserve_db();
try {
    $db->prepare('UPDATE users SET availability = ?, availability_updated_at = NOW() WHERE id = ? LIMIT 1')
       ->execute([$value, (int)$user['id']]);
    log_activity((int)$user['company_id'], (int)$user['id'], 'availability_changed',
        'user', (int)$user['id'], $value);
    echo json_encode(['ok' => true, 'availability' => $value]);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Save failed: ' . $e->getMessage()]));
}
