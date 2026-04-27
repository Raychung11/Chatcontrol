<?php
/**
 * WhatsApp Cloud API client.
 *
 * Handles outgoing message sending against the Meta Graph API:
 *   POST https://graph.facebook.com/{api_version}/{phone_number_id}/messages
 *
 * Inbound messages are handled in /webhook/whatsapp.php.
 */

require_once __DIR__ . '/helpers.php';

function load_company_settings(int $companyId): ?array
{
    $stmt = aiserve_db()->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    return $stmt->fetch() ?: null;
}

/**
 * Send a free-text WhatsApp message to a wa_id (E.164 digits, no +).
 *
 * @return array{ok:bool, wa_message_id:?string, error:?string, http_code:int, raw:?array}
 */
function whatsapp_send_text(array $company, string $waId, string $messageText): array
{
    return whatsapp_api_send_payload($company, [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $waId,
        'type'              => 'text',
        'text'              => [
            'preview_url' => false,
            'body'        => $messageText,
        ],
    ]);
}

/**
 * Send an approved template message.
 *
 * @param array  $company       Company row.
 * @param string $waId          Recipient wa_id.
 * @param string $templateName  Approved template name.
 * @param string $language      e.g. 'en', 'en_US', 'ms'.
 * @param array  $bodyParams    Ordered list of body variable values.
 */
function whatsapp_send_template(array $company, string $waId, string $templateName, string $language = 'en', array $bodyParams = []): array
{
    $components = [];
    if (!empty($bodyParams)) {
        $components[] = [
            'type'       => 'body',
            'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $bodyParams),
        ];
    }
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $waId,
        'type'              => 'template',
        'template'          => [
            'name'     => $templateName,
            'language' => ['code' => $language],
        ],
    ];
    if ($components) {
        $payload['template']['components'] = $components;
    }
    return whatsapp_api_send_payload($company, $payload);
}

/**
 * Send a media message (image / video / document / audio).
 *
 * @param string $kind     One of 'image','video','document','audio'.
 * @param string $mediaId  Meta media id returned by upload step.
 */
function whatsapp_send_media(array $company, string $waId, string $kind, string $mediaId, ?string $caption = null, ?string $filename = null): array
{
    if (!in_array($kind, ['image', 'video', 'document', 'audio'], true)) {
        return ['ok' => false, 'wa_message_id' => null, 'error' => 'Unsupported media type.', 'http_code' => 0, 'raw' => null];
    }
    $mediaPart = ['id' => $mediaId];
    if ($caption !== null && $caption !== '' && in_array($kind, ['image', 'video', 'document'], true)) {
        $mediaPart['caption'] = $caption;
    }
    if ($kind === 'document' && !empty($filename)) {
        $mediaPart['filename'] = $filename;
    }
    return whatsapp_api_send_payload($company, [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $waId,
        'type'              => $kind,
        $kind               => $mediaPart,
    ]);
}

/**
 * Upload a local file to Meta and get back a media_id.
 *
 * @return array{ok:bool, media_id:?string, error:?string}
 */
function whatsapp_upload_media(array $company, string $localPath, string $mimeType): array
{
    $phoneNumberId = trim((string)($company['phone_number_id'] ?? ''));
    $accessToken   = trim((string)($company['access_token']    ?? ''));
    $apiVersion    = trim((string)($company['api_version']     ?? 'v21.0')) ?: 'v21.0';

    if ($phoneNumberId === '' || $accessToken === '') {
        return ['ok' => false, 'media_id' => null, 'error' => 'WhatsApp API not configured.'];
    }
    if (!is_readable($localPath)) {
        return ['ok' => false, 'media_id' => null, 'error' => 'File not readable: ' . basename($localPath)];
    }

    $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/media";
    $cfile = new CURLFile($localPath, $mimeType, basename($localPath));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_POSTFIELDS     => [
            'messaging_product' => 'whatsapp',
            'type'              => $mimeType,
            'file'              => $cfile,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'media_id' => null, 'error' => 'Upload failed: ' . $curlErr];
    }
    $decoded = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['id'])) {
        return ['ok' => true, 'media_id' => (string)$decoded['id'], 'error' => null];
    }
    return [
        'ok'       => false,
        'media_id' => null,
        'error'    => $decoded['error']['message'] ?? ('HTTP ' . $httpCode),
    ];
}

/**
 * Download an inbound media object by Meta media_id.
 * Two-step: GET /{media_id} -> URL, then GET that URL with auth header.
 *
 * @return array{ok:bool, local_path:?string, mime_type:?string, error:?string}
 */
function whatsapp_download_media(array $company, string $mediaId, string $destDir): array
{
    $accessToken = trim((string)($company['access_token']    ?? ''));
    $apiVersion  = trim((string)($company['api_version']     ?? 'v21.0')) ?: 'v21.0';

    if ($accessToken === '') {
        return ['ok' => false, 'local_path' => null, 'mime_type' => null, 'error' => 'No access token.'];
    }
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }

    // Step 1: lookup URL
    $ch = curl_init("https://graph.facebook.com/{$apiVersion}/{$mediaId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'local_path' => null, 'mime_type' => null, 'error' => 'Lookup failed (HTTP ' . $code . ').'];
    }
    $info = json_decode((string)$resp, true);
    $mediaUrl = $info['url']       ?? null;
    $mime     = $info['mime_type'] ?? 'application/octet-stream';
    if (!$mediaUrl) {
        return ['ok' => false, 'local_path' => null, 'mime_type' => null, 'error' => 'No download URL in lookup.'];
    }

    // Step 2: download bytes
    $ext = whatsapp_extension_for_mime($mime);
    $filename = $mediaId . '_' . bin2hex(random_bytes(4)) . $ext;
    $destPath = rtrim($destDir, '/') . '/' . $filename;

    $fp = @fopen($destPath, 'wb');
    if (!$fp) {
        return ['ok' => false, 'local_path' => null, 'mime_type' => null, 'error' => 'Cannot write upload file.'];
    }
    $ch = curl_init($mediaUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE        => $fp,
        CURLOPT_TIMEOUT     => 60,
        CURLOPT_HTTPHEADER  => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $code < 200 || $code >= 300) {
        @unlink($destPath);
        return ['ok' => false, 'local_path' => null, 'mime_type' => null, 'error' => 'Download failed (HTTP ' . $code . ').'];
    }
    return ['ok' => true, 'local_path' => $destPath, 'mime_type' => $mime, 'error' => null];
}

function whatsapp_extension_for_mime(string $mime): string
{
    static $map = [
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/webp' => '.webp',
        'image/gif'  => '.gif',
        'audio/ogg'  => '.ogg',
        'audio/mpeg' => '.mp3',
        'audio/mp4'  => '.m4a',
        'audio/aac'  => '.aac',
        'audio/amr'  => '.amr',
        'video/mp4'  => '.mp4',
        'video/3gpp' => '.3gp',
        'application/pdf' => '.pdf',
        'application/zip' => '.zip',
        'text/plain' => '.txt',
        'application/msword' => '.doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
        'application/vnd.ms-excel' => '.xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
    ];
    if (isset($map[$mime])) return $map[$mime];
    if (str_starts_with($mime, 'image/'))    return '.bin';
    return '.bin';
}

/**
 * Apply the company's auto-routing rules to an inbound message.
 * Returns ['department_id' => ?int, 'assigned_user_id' => ?int].
 */
function apply_routing_rules(int $companyId, ?string $messageBody): array
{
    $body = trim((string)$messageBody);
    $lcBody = mb_strtolower($body);

    $stmt = aiserve_db()->prepare(
        'SELECT * FROM routing_rules WHERE company_id = ? AND status = "active" ORDER BY priority ASC, id ASC'
    );
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll() as $rule) {
        $needle = mb_strtolower((string)$rule['match_value']);
        $hit = false;
        switch ($rule['match_type']) {
            case 'equals':      $hit = ($lcBody === $needle); break;
            case 'starts_with': $hit = str_starts_with($lcBody, $needle); break;
            case 'regex':
                $pattern = '/' . str_replace('/', '\\/', (string)$rule['match_value']) . '/iu';
                $hit = @preg_match($pattern, $body) === 1;
                break;
            case 'contains':
            default:
                $hit = $needle !== '' && str_contains($lcBody, $needle);
        }
        if ($hit) {
            return [
                'department_id'    => (int)$rule['department_id'],
                'assigned_user_id' => $rule['assigned_user_id'] ? (int)$rule['assigned_user_id'] : null,
            ];
        }
    }

    // Fallback to company default department
    $stmt = aiserve_db()->prepare('SELECT default_department_id FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $defaultDept = $stmt->fetchColumn();
    return [
        'department_id'    => $defaultDept ? (int)$defaultDept : null,
        'assigned_user_id' => null,
    ];
}

function whatsapp_api_send_payload(array $company, array $payload): array
{
    $phoneNumberId = trim((string)($company['phone_number_id'] ?? ''));
    $accessToken   = trim((string)($company['access_token'] ?? ''));
    $apiVersion    = trim((string)($company['api_version'] ?? 'v21.0')) ?: 'v21.0';

    if ($phoneNumberId === '' || $accessToken === '') {
        return [
            'ok'            => false,
            'wa_message_id' => null,
            'error'         => 'WhatsApp API not configured. Set Phone Number ID and Access Token in Settings.',
            'http_code'     => 0,
            'raw'           => null,
        ];
    }

    $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false, 'wa_message_id' => null,
            'error' => 'Network error: ' . $curlErr,
            'http_code' => $httpCode, 'raw' => null,
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false, 'wa_message_id' => null,
            'error' => 'Invalid response from WhatsApp API.',
            'http_code' => $httpCode, 'raw' => null,
        ];
    }

    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['messages'][0]['id'])) {
        return [
            'ok' => true,
            'wa_message_id' => (string)$decoded['messages'][0]['id'],
            'error' => null,
            'http_code' => $httpCode,
            'raw' => $decoded,
        ];
    }

    $errMsg = $decoded['error']['message']
        ?? $decoded['error']['error_user_msg']
        ?? ('HTTP ' . $httpCode);
    return [
        'ok' => false,
        'wa_message_id' => null,
        'error' => $errMsg,
        'http_code' => $httpCode,
        'raw' => $decoded,
    ];
}
