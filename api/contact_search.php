<?php
/**
 * GET /api/contact_search.php?q=<query>&exclude=<contact_id>
 *
 * Search the operator's workspace contacts by name / phone / wa_id.
 * Feeds the "Merge contact" modal on the chat side panel — operator
 * picks one, then POSTs to /api/contact_merge.php.
 *
 * Response:
 *   { ok:true, items: [{id, display_name, wa_id, phone, is_lid}] }
 *
 * Excludes the source contact (?exclude=) so the operator can't merge
 * a contact into itself. Limits to 30 rows.
 */

require_once __DIR__ . '/../inc/auth.php';

$user = require_login();
header('Content-Type: application/json');

$q       = trim((string)($_GET['q'] ?? ''));
$exclude = (int)($_GET['exclude'] ?? 0);
$db      = aiserve_db();
$companyId = (int)$user['company_id'];

$where  = ['company_id = ?'];
$params = [$companyId];
if ($q !== '') {
    $where[] = '(display_name LIKE ? OR profile_name LIKE ? OR wa_id LIKE ? OR phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($exclude > 0) {
    $where[] = 'id != ?';
    $params[] = $exclude;
}

$sql = 'SELECT id, wa_id, wa_lid, display_name, profile_name, phone
        FROM contacts
        WHERE ' . implode(' AND ', $where)
     . ' ORDER BY last_message_at DESC, id DESC LIMIT 30';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id'           => (int)$r['id'],
        'wa_id'        => (string)$r['wa_id'],
        'display_name' => (string)($r['display_name'] ?: $r['profile_name'] ?: $r['wa_id']),
        'phone'        => (string)($r['phone'] ?? ''),
        'is_lid'       => !empty($r['wa_lid']),
    ];
}

echo json_encode(['ok' => true, 'items' => $items]);
