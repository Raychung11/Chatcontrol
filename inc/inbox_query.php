<?php
/**
 * Shared inbox query builder.
 *
 * Used by /inbox/index.php (initial render) and /api/poll.php (live refresh)
 * so the filtering / permission logic stays in exactly one place.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/**
 * @return array{
 *   conversations: array<int,array>,
 *   tags_by_conv: array<int,array>,
 *   counts: array
 * }
 */
function inbox_fetch(PDO $db, array $user, string $filter, string $search, int $deptFilter, int $tagFilter, int $assigneeFilter = 0): array
{
    $companyId = (int)$user['company_id'];
    $role      = $user['role'];

    $where  = ['c.company_id = ?'];
    $params = [$companyId];

    // "Awaiting reply" = the most recent message in the conversation was from
    // the customer (last_message_at <= last_customer_message_at, with NULLs
    // treated as "still waiting"). Anything closed is excluded - a closed
    // conversation does not need a reply even if the customer messaged last.
    $awaitingExpr = '(c.last_customer_message_at IS NOT NULL
                       AND (c.last_message_at IS NULL OR c.last_message_at <= c.last_customer_message_at)
                       AND c.status <> "closed")';

    switch ($filter) {
        case 'unassigned':
            $where[] = 'c.assigned_user_id IS NULL';
            $where[] = 'c.status <> "closed"';
            break;
        case 'mine':
            $where[]  = 'c.assigned_user_id = ?';
            $params[] = (int)$user['id'];
            break;
        case 'awaiting':    // legacy filter name - kept for URL back-compat
        case 'unread':      // new WhatsApp-style label
            $where[] = $awaitingExpr;
            break;
        case 'replied':
            // Everything NOT waiting for us and NOT closed - i.e. our team
            // (or the AI bot) has already responded to the customer's most
            // recent message. Mirrors WhatsApp's tab-style "you're caught up".
            $where[] = 'NOT ' . $awaitingExpr;
            $where[] = 'c.status <> "closed"';
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

    // Phase 26: per-agent channel access. If this user is restricted to a
    // subset of channels, the inbox query returns only conversations on
    // those channels. Null (managers, super admins, or unrestricted
    // agents) skips this clause entirely.
    $allowedChannels = user_visible_channel_ids($user);
    if ($allowedChannels !== null) {
        $placeholders = implode(',', array_fill(0, count($allowedChannels), '?'));
        $where[] = 'c.channel_id IN (' . $placeholders . ')';
        foreach ($allowedChannels as $cid) $params[] = $cid;
    }

    // Phase 30: per-user branch access. Managers or agents with entries
    // in user_branches only see conversations whose contact belongs to
    // one of those branches. Super admins bypass. The JOIN below uses
    // ct.branch_id which is already loaded via the contacts join later.
    $allowedBranches = user_visible_branch_ids($user);
    if ($allowedBranches !== null) {
        $placeholders = implode(',', array_fill(0, count($allowedBranches), '?'));
        $where[] = 'ct.branch_id IN (' . $placeholders . ')';
        foreach ($allowedBranches as $bid) $params[] = $bid;
    }

    if ($deptFilter > 0) {
        $where[]  = 'c.department_id = ?';
        $params[] = $deptFilter;
    }

    // Filter by a specific assignee (manager or agent). Applied on top of
    // the existing agent-visibility clause so an agent can't see anyone
    // else's conversations even if they pick a name from the dropdown.
    if ($assigneeFilter > 0) {
        $where[]  = 'c.assigned_user_id = ?';
        $params[] = $assigneeFilter;
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

    // Pure time-based sort - matches WhatsApp's native chronological order.
    // Closed conversations still sink to the bottom because they are done,
    // but within the active bucket every row goes newest-message-first
    // regardless of who sent it last. Previously we floated "awaiting reply"
    // rows to the top which surprised users switching from WhatsApp -
    // the amber pill / dot still marks them so urgency stays visible without
    // reshuffling the list.
    $sql = 'SELECT c.*, ct.display_name, ct.profile_name, ct.phone AS contact_phone, ct.wa_id,
                   u.name AS agent_name, d.name AS department_name,
                   ch.name AS channel_name, ch.display_phone AS channel_phone,
                   ' . $awaitingExpr . ' AS awaiting_reply
            FROM conversations c
            INNER JOIN contacts ct ON ct.id = c.contact_id
            LEFT  JOIN users    u  ON u.id  = c.assigned_user_id
            LEFT  JOIN departments d ON d.id = c.department_id
            LEFT  JOIN channels  ch ON ch.id = c.channel_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY (c.status = "closed") ASC,
                     COALESCE(c.last_message_at, c.created_at) DESC
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
           SUM(status = "escalated")                            AS s_escalated,
           SUM(last_customer_message_at IS NOT NULL
               AND (last_message_at IS NULL OR last_message_at <= last_customer_message_at)
               AND status <> "closed")                          AS awaiting,
           SUM(NOT (last_customer_message_at IS NOT NULL
                    AND (last_message_at IS NULL OR last_message_at <= last_customer_message_at))
               AND status <> "closed")                          AS replied
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
 * Render a single inbox row's inner markup.
 *
 * The row uses a WhatsApp-native three-line pattern:
 *   ┌──────────┬──────────────────────────────────┐
 *   │  avatar  │  name                        time│
 *   │  (with   │  preview                  unread │
 *   │  status  │  status · agent · dept · #tags   │
 *   │   dot)   │                                  │
 *   └──────────┴──────────────────────────────────┘
 *
 * Status badge only shown when conversation is NOT open (open = default state,
 * adding a chip for it is noise). The avatar carries a status dot when the
 * customer is waiting for a reply or the conversation is escalated, so the
 * eye picks up urgency before reading any text.
 *
 * Shared between the server-rendered list and the JS-driven poll refresh.
 */
function inbox_row_html(array $c, array $tags): string
{
    $name      = $c['display_name'] ?: $c['profile_name'] ?: $c['wa_id'];
    $initial   = strtoupper(mb_substr($c['display_name'] ?: $c['profile_name'] ?: '?', 0, 1));
    $preview   = mb_strimwidth((string)$c['last_message_text'], 0, 80, '…');
    $time      = relative_time($c['last_message_at'] ?? $c['created_at']);
    $awaiting  = !empty($c['awaiting_reply']);
    $status    = (string)($c['status'] ?? 'open');
    $unread    = (int)($c['unread_count'] ?? 0);
    $agent     = (string)($c['agent_name'] ?? '');
    $dept      = (string)($c['department_name'] ?? '');
    $channel   = (string)($c['channel_name'] ?? '');

    // Pick the dominant accent for the avatar - awaiting beats escalated beats
    // pending so we don't double-up indicators.
    $dot = $awaiting ? 'awaiting' : ($status === 'escalated' ? 'escalated' : ($status === 'pending' ? 'pending' : ''));

    $html  = '<a class="ix-row" '
           . 'data-conv-id="' . (int)$c['id'] . '" '
           . 'data-awaiting="' . ($awaiting ? '1' : '0') . '" '
           . 'data-status="' . e($status) . '" '
           . 'data-unread="' . ($unread > 0 ? '1' : '0') . '" '
           . 'href="/inbox/chat.php?id=' . (int)$c['id'] . '">';

    $html .= '<div class="ix-avatar">';
    $html .=   '<span class="ix-initial">' . e($initial) . '</span>';
    if ($dot !== '') {
        $html .= '<span class="ix-dot ix-dot-' . e($dot) . '" aria-hidden="true"></span>';
    }
    $html .= '</div>';

    $html .= '<div class="ix-body">';

    // Line 1: name + time
    $html .=   '<div class="ix-l1">';
    $html .=     '<span class="ix-name">' . e($name) . '</span>';
    $html .=     '<span class="ix-time">' . e($time) . '</span>';
    $html .=   '</div>';

    // Line 2: preview + unread counter (or awaiting clock if unread = 0 but still awaiting)
    $html .=   '<div class="ix-l2">';
    $html .=     '<span class="ix-preview">' . e($preview) . '</span>';
    if ($unread > 0) {
        $html .=   '<span class="ix-counter ix-counter-unread">' . (int)$unread . '</span>';
    } elseif ($awaiting) {
        $html .=   '<span class="ix-counter ix-counter-awaiting" title="Customer is waiting for a reply">⏰</span>';
    }
    $html .=   '</div>';

    // Line 3: status (non-open only) + assignment + department + tags
    $html .=   '<div class="ix-l3">';
    if ($status !== 'open') {
        $html .= '<span class="ix-pill ix-pill-status ix-pill-' . e($status) . '">' . e(ucfirst($status)) . '</span>';
    }
    if ($awaiting) {
        $html .= '<span class="ix-pill ix-pill-awaiting" title="Customer is waiting for a reply">⏰ Awaiting</span>';
    }
    if ($agent !== '') {
        $html .= '<span class="ix-meta ix-meta-agent">' . e($agent) . '</span>';
    } else {
        $html .= '<span class="ix-meta ix-meta-unassigned">Unassigned</span>';
    }
    if ($dept !== '') {
        $html .= '<span class="ix-meta">' . e($dept) . '</span>';
    }
    if ($channel !== '') {
        $html .= '<span class="ix-meta ix-meta-channel" title="' . e($channel) . '">'
              . e(mb_strimwidth($channel, 0, 14, '…')) . '</span>';
    }
    foreach ($tags as $tg) {
        $html .= '<span class="ix-tag" style="--tag: ' . e($tg['color']) . '">' . e($tg['name']) . '</span>';
    }
    $html .=   '</div>';

    $html .= '</div></a>';
    return $html;
}
