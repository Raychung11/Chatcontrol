<?php
/**
 * WhatsApp Cloud API Webhook
 *
 * GET  /webhook/whatsapp.php  - Meta verification challenge.
 * POST /webhook/whatsapp.php  - Inbound messages, statuses, etc.
 *
 * Configure in Meta App Dashboard:
 *   Callback URL = https://your-domain/webhook/whatsapp.php
 *   Verify Token = same value as companies.webhook_verify_token
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/whatsapp_api.php';

header('Cache-Control: no-store');

// Multi-tenant: ?company=<slug> picks the tenant. Falls back to
// ACTIVE_COMPANY_ID for legacy single-tenant deployments.
$company = resolve_company_for_webhook();
if (!$company) {
    http_response_code(404);
    error_log('[AiServe webhook] Unknown tenant: company=' . ($_GET['company'] ?? '(none)'));
    exit('Unknown tenant.');
}

// -------------------- GET verification --------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';
    $expected  = (string)($company['webhook_verify_token'] ?? '');

    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, (string)$token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('Verification failed.');
}

// -------------------- POST inbound --------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);

$messageCount = 0;
$statusCount  = 0;
$errorText    = null;
$httpStatus   = 200;

if (!is_array($payload)) {
    $httpStatus = 400;
    $errorText  = 'Invalid JSON payload';
    error_log('[AiServe webhook] ' . $errorText);
} else {
    // Pre-count for diagnostics (even if processing throws, we want this in the log)
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? [];
            $messageCount += isset($value['messages']) && is_array($value['messages']) ? count($value['messages']) : 0;
            $statusCount  += isset($value['statuses']) && is_array($value['statuses']) ? count($value['statuses']) : 0;
        }
    }

    try {
        process_webhook_payload($company, $payload, $rawBody);
    } catch (Throwable $e) {
        $errorText = mb_substr('Exception: ' . $e->getMessage(), 0, 500);
        error_log('[AiServe webhook] ' . $errorText);
    }
}

// Log every POST attempt for diagnostics (best-effort - never block the webhook).
try {
    $logBody = mb_substr($rawBody, 0, 65000);
    $logStmt = aiserve_db()->prepare(
        'INSERT INTO webhook_events
            (company_id, method, http_status, message_count, status_count, error_text, raw_body, ip_address)
         VALUES (?, "POST", ?, ?, ?, ?, ?, ?)'
    );
    $logStmt->execute([
        (int)$company['id'], $httpStatus, $messageCount, $statusCount, $errorText, $logBody, client_ip(),
    ]);
} catch (Throwable $e) {
    error_log('[AiServe webhook] webhook_events insert failed: ' . $e->getMessage());
}

http_response_code($httpStatus);
echo $httpStatus === 200 ? 'EVENT_RECEIVED' : 'BAD_REQUEST';

// =============================================================
// Functions
// =============================================================

function process_webhook_payload(array $company, array $payload, string $raw): void
{
    if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
        error_log('[AiServe webhook] Unexpected object: ' . ($payload['object'] ?? ''));
        return;
    }

    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? [];
            if (!empty($value['messages'])) {
                foreach ($value['messages'] as $msg) {
                    handle_incoming_message($company, $value, $msg, $raw);
                }
            }
            if (!empty($value['statuses'])) {
                foreach ($value['statuses'] as $st) {
                    handle_status_update($company, $st);
                }
            }
        }
    }
}

function handle_incoming_message(array $company, array $value, array $msg, string $raw): void
{
    $waMessageId = (string)($msg['id'] ?? '');
    if ($waMessageId === '') {
        return;
    }

    $db = aiserve_db();

    // De-dupe: skip if we've already stored this wa_message_id.
    $check = $db->prepare('SELECT id FROM messages WHERE wa_message_id = ? LIMIT 1');
    $check->execute([$waMessageId]);
    if ($check->fetchColumn()) {
        return;
    }

    $waId      = (string)($msg['from'] ?? '');
    $msgType   = (string)($msg['type'] ?? 'text');
    $timestamp = isset($msg['timestamp']) ? (int)$msg['timestamp'] : time();

    // Try to extract a profile name from contacts array
    $profileName = null;
    foreach (($value['contacts'] ?? []) as $c) {
        if (($c['wa_id'] ?? '') === $waId) {
            $profileName = $c['profile']['name'] ?? null;
            break;
        }
    }

    // Decode body / media metadata by message type
    $body          = null;
    $mediaUrl      = null;
    $mediaMime     = null;
    $mediaFilename = null;
    $mediaId       = null;

    switch ($msgType) {
        case 'text':
            $body = $msg['text']['body'] ?? '';
            break;
        case 'image':
        case 'video':
        case 'audio':
        case 'document':
        case 'sticker':
            $mediaMime     = $msg[$msgType]['mime_type'] ?? null;
            $mediaFilename = $msg[$msgType]['filename'] ?? null;
            $mediaId       = $msg[$msgType]['id']        ?? null;
            $body          = $msg[$msgType]['caption']
                          ?? $mediaFilename
                          ?? '[' . $msgType . ']';
            break;
        case 'location':
            $lat = $msg['location']['latitude']  ?? null;
            $lng = $msg['location']['longitude'] ?? null;
            $body = '[location] ' . $lat . ', ' . $lng;
            break;
        case 'contacts':
            $body = '[contacts shared]';
            break;
        case 'interactive':
            $iType = $msg['interactive']['type'] ?? '';
            if ($iType === 'button_reply') {
                $body = $msg['interactive']['button_reply']['title'] ?? '[button reply]';
            } elseif ($iType === 'list_reply') {
                $body = $msg['interactive']['list_reply']['title']  ?? '[list reply]';
            } else {
                $body = '[interactive]';
            }
            break;
        case 'button':
            $body = $msg['button']['text'] ?? '[button]';
            break;
        default:
            $body = '[' . $msgType . ']';
            break;
    }

    $companyId = (int)$company['id'];

    // Find or create contact
    $stmt = $db->prepare('SELECT * FROM contacts WHERE company_id = ? AND wa_id = ? LIMIT 1');
    $stmt->execute([$companyId, $waId]);
    $contact = $stmt->fetch();
    if (!$contact) {
        $ins = $db->prepare(
            'INSERT INTO contacts (company_id, wa_id, phone, profile_name, display_name, last_message_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([
            $companyId, $waId, $waId,
            $profileName, $profileName ?: $waId,
        ]);
        $contactId = (int)$db->lastInsertId();
    } else {
        $contactId = (int)$contact['id'];
        $upd = $db->prepare(
            'UPDATE contacts
             SET profile_name = COALESCE(?, profile_name),
                 display_name = COALESCE(NULLIF(display_name,""), ?, wa_id),
                 last_message_at = NOW()
             WHERE id = ?'
        );
        $upd->execute([$profileName, $profileName, $contactId]);
    }

    // Find or create active conversation (open or pending)
    $stmt = $db->prepare(
        'SELECT * FROM conversations
         WHERE company_id = ? AND contact_id = ? AND status IN ("open","pending","escalated")
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$companyId, $contactId]);
    $conv = $stmt->fetch();

    $previewText  = mb_substr((string)$body, 0, 500);
    $messageDate  = date('Y-m-d H:i:s', $timestamp);
    $expiryDate   = date('Y-m-d H:i:s', $timestamp + 24 * 3600);

    if (!$conv) {
        // Auto-routing: pick department + (optionally) assigned agent
        $route = apply_routing_rules($companyId, $body);
        $ins = $db->prepare(
            'INSERT INTO conversations
                (company_id, contact_id, department_id, assigned_user_id, status,
                 last_message_text, last_message_at,
                 last_customer_message_at, service_window_expires_at, unread_count)
             VALUES (?, ?, ?, ?, "open", ?, ?, ?, ?, 1)'
        );
        $ins->execute([
            $companyId, $contactId,
            $route['department_id'], $route['assigned_user_id'],
            $previewText, $messageDate, $messageDate, $expiryDate,
        ]);
        $conversationId = (int)$db->lastInsertId();
        if ($route['department_id'] || $route['assigned_user_id']) {
            log_activity($companyId, null, 'conversation_auto_routed',
                'conversation', $conversationId,
                'dept=' . ($route['department_id'] ?? 'null')
                . ' agent=' . ($route['assigned_user_id'] ?? 'null'));
        }
    } else {
        $conversationId = (int)$conv['id'];
        $newStatus = ($conv['status'] === 'closed') ? 'open' : $conv['status'];
        $upd = $db->prepare(
            'UPDATE conversations
             SET status = ?,
                 last_message_text = ?,
                 last_message_at = ?,
                 last_customer_message_at = ?,
                 service_window_expires_at = ?,
                 unread_count = unread_count + 1
             WHERE id = ?'
        );
        $upd->execute([$newStatus, $previewText, $messageDate, $messageDate, $expiryDate, $conversationId]);
    }

    // Insert message (de-duped on wa_message_id unique key)
    try {
        $ins = $db->prepare(
            'INSERT INTO messages
                (company_id, conversation_id, contact_id, sender_type, wa_message_id, direction,
                 message_type, message_text, media_mime_type, media_filename, media_id, raw_payload, status, created_at)
             VALUES (?, ?, ?, "customer", ?, "incoming", ?, ?, ?, ?, ?, ?, "received", ?)'
        );
        $ins->execute([
            $companyId, $conversationId, $contactId,
            $waMessageId, $msgType, $body,
            $mediaMime, $mediaFilename, $mediaId, $raw,
            $messageDate,
        ]);
        $msgRowId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('[AiServe webhook] insert message failed: ' . $e->getMessage());
        return;
    }

    // Best-effort inbound media download (synchronous; small files, short timeout).
    if ($mediaId && in_array($msgType, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
        try {
            $destDir = __DIR__ . '/../uploads/' . $companyId . '/inbound';
            $dl = whatsapp_download_media($company, (string)$mediaId, $destDir);
            if ($dl['ok']) {
                $u = $db->prepare(
                    'UPDATE messages SET media_local_path = ?, media_mime_type = COALESCE(?, media_mime_type) WHERE id = ?'
                );
                $u->execute([$dl['local_path'], $dl['mime_type'] ?? null, $msgRowId]);
            } else {
                error_log('[AiServe webhook] media download failed: ' . ($dl['error'] ?? 'unknown'));
            }
        } catch (Throwable $e) {
            error_log('[AiServe webhook] media download exception: ' . $e->getMessage());
        }
    }

    log_activity($companyId, null, 'message_received', 'conversation', $conversationId,
        'Inbound ' . $msgType . ' from ' . $waId);
}

function handle_status_update(array $company, array $status): void
{
    $waId       = (string)($status['id']     ?? '');
    $statusName = (string)($status['status'] ?? '');
    if ($waId === '' || $statusName === '') {
        return;
    }
    // Map Meta statuses to our enum
    $map = [
        'sent'      => 'sent',
        'delivered' => 'delivered',
        'read'      => 'read',
        'failed'    => 'failed',
    ];
    if (!isset($map[$statusName])) {
        return;
    }
    $col = match ($statusName) {
        'sent'      => 'sent_at',
        'delivered' => 'delivered_at',
        'read'      => 'read_at',
        default     => null,
    };

    $db = aiserve_db();
    if ($statusName === 'failed') {
        $err = $status['errors'][0]['title']
            ?? $status['errors'][0]['message']
            ?? 'Failed';
        $stmt = $db->prepare('UPDATE messages SET status = "failed", error_message = ? WHERE wa_message_id = ?');
        $stmt->execute([$err, $waId]);
        return;
    }

    if ($col) {
        $sql = 'UPDATE messages SET status = ?, ' . $col . ' = NOW() WHERE wa_message_id = ?';
    } else {
        $sql = 'UPDATE messages SET status = ? WHERE wa_message_id = ?';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$map[$statusName], $waId]);
}
