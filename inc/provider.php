<?php
/**
 * Provider dispatch layer.
 *
 * The portal supports two messaging providers per company:
 *   - cloud_api : Meta WhatsApp Business Cloud API  (official, paid)
 *   - evolution : Evolution API on top of Baileys    (self-hosted, unofficial)
 *
 * All outbound send paths and admin tooling go through these dispatch
 * functions instead of calling Meta endpoints directly. The right provider
 * client is chosen based on $company['provider'].
 *
 * Each provider client returns a normalized result shape:
 *   ['ok' => bool, 'wa_message_id' => ?string, 'error' => ?string,
 *    'http_code' => int, 'raw' => mixed]
 */

require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/evolution_api.php';

function provider_name(array $company): string
{
    $p = (string)($company['provider'] ?? 'cloud_api');
    return $p === 'evolution' ? 'evolution' : 'cloud_api';
}

function provider_supports_templates(array $company): bool
{
    return provider_name($company) === 'cloud_api';
}

function provider_enforces_24h_window(array $company): bool
{
    return provider_name($company) === 'cloud_api';
}

function provider_send_text(array $company, string $waId, string $text): array
{
    return provider_name($company) === 'evolution'
        ? evolution_send_text($company, $waId, $text)
        : whatsapp_send_text($company, $waId, $text);
}

/**
 * @param string $kind     'image' | 'video' | 'audio' | 'document'
 * @param string $mediaRef For Cloud: Meta media_id. For Evolution: local file path.
 */
function provider_send_media(array $company, string $waId, string $kind, string $mediaRef, ?string $caption = null, ?string $filename = null, ?string $mime = null): array
{
    if (provider_name($company) === 'evolution') {
        return evolution_send_media($company, $waId, $kind, $mediaRef, $caption, $filename, $mime);
    }
    return whatsapp_send_media($company, $waId, $kind, $mediaRef, $caption, $filename);
}

function provider_send_template(array $company, string $waId, string $templateName, string $language, array $bodyParams): array
{
    if (provider_name($company) === 'evolution') {
        return [
            'ok' => false, 'wa_message_id' => null,
            'error' => 'Templates are not supported on Evolution. Send a plain text or media message instead.',
            'http_code' => 400, 'raw' => null,
        ];
    }
    return whatsapp_send_template($company, $waId, $templateName, $language, $bodyParams);
}

/**
 * Upload a local file and return a reference suitable for provider_send_media().
 *
 * For Cloud API: uploads to Meta and returns its media_id.
 * For Evolution: no upload step is required; the local file path is the reference.
 *
 * @return array{ok:bool, media_ref:?string, error:?string}
 */
function provider_upload_media(array $company, string $localPath, string $mime): array
{
    if (provider_name($company) === 'evolution') {
        return [
            'ok'        => true,
            'media_ref' => $localPath,
            'error'     => null,
        ];
    }
    $r = whatsapp_upload_media($company, $localPath, $mime);
    return [
        'ok'        => $r['ok'],
        'media_ref' => $r['media_id'],
        'error'     => $r['error'],
    ];
}
