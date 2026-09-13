<?php
/**
 * Meta Graph API client — Facebook Pages + Instagram Business.
 *
 * Kept separate from whatsapp_api.php because although WhatsApp Cloud API
 * is also on graph.facebook.com, its scopes / token model / endpoint
 * shape are different enough that mixing them makes both harder to reason
 * about. Anything shared can migrate to a common http helper later.
 */

require_once __DIR__ . '/../config/meta_config.php';

/**
 * Perform an HTTPS request against Graph API and decode JSON.
 *
 * @param string $method  'GET' | 'POST' | 'DELETE'
 * @param string $path    Graph path AFTER the version, e.g. '/me/accounts'
 * @param array  $params  Query params for GET, form/body params for POST
 * @param string|null $accessToken  Bearer / access_token to attach
 * @return array          { ok, http_code, data, error }
 */
function meta_graph_request(string $method, string $path, array $params = [], ?string $accessToken = null): array
{
    $method = strtoupper($method);
    $url    = meta_graph_base() . $path;

    if ($accessToken !== null && $accessToken !== '') {
        $params['access_token'] = $accessToken;
    }

    if ($method === 'GET' || $method === 'DELETE') {
        if (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } elseif ($method === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    }
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false, 'http_code' => $httpCode, 'data' => null,
            'error' => 'Network error: ' . $curlErr,
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false, 'http_code' => $httpCode, 'data' => null,
            'error' => 'Invalid response: ' . substr($response, 0, 200),
        ];
    }

    if ($httpCode >= 400 || isset($decoded['error'])) {
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
        return [
            'ok' => false, 'http_code' => $httpCode, 'data' => $decoded,
            'error' => $msg,
        ];
    }

    return ['ok' => true, 'http_code' => $httpCode, 'data' => $decoded, 'error' => null];
}

/**
 * Exchange the short-lived OAuth code for a short-lived user access token.
 *
 * Uses the standard OAuth2 code flow. Endpoint returns:
 *   { access_token, token_type, expires_in }
 */
function meta_exchange_code_for_token(string $code, string $redirectUri): array
{
    return meta_graph_request('GET', '/oauth/access_token', [
        'client_id'     => META_APP_ID,
        'client_secret' => META_APP_SECRET,
        'redirect_uri'  => $redirectUri,
        'code'          => $code,
    ]);
}

/**
 * Trade a short-lived user token for a long-lived (~60 day) one.
 *
 *   fb_exchange_token flow — see:
 *   https://developers.facebook.com/docs/facebook-login/guides/access-tokens/get-long-lived
 *
 * Note: Long-lived USER tokens expire (~60d) but the PAGE tokens we
 * subsequently derive from them via /me/accounts do NOT expire, so we
 * only need to keep the user token around long enough to fetch Page
 * tokens once, then we can rely on Page tokens.
 */
function meta_exchange_for_long_lived_token(string $shortLivedToken): array
{
    return meta_graph_request('GET', '/oauth/access_token', [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => META_APP_ID,
        'client_secret'     => META_APP_SECRET,
        'fb_exchange_token' => $shortLivedToken,
    ]);
}

/**
 * List all Pages the authenticated user administers.
 *
 * Returns Page objects with { id, name, access_token, category,
 * instagram_business_account }.
 */
function meta_list_pages(string $userAccessToken): array
{
    return meta_graph_request('GET', '/me/accounts', [
        'fields' => 'id,name,category,access_token,tasks,instagram_business_account{id,username,profile_picture_url}',
        'limit'  => 100,
    ], $userAccessToken);
}

/**
 * Subscribe a Page to our webhook so we receive new comment events.
 *
 * The Page must have granted pages_manage_metadata for this to succeed.
 * subscribed_fields is a comma-separated list from META_PAGE_SUBSCRIBED_FIELDS.
 */
function meta_subscribe_page_to_app(string $pageId, string $pageAccessToken, string $subscribedFields): array
{
    return meta_graph_request('POST', '/' . rawurlencode($pageId) . '/subscribed_apps', [
        'subscribed_fields' => $subscribedFields,
    ], $pageAccessToken);
}

/**
 * Post a public reply to a comment.
 *
 *   POST /{comment-id}/comments  { message }
 *
 * Works for both FB and IG comments — the comment id namespace is
 * platform-tagged in practice, and Graph routes correctly.
 */
function meta_reply_to_comment(string $commentId, string $pageAccessToken, string $message): array
{
    return meta_graph_request('POST', '/' . rawurlencode($commentId) . '/comments', [
        'message' => $message,
    ], $pageAccessToken);
}

/**
 * Hide (visually collapse for other viewers) or delete a comment.
 *
 * Hide is preferred over delete for compliance: the commenter never sees
 * their comment vanish (they can still see it themselves), which reduces
 * "you deleted my comment!!" complaints.
 */
function meta_hide_comment(string $commentId, string $pageAccessToken, bool $hide = true): array
{
    return meta_graph_request('POST', '/' . rawurlencode($commentId), [
        'is_hidden' => $hide ? 'true' : 'false',
    ], $pageAccessToken);
}

function meta_delete_comment(string $commentId, string $pageAccessToken): array
{
    return meta_graph_request('DELETE', '/' . rawurlencode($commentId), [], $pageAccessToken);
}

/**
 * Fetch a post preview (media, caption, permalink) for showing agents the
 * context of the comment they're about to reply to.
 */
function meta_fetch_post_preview(string $postId, string $pageAccessToken): array
{
    return meta_graph_request('GET', '/' . rawurlencode($postId), [
        'fields' => 'id,message,permalink_url,created_time,full_picture,attachments{media_type,media{image{src}},url}',
    ], $pageAccessToken);
}

function meta_fetch_ig_media_preview(string $mediaId, string $pageAccessToken): array
{
    return meta_graph_request('GET', '/' . rawurlencode($mediaId), [
        'fields' => 'id,caption,permalink,media_type,media_url,thumbnail_url,timestamp',
    ], $pageAccessToken);
}

/**
 * Build the Facebook Login authorization URL.
 *
 * scopes  — CSV string of permission names
 * state   — CSRF token bound to the session; we verify it on callback
 */
function meta_build_auth_url(string $redirectUri, string $scopes, string $state): string
{
    $q = http_build_query([
        'client_id'     => META_APP_ID,
        'redirect_uri'  => $redirectUri,
        'state'         => $state,
        'scope'         => $scopes,
        'response_type' => 'code',
        // 'auth_type' => 'rerequest',  // set when re-requesting a denied permission
    ]);
    return 'https://www.facebook.com/' . META_GRAPH_VERSION . '/dialog/oauth?' . $q;
}

/**
 * Compute the absolute redirect URI. Meta compares this against the
 * "Valid OAuth Redirect URIs" list in the App console and rejects any
 * mismatch, so it must be deterministic.
 */
function meta_redirect_uri(): string
{
    $base = defined('APP_BASE_URL') ? rtrim((string)APP_BASE_URL, '/') : '';
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
        $base   = $scheme . '://' . $host;
    }
    return $base . '/api/meta_oauth_callback.php';
}
