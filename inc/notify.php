<?php
/**
 * inc/notify.php — one place to fan out "new inbound message" notifications.
 *
 * Every path that inserts an incoming customer message
 * (webhook/evolution.php, webhook/whatsapp.php, api/widget_send.php,
 * webhook/instagram.php, webhook/facebook.php, …) calls
 * notify_new_inbound() with the conversation + message ids. This
 * helper decides who to notify, dedupes, suppresses "already looking
 * at this chat", and falls back to email when push isn't set up.
 *
 * Routing rules:
 *
 *   ASSIGNED conversation
 *     - Push to the assigned agent (with viewer-suppression + 60s
 *       per-conversation rate limit).
 *     - If the agent has NO active push subscriptions, send an email
 *       instead (with a 15-min per-conversation rate limit so a
 *       burst customer doesn't spam the inbox).
 *
 *   UNASSIGNED conversation
 *     - Push fan-out to every active workspace agent. If the
 *       conversation has a department_id, restrict to that
 *       department's members plus super_admins. Same viewer-
 *       suppression and per-user rate limit as the assigned path.
 *     - No email fallout on fan-out — a message to 8 agents' inboxes
 *       for every unassigned inbound would be inbox spam. Assigned-
 *       only email keeps the signal high.
 *
 * All failure modes are logged and swallowed — inbound message
 * ingest must never fail because notification failed.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/email.php';

// -----------------------------------------------------------------
// Public entry point
// -----------------------------------------------------------------

function notify_new_inbound(int $conversationId, int $messageId): void
{
    if ($conversationId <= 0 || $messageId <= 0) return;

    try {
        $db = aiserve_db();
        $stmt = $db->prepare(
            'SELECT c.id, c.company_id, c.assigned_user_id, c.department_id,
                    m.message_text, m.message_type,
                    ct.display_name, ct.wa_id,
                    co.name AS workspace_name
             FROM conversations c
             INNER JOIN messages m  ON m.id = ?
             INNER JOIN contacts ct ON ct.id = c.contact_id
             INNER JOIN companies co ON co.id = c.company_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$messageId, $conversationId]);
        $r = $stmt->fetch();
        if (!$r) return;

        $assignedUserId = (int)($r['assigned_user_id'] ?? 0);

        if ($assignedUserId > 0) {
            // Assigned path — the owner gets a personal push, or email
            // if they have no working push subscription.
            notify_dispatch_to_user($assignedUserId, $r, ['can_email' => true]);
        } else {
            // Unassigned — fan out to eligible pool. Email fallback is
            // OFF here to keep the inbox from turning into a play-by-play.
            $eligible = notify_eligible_agents(
                (int)$r['company_id'],
                (int)($r['department_id'] ?? 0)
            );
            foreach ($eligible as $userId) {
                notify_dispatch_to_user((int)$userId, $r, ['can_email' => false]);
            }
        }
    } catch (Throwable $e) {
        error_log('[AiServe notify_new_inbound] ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------
// Per-user dispatch — push first, email fallback second
// -----------------------------------------------------------------

function notify_dispatch_to_user(int $userId, array $r, array $opts): void
{
    $conversationId = (int)$r['id'];

    // 1. Viewer suppression. If the agent's chat page is polling this
    //    conversation right now (viewing_at within the last 30s), they
    //    already see the new message stream in — no need to nag them
    //    with a lock-screen entry. Mirrors WhatsApp Web's behavior.
    if (notify_user_viewing($userId, $conversationId)) return;

    // 2. Per-conversation rate limit on push. A customer's burst of 6
    //    messages in 20 seconds shouldn't fire 6 lock-screen entries.
    //    The SW's per-conversation `tag` collapses them visually, but
    //    the push service still burns 6 deliveries — the rate limit
    //    stops us before that.
    if (notify_recently_dispatched($userId, $conversationId, 'push', 60)) return;

    // 3. Push attempt.
    $subsCount = notify_active_subscription_count($userId);
    if ($subsCount > 0) {
        $preview = notify_message_preview((string)$r['message_type'], (string)$r['message_text']);
        $ok = push_send_to_user($userId, [
            'title' => notify_title($r),
            'body'  => $preview,
            'url'   => '/inbox/chat.php?id=' . $conversationId,
            'tag'   => 'conv-' . $conversationId,
        ]);
        if ($ok > 0) {
            notify_log_dispatch($userId, $conversationId, 'push');
            return;
        }
        // Every subscription failed (endpoints all 404/410 and got
        // pruned). Fall through to email if allowed.
    }

    // 4. Email fallback — assigned agents only.
    if (empty($opts['can_email'])) return;
    if (notify_recently_dispatched($userId, $conversationId, 'email', 900)) return;
    if (notify_send_email_fallback($userId, $r)) {
        notify_log_dispatch($userId, $conversationId, 'email');
    }
}

// -----------------------------------------------------------------
// Eligible-agent resolver (unassigned fan-out)
// -----------------------------------------------------------------

function notify_eligible_agents(int $companyId, int $departmentId): array
{
    $db = aiserve_db();

    // Two-layer rule: a department-scoped conversation targets the
    // department's members plus super_admins (who see everything).
    // A conversation with no department targets every active workspace
    // agent, plus super_admins (already covered by role IN).
    if ($departmentId > 0) {
        $stmt = $db->prepare(
            "SELECT id FROM users
             WHERE company_id = ? AND status = 'active'
               AND role IN ('super_admin','manager','agent')
               AND (role = 'super_admin' OR department_id = ?)"
        );
        $stmt->execute([$companyId, $departmentId]);
    } else {
        $stmt = $db->prepare(
            "SELECT id FROM users
             WHERE company_id = ? AND status = 'active'
               AND role IN ('super_admin','manager','agent')"
        );
        $stmt->execute([$companyId]);
    }
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// -----------------------------------------------------------------
// Viewer heartbeat (updated by api/poll.php scope=chat)
// -----------------------------------------------------------------

function notify_mark_viewing(int $userId, int $conversationId): void
{
    if ($userId <= 0) return;
    aiserve_db()->prepare(
        'UPDATE users SET viewing_conversation_id = ?, viewing_at = NOW()
         WHERE id = ? LIMIT 1'
    )->execute([$conversationId > 0 ? $conversationId : null, $userId]);
}

function notify_user_viewing(int $userId, int $conversationId): bool
{
    if ($userId <= 0 || $conversationId <= 0) return false;
    // 30-second freshness window: the chat poll fires every 5s, so a
    // real viewer's heartbeat stays comfortably fresh. If they closed
    // the tab or navigated away, the timestamp goes stale and pushes
    // resume.
    $stmt = aiserve_db()->prepare(
        'SELECT 1 FROM users
         WHERE id = ? AND viewing_conversation_id = ?
           AND viewing_at > (NOW() - INTERVAL 30 SECOND)
         LIMIT 1'
    );
    $stmt->execute([$userId, $conversationId]);
    return (bool)$stmt->fetchColumn();
}

// -----------------------------------------------------------------
// Rate-limit + dispatch bookkeeping
// -----------------------------------------------------------------

function notify_recently_dispatched(int $userId, int $conversationId, string $kind, int $windowSec): bool
{
    $stmt = aiserve_db()->prepare(
        'SELECT 1 FROM notification_dispatch_log
         WHERE user_id = ? AND conversation_id = ? AND kind = ?
           AND sent_at > (NOW() - INTERVAL ? SECOND)
         LIMIT 1'
    );
    $stmt->execute([$userId, $conversationId, $kind, $windowSec]);
    return (bool)$stmt->fetchColumn();
}

function notify_log_dispatch(int $userId, int $conversationId, string $kind): void
{
    aiserve_db()->prepare(
        'INSERT INTO notification_dispatch_log (user_id, conversation_id, kind)
         VALUES (?, ?, ?)'
    )->execute([$userId, $conversationId, $kind]);
}

function notify_active_subscription_count(int $userId): int
{
    $stmt = aiserve_db()->prepare(
        'SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

// -----------------------------------------------------------------
// Email fallback body — short, actionable, links straight in
// -----------------------------------------------------------------

function notify_send_email_fallback(int $userId, array $r): bool
{
    $db = aiserve_db();
    $u = $db->prepare('SELECT email, name FROM users WHERE id = ? LIMIT 1');
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user || empty($user['email'])
        || !filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $sender  = trim((string)($r['display_name'] ?? '')) ?: (string)$r['wa_id'];
    $preview = notify_message_preview((string)$r['message_type'], (string)$r['message_text']);
    $base    = defined('APP_BASE_URL') && APP_BASE_URL !== ''
        ? rtrim((string)APP_BASE_URL, '/')
        : 'https://inbox.aiserve.my';
    $link    = $base . '/inbox/chat.php?id=' . (int)$r['id'];

    $subject = '💬 ' . $sender . ' — ' . mb_substr($preview, 0, 60);
    $body    = "New WhatsApp message from " . $sender . ".\n\n"
             . $preview . "\n\n"
             . "Reply here: " . $link . "\n\n"
             . "— " . (string)$r['workspace_name'] . " (AiServe Inbox)\n\n"
             . "You're getting this email because push notifications aren't "
             . "set up on any of your devices yet. To switch to instant "
             . "phone alerts, open the portal on your phone, tap the 🔔 "
             . "bell, and allow notifications.";

    try {
        return (bool)send_email((string)$user['email'], $subject, $body, 'AiServe Inbox');
    } catch (Throwable $e) {
        error_log('[AiServe notify email fallback] ' . $e->getMessage());
        return false;
    }
}

// -----------------------------------------------------------------
// Formatting helpers
// -----------------------------------------------------------------

function notify_title(array $r): string
{
    $sender    = trim((string)($r['display_name'] ?? '')) ?: (string)$r['wa_id'];
    $workspace = trim((string)($r['workspace_name'] ?? ''));
    return $workspace !== '' ? ($sender . ' · ' . $workspace) : $sender;
}

function notify_message_preview(string $type, string $text): string
{
    $text = trim($text);
    switch ($type) {
        case 'audio':    return '🎙 Voice note';
        case 'image':    return '📷 Photo'    . ($text !== '' ? ' — ' . mb_substr($text, 0, 120) : '');
        case 'video':    return '🎞 Video'    . ($text !== '' ? ' — ' . mb_substr($text, 0, 120) : '');
        case 'document': return '📎 Document' . ($text !== '' ? ' — ' . mb_substr($text, 0, 120) : '');
        case 'location': return '📍 Location';
        case 'sticker':  return '🖼 Sticker';
        default:         return $text !== '' ? mb_substr($text, 0, 160) : '(new message)';
    }
}
