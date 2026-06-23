<?php
/**
 * Multi-channel helpers.
 *
 * Each company can hold multiple "channels" - each channel is one WhatsApp
 * number with its own provider config and its own webhook URL. Conversations
 * and messages are stamped with a channel_id so the inbox can filter by
 * channel, reports can break down by channel, and outbound sends pick the
 * right provider config.
 *
 * Backwards compatibility: an old webhook URL without ?ch=<token> still
 * works by falling back to the workspace's is_default = 1 channel. This is
 * what existing tenants' partner gateway URLs already point at.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Look up a channel by its webhook_token. The token is the secret in the
 * inbound webhook URL - we treat it as unguessable (48 hex chars).
 */
function channel_by_token(string $token): ?array
{
    if ($token === '') return null;
    $stmt = aiserve_db()->prepare(
        'SELECT * FROM channels WHERE webhook_token = ? AND status = "active" LIMIT 1'
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function channel_by_id(int $id): ?array
{
    if ($id <= 0) return null;
    $stmt = aiserve_db()->prepare('SELECT * FROM channels WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function channel_default_for_company(int $companyId): ?array
{
    $stmt = aiserve_db()->prepare(
        'SELECT * FROM channels
         WHERE company_id = ? AND status = "active"
         ORDER BY is_default DESC, id ASC LIMIT 1'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetch() ?: null;
}

function channel_for_conversation(array $conversation): ?array
{
    $cid = (int)($conversation['channel_id'] ?? 0);
    if ($cid > 0) return channel_by_id($cid);
    return channel_default_for_company((int)$conversation['company_id']);
}

/**
 * Resolve the channel for an inbound webhook request:
 *   1. ?ch=<token> picks one channel directly (preferred).
 *   2. Fallback: legacy ?company=<slug> + ?token=<verify_token> uses the
 *      first/default channel of that workspace.
 *   3. Last resort: ACTIVE_COMPANY_ID's default channel (single-tenant
 *      installs that haven't been multi-tenant-migrated yet).
 */
function resolve_channel_for_webhook(): ?array
{
    $token = trim((string)($_GET['ch'] ?? ''));
    if ($token !== '') {
        return channel_by_token($token);
    }

    $companySlug = trim((string)($_GET['company'] ?? ''));
    if ($companySlug !== '') {
        $stmt = aiserve_db()->prepare(
            'SELECT id FROM companies WHERE slug = ? AND status = "active" LIMIT 1'
        );
        $stmt->execute([$companySlug]);
        $companyId = (int)$stmt->fetchColumn();
        if ($companyId > 0) {
            return channel_default_for_company($companyId);
        }
    }

    return channel_default_for_company((int)ACTIVE_COMPANY_ID);
}

/**
 * Build the inbound webhook URL the operator pastes into the provider
 * (Meta / Evolution / partner gateway). Channel-token based, so the URL
 * stays stable across workspace renames.
 */
function channel_webhook_url(array $channel, string $endpoint): string
{
    $host = APP_BASE_URL ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''));
    return $host . $endpoint . '?ch=' . urlencode((string)$channel['webhook_token']);
}

function channel_generate_webhook_token(): string
{
    return substr(bin2hex(random_bytes(24)), 0, 48);
}
