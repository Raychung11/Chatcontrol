<?php
/**
 * AiServe Shared WhatsApp Inbox
 * Meta (Facebook + Instagram) app configuration.
 *
 * This is a single Meta App shared across all workspaces on the platform.
 * Each workspace connects their own Pages/IG Business accounts against it
 * via OAuth (see api/meta_oauth_start.php).
 *
 * How to fill this in:
 *   1. Go to https://developers.facebook.com/apps and open your app.
 *   2. Settings → Basic. Copy App ID and App Secret.
 *   3. Settings → Basic → App Domains: add your portal domain
 *      (e.g. inbox.aiserve.my).
 *   4. Add product "Facebook Login for Business". In its Settings:
 *        - Valid OAuth Redirect URIs:
 *            https://YOUR-DOMAIN/api/meta_oauth_callback.php
 *   5. Add product "Webhooks":
 *        - Callback URL: https://YOUR-DOMAIN/webhook/meta.php
 *        - Verify token: match META_WEBHOOK_VERIFY_TOKEN below
 *   6. Set the App to Live mode after App Review is approved. Until then,
 *      only users in App Roles can connect.
 *
 * Do NOT commit the App Secret. Use env vars in production.
 */

if (!defined('META_APP_ID')) {
    define('META_APP_ID', getenv('META_APP_ID') ?: '');
}

if (!defined('META_APP_SECRET')) {
    // NEVER hard-code the secret in source. Set via env var in production
    // (systemd Environment=, apache SetEnv, shared hosting env panel, etc).
    define('META_APP_SECRET', getenv('META_APP_SECRET') ?: '');
}

if (!defined('META_GRAPH_VERSION')) {
    // Meta bumps Graph API versions ~quarterly. Pin so behavior is
    // deterministic; update when Meta deprecates the current one.
    define('META_GRAPH_VERSION', getenv('META_GRAPH_VERSION') ?: 'v21.0');
}

if (!defined('META_WEBHOOK_VERIFY_TOKEN')) {
    // Random string you invent and paste into Meta App → Webhooks →
    // "Verify token". Meta echoes it back on the verification GET; we
    // compare and 200 only if it matches.
    define('META_WEBHOOK_VERIFY_TOKEN', getenv('META_WEBHOOK_VERIFY_TOKEN') ?: '');
}

/**
 * Permission scopes we request during OAuth. Keep this in one place
 * so the OAuth start and the App Review submission stay in lock-step.
 *
 * pages_show_list         : list Pages the user administers
 * pages_read_engagement   : read post + comment data
 * pages_manage_engagement : post replies, hide/delete comments
 * pages_manage_metadata   : subscribe the Page to our webhook
 * instagram_basic         : link IG Business account to Page
 * instagram_manage_comments : reply / hide / delete IG comments
 * business_management     : (only if you need to enumerate the user's
 *                            businesses -- often optional)
 *
 * NOT included yet (add later if we ship the private DM fallback):
 *   pages_messaging       : send a DM in reply to a public comment
 *   instagram_manage_messages : same for IG
 */
if (!defined('META_OAUTH_SCOPES')) {
    define('META_OAUTH_SCOPES', implode(',', [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_engagement',
        'pages_manage_metadata',
        'instagram_basic',
        'instagram_manage_comments',
    ]));
}

/**
 * Webhook fields we subscribe each Page to. Kept as an array so
 * the subscribe call and the App Review checklist stay in sync.
 */
if (!defined('META_PAGE_SUBSCRIBED_FIELDS')) {
    define('META_PAGE_SUBSCRIBED_FIELDS', implode(',', [
        'feed',      // new comments on the Page's posts
        // 'messages',  // Page inbox DMs -- future phase
    ]));
}

/**
 * Small helper — returns true iff the platform admin has configured a
 * Meta app. Callers should show a friendly "not configured yet" banner
 * instead of exploding when connect is attempted with no app.
 */
function meta_is_configured(): bool
{
    return META_APP_ID !== '' && META_APP_SECRET !== '';
}

/**
 * Base URL for Graph API calls, e.g.
 *   https://graph.facebook.com/v21.0
 */
function meta_graph_base(): string
{
    return 'https://graph.facebook.com/' . META_GRAPH_VERSION;
}
