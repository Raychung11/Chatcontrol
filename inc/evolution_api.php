<?php
/**
 * Evolution API client (Baileys-based, unofficial WhatsApp).
 *
 * Endpoint contract (Evolution API v2):
 *   Auth header:  apikey: <evolution_api_key>
 *   Base URL:     companies.evolution_base_url   e.g. https://evo.example.com
 *   Instance:     companies.evolution_instance   e.g. "aiserve-prod"
 *
 * Phase 3 commit 2 fills in the real implementations. This file currently
 * provides safe stubs that report "not configured" so the dispatch layer in
 * inc/provider.php can be wired up first without breaking Cloud API users.
 */

require_once __DIR__ . '/helpers.php';

function evolution_is_configured(array $company): bool
{
    return !empty($company['evolution_base_url'])
        && !empty($company['evolution_api_key'])
        && !empty($company['evolution_instance']);
}

function evolution_send_text(array $company, string $waId, string $text): array
{
    if (!evolution_is_configured($company)) {
        return evolution_not_configured_error();
    }
    // TODO commit 2: POST {base}/message/sendText/{instance} {number, text}
    return evolution_not_implemented_error('sendText');
}

function evolution_send_media(array $company, string $waId, string $kind, string $localPath, ?string $caption = null, ?string $filename = null, ?string $mime = null): array
{
    if (!evolution_is_configured($company)) {
        return evolution_not_configured_error();
    }
    // TODO commit 2: POST {base}/message/sendMedia/{instance}
    return evolution_not_implemented_error('sendMedia');
}

function evolution_not_configured_error(): array
{
    return [
        'ok' => false, 'wa_message_id' => null,
        'error' => 'Evolution API is not configured. Set base URL, API key, and instance in Settings, then pair WhatsApp.',
        'http_code' => 0, 'raw' => null,
    ];
}

function evolution_not_implemented_error(string $op): array
{
    return [
        'ok' => false, 'wa_message_id' => null,
        'error' => 'Evolution provider not yet implemented (' . $op . '). Coming in Phase 3 commit 2.',
        'http_code' => 0, 'raw' => null,
    ];
}
