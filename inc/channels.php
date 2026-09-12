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

/**
 * Detect channels that appear to have stopped receiving inbound
 * messages. Called from the inbox / dashboard to render a warning
 * banner the operator sees the moment they open the app — the
 * common "5 hours of chat went missing" moment.
 *
 * A channel is flagged when ALL of these are true:
 *   - It's active (channels.status = 'active')
 *   - It's a real inbound provider (not web_chat, which is
 *     entry-point rather than a listener that can go silent)
 *   - It has received at least one inbound message historically
 *     (so a brand-new never-used channel doesn't false-alarm)
 *   - The last inbound was more than $staleHours ago
 *
 * Returns [ { channel_id, name, provider, minutes_since,
 *             last_inbound_at, total_recent } ] sorted by
 * minutes_since DESC (worst offender first).
 *
 * Cheap: two prepared queries per channel is overkill on a page
 * with 20+ channels, so we do it in ONE SQL round-trip with an
 * INNER JOIN + LEFT JOIN.
 */
function channels_stale_ingestion(int $companyId, int $staleHours = 6): array
{
    if ($companyId <= 0) return [];
    try {
        $stmt = aiserve_db()->prepare(
            "SELECT c.id AS channel_id, c.name, c.provider,
                    MAX(m.created_at) AS last_inbound_at,
                    SUM(m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent_count
             FROM channels c
             INNER JOIN messages m
                ON m.channel_id = c.id
               AND m.direction  = 'incoming'
             WHERE c.company_id = ?
               AND c.status     = 'active'
               AND c.provider IN ('cloud_api', 'evolution', 'aiserve_chatbot',
                                  'facebook_page', 'instagram_business')
             GROUP BY c.id, c.name, c.provider
             HAVING recent_count > 0
                AND last_inbound_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY last_inbound_at ASC"
        );
        $stmt->execute([$companyId, $staleHours]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[AiServe channels_stale_ingestion] ' . $e->getMessage());
        return [];
    }
    $now = time();
    foreach ($rows as &$r) {
        $lastTs = db_datetime_to_ts((string)$r['last_inbound_at']) ?? 0;
        $r['minutes_since']    = max(0, (int)round(($now - $lastTs) / 60));
        $r['total_recent']     = (int)$r['recent_count'];
    }
    return $rows;
}

/**
 * Render the stale-channel warning banner as a string of HTML. Empty
 * string when there's nothing to warn about. Kept as a helper so
 * every page (inbox, dashboard, F&B orders) can drop it in with one
 * call and stay consistent. Uses only inline styles so it works
 * without page-specific CSS.
 */
function channels_stale_banner_html(int $companyId, int $staleHours = 6): string
{
    $stale = channels_stale_ingestion($companyId, $staleHours);
    if (!$stale) return '';

    $lines = [];
    foreach ($stale as $s) {
        $hrs = (int)floor($s['minutes_since'] / 60);
        $mins = (int)$s['minutes_since'] % 60;
        $ago = $hrs > 0 ? ($hrs . 'h ' . $mins . 'm') : ($mins . 'm');
        $lines[] = '<strong>' . htmlspecialchars((string)$s['name'], ENT_QUOTES, 'UTF-8')
                 . '</strong> <span style="opacity:.75;">(' . htmlspecialchars((string)$s['provider'], ENT_QUOTES, 'UTF-8') . ')</span>'
                 . ' — last inbound <strong>' . $ago . '</strong> ago';
    }
    $body = implode('<br>', $lines);

    return '<div class="alert-stale-channels" style="margin: 0 0 12px 0; padding: 12px 14px;'
         . ' background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px;'
         . ' color: #7f1d1d; font-size: 13.5px; line-height: 1.5;">'
         . '<div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">'
         . '<span style="font-size:22px; line-height:1;">⚠️</span>'
         . '<div style="flex:1; min-width:0;">'
         . '<strong style="color:#991b1b;">Channel silence detected — messages may be stuck at the gateway.</strong><br>'
         . $body . '<br>'
         . '<span style="color:#7c2d12;">Check the provider dashboard: WhatsApp Cloud API webhook status, Evolution QR reconnect, or aiserve_chatbot delivery queue. '
         . '<a href="/admin/channels_health.php" style="color:#991b1b;">Run health checks →</a></span>'
         . '</div></div></div>';
}
