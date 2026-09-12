<?php
/**
 * POST /api/push_subscribe.php
 *
 * Register a Web Push subscription for the current logged-in agent.
 *
 * The browser calls PushManager.subscribe(), gets back a
 * PushSubscription that looks like:
 *   { endpoint, keys: { p256dh, auth } }
 * The frontend posts that verbatim as JSON here; we persist it in
 * push_subscriptions keyed by endpoint (the browser reissues the same
 * endpoint on repeat subscribe, so uniqueness on endpoint is stable).
 *
 * Also serves the VAPID public key on GET so the frontend has an
 * applicationServerKey to hand to subscribe() — bootstrapping
 * without leaking the private key.
 *
 * DELETE removes the subscription — used when the agent turns push
 * off from Settings or unsubscribes.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/push.php';

$user = require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Hand the frontend the VAPID public key so it can subscribe.
    // Auto-generates on first call so a brand-new install works without
    // any admin action.
    $v = push_get_vapid_keys();
    if ($v['public_b64u'] === '') {
        json_response(['ok' => false, 'error' => 'VAPID keypair unavailable — check OpenSSL EC support.'], 500);
    }
    json_response([
        'ok'                    => true,
        'application_server_key'=> $v['public_b64u'],
    ]);
}

if ($method === 'POST') {
    csrf_check();
    $raw = file_get_contents('php://input');
    $j   = json_decode((string)$raw, true);
    if (!is_array($j)) json_response(['ok' => false, 'error' => 'Bad JSON.'], 400);

    $endpoint = (string)($j['endpoint'] ?? '');
    $p256dh   = (string)($j['keys']['p256dh'] ?? '');
    $auth     = (string)($j['keys']['auth']   ?? '');

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        json_response(['ok' => false, 'error' => 'Missing subscription fields.'], 400);
    }
    // Sanity — every real push endpoint is HTTPS and > 60 chars.
    if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 600) {
        json_response(['ok' => false, 'error' => 'Bad endpoint.'], 400);
    }

    push_subscribe(
        (int)$user['id'],
        $endpoint, $p256dh, $auth,
        (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    csrf_check();
    $raw = file_get_contents('php://input');
    $j   = json_decode((string)$raw, true);
    $endpoint = (string)(($j['endpoint'] ?? '') ?: ($_GET['endpoint'] ?? ''));
    if ($endpoint === '') json_response(['ok' => false, 'error' => 'Missing endpoint.'], 400);
    // Only remove if it belongs to THIS user — never let a session
    // clobber another agent's subscription.
    aiserve_db()->prepare(
        'DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?'
    )->execute([$endpoint, (int)$user['id']]);
    json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
