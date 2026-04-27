<?php
/**
 * Evolution API webhook receiver.
 *
 * Configure in Evolution: webhook URL = https://your-portal/webhook/evolution.php
 *
 * Authenticated via two layers (one is enough):
 *   - apikey header (Evolution forwards your instance's apikey)
 *   - ?token=<webhook_verify_token> query param (matches companies.webhook_verify_token)
 *
 * Maps the following Evolution events into the same messages/conversations
 * rows our Meta webhook writes:
 *   MESSAGES_UPSERT     -> incoming customer message OR our own outgoing send
 *   MESSAGES_UPDATE     -> delivery / read status update
 *   CONNECTION_UPDATE   -> updates companies.evolution_status
 */

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/whatsapp_api.php'; // apply_routing_rules() lives here
require_once __DIR__ . '/../inc/evolution_api.php';

header('Cache-Control: no-store');

$company = (function (): ?array {
    $stmt = aiserve_db()->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $stmt->execute([ACTIVE_COMPANY_ID]);
    return $stmt->fetch() ?: null;
})();

if (!$company) {
    http_response_code(500);
    error_log('[AiServe evolution] No company configured for id=' . ACTIVE_COMPANY_ID);
    exit('Webhook misconfigured.');
}

// Auth - either matching apikey header or matching ?token query
$incomingApiKey = $_SERVER['HTTP_APIKEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
$incomingToken  = $_GET['token']           ?? '';
$expectedToken  = (string)($company['webhook_verify_token'] ?? '');
$expectedKey    = (string)($company['evolution_api_key']    ?? '');

$authOk = false;
if ($expectedToken !== '' && hash_equals($expectedToken, (string)$incomingToken)) {
    $authOk = true;
}
if (!$authOk && $expectedKey !== '' && hash_equals($expectedKey, (string)$incomingApiKey)) {
    $authOk = true;
}
if (!$authOk) {
    http_response_code(401);
    error_log('[AiServe evolution] Unauthorized webhook call from ' . client_ip());
    exit('Unauthorized.');
}

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
    error_log('[AiServe evolution] ' . $errorText);
} else {
    try {
        $event = strtoupper((string)($payload['event'] ?? ''));
        switch ($event) {
            case 'MESSAGES_UPSERT':
                $rows = evolution_extract_messages($payload);
                foreach ($rows as $msg) {
                    if (handle_evolution_message($company, $msg, $rawBody)) {
                        $messageCount++;
                    }
                }
                break;

            case 'MESSAGES_UPDATE':
                $rows = evolution_extract_messages($payload);
                foreach ($rows as $upd) {
                    handle_evolution_status($company, $upd);
                    $statusCount++;
                }
                break;

            case 'CONNECTION_UPDATE':
                handle_evolution_connection($company, $payload);
                break;

            case 'SEND_MESSAGE':
                // Our own send acknowledged - usually we already wrote the row in /api/send_*.php
                // No-op here.
                break;

            default:
                // Unknown event - log but accept.
                break;
        }
    } catch (Throwable $e) {
        $errorText = mb_substr('Exception: ' . $e->getMessage(), 0, 500);
        error_log('[AiServe evolution] ' . $errorText);
    }
}

// Same diagnostic logging as the Meta webhook
try {
    $logBody = mb_substr($rawBody, 0, 65000);
    $logStmt = aiserve_db()->prepare(
        'INSERT INTO webhook_events
            (company_id, method, http_status, message_count, status_count, error_text, raw_body, ip_address)
         VALUES (?, "POST-EVO", ?, ?, ?, ?, ?, ?)'
    );
    $logStmt->execute([
        (int)$company['id'], $httpStatus, $messageCount, $statusCount, $errorText, $logBody, client_ip(),
    ]);
} catch (Throwable $e) {
    error_log('[AiServe evolution] webhook_events insert failed: ' . $e->getMessage());
}

http_response_code($httpStatus);
echo $httpStatus === 200 ? 'OK' : 'BAD_REQUEST';

// =============================================================
// Functions
// =============================================================

/**
 * Evolution sometimes wraps a single object in `data` and sometimes sends
 * an array. Normalize to a list.
 */
function evolution_extract_messages(array $payload): array
{
    $data = $payload['data'] ?? null;
    if ($data === null) return [];
    return array_is_list($data) ? $data : [$data];
}

/**
 * @return bool true if the row ended up persisted (incoming customer msg).
 */
function handle_evolution_message(array $company, array $msg, string $raw): bool
{
    $key       = $msg['key']    ?? [];
    $remoteJid = (string)($key['remoteJid'] ?? '');
    $waMsgId   = (string)($key['id']        ?? '');
    $fromMe    = (bool)  ($key['fromMe']    ?? false);
    if ($remoteJid === '' || $waMsgId === '') return false;

    // Skip group chats for now - portal is 1:1 customer support
    if (str_ends_with($remoteJid, '@g.us')) {
        return false;
    }

    $waId = explode('@', $remoteJid)[0];

    // If this is our own outgoing message echoed back, we've already inserted
    // the row in /api/send_message.php. Skip.
    if ($fromMe) {
        return false;
    }

    $db = aiserve_db();

    // Dedupe
    $check = $db->prepare('SELECT id FROM messages WHERE wa_message_id = ? LIMIT 1');
    $check->execute([$waMsgId]);
    if ($check->fetchColumn()) {
        return false;
    }

    // Decode message body / media
    $messageType = (string)($msg['messageType'] ?? 'unknown');
    $message     = $msg['message'] ?? [];
    $body        = null;
    $mediaMime   = null;
    $mediaName   = null;
    $mediaBase64 = null;
    $kind        = 'text';

    if (!empty($message['conversation'])) {
        $kind = 'text';
        $body = (string)$message['conversation'];
    } elseif (!empty($message['extendedTextMessage']['text'])) {
        $kind = 'text';
        $body = (string)$message['extendedTextMessage']['text'];
    } elseif (!empty($message['imageMessage'])) {
        $kind        = 'image';
        $mediaMime   = $message['imageMessage']['mimetype'] ?? 'image/jpeg';
        $body        = $message['imageMessage']['caption'] ?? '[image]';
        $mediaBase64 = $message['base64'] ?? null;
    } elseif (!empty($message['videoMessage'])) {
        $kind        = 'video';
        $mediaMime   = $message['videoMessage']['mimetype'] ?? 'video/mp4';
        $body        = $message['videoMessage']['caption'] ?? '[video]';
        $mediaBase64 = $message['base64'] ?? null;
    } elseif (!empty($message['audioMessage'])) {
        $kind        = 'audio';
        $mediaMime   = $message['audioMessage']['mimetype'] ?? 'audio/ogg';
        $body        = '[audio]';
        $mediaBase64 = $message['base64'] ?? null;
    } elseif (!empty($message['documentMessage'])) {
        $kind        = 'document';
        $mediaMime   = $message['documentMessage']['mimetype'] ?? 'application/octet-stream';
        $mediaName   = $message['documentMessage']['fileName']  ?? null;
        $body        = $mediaName ?: '[document]';
        $mediaBase64 = $message['base64'] ?? null;
    } elseif (!empty($message['stickerMessage'])) {
        $kind = 'sticker';
        $body = '[sticker]';
        $mediaMime   = 'image/webp';
        $mediaBase64 = $message['base64'] ?? null;
    } elseif (!empty($message['locationMessage'])) {
        $kind = 'location';
        $lat  = $message['locationMessage']['degreesLatitude']  ?? null;
        $lng  = $message['locationMessage']['degreesLongitude'] ?? null;
        $body = '[location] ' . $lat . ', ' . $lng;
    } else {
        $body = '[' . $messageType . ']';
    }

    // ---- Contact ----
    $companyId   = (int)$company['id'];
    $profileName = $msg['pushName'] ?? null;

    $stmt = $db->prepare('SELECT * FROM contacts WHERE company_id = ? AND wa_id = ? LIMIT 1');
    $stmt->execute([$companyId, $waId]);
    $contact = $stmt->fetch();
    if (!$contact) {
        $ins = $db->prepare(
            'INSERT INTO contacts (company_id, wa_id, phone, profile_name, display_name, last_message_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([$companyId, $waId, $waId, $profileName, $profileName ?: $waId]);
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

    // ---- Conversation ----
    $timestamp   = (int)($msg['messageTimestamp'] ?? time());
    $messageDate = date('Y-m-d H:i:s', $timestamp);
    $expiryDate  = date('Y-m-d H:i:s', $timestamp + 24 * 3600);
    $previewText = mb_substr((string)$body, 0, 500);

    $stmt = $db->prepare(
        'SELECT * FROM conversations
         WHERE company_id = ? AND contact_id = ? AND status IN ("open","pending","escalated")
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$companyId, $contactId]);
    $conv = $stmt->fetch();

    if (!$conv) {
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

    // ---- Inbound media: persist base64 to disk if present ----
    $mediaLocalPath = null;
    if ($mediaBase64) {
        try {
            $destDir = __DIR__ . '/../uploads/' . $companyId . '/inbound';
            if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
            $ext = $mediaName
                ? '.' . pathinfo($mediaName, PATHINFO_EXTENSION)
                : evolution_extension_for_mime((string)$mediaMime);
            $fname  = $waMsgId . '_' . bin2hex(random_bytes(4)) . $ext;
            $target = $destDir . '/' . $fname;
            file_put_contents($target, base64_decode($mediaBase64));
            @chmod($target, 0640);
            $mediaLocalPath = $target;
        } catch (Throwable $e) {
            error_log('[AiServe evolution] media write failed: ' . $e->getMessage());
        }
    }

    // ---- Insert message row ----
    try {
        $ins = $db->prepare(
            'INSERT INTO messages
                (company_id, conversation_id, contact_id, sender_type, wa_message_id, direction,
                 message_type, message_text, media_mime_type, media_filename, media_local_path,
                 raw_payload, status, created_at)
             VALUES (?, ?, ?, "customer", ?, "incoming", ?, ?, ?, ?, ?, ?, "received", ?)'
        );
        $ins->execute([
            $companyId, $conversationId, $contactId,
            $waMsgId, $kind, $body,
            $mediaMime, $mediaName, $mediaLocalPath,
            $raw, $messageDate,
        ]);
    } catch (Throwable $e) {
        error_log('[AiServe evolution] insert message failed: ' . $e->getMessage());
        return false;
    }

    log_activity($companyId, null, 'message_received', 'conversation', $conversationId,
        'Inbound ' . $kind . ' from ' . $waId . ' via Evolution');
    return true;
}

function handle_evolution_status(array $company, array $msg): void
{
    $key       = $msg['key']    ?? [];
    $waMsgId   = (string)($key['id'] ?? '');
    if ($waMsgId === '') return;

    // Evolution status enums: PENDING, SERVER_ACK, DELIVERY_ACK, READ, PLAYED
    $status = strtoupper((string)($msg['status'] ?? ''));
    $map = [
        'SERVER_ACK'   => ['sent',      'sent_at'],
        'DELIVERY_ACK' => ['delivered', 'delivered_at'],
        'READ'         => ['read',      'read_at'],
        'PLAYED'       => ['read',      'read_at'],
    ];
    if (!isset($map[$status])) return;
    [$enum, $col] = $map[$status];

    $sql = 'UPDATE messages SET status = ?, ' . $col . ' = NOW() WHERE wa_message_id = ?';
    aiserve_db()->prepare($sql)->execute([$enum, $waMsgId]);
}

function handle_evolution_connection(array $company, array $payload): void
{
    $state = $payload['data']['state'] ?? $payload['state'] ?? null;
    $map = ['open' => 'connected', 'connecting' => 'connecting', 'close' => 'disconnected'];
    $normalized = $map[$state] ?? 'disconnected';
    aiserve_db()->prepare('UPDATE companies SET evolution_status = ? WHERE id = ?')
                ->execute([$normalized, (int)$company['id']]);
}

function evolution_extension_for_mime(string $mime): string
{
    static $map = [
        'image/jpeg'   => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'image/gif' => '.gif',
        'audio/ogg'    => '.ogg', 'audio/mpeg' => '.mp3', 'audio/mp4' => '.m4a',
        'video/mp4'    => '.mp4',
        'application/pdf' => '.pdf',
    ];
    return $map[$mime] ?? '.bin';
}
