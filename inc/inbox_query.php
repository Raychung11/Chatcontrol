<?php
/**
 * Shared inbox query builder.
 *
 * Used by /inbox/index.php (initial render) and /api/poll.php (live refresh)
 * so the filtering / permission logic stays in exactly one place.
 */

require_once __DIR__ . '/helpers.php';

/**
 * @return array{
 *   conversations: array<int,array>,
 *   tags_by_conv: array<int,array>,
 *   counts: array
 * }
 */
function inbox_fetch(PDO $db, array $user, string $filter, string $search, int $deptFilter, int $tagFilter): array
{
    $companyId = (int)$user['company_id'];
    $role      = $user['role'];

    $where  = ['c.company_id = ?'];
    $params = [$companyId];

    switch ($filter) {
        case 'unassigned':
            $where[] = 'c.assigned_user_id IS NULL';
            $where[] = 'c.status <> "closed"';
            break;
        case 'mine':
            $where[]  = 'c.assigned_user_id = ?';
            $params[] = (int)$user['id'];
            break;
        case 'open':
            $where[] = 'c.status = "open"';
            break;
        case 'pending':
            $where[] = 'c.status = "pending"';
            break;
        case 'closed':
            $where[] = 'c.status = "closed"';
            break;
        case 'escalated':
            $where[] = 'c.status = "escalated"';
            break;
        case 'all':
        default:
            break;
    }

    if ($role === 'agent') {
        $where[]  = '(c.assigned_user_id = ?
                       OR (c.assigned_user_id IS NULL
                           AND (c.department_id IS NULL OR c.department_id = ?)))';
        $params[] = (int)$user['id'];
        $params[] = (int)($user['department_id'] ?? 0);
    }

    if ($deptFilter > 0) {
        $where[]  = 'c.department_id = ?';
        $params[] = $deptFilter;
    }

    if ($search !== '') {
        $where[] = '(ct.display_name LIKE ? OR ct.profile_name LIKE ? OR ct.phone LIKE ? OR ct.wa_id LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    if ($tagFilter > 0) {
        $where[]  = 'EXISTS (SELECT 1 FROM conversation_tag_map m WHERE m.conversation_id = c.id AND m.tag_id = ?)';
        $params[] = $tagFilter;
    }

    $sql = 'SELECT c.*, ct.display_name, ct.profile_name, ct.phone AS contact_phone, ct.wa_id,
                   u.name AS agent_name, d.name AS department_name
            FROM conversations c
            INNER JOIN contacts ct ON ct.id = c.contact_id
            LEFT  JOIN users    u  ON u.id  = c.assigned_user_id
            LEFT  JOIN departments d ON d.id = c.department_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY (c.status = "closed") ASC, COALESCE(c.last_message_at, c.created_at) DESC
            LIMIT 200';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $conversations = $stmt->fetchAll();

    $tagsByConv = [];
    if ($conversations) {
        $convIds = array_map(fn($r) => (int)$r['id'], $conversations);
        $placeholders = implode(',', array_fill(0, count($convIds), '?'));
        $tagStmt = $db->prepare(
            'SELECT m.conversation_id, t.id, t.name, t.color
             FROM conversation_tag_map m
             INNER JOIN conversation_tags t ON t.id = m.tag_id
             WHERE m.conversation_id IN (' . $placeholders . ') AND t.company_id = ?'
        );
        $tagStmt->execute(array_merge($convIds, [$companyId]));
        foreach ($tagStmt->fetchAll() as $row) {
            $tagsByConv[(int)$row['conversation_id']][] = $row;
        }
    }

    $cstmt = $db->prepare(
        'SELECT
           SUM(status <> "closed")                              AS open_total,
           SUM(assigned_user_id IS NULL AND status <> "closed") AS unassigned,
           SUM(assigned_user_id = ? AND status <> "closed")     AS mine,
           SUM(status = "open")                                 AS s_open,
           SUM(status = "pending")                              AS s_pending,
           SUM(status = "closed")                               AS s_closed,
           SUM(status = "escalated")                            AS s_escalated
         FROM conversations WHERE company_id = ?'
    );
    $cstmt->execute([(int)$user['id'], $companyId]);
    $counts = $cstmt->fetch() ?: [];

    return [
        'conversations' => $conversations,
        'tags_by_conv'  => $tagsByConv,
        'counts'        => $counts,
    ];
}

/**
 * Render a single inbox row's inner markup. Shared so the JS-driven live
 * list and the server-rendered list look identical.
 */
function inbox_row_html(array $c, array $tags): string
{
    $name    = $c['display_name'] ?: $c['profile_name'] ?: $c['wa_id'];
    $initial = strtoupper(substr($c['display_name'] ?: $c['profile_name'] ?: '?', 0, 1));
    $preview = mb_strimwidth((string)$c['last_message_text'], 0, 70, '…');
    $time    = relative_time($c['last_message_at'] ?? $c['created_at']);

    $html  = '<a class="inbox-row" data-conv-id="' . (int)$c['id'] . '" href="/inbox/chat.php?id=' . (int)$c['id'] . '">';
    $html .= '<div class="row-avatar">' . e($initial) . '</div>';
    $html .= '<div class="row-main">';
    $html .= '<div class="row-top"><span class="row-name">' . e($name) . '</span>'
           . '<span class="row-time">' . e($time) . '</span></div>';
    $html .= '<div class="row-mid"><span class="row-preview">' . e($preview) . '</span>';
    if ((int)$c['unread_count'] > 0) {
        $html .= '<span class="row-badge">' . (int)$c['unread_count'] . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="row-bot">' . status_badge($c['status']);
    $html .= '<span class="row-meta">' . e($c['agent_name'] ? 'Assigned: ' . $c['agent_name'] : 'Unassigned') . '</span>';
    if (!empty($c['department_name'])) {
        $html .= '<span class="row-meta">· ' . e($c['department_name']) . '</span>';
    }
    foreach ($tags as $tg) {
        $html .= '<span class="tag-chip" style="background: ' . e($tg['color']) . '">' . e($tg['name']) . '</span>';
    }
    $html .= '</div></div></a>';
    return $html;
}
