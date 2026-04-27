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
 * Send an approved template message. (Phase 2 ready - admin can wire from UI.)
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
