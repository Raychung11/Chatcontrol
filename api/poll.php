<?php
/**
 * GET /api/poll.php  - lightweight live-refresh endpoint.
 *
 * scope=inbox  : &filter=&q=&department_id=&tag_id=
 *   -> { ok, rows_html, counts, server_time }
 *
 * scope=chat   : &conversation_id=&after_id=
 *   -> { ok, messages_html, last_msg_id, status, unread_count,
 *        window_open, statuses:{id:status}, new_inbound:bool }
 *
 * Read-only. No state changes (so it is safe to poll frequently).
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/inbox_query.php';
require_once __DIR__ . '/../inc/chat_render.php';
require_once __DIR__ . '/../inc/provider.php';

$user = require_login();
$db   = aiserve_db();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$scope = (string)($_GET['scope'] ?? '');

if ($scope === 'inbox') {
    $filter         = (string)($_GET['filter'] ?? 'all');
    $search         = trim((string)($_GET['q'] ?? ''));
    $deptFilter     = (int)($_GET['department_id'] ?? 0);
    $tagFilter      = (int)($_GET['tag_id']        ?? 0);
    $assigneeFilter = (int)($_GET['assignee_id']   ?? 0);

    $data = inbox_fetch($db, $user, $filter, $search, $deptFilter, $tagFilter, $assigneeFilter);

    $rowsHtml = '';
    if (!$data['conversations']) {
        $rowsHtml = '<div class="empty-state">No conversations match this view.</div>';
    } else {
        foreach ($data['conversations'] as $c) {
            $rowsHtml .= inbox_row_html($c, $data['tags_by_conv'][(int)$c['id']] ?? []);
        }
    }

    json_response([
        'ok'          => true,
        'rows_html'   => $rowsHtml,
        'counts'      => array_map('intval', $data['counts'] ?: []),
        'server_time' => time(),
    ]);
}

if ($scope === 'chat') {
    $conversationId = (int)($_GET['conversation_id'] ?? 0);
    $afterId        = (int)($_GET['after_id'] ?? 0);
    if ($conversationId <= 0) {
        json_response(['ok' => false, 'error' => 'conversation_id required.'], 400);
    }

    $stmt = $db->prepare(
        'SELECT c.*, ct.wa_id, ct.display_name
         FROM conversations c
         INNER JOIN contacts ct ON ct.id = c.contact_id
         WHERE c.id = ? AND c.company_id = ? LIMIT 1'
    );
    $stmt->execute([$conversationId, (int)$user['company_id']]);
    $conv = $stmt->fetch();
    if (!$conv) {
        json_response(['ok' => false, 'error' => 'Not found.'], 404);
    }
    if (!user_can_view_conversation($user, $conv)) {
        json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
    }

    // New messages since after_id — skip soft-deleted (phase 52).
    $mstmt = $db->prepare(
        'SELECT m.*, u.name AS sender_name
         FROM messages m
         LEFT JOIN users u ON u.id = m.sender_user_id
         WHERE m.conversation_id = ? AND m.id > ?
           AND m.deleted_at IS NULL
         ORDER BY m.id ASC LIMIT 100'
    );
    $mstmt->execute([$conversationId, $afterId]);
    $newMessages = $mstmt->fetchAll();

    $html       = '';
    $lastId     = $afterId;
    $newInbound = false;
    foreach ($newMessages as $m) {
        $html  .= message_bubble_html($m);
        $lastId = max($lastId, (int)$m['id']);
        if ($m['direction'] === 'incoming') {
            $newInbound = true;
        }
    }

    // Status map for recent outgoing messages so delivery ticks update live
    $sstmt = $db->prepare(
        'SELECT id, status FROM messages
         WHERE conversation_id = ? AND direction = "outgoing"
         ORDER BY id DESC LIMIT 30'
    );
    $sstmt->execute([$conversationId]);
    $statuses = [];
    foreach ($sstmt->fetchAll() as $row) {
        $statuses[(int)$row['id']] = $row['status'];
    }

    $company = load_company_settings((int)$user['company_id']) ?: [];
    // Match inbox/chat.php: read the CHANNEL's provider, not the stale
    // companies.provider column. Otherwise aiserve_chatbot channels
    // wrongly get flagged as needing the Meta 24-hour reply window.
    require_once __DIR__ . '/../inc/channels.php';
    $chatChannel = channel_for_conversation($conv);
    $providerCtx = $chatChannel ?: $company;
    $windowOpen = !provider_enforces_24h_window($providerCtx)
        || is_within_service_window($conv['service_window_expires_at']);

    json_response([
        'ok'            => true,
        'messages_html' => $html,
        'last_msg_id'   => $lastId,
        'status'        => $conv['status'],
        'unread_count'  => (int)$conv['unread_count'],
        'window_open'   => $windowOpen,
        'statuses'      => $statuses,
        'new_inbound'   => $newInbound,
        'server_time'   => time(),
    ]);
}

json_response(['ok' => false, 'error' => 'Unknown scope.'], 400);
