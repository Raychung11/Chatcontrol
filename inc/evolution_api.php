<?php
/**
 * Evolution API client (Baileys-based, unofficial WhatsApp).
 *
 * Endpoint contract (Evolution API v2):
 *   Auth header:  apikey: <evolution_api_key>
 *   Base URL:     companies.evolution_base_url   e.g. https://evo.example.com
 *   Instance:     companies.evolution_instance   e.g. "aiserve-prod"
 *
 * Provides parity with whatsapp_api.php for the operations the portal needs:
 *   - sendText / sendMedia
 *   - createInstance / setWebhook
 *   - getQR / connectionState (used by /admin/whatsapp_pair.php)
 */

require_once __DIR__ . '/helpers.php';

// =============================================================
// Config helpers
// =============================================================

function evolution_is_configured(array $company): bool
{
    return !empty($company['evolution_base_url'])
        && !empty($company['evolution_api_key'])
        && !empty($company['evolution_instance']);
}

function evolution_normalize_wa_id(string $waId): string
{
    // Accept "60134691341" or "60134691341@s.whatsapp.net". Evolution accepts plain digits.
    if (str_contains($waId, '@')) {
        $waId = explode('@', $waId)[0];
    }
    return preg_replace('/[^0-9]/', '', $waId) ?: '';
}

/**
 * Return the value to put in Evolution's outbound "number" field.
 *
 * For a normal phone-number contact this is just the digits — Evolution
 * routes them via @s.whatsapp.net by default.
 *
 * For a WhatsApp-LID contact (Meta's privacy-preserving alias where
 * the real phone number is hidden), addressing via @s.whatsapp.net
 * fails with `exists: false` because the LID isn't in WhatsApp's
 * phone-number registry. The correct addressing is `<digits>@lid`,
 * which Baileys / Evolution recognizes as a LID target.
 *
 * We detect LID contacts by looking up contacts.wa_lid — the column
 * migration_phase22 added and both ingest paths (evolution.php +
 * webhook/whatsapp.php) stamp on inbound. If the contact has wa_lid
 * set for this workspace, we append @lid; otherwise plain digits.
 *
 * Silently returns plain digits on any DB error so a broken lookup
 * can never brick outbound entirely — worst case a LID reply still
 * fails the same way it did before this fix.
 */
function evolution_addressable_number(array $channel, string $waId): string
{
    $normalized = evolution_normalize_wa_id($waId);
    if ($normalized === '') return $normalized;
    $companyId = (int)($channel['company_id'] ?? 0);
    if ($companyId <= 0) return $normalized;
    try {
        $stmt = aiserve_db()->prepare(
            'SELECT wa_lid FROM contacts
             WHERE company_id = ? AND wa_id = ?
             LIMIT 1'
        );
        $stmt->execute([$companyId, $normalized]);
        $lid = trim((string)($stmt->fetchColumn() ?: ''));
        if ($lid !== '') {
            return $normalized . '@lid';
        }
    } catch (Throwable $e) { /* schema drift — assume regular number */ }
    return $normalized;
}

function evolution_base(array $company): string
{
    return rtrim((string)$company['evolution_base_url'], '/');
}

/**
 * Platform-level defaults for the Evolution base URL + API key.
 *
 * Read from platform_settings (keys 'evolution_default_base_url' and
 * 'evolution_default_api_key'), settable via /admin/evolution_defaults.php.
 * When set, admin/channel_edit.php and admin/evolution_connect.php
 * auto-fill blank per-channel fields from these — a workspace admin
 * only needs to pick an instance name to pair.
 *
 * Falls back to PHP constants EVOLUTION_DEFAULT_BASE_URL /
 * EVOLUTION_DEFAULT_API_KEY (settable in config/db_config.local.php)
 * so a brand-new install can boot with sane defaults before anyone
 * touches the admin UI. Ultimately empty strings if nothing is set.
 *
 * Returns ['base_url' => ..., 'api_key' => ...].
 */
function evolution_platform_defaults(): array
{
    $base = platform_setting('evolution_default_base_url', '');
    $key  = platform_setting('evolution_default_api_key',  '');
    if ($base === '' && defined('EVOLUTION_DEFAULT_BASE_URL')) $base = (string)EVOLUTION_DEFAULT_BASE_URL;
    if ($key  === '' && defined('EVOLUTION_DEFAULT_API_KEY'))  $key  = (string)EVOLUTION_DEFAULT_API_KEY;
    return [
        'base_url' => rtrim($base, '/'),
        'api_key'  => $key,
    ];
}

function evolution_instance_name(array $company): string
{
    return (string)$company['evolution_instance'];
}

// =============================================================
// HTTP helper
// =============================================================

/**
 * @return array{ok:bool, http_code:int, body:string, json:?array}
 */
function evolution_request(array $company, string $method, string $path, ?array $body = null): array
{
    $url = evolution_base($company) . $path;
    $ch = curl_init($url);
    $headers = [
        'apikey: ' . (string)$company['evolution_api_key'],
        'Content-Type: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

// =============================================================
// Send text
// =============================================================

function evolution_send_text(array $company, string $waId, string $text): array
{
    if (!evolution_is_configured($company)) {
        return evolution_not_configured_error();
    }
    $number = evolution_addressable_number($company, $waId);
    if ($number === '') {
        return ['ok' => false, 'wa_message_id' => null, 'error' => 'Invalid recipient number.', 'http_code' => 400, 'raw' => null];
    }

    $path = '/message/sendText/' . rawurlencode(evolution_instance_name($company));
    $r = evolution_request($company, 'POST', $path, [
        'number' => $number,
        'text'   => $text,
    ]);

    return evolution_normalize_send_result($r);
}

// =============================================================
// Send media (image / video / audio / document)
// =============================================================

function evolution_send_media(array $company, string $waId, string $kind, string $localPath, ?string $caption = null, ?string $filename = null, ?string $mime = null): array
{
    if (!evolution_is_configured($company)) {
        return evolution_not_configured_error();
    }
    if (!is_readable($localPath)) {
        return ['ok' => false, 'wa_message_id' => null, 'error' => 'File not readable.', 'http_code' => 400, 'raw' => null];
    }
    $kindMap = [
        'image'    => 'image',
        'video'    => 'video',
        'document' => 'document',
        'audio'    => 'audio',
    ];
    if (!isset($kindMap[$kind])) {
        return ['ok' => false, 'wa_message_id' => null, 'error' => 'Unsupported media kind.', 'http_code' => 400, 'raw' => null];
    }

    $number = evolution_addressable_number($company, $waId);
    $base64 = base64_encode((string)file_get_contents($localPath));

    if ($kind === 'audio') {
        // Evolution exposes a separate endpoint for native voice notes.
        $path = '/message/sendWhatsAppAudio/' . rawurlencode(evolution_instance_name($company));
        $payload = [
            'number' => $number,
            'audio'  => $base64,
        ];
    } else {
        $path = '/message/sendMedia/' . rawurlencode(evolution_instance_name($company));
        $payload = [
            'number'    => $number,
            'mediatype' => $kindMap[$kind],
            'mimetype'  => $mime ?: 'application/octet-stream',
            'media'     => $base64,
            'fileName'  => $filename ?: basename($localPath),
        ];
        if ($caption !== null && $caption !== '' && $kind !== 'audio') {
            $payload['caption'] = $caption;
        }
    }

    $r = evolution_request($company, 'POST', $path, $payload);
    return evolution_normalize_send_result($r);
}

function evolution_normalize_send_result(array $r): array
{
    if ($r['ok'] && is_array($r['json'])) {
        $waId = $r['json']['key']['id']
             ?? $r['json']['messageId']
             ?? null;
        return [
            'ok'            => true,
            'wa_message_id' => $waId,
            'error'         => null,
            'http_code'     => $r['http_code'],
            'raw'           => $r['json'],
        ];
    }
    $err = $r['json']['message']
        ?? $r['json']['response']['message']
        ?? $r['json']['error']
        ?? ('HTTP ' . $r['http_code']);
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

// =============================================================
// Instance management (used by /admin/whatsapp_pair.php)
// =============================================================

/**
 * Create or fetch an instance. Idempotent-ish: if the instance already
 * exists, Evolution returns 403/409. We treat that as success.
 */
function evolution_create_instance(array $company): array
{
    if (!evolution_is_configured($company)) {
        return ['ok' => false, 'error' => 'Evolution not configured.'];
    }
    $r = evolution_request($company, 'POST', '/instance/create', [
        'instanceName' => evolution_instance_name($company),
        'qrcode'       => true,
        'integration'  => 'WHATSAPP-BAILEYS',
    ]);
    if ($r['ok']) {
        return ['ok' => true, 'data' => $r['json']];
    }
    // Already exists -> ok
    $msg = strtolower((string)($r['json']['message'] ?? $r['json']['response']['message'] ?? ''));
    if (str_contains($msg, 'already') || $r['http_code'] === 403 || $r['http_code'] === 409) {
        return ['ok' => true, 'data' => $r['json'], 'note' => 'Instance already existed.'];
    }
    return ['ok' => false, 'error' => $msg ?: ('HTTP ' . $r['http_code']), 'data' => $r['json']];
}

/**
 * Get a fresh QR code for pairing. Returns the base64 string (without the
 * "data:image/png;base64," prefix) or null if already connected.
 */
function evolution_get_qr(array $company): array
{
    if (!evolution_is_configured($company)) {
        return ['ok' => false, 'error' => 'Evolution not configured.'];
    }
    $path = '/instance/connect/' . rawurlencode(evolution_instance_name($company));
    $r = evolution_request($company, 'GET', $path);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => 'HTTP ' . $r['http_code'], 'raw' => $r['json']];
    }
    // Different Evolution versions return slightly different shapes
    $base64 = $r['json']['base64']
           ?? $r['json']['qrcode']['base64']
           ?? $r['json']['qrcode']
           ?? null;
    if (is_string($base64) && str_starts_with($base64, 'data:image')) {
        $parts = explode(',', $base64, 2);
        $base64 = $parts[1] ?? $base64;
    }
    return [
        'ok'      => true,
        'qr'      => $base64,
        'pairing' => $r['json']['pairingCode'] ?? null,
        'raw'     => $r['json'],
    ];
}

function evolution_connection_state(array $company): array
{
    if (!evolution_is_configured($company)) {
        return ['ok' => false, 'state' => 'disconnected', 'error' => 'Evolution not configured.'];
    }
    $path = '/instance/connectionState/' . rawurlencode(evolution_instance_name($company));
    $r = evolution_request($company, 'GET', $path);
    if (!$r['ok']) {
        return ['ok' => false, 'state' => 'disconnected', 'error' => 'HTTP ' . $r['http_code']];
    }
    $state = $r['json']['instance']['state']
          ?? $r['json']['state']
          ?? 'disconnected';
    // Evolution sometimes returns "open" for connected
    $map = ['open' => 'connected', 'connecting' => 'connecting', 'close' => 'disconnected'];
    $normalized = $map[$state] ?? $state;
    return ['ok' => true, 'state' => $normalized, 'raw' => $r['json']];
}

function evolution_logout_instance(array $company): array
{
    $path = '/instance/logout/' . rawurlencode(evolution_instance_name($company));
    $r = evolution_request($company, 'DELETE', $path);
    return ['ok' => $r['ok'], 'raw' => $r['json']];
}

/**
 * Fetch a message's media bytes as base64 from Evolution.
 *
 * Evolution v2.3.x rejects the per-webhook 'webhookBase64: true' setting
 * (sets it back to false) and its documented container env vars for
 * base64 don't reliably propagate through Docker either. So when a
 * webhook arrives for a media message WITHOUT the base64 field embedded,
 * we call this endpoint to fetch the bytes on demand.
 *
 * Endpoint: POST /chat/getBase64FromMediaMessage/<instance>
 * Body:    { "message": { "key": { "id": <wa_message_id> } },
 *            "convertToMp4": false }
 * Reply:   { "base64": "<b64>", "mediaType": "audioMessage", ... }
 *
 * Returns the raw base64 string on success, null when unavailable. The
 * caller is responsible for size-guarding and writing to disk — this
 * helper is intentionally scope-limited to "get me the bytes."
 */
function evolution_fetch_media_base64(array $company, string $waMessageId, bool $singleShot = false): ?string
{
    if (!evolution_is_configured($company) || $waMessageId === '') return null;
    $path = '/chat/getBase64FromMediaMessage/' . rawurlencode(evolution_instance_name($company));
    $body = [
        'message'      => ['key' => ['id' => $waMessageId]],
        'convertToMp4' => false,
    ];

    // Race: Evolution fires MESSAGES_UPSERT the instant WhatsApp
    // signals a new message, but Baileys hasn't finished downloading
    // the media bytes from Meta's CDN yet. getBase64 returns
    // "Message not found" until the media is fully cached — sometimes
    // 2-15 seconds later, occasionally minutes (a big voice note on
    // slow WhatsApp CDN, or an unreliable Baileys session).
    //
    // Webhook path: three attempts inline (0s / 1.5s / 3.5s ≈ 5s worst
    // case) so quick media renders immediately without needing the
    // cron sweeper.
    //
    // Cron path: cron/evolution_media_sync.php calls with
    // $singleShot=true — one attempt per candidate — because it re-runs
    // every minute for 15 minutes; blocking on retries here would let
    // a batch of 100 pending audios starve the sweeper's runtime.
    $sleeps = $singleShot ? [0] : [0, 1_500_000, 3_500_000]; // microseconds
    foreach ($sleeps as $i => $wait) {
        if ($wait > 0) usleep($wait);
        $r = evolution_request($company, 'POST', $path, $body);
        if ($r['ok']) {
            $b64 = $r['json']['base64'] ?? null;
            if (is_string($b64) && $b64 !== '') {
                // Some Evolution builds prepend a 'data:<mime>;base64,'
                // scheme — strip it so callers get raw base64.
                if (str_starts_with($b64, 'data:')) {
                    $comma = strpos($b64, ',');
                    if ($comma !== false) $b64 = substr($b64, $comma + 1);
                }
                if ($i > 0) {
                    error_log('[AiServe evolution] getBase64 succeeded on attempt '
                              . ($i + 1) . ' for ' . $waMessageId);
                }
                return $b64;
            }
        }
        // Only retry when the failure looks temporary — 400 "Message
        // not found" is the race we're chasing. Bail early on 401 /
        // 403 (auth broken — retrying won't help) or 5xx (Evolution
        // is down — same).
        $code = (int)($r['http_code'] ?? 0);
        if (in_array($code, [401, 403, 500, 502, 503, 504], true)) {
            error_log('[AiServe evolution] getBase64 hard-fail HTTP ' . $code . ' for ' . $waMessageId);
            return null;
        }
    }
    return null;
}

/**
 * Map a media MIME type onto a filename extension for on-disk storage.
 *
 * Shared by the webhook (immediate save) and cron/evolution_media_sync.php
 * (async sweep). Evolution sends WhatsApp voice notes with mime
 * 'audio/ogg; codecs=opus' — a naive string compare against 'audio/ogg'
 * misses because of the ';codecs=…' parameter suffix, and every voice
 * note ended up saved as '.bin' which browsers refuse to render as
 * audio. Strip the parameter and lowercase before matching so both
 * 'audio/ogg' and 'audio/ogg; codecs=opus' resolve to '.ogg'.
 */
function evolution_extension_for_mime(string $mime): string
{
    $bare = strtolower(trim(explode(';', $mime)[0]));
    static $map = [
        'image/jpeg'   => '.jpg', 'image/pjpeg' => '.jpg',
        'image/png'    => '.png',
        'image/webp'   => '.webp',
        'image/gif'    => '.gif',
        'image/heic'   => '.heic', 'image/heif' => '.heif',
        'audio/ogg'    => '.ogg',  'audio/opus' => '.opus',
        'audio/mpeg'   => '.mp3',  'audio/mp3'  => '.mp3',
        'audio/mp4'    => '.m4a',  'audio/aac'  => '.aac',
        'audio/wav'    => '.wav',  'audio/webm' => '.webm',
        'video/mp4'    => '.mp4',
        'video/3gpp'   => '.3gp',
        'video/quicktime' => '.mov',
        'video/webm'   => '.webm',
        'application/pdf' => '.pdf',
        'application/msword' => '.doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
        'application/vnd.ms-excel' => '.xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
    ];
    return $map[$bare] ?? '.bin';
}

/**
 * Tell Evolution to push events to our webhook.
 */
function evolution_set_webhook(array $company, string $webhookUrl): array
{
    if (!evolution_is_configured($company)) {
        return ['ok' => false, 'error' => 'Evolution not configured.'];
    }
    $path = '/webhook/set/' . rawurlencode(evolution_instance_name($company));
    $r = evolution_request($company, 'POST', $path, [
        'webhook' => [
            'enabled'  => true,
            'url'      => $webhookUrl,
            'webhookByEvents'    => false,
            'webhookBase64'      => true,
            'events'   => [
                'MESSAGES_UPSERT',
                'MESSAGES_UPDATE',
                'CONNECTION_UPDATE',
                'SEND_MESSAGE',
            ],
        ],
    ]);
    return ['ok' => $r['ok'], 'raw' => $r['json'], 'http_code' => $r['http_code']];
}

// =============================================================
// Errors
// =============================================================

function evolution_not_configured_error(): array
{
    return [
        'ok' => false, 'wa_message_id' => null,
        'error' => 'Evolution API is not configured. Set base URL, API key, and instance in Settings, then pair WhatsApp.',
        'http_code' => 0, 'raw' => null,
    ];
}
