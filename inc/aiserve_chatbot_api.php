<?php
/**
 * AiServe Chatbot Gateway client.
 *
 * Custom Bearer-token HTTP gateway (partner-hosted) sitting in front of
 * Evolution. Endpoint contract:
 *
 *   POST {base_url}/api/boardcast/sendMessage
 *   Headers: Authorization: Bearer {token}
 *   Body (multipart form-data):
 *     msg       : text body
 *     to        : recipient number (digits, no +; e.g. 60163917794)
 *     mediatype : optional - one of image|video|document
 *     mediaUrl  : optional - public URL to the file
 *
 * Response (200):
 *   { key: { id, remoteJid, fromMe }, status: "PENDING", message: { conversation: "..." }, ... }
 */

require_once __DIR__ . '/helpers.php';

function chatbot_is_configured(array $company): bool
{
    return !empty($company['chatbot_base_url'])
        && !empty($company['chatbot_bearer_token']);
}

function chatbot_normalize_wa_id(string $waId): string
{
    if (str_contains($waId, '@')) {
        $waId = explode('@', $waId)[0];
    }
    return preg_replace('/[^0-9]/', '', $waId) ?: '';
}

function chatbot_base(array $company): string
{
    return rtrim((string)$company['chatbot_base_url'], '/');
}

function chatbot_post_form(array $company, string $path, array $fields): array
{
    $url = chatbot_base($company) . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . (string)$company['chatbot_bearer_token'],
        ],
        CURLOPT_POSTFIELDS     => $fields, // multipart form-data
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'http_code' => $code, 'body' => 'curl error: ' . $err, 'json' => null];
    }
    $json = json_decode((string)$resp, true);
    return [
        'ok'        => $code >= 200 && $code < 300,
        'http_code' => $code,
        'body'      => (string)$resp,
        'json'      => is_array($json) ? $json : null,
    ];
}

function chatbot_send_text(array $company, string $waId, string $text): array
{
    if (!chatbot_is_configured($company)) {
        return chatbot_not_configured_error();
    }
    $to = chatbot_normalize_wa_id($waId);
    if ($to === '') {
        return ['ok' => false, 'wa_message_id' => null, 'error' => 'Invalid recipient number.', 'http_code' => 400, 'raw' => null];
    }
    $r = chatbot_post_form($company, '/api/boardcast/sendMessage', [
        'msg' => $text,
        'to'  => $to,
    ]);
    return chatbot_normalize_send_result($r);
}

/**
 * Send media. The gateway accepts a *public* mediaUrl (it does not accept
 * file uploads), so the local file must be reachable over HTTPS. We expose
 * it through /api/media_public.php with an HMAC signature derived from the
 * company's webhook_verify_token (kept server-side).
 *
 * @param string $localPath path of the file under /uploads
 */
function chatbot_send_media(array $company, string $waId, string $kind, string $localPath, ?string $caption = null, ?string $filename = null, ?string $mime = null): array
{
    if (!chatbot_is_configured($company)) {
        return chatbot_not_configured_error();
    }
    $kindMap = ['image' => 'image', 'video' => 'video', 'document' => 'document'];
    if (!isset($kindMap[$kind])) {
        return [
            'ok' => false, 'wa_message_id' => null,
            'error' => 'AiServe Chatbot gateway supports image/video/document only.',
            'http_code' => 400, 'raw' => null,
        ];
    }
    $publicUrl = chatbot_public_media_url($company, $localPath);
    if ($publicUrl === null) {
        return [
            'ok' => false, 'wa_message_id' => null,
            'error' => 'Could not generate public URL for media. Set Webhook verify token in Settings (used to sign URLs).',
            'http_code' => 500, 'raw' => null,
        ];
    }

    $fields = [
        'msg'       => $caption ?? ($filename ?? ''),
        'to'        => chatbot_normalize_wa_id($waId),
        'mediatype' => $kindMap[$kind],
        'mediaUrl'  => $publicUrl,
    ];
    $r = chatbot_post_form($company, '/api/boardcast/sendMessage', $fields);
    return chatbot_normalize_send_result($r);
}

function chatbot_normalize_send_result(array $r): array
{
    if ($r['ok'] && is_array($r['json'])) {
        $id = $r['json']['key']['id'] ?? $r['json']['id'] ?? null;
        return [
            'ok'            => true,
            'wa_message_id' => $id,
            'error'         => null,
            'http_code'     => $r['http_code'],
            'raw'           => $r['json'],
        ];
    }
    $err = $r['json']['message'] ?? $r['json']['error'] ?? ('HTTP ' . $r['http_code']);
    if (is_array($err)) {
        $err = json_encode($err, JSON_UNESCAPED_SLASHES);
    }
    return [
        'ok'            => false,
        'wa_message_id' => null,
        'error'         => (string)$err,
        'http_code'     => $r['http_code'],
        'raw'           => $r['json'],
    ];
}

function chatbot_not_configured_error(): array
{
    return [
        'ok' => false, 'wa_message_id' => null,
        'error' => 'AiServe Chatbot gateway is not configured. Set base URL and bearer token in Settings.',
        'http_code' => 0, 'raw' => null,
    ];
}

/**
 * Build a signed public URL to a local media file under /uploads.
 * The signature uses the company's webhook_verify_token as the HMAC key
 * so an external caller (the gateway) can fetch the file without auth.
 *
 * Returns null if there's no signing secret configured.
 */
function chatbot_public_media_url(array $company, string $localPath): ?string
{
    $secret = (string)($company['webhook_verify_token'] ?? '');
    if ($secret === '') {
        return null;
    }
    $companyId = (int)$company['id'];
    $uploadsDir = realpath(__DIR__ . '/../uploads');
    $real       = realpath($localPath);
    if (!$uploadsDir || !$real || !str_starts_with($real, $uploadsDir . '/')) {
        return null;
    }
    $rel = substr($real, strlen($uploadsDir) + 1);
    // Strip leading "{company_id}/" prefix for the public URL.
    $prefix = $companyId . '/';
    if (!str_starts_with($rel, $prefix)) {
        return null;
    }
    $relForCompany = substr($rel, strlen($prefix));
    $payload = $companyId . ':' . $relForCompany;
    $sig = hash_hmac('sha256', $payload, $secret);

    $base = APP_BASE_URL ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''));
    return $base . '/api/media_public.php?c=' . $companyId
                 . '&p=' . rawurlencode($relForCompany)
                 . '&sig=' . $sig;
}
