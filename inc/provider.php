<?php
/**
 * Provider dispatch layer.
 *
 * The portal supports three messaging providers per company:
 *   - cloud_api       : Meta WhatsApp Business Cloud API   (official, paid)
 *   - evolution       : Evolution API on top of Baileys    (self-hosted, unofficial)
 *   - aiserve_chatbot : custom Bearer-token gateway in front of Evolution
 *                       (partner-hosted, see chatbot.aiserve.my)
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
require_once __DIR__ . '/aiserve_chatbot_api.php';

function provider_name(array $company): string
{
    $p = (string)($company['provider'] ?? 'cloud_api');
    return in_array($p, ['cloud_api', 'evolution', 'aiserve_chatbot'], true) ? $p : 'cloud_api';
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
    return match (provider_name($company)) {
        'evolution'       => evolution_send_text($company, $waId, $text),
        'aiserve_chatbot' => chatbot_send_text($company, $waId, $text),
        default           => whatsapp_send_text($company, $waId, $text),
    };
}

/**
 * @param string $kind     'image' | 'video' | 'audio' | 'document'
 * @param string $mediaRef For Cloud: Meta media_id.
 *                         For Evolution / AiServe Chatbot: local file path.
 */
function provider_send_media(array $company, string $waId, string $kind, string $mediaRef, ?string $caption = null, ?string $filename = null, ?string $mime = null): array
{
    return match (provider_name($company)) {
        'evolution'       => evolution_send_media($company, $waId, $kind, $mediaRef, $caption, $filename, $mime),
        'aiserve_chatbot' => chatbot_send_media($company, $waId, $kind, $mediaRef, $caption, $filename, $mime),
        default           => whatsapp_send_media($company, $waId, $kind, $mediaRef, $caption, $filename),
    };
}

function provider_send_template(array $company, string $waId, string $templateName, string $language, array $bodyParams): array
{
    if (provider_name($company) !== 'cloud_api') {
        return [
            'ok' => false, 'wa_message_id' => null,
            'error' => 'Templates are only supported on Meta Cloud API. Send plain text or media instead.',
            'http_code' => 400, 'raw' => null,
        ];
    }
    return whatsapp_send_template($company, $waId, $templateName, $language, $bodyParams);
}

/**
 * Upload a local file and return a reference suitable for provider_send_media().
 *
 * For Cloud API     : uploads to Meta and returns its media_id.
 * For Evolution     : no upload needed; the local file path IS the reference.
 * For AiServe Chatbot: no upload needed; we pass the local file path. The
 *                     gateway will be given a signed public URL at send time.
 */
function provider_upload_media(array $company, string $localPath, string $mime): array
{
    if (provider_name($company) !== 'cloud_api') {
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
