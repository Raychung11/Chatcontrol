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
require_once __DIR__ . '/dns_helper.php';

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
    $opts = [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_POST              => true,
        CURLOPT_TIMEOUT           => 30,
        CURLOPT_DNS_CACHE_TIMEOUT => 0,
        CURLOPT_FRESH_CONNECT     => true,
        CURLOPT_FORBID_REUSE      => true,
        CURLOPT_HTTPHEADER        => [
            'Authorization: Bearer ' . (string)$company['chatbot_bearer_token'],
        ],
        CURLOPT_POSTFIELDS        => $fields, // multipart form-data
    ];
    // Bypass Hostinger's stale OS resolver - look up the host via Google DoH
    // and pin the IP at the curl layer.
    $resolved = fresh_dns_resolve_entry($url);
    if ($resolved) {
        $opts[CURLOPT_RESOLVE] = [$resolved['entry']];
    }
    curl_setopt_array($ch, $opts);
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
    // Audio is added to the map so agents can reply with voice notes.
    // The partner's Evolution-shaped gateway accepts 'audio' as a
    // mediatype value. If a specific gateway build ever rejects it we
    // fall through to the normal error path.
    $kindMap = ['image' => 'image', 'video' => 'video', 'document' => 'document', 'audio' => 'audio'];
    if (!isset($kindMap[$kind])) {
        return [
            'ok' => false, 'wa_message_id' => null,
            'error' => 'AiServe Chatbot gateway supports image/video/audio/document only.',
            'http_code' => 400, 'raw' => null,
        ];
    }
    $publicUrl = chatbot_public_media_url($company, $localPath); // $company here is actually the channel
    if ($publicUrl === null) {
        // The old wording blamed the webhook_verify_token, but by far
        // the most common cause is a path-layout mismatch (file lives
        // under uploads/broadcasts/<cid>/ but companyId isn't a segment,
        // or the file was moved/deleted). See nginx error log for the
        // '[AiServe chatbot_public_media_url]' line naming the exact
        // reason.
        return [
            'ok' => false, 'wa_message_id' => null,
            'error' => 'Could not build signed media URL. Check nginx error log for [AiServe chatbot_public_media_url] — likely the file path is missing / moved / outside the workspace uploads dir, or the channel has no webhook_token.',
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
 * The signature uses the channel's webhook_token as the HMAC key so an
 * external caller (the gateway) can fetch the file without auth.
 *
 * The first argument is a CHANNEL row (the chatbot dispatch already passes
 * the channel, not the company). Channel.company_id resolves the uploads
 * subdirectory.
 *
 * Returns null if no signing secret is configured.
 */
function chatbot_public_media_url(array $channel, string $localPath): ?string
{
    $secret = (string)($channel['webhook_token'] ?? '');
    if ($secret === '') {
        error_log('[AiServe chatbot_public_media_url] channel has no webhook_token');
        return null;
    }
    $companyId = (int)($channel['company_id'] ?? 0);
    $channelId = (int)($channel['id']         ?? 0);
    if ($companyId <= 0 || $channelId <= 0) return null;

    $uploadsDir = realpath(__DIR__ . '/../uploads');
    $real       = realpath($localPath);
    if (!$uploadsDir || !$real || !str_starts_with($real, $uploadsDir . '/')) {
        error_log('[AiServe chatbot_public_media_url] localPath outside uploads dir: '
                 . ($localPath ?: '(empty)'));
        return null;
    }
    // Relative path under /uploads. Accepts both layouts:
    //   • <companyId>/foo.png          — old chat media
    //   • broadcasts/<companyId>/foo.png — broadcast attachments
    //   • <anything>/<companyId>/foo.png — future subdirs are fine as long
    //     as the company_id appears as a path segment. Prevents cross-
    //     company leaks (a file under a different company's directory can
    //     never sign a URL for this channel's HMAC).
    $rel = substr($real, strlen($uploadsDir) + 1);
    $segments = explode('/', $rel);
    if (!in_array((string)$companyId, $segments, true)) {
        error_log('[AiServe chatbot_public_media_url] path does not contain companyId=' . $companyId
                 . ' as a segment: ' . $rel);
        return null;
    }

    // Sign the FULL relative-under-uploads path — receiver side rebuilds
    // it as uploads/<rel> and re-verifies the same HMAC.
    $sig = hash_hmac('sha256', $channelId . ':' . $rel, $secret);
    $base = APP_BASE_URL ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''));
    return $base . '/api/media_public.php?ch=' . $channelId
                 . '&p=' . rawurlencode($rel)
                 . '&sig=' . $sig;
}
