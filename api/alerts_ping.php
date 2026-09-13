<?php
/**
 * GET  /api/alerts_ping.php               — return open alerts JSON
 * POST /api/alerts_ping.php?dismiss=<id>  — mark one alert resolved (agent clicked X)
 * POST /api/alerts_ping.php?ack=<id>&leg=browser
 *                                         — client says "I showed the desktop
 *                                           notification for this alert"
 *
 * Called every POLL_MS by the app.js bell widget. Read-only for GET
 * so it is safe to poll frequently. The response includes only OPEN
 * alerts scoped to the caller's workspace.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/alerts.php';

$user = require_login();
$db   = aiserve_db();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    csrf_check();
    $companyId = (int)$user['company_id'];

    if (!empty($_POST['dismiss']) || !empty($_GET['dismiss'])) {
        $id = (int)($_POST['dismiss'] ?? $_GET['dismiss'] ?? 0);
        // Only rows on the caller's workspace — never someone else's alert.
        $stmt = $db->prepare(
            'UPDATE alerts SET resolved_at = NOW()
             WHERE id = ? AND company_id = ? AND resolved_at IS NULL'
        );
        $stmt->execute([$id, $companyId]);
        json_response(['ok' => true, 'dismissed' => $stmt->rowCount()]);
    }

    if (!empty($_POST['ack']) || !empty($_GET['ack'])) {
        $id  = (int)($_POST['ack'] ?? $_GET['ack'] ?? 0);
        $leg = trim((string)($_POST['leg'] ?? $_GET['leg'] ?? ''));
        if ($leg === '' || !preg_match('/^[a-z]{3,20}$/', $leg)) {
            json_response(['ok' => false, 'error' => 'Bad leg.'], 400);
        }
        // Only rows on the caller's workspace.
        $chk = $db->prepare('SELECT id FROM alerts WHERE id = ? AND company_id = ? LIMIT 1');
        $chk->execute([$id, $companyId]);
        if (!$chk->fetchColumn()) json_response(['ok' => false, 'error' => 'Not found.'], 404);
        alert_mark_dispatched($id, $leg);
        json_response(['ok' => true, 'acked' => true]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}

// GET — list open alerts for this workspace.
$rows = alerts_open_for_company((int)$user['company_id'], 20);

// Simplify shape for JS.
$alerts = array_map(function (array $r): array {
    return [
        'id'              => (int)$r['id'],
        'kind'            => (string)$r['kind'],
        'severity'        => (string)$r['severity'],
        'title'           => (string)$r['title'],
        'body'            => (string)$r['body'],
        'href'            => (string)($r['href'] ?? ''),
        'created_at'      => (string)$r['created_at'],
        'dispatched_via'  => (string)($r['dispatched_via'] ?? ''),
    ];
}, $rows);

json_response([
    'ok'          => true,
    'alerts'      => $alerts,
    'server_time' => time(),
]);
