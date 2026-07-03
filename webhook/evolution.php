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

require_once __DIR__ . '/../inc/channels.php';
$channel = resolve_channel_for_webhook();
if (!$channel) {
    http_response_code(404);
    error_log('[AiServe evolution] Unknown channel/tenant: ch=' . ($_GET['ch'] ?? '(none)')
              . ' company=' . ($_GET['company'] ?? '(none)'));
    exit('Unknown channel.');
}
$stmt = aiserve_db()->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$stmt->execute([(int)$channel['company_id']]);
$company = $stmt->fetch();
if (!$company) {
    http_response_code(404);
    exit('Unknown company.');
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

// AiServe Chatbot Gateway added a `type` query parameter to distinguish
// incoming customer messages (existing Evolution shape) from outgoing
// AI-bot responses (new custom shape defined in their spec). We branch
// on that here. Anything not "outgoing" falls through to the existing
// Evolution flow - preserves backward compatibility for partners that
// don't use the new type field.
$hookType = strtolower(trim((string)($_GET['type'] ?? 'incoming')));

if ($hookType === 'outgoing') {
    if (!is_array($payload)) {
        $httpStatus = 400;
        $errorText  = 'Invalid JSON payload';
        error_log('[AiServe evolution outgoing] ' . $errorText);
    } else {
        try {
            if (handle_aiserve_outgoing_ai($company, $channel, $payload)) {
                $messageCount = 1;
            }
        } catch (Throwable $e) {
            $errorText  = mb_substr('Exception: ' . $e->getMessage(), 0, 500);
            $httpStatus = 500;
            error_log('[AiServe evolution outgoing] ' . $errorText);
        }
    }
} elseif (!is_array($payload)) {
    $httpStatus = 400;
    $errorText  = 'Invalid JSON payload';
    error_log('[AiServe evolution] ' . $errorText);
} else {
    try {
        // Evolution sends events in either dot ("messages.upsert") or
        // underscore ("MESSAGES_UPSERT") form depending on version/config.
        // Normalize both to "MESSAGES_UPSERT" before switching.
        $event = str_replace('.', '_', strtoupper((string)($payload['event'] ?? '')));
        switch ($event) {
            case 'MESSAGES_UPSERT':
                $rows = evolution_extract_messages($payload);
                foreach ($rows as $msg) {
                    if (handle_evolution_message($company, $channel, $msg, $rawBody)) {
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

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Post-response inbound automation.
// Order matters:
//   1. Keyword auto-reply (catalog send, canned answers) - deterministic,
//      cheap, no LLM cost. If a rule matches we stop.
//   2. AI first-touch / always-on - only if no keyword rule fired.
require_once __DIR__ . '/../inc/auto_replies.php';
require_once __DIR__ . '/../inc/ai_api.php';
foreach (evolution_touched_conversation_ids() as $cid) {
    $fired = keyword_auto_reply_dispatch($company, $cid);
    if (!$fired) {
        inbound_automation_handle($company, $cid);
    }
}

// =============================================================
// Functions
// =============================================================

function evolution_touched_conversation_ids(?int $append = null): array
{
    static $ids = [];
    if ($append !== null) { $ids[] = $append; return []; }
    return $ids;
}

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
 * Handle AiServe Chatbot Gateway's OUTGOING AI-response webhook.
 *
 * Payload shape (per partner spec):
 *   {
 *     "ai_response": { "type": "text", "msg": "..." },
 *     "chatbot_id":  8,
 *     "contact":     "111944195432638@lid" | "60123456789@s.whatsapp.net",
 *     "final_result": {
 *       "msg":       "...",              // Prefer this - post-processed
 *       "mediatype": "image" | null,
 *       "mediaUrl":  "https://..." | null
 *     }
 *   }
 *
 * We persist the AI reply into the same messages / conversations tables
 * an agent-sent message would land in, tagged sender_type = 'ai' so the
 * chat UI can render it differently. Bumps last_message_at + first_
 * response_at so the "Awaiting reply" chip clears the moment the AI
 * answered.
 *
 * @return bool true if a row was persisted
 */
function handle_aiserve_outgoing_ai(array $company, array $channel, array $payload): bool
{
    // Prefer final_result (post-processing applied). Fall back to ai_response.
    $final = $payload['final_result'] ?? [];
    $text  = trim((string)($final['msg'] ?? ($payload['ai_response']['msg'] ?? '')));
    $mediaType = (string)($final['mediatype'] ?? '');
    $mediaUrl  = (string)($final['mediaUrl']  ?? '');

    // Parse contact JID -> phone digits, following the same LID handling
    // as the incoming path. AiServe currently sends contact as a raw JID
    // like "111944195432638@lid" or "60123456789@s.whatsapp.net".
    $contact = (string)($payload['contact'] ?? '');
    if ($contact === '') {
        error_log('[AiServe outgoing AI] missing contact');
        return false;
    }
    $jid      = $contact;
    $waId     = explode('@', $jid)[0];
    $isLid    = str_ends_with($jid, '@lid');
    if ($isLid || !ctype_digit($waId)) {
        // LID means the sender's real phone is hidden. We still need a row
        // so agents see the AI reply - but use a placeholder wa_id so it
        // doesn't collide with a real number. Real phone will surface when
        // the customer eventually replies through the incoming webhook.
        $waId = 'lid_' . preg_replace('/[^A-Za-z0-9]/', '', $jid);
    }

    if ($text === '' && $mediaUrl === '') {
        error_log('[AiServe outgoing AI] empty ai response for contact=' . $contact);
        return false;
    }

    $companyId = (int)$company['id'];
    $channelId = (int)$channel['id'];
    $db = aiserve_db();

    // Dedupe on content hash - the payload has no message id, so a partner
    // retry would otherwise create a duplicate. Prefix marks these as
    // AI-bot echoes so they don't clash with real WA IDs.
    $waMsgId = 'aiserve-ai:' . (int)($payload['chatbot_id'] ?? 0) . ':'
             . substr(hash('sha256', $waId . '|' . $text . '|' . $mediaUrl), 0, 32);

    $check = $db->prepare('SELECT id FROM messages WHERE wa_message_id = ? LIMIT 1');
    $check->execute([$waMsgId]);
    if ($check->fetchColumn()) return false;

    // Find or create contact
    $stmt = $db->prepare('SELECT id FROM contacts WHERE company_id = ? AND wa_id = ? LIMIT 1');
    $stmt->execute([$companyId, $waId]);
    $contactId = (int)($stmt->fetchColumn() ?: 0);
    if ($contactId === 0) {
        $ins = $db->prepare(
            'INSERT INTO contacts (company_id, wa_id, phone, display_name, last_message_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $ins->execute([$companyId, $waId, $waId, $waId]);
        $contactId = (int)$db->lastInsertId();
    }

    // Find or create active conversation
    $stmt = $db->prepare(
        'SELECT id FROM conversations
         WHERE company_id = ? AND contact_id = ? AND status IN ("open","pending","escalated")
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$companyId, $contactId]);
    $conversationId = (int)($stmt->fetchColumn() ?: 0);
    if ($conversationId === 0) {
        $ins = $db->prepare(
            'INSERT INTO conversations
                (company_id, channel_id, contact_id, status,
                 last_message_text, last_message_at, first_response_at, unread_count)
             VALUES (?, ?, ?, "open", ?, NOW(), NOW(), 0)'
        );
        $ins->execute([$companyId, $channelId, $contactId, mb_substr($text ?: '(media)', 0, 500)]);
        $conversationId = (int)$db->lastInsertId();
    }

    // Insert the outbound AI message
    $messageType = $mediaUrl !== '' ? ($mediaType ?: 'document') : 'text';
    $ins = $db->prepare(
        'INSERT INTO messages
            (company_id, channel_id, conversation_id, contact_id,
             sender_type, sender_user_id, wa_message_id,
             direction, message_type, message_text,
             media_url, status, sent_at, raw_payload)
         VALUES (?, ?, ?, ?, "ai", NULL, ?, "outgoing", ?, ?, ?, "sent", NOW(), ?)'
    );
    $ins->execute([
        $companyId, $channelId, $conversationId, $contactId,
        $waMsgId, $messageType, $text,
        $mediaUrl ?: null,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    // Bump conversation - this clears the "Awaiting reply" state because
    // last_message_at is now >= last_customer_message_at.
    $db->prepare(
        'UPDATE conversations
         SET last_message_text = ?,
             last_message_at   = NOW(),
             first_response_at = COALESCE(first_response_at, NOW())
         WHERE id = ?'
    )->execute([mb_substr($text ?: '(media)', 0, 500), $conversationId]);

    log_activity($companyId, null, 'ai_reply_delivered', 'conversation', $conversationId,
        'AiServe AI reply chatbot_id=' . (int)($payload['chatbot_id'] ?? 0));
    return true;
}

/**
 * @return bool true if the row ended up persisted (incoming customer msg).
 */
function handle_evolution_message(array $company, array $channel, array $msg, string $raw): bool
{
    $key            = $msg['key'] ?? [];
    $remoteJid      = (string)($key['remoteJid']      ?? '');
    $remoteJidAlt   = (string)($key['remoteJidAlt']   ?? '');
    $addressingMode = (string)($key['addressingMode'] ?? '');
    $waMsgId        = (string)($key['id']             ?? '');
    $fromMe         = (bool)  ($key['fromMe']         ?? false);
    if ($remoteJid === '' || $waMsgId === '') return false;

    // Skip group chats - portal is 1:1 customer support
    if (str_ends_with($remoteJid, '@g.us') || str_ends_with($remoteJidAlt, '@g.us')) {
        return false;
    }

    // WhatsApp's LID (Linked ID) addressing hides the real phone number in
    // remoteJid and exposes it in remoteJidAlt instead. Prefer the alt JID
    // whenever it points at a real phone (@s.whatsapp.net).
    $sourceJid = $remoteJid;
    if (str_ends_with($remoteJidAlt, '@s.whatsapp.net')
        && ($addressingMode === 'lid' || str_ends_with($remoteJid, '@lid'))) {
        $sourceJid = $remoteJidAlt;
    }
    $waId = explode('@', $sourceJid)[0];
    if ($waId === '' || !ctype_digit($waId)) {
        error_log('[AiServe evolution] Could not extract phone from JID: ' . $remoteJid . ' / alt=' . $remoteJidAlt);
        return false;
    }

    // Dedupe first - covers both echoes of our own portal sends (already
    // written by /api/send_message.php) and Evolution's at-least-once
    // re-delivery of the same event.
    $db = aiserve_db();
    $check = $db->prepare('SELECT id FROM messages WHERE wa_message_id = ? LIMIT 1');
    $check->execute([$waMsgId]);
    if ($check->fetchColumn()) {
        return false;
    }
    // A `fromMe` event that survives the dedupe is an outgoing message we
    // did NOT send from the portal - typically the partner's AI bot reply
    // or a message typed on a linked WhatsApp device. We still want it in
    // the inbox so agents see the full conversation thread.

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
        // A brand-new conversation that starts with a fromMe message would be
        // odd (the bot replied to nobody) - still create it so we have a row.
        $route = apply_routing_rules($companyId, $body);
        $unread     = $fromMe ? 0       : 1;
        $custMsgAt  = $fromMe ? null    : $messageDate;
        $windowExp  = $fromMe ? null    : $expiryDate;
        $firstResp  = $fromMe ? $messageDate : null;
        $ins = $db->prepare(
            'INSERT INTO conversations
                (company_id, channel_id, contact_id, department_id, assigned_user_id, status,
                 last_message_text, last_message_at,
                 last_customer_message_at, service_window_expires_at,
                 unread_count, first_response_at)
             VALUES (?, ?, ?, ?, ?, "open", ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $companyId, (int)$channel['id'], $contactId,
            $route['department_id'], $route['assigned_user_id'],
            $previewText, $messageDate, $custMsgAt, $windowExp,
            $unread, $firstResp,
        ]);
        $conversationId = (int)$db->lastInsertId();
    } else {
        $conversationId = (int)$conv['id'];
        $newStatus = ($conv['status'] === 'closed') ? 'open' : $conv['status'];
        if ($fromMe) {
            // Outgoing (bot) reply: update last_message_* + first_response_at,
            // but DO NOT touch unread_count or the customer service window.
            $upd = $db->prepare(
                'UPDATE conversations
                 SET status = ?,
                     last_message_text = ?,
                     last_message_at   = ?,
                     first_response_at = COALESCE(first_response_at, ?)
                 WHERE id = ?'
            );
            $upd->execute([$newStatus, $previewText, $messageDate, $messageDate, $conversationId]);
        } else {
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
    $senderType = $fromMe ? 'ai'       : 'customer';
    $direction  = $fromMe ? 'outgoing' : 'incoming';
    $status     = $fromMe ? 'sent'     : 'received';
    $sentAt     = $fromMe ? $messageDate : null;

    try {
        $ins = $db->prepare(
            'INSERT INTO messages
                (company_id, channel_id, conversation_id, contact_id, sender_type, wa_message_id, direction,
                 message_type, message_text, media_mime_type, media_filename, media_local_path,
                 raw_payload, status, sent_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $companyId, (int)$channel['id'], $conversationId, $contactId,
            $senderType, $waMsgId, $direction,
            $kind, $body,
            $mediaMime, $mediaName, $mediaLocalPath,
            $raw, $status, $sentAt, $messageDate,
        ]);
    } catch (Throwable $e) {
        error_log('[AiServe evolution] insert message failed: ' . $e->getMessage());
        return false;
    }

    $action = $fromMe ? 'ai_reply_received' : 'message_received';
    $desc   = ($fromMe ? 'Outbound AI/bot ' : 'Inbound ') . $kind . ' ' . ($fromMe ? 'to ' : 'from ') . $waId . ' via Evolution';
    log_activity($companyId, null, $action, 'conversation', $conversationId, $desc);

    // Mark this conversation for first-touch auto-reply consideration only
    // if the message is INCOMING (not our own AI bot echo).
    if (!$fromMe) {
        evolution_touched_conversation_ids($conversationId);
    }
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
