<?php
/**
 * GET /api/forward_targets.php?q=<search>
 *
 * Returns the operator's OPEN conversations, matching an optional
 * name/phone search. Feeds the Forward modal on the chat page —
 * operator picks one, then POSTs to /api/message_forward.php.
 *
 * Response:
 *   { ok:true, items: [{id, contact_name, wa_id, channel_name, last_message_at}] }
 *
 * Limits to 30 rows (agents rarely need to search past 30). Search
 * is case-insensitive substring on display_name / phone / wa_id.
 */

require_once __DIR__ . '/../inc/auth.php';

$user = require_login();
header('Content-Type: application/json');

$q = trim((string)($_GET['q'] ?? ''));
$db = aiserve_db();
$companyId = (int)$user['company_id'];

$where  = ['c.company_id = ?', 'c.status = "open"'];
$params = [$companyId];
if ($q !== '') {
    $where[] = '(ct.display_name LIKE ? OR ct.profile_name LIKE ? OR ct.wa_id LIKE ? OR ct.phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$sql = 'SELECT c.id, ct.display_name, ct.profile_name, ct.wa_id,
               ch.name AS channel_name, c.last_message_at
        FROM conversations c
        INNER JOIN contacts ct ON ct.id = c.contact_id
        INNER JOIN channels  ch ON ch.id = c.channel_id
        WHERE ' . implode(' AND ', $where)
     . ' ORDER BY c.last_message_at DESC, c.id DESC LIMIT 30';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id'              => (int)$r['id'],
        'contact_name'    => (string)($r['display_name'] ?: $r['profile_name'] ?: $r['wa_id']),
        'wa_id'           => (string)$r['wa_id'],
        'channel_name'    => (string)$r['channel_name'],
        'last_message_at' => (string)($r['last_message_at'] ?? ''),
    ];
}

echo json_encode(['ok' => true, 'items' => $items]);
