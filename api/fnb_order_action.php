<?php
/**
 * POST /api/fnb_order_action.php
 *
 * Handles fast mutations on an F&B order — mainly status changes
 * from the kanban board and order-view buttons.
 *
 * Params:
 *   action    : 'set_status' (currently the only one)
 *   order_id  : int
 *   status    : 'new' | 'confirmed' | 'processing' | 'completed' | 'cancelled'
 *   redirect  : 'view' (optional) to return to the order-view page
 */

require_once __DIR__ . '/../inc/auth.php';

$user = require_role(['super_admin', 'manager']);

if (!is_post()) {
    http_response_code(405);
    exit('Method not allowed.');
}
csrf_check();

$companyId = (int)$user['company_id'];
if (!fnb_module_active($companyId)) {
    http_response_code(403);
    exit('F&B module is not enabled for this workspace.');
}

$db      = aiserve_db();
$action  = (string)($_POST['action']   ?? '');
$orderId = (int)($_POST['order_id']    ?? 0);

if ($orderId <= 0) { http_response_code(400); exit('Missing order id.'); }

$check = $db->prepare('SELECT id, status FROM fnb_orders WHERE id = ? AND company_id = ? LIMIT 1');
$check->execute([$orderId, $companyId]);
$order = $check->fetch();
if (!$order) { http_response_code(404); exit('Order not found.'); }

if ($action === 'set_status') {
    $newStatus = (string)($_POST['status'] ?? '');
    if (!in_array($newStatus, ['new','confirmed','processing','completed','cancelled'], true)) {
        http_response_code(400);
        exit('Invalid status.');
    }
    if ($order['status'] === $newStatus) {
        redirect(($_POST['redirect'] ?? '') === 'view' ? '/admin/fnb_order_view.php?id=' . $orderId : '/admin/fnb_orders.php');
    }
    $db->prepare('UPDATE fnb_orders SET status = ? WHERE id = ? AND company_id = ?')
       ->execute([$newStatus, $orderId, $companyId]);
    log_activity(
        $companyId, (int)$user['id'], 'fnb_order_status_changed',
        'fnb_order', $orderId,
        'from=' . $order['status'] . ' to=' . $newStatus
    );
    redirect(($_POST['redirect'] ?? '') === 'view'
        ? '/admin/fnb_order_view.php?id=' . $orderId
        : '/admin/fnb_orders.php');
}

http_response_code(400);
exit('Unknown action.');
