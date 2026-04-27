<?php
/**
 * POST /api/conversation_action.php
 * Conversation management endpoint - assign, reassign, change department,
 * change status (open/pending/closed/escalated), add internal note.
 */

require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

$action         = (string)($_POST['action']           ?? '');
$conversationId = (int)   ($_POST['conversation_id']  ?? 0);

if ($conversationId <= 0 || $action === '') {
    json_response(['ok' => false, 'error' => 'action and conversation_id required.'], 400);
}

$db   = aiserve_db();
$stmt = $db->prepare('SELECT * FROM conversations WHERE id = ? AND company_id = ? LIMIT 1');
$stmt->execute([$conversationId, (int)$user['company_id']]);
$conv = $stmt->fetch();
if (!$conv) {
    json_response(['ok' => false, 'error' => 'Conversation not found.'], 404);
}
if (!user_can_view_conversation($user, $conv)) {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

switch ($action) {

    case 'assign':
        // Assign / reassign / unassign
        $assignTo = $_POST['assigned_user_id'] ?? '';
        $assignTo = ($assignTo === '' || $assignTo === '0') ? null : (int)$assignTo;

        // Agents can self-assign an unassigned conversation in their dept;
        // only managers/admins can assign to others.
        if (!user_can_assign($user)) {
            if ($assignTo !== (int)$user['id']) {
                json_response(['ok' => false, 'error' => 'Agents can only self-assign.'], 403);
            }
            if (!empty($conv['assigned_user_id']) && (int)$conv['assigned_user_id'] !== (int)$user['id']) {
                json_response(['ok' => false, 'error' => 'Conversation already assigned.'], 403);
            }
        }

        if ($assignTo) {
            $check = $db->prepare(
                'SELECT id FROM users WHERE id = ? AND company_id = ? AND status = "active" LIMIT 1'
            );
            $check->execute([$assignTo, (int)$user['company_id']]);
            if (!$check->fetchColumn()) {
                json_response(['ok' => false, 'error' => 'Target user invalid.'], 400);
            }
        }

        $upd = $db->prepare('UPDATE conversations SET assigned_user_id = ? WHERE id = ?');
        $upd->execute([$assignTo, $conversationId]);

        log_activity((int)$user['company_id'], (int)$user['id'], 'conversation_assigned',
            'conversation', $conversationId, 'Assigned to user_id=' . ($assignTo ?? 'null'));
        json_response(['ok' => true]);
        break;

    case 'change_department':
        if (!user_can_assign($user)) {
            json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
        }
        $deptId = $_POST['department_id'] ?? '';
        $deptId = ($deptId === '' || $deptId === '0') ? null : (int)$deptId;
        if ($deptId) {
            $check = $db->prepare('SELECT id FROM departments WHERE id = ? AND company_id = ? LIMIT 1');
            $check->execute([$deptId, (int)$user['company_id']]);
            if (!$check->fetchColumn()) {
                json_response(['ok' => false, 'error' => 'Department invalid.'], 400);
            }
        }
        $upd = $db->prepare('UPDATE conversations SET department_id = ? WHERE id = ?');
        $upd->execute([$deptId, $conversationId]);
        log_activity((int)$user['company_id'], (int)$user['id'], 'conversation_department_changed',
            'conversation', $conversationId, 'Department -> ' . ($deptId ?? 'null'));
        json_response(['ok' => true]);
        break;

    case 'change_status':
        $newStatus = (string)($_POST['status'] ?? '');
        if (!in_array($newStatus, ['open', 'pending', 'closed', 'escalated'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid status.'], 400);
        }
        $upd = $db->prepare('UPDATE conversations SET status = ? WHERE id = ?');
        $upd->execute([$newStatus, $conversationId]);
        log_activity((int)$user['company_id'], (int)$user['id'], 'conversation_status_changed',
            'conversation', $conversationId, 'Status -> ' . $newStatus);
        json_response(['ok' => true]);
        break;

    case 'add_note':
        $noteText = trim((string)($_POST['note_text'] ?? ''));
        if ($noteText === '') {
            json_response(['ok' => false, 'error' => 'Note text required.'], 400);
        }
        $ins = $db->prepare(
            'INSERT INTO internal_notes (company_id, conversation_id, user_id, note_text)
             VALUES (?, ?, ?, ?)'
        );
        $ins->execute([(int)$user['company_id'], $conversationId, (int)$user['id'], $noteText]);
        log_activity((int)$user['company_id'], (int)$user['id'], 'note_added',
            'conversation', $conversationId, mb_substr($noteText, 0, 200));
        json_response(['ok' => true, 'note_id' => (int)$db->lastInsertId()]);
        break;

    case 'mark_read':
        $upd = $db->prepare('UPDATE conversations SET unread_count = 0 WHERE id = ?');
        $upd->execute([$conversationId]);
        json_response(['ok' => true]);
        break;

    default:
        json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}
