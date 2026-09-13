<?php
/**
 * GET /api/fnb_orders_ping.php?since=<id>[&branch_id=N]
 *
 * Lightweight poll endpoint for /admin/fnb_orders.php's live board.
 * Returns any orders whose id > since for the caller's company (and
 * optional branch filter — so the poll respects the operator's
 * active filter). Kept dirt cheap: one prepared SELECT, no joins.
 *
 * Response shape:
 *   { ok, latest_id, count, orders: [
 *       { id, order_number, status, order_type, customer_name,
 *         total, item_count, branch_id, branch_name, created_at }, ... ] }
 *
 * The dashboard's JS uses this to inject new cards into the New column
 * without a full page reload, plus play a subtle chime + toast per
 * arrival. Because it only returns id > since, the payload stays tiny
 * even on a busy day.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/fnb_helpers.php';

$user = require_role(['super_admin', 'manager']);
$companyId = (int)$user['company_id'];

header('Content-Type: application/json; charset=utf-8');

if (!fnb_module_active($companyId)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'F&B module not enabled']));
}

$since    = (int)($_GET['since']     ?? 0);
$branchId = (int)($_GET['branch_id'] ?? 0);

$db = aiserve_db();

$where  = ['o.company_id = ?', 'o.id > ?'];
$params = [$companyId, $since];
if ($branchId > 0) {
    $where[]  = 'o.branch_id = ?';
    $params[] = $branchId;
}
$sql = 'SELECT o.id, o.order_number, o.status, o.order_type,
               o.customer_name, o.total, o.branch_id, o.created_at,
               (SELECT COUNT(*) FROM fnb_order_items WHERE order_id = o.id) AS item_count,
               (SELECT name FROM branches WHERE id = o.branch_id LIMIT 1) AS branch_name
        FROM fnb_orders o
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY o.id ASC LIMIT 50';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$latest = $since;
$orders = [];
foreach ($rows as $r) {
    $latest = max($latest, (int)$r['id']);
    $orders[] = [
        'id'            => (int)$r['id'],
        'order_number'  => (string)$r['order_number'],
        'status'        => (string)$r['status'],
        'order_type'    => (string)$r['order_type'],
        'customer_name' => (string)$r['customer_name'],
        'total'         => (float)$r['total'],
        'item_count'    => (int)$r['item_count'],
        'branch_id'     => (int)($r['branch_id'] ?? 0),
        'branch_name'   => (string)($r['branch_name'] ?? ''),
        'created_at'    => (string)$r['created_at'],
    ];
}

echo json_encode([
    'ok'        => true,
    'latest_id' => $latest,
    'count'     => count($orders),
    'orders'    => $orders,
]);
