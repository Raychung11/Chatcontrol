<?php
/**
 * POST /api/push_test.php — fire a test push at the current agent.
 *
 * Called from the "Send me a test" button on the Notifications settings
 * card so an agent can verify the whole pipeline (VAPID → push service
 * → their device) without waiting for a real customer message.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/push.php';

$user = require_login();
csrf_check();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$n = push_send_to_user((int)$user['id'], [
    'title' => '🔔 Test notification',
    'body'  => 'If you see this on your phone, push is working. Real customer messages will notify you the same way.',
    'url'   => '/inbox/',
    'tag'   => 'aiserve-test-' . time(),
]);

json_response([
    'ok'         => true,
    'delivered'  => $n,
    'hint'       => $n === 0
        ? 'No active subscriptions — turn on push notifications first.'
        : ('Delivered to ' . $n . ' device' . ($n === 1 ? '' : 's') . '.'),
]);
