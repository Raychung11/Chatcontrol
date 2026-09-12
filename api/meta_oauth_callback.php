<?php
/**
 * GET /api/meta_oauth_callback.php
 *
 * Facebook redirects here after the user approves. Query params:
 *   ?code=...&state=...              on success
 *   ?error=...&error_description=... on denial
 *
 * We:
 *   1. Validate `state` matches the one we stashed in the session.
 *   2. Exchange `code` -> short-lived user token.
 *   3. Upgrade short-lived -> long-lived user token (~60d).
 *   4. Call /me/accounts to list the Pages the user administers.
 *   5. Create one channel row per Page. Store the long-lived Page token
 *      (which does NOT expire) and, if IG platform was requested and the
 *      Page has a linked IG Business account, also store that id.
 *   6. Subscribe each Page to the webhook (feed field) so we start
 *      receiving new-comment events.
 *   7. Redirect back to /admin/channels.php with a success/error flash.
 *
 * We do NOT ship an intermediate "which Pages do you want?" picker in
 * this scaffolding — every Page returned by /me/accounts becomes a
 * channel. A picker UI is a small follow-up if it matters.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/meta_api.php';

$user = require_login();

if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin only.');
}

// Facebook uses GET on the redirect. Anything else is either someone
// probing the endpoint or a broken proxy.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$db = aiserve_db();

// ---------------------------------------------------------------------
// 0. Read + clear session state. If any of these are missing, the user
//    hit this URL without going through /api/meta_oauth_start.php.
// ---------------------------------------------------------------------
$expectedState = (string)($_SESSION['meta_oauth_state'] ?? '');
$platform      = (string)($_SESSION['meta_oauth_platform'] ?? 'facebook');
$companyId     = (int)($_SESSION['meta_oauth_company_id'] ?? 0);
unset(
    $_SESSION['meta_oauth_state'],
    $_SESSION['meta_oauth_platform'],
    $_SESSION['meta_oauth_company_id'],
    $_SESSION['meta_oauth_user_id']
);

if ($expectedState === '' || $companyId <= 0) {
    redirect('/admin/channels.php?error=' . rawurlencode('Session expired. Start the connect flow again.'));
}

// ---------------------------------------------------------------------
// 1. Handle user denial. Facebook redirects with ?error=access_denied
//    when the user clicks Cancel on the consent screen.
// ---------------------------------------------------------------------
if (!empty($_GET['error'])) {
    $desc = (string)($_GET['error_description'] ?? $_GET['error']);
    redirect('/admin/channels.php?error=' . rawurlencode($desc));
}

// ---------------------------------------------------------------------
// 2. CSRF: verify state matches. Missing/mismatched state means
//    someone is trying to redirect a victim through this callback.
// ---------------------------------------------------------------------
$returnedState = (string)($_GET['state'] ?? '');
if (!hash_equals($expectedState, $returnedState)) {
    redirect('/admin/channels.php?error=' . rawurlencode('OAuth state mismatch. Try again.'));
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    redirect('/admin/channels.php?error=' . rawurlencode('Missing authorization code.'));
}

// ---------------------------------------------------------------------
// 3. code -> short-lived user token
// ---------------------------------------------------------------------
$tokenResp = meta_exchange_code_for_token($code, meta_redirect_uri());
if (!$tokenResp['ok']) {
    redirect('/admin/channels.php?error=' . rawurlencode('Token exchange failed: ' . $tokenResp['error']));
}
$shortLived = (string)($tokenResp['data']['access_token'] ?? '');
if ($shortLived === '') {
    redirect('/admin/channels.php?error=' . rawurlencode('No access token returned.'));
}

// ---------------------------------------------------------------------
// 4. short-lived -> long-lived user token (~60 days). If this fails
//    we still proceed with the short-lived token; Page tokens derived
//    from either flavor are long-lived and non-expiring.
// ---------------------------------------------------------------------
$longResp = meta_exchange_for_long_lived_token($shortLived);
$userAccessToken = $longResp['ok']
    ? (string)($longResp['data']['access_token'] ?? $shortLived)
    : $shortLived;
$userTokenExpiresIn = (int)($longResp['data']['expires_in'] ?? 0);
$userTokenExpiresAt = $userTokenExpiresIn > 0
    ? date('Y-m-d H:i:s', time() + $userTokenExpiresIn)
    : null;

// ---------------------------------------------------------------------
// 5. Fetch Meta user id (for logging + future re-auth prompts).
// ---------------------------------------------------------------------
$meResp = meta_graph_request('GET', '/me', ['fields' => 'id,name'], $userAccessToken);
$metaUserId = $meResp['ok'] ? (string)($meResp['data']['id'] ?? '') : '';

// ---------------------------------------------------------------------
// 6. List Pages the user administers.
// ---------------------------------------------------------------------
$pagesResp = meta_list_pages($userAccessToken);
if (!$pagesResp['ok']) {
    redirect('/admin/channels.php?error=' . rawurlencode('Failed to list Pages: ' . $pagesResp['error']));
}
$pages = $pagesResp['data']['data'] ?? [];
if (!is_array($pages) || count($pages) === 0) {
    redirect('/admin/channels.php?error=' . rawurlencode('No Pages found on this Facebook account. The user must be an admin/editor/moderator of at least one Page.'));
}

// ---------------------------------------------------------------------
// 7. Create one channel per Page. Skip Pages already connected to this
//    workspace (idempotent — a customer clicking Connect twice doesn't
//    double up rows).
// ---------------------------------------------------------------------
$provider  = $platform === 'instagram' ? 'instagram_business' : 'facebook_page';
$fields    = META_PAGE_SUBSCRIBED_FIELDS;
$createdCount = 0;
$skippedCount = 0;
$errors       = [];

foreach ($pages as $p) {
    $pageId          = (string)($p['id'] ?? '');
    $pageName        = (string)($p['name'] ?? 'Page ' . $pageId);
    $pageAccessToken = (string)($p['access_token'] ?? '');
    if ($pageId === '' || $pageAccessToken === '') {
        continue;
    }

    // For IG: skip Pages that don't have an IG Business account linked.
    $igAccount = $p['instagram_business_account'] ?? null;
    if ($platform === 'instagram' && (!is_array($igAccount) || empty($igAccount['id']))) {
        $skippedCount++;
        continue;
    }
    $igId       = is_array($igAccount) ? (string)($igAccount['id'] ?? '') : '';
    $igUsername = is_array($igAccount) ? (string)($igAccount['username'] ?? '') : '';

    // Already connected? Update tokens (re-connect flow) and move on.
    $existsStmt = $db->prepare(
        'SELECT id FROM channels
         WHERE company_id = ? AND provider = ? AND meta_page_id = ?
         LIMIT 1'
    );
    $existsStmt->execute([$companyId, $provider, $pageId]);
    $existingId = (int)$existsStmt->fetchColumn();

    if ($existingId > 0) {
        $up = $db->prepare(
            'UPDATE channels
             SET meta_user_id = ?, meta_user_access_token = ?,
                 meta_page_access_token = ?, meta_ig_business_id = ?,
                 meta_ig_username = ?, meta_subscribed_fields = ?,
                 meta_connected_at = NOW(), meta_token_expires_at = ?,
                 status = "active"
             WHERE id = ?'
        );
        $up->execute([
            $metaUserId, $userAccessToken, $pageAccessToken,
            $igId ?: null, $igUsername ?: null, $fields,
            $userTokenExpiresAt, $existingId,
        ]);
        $skippedCount++;
    } else {
        $ins = $db->prepare(
            'INSERT INTO channels
             (company_id, name, display_phone, provider, webhook_token,
              meta_user_id, meta_user_access_token,
              meta_page_id, meta_page_access_token,
              meta_ig_business_id, meta_ig_username,
              meta_subscribed_fields, meta_connected_at, meta_token_expires_at,
              is_default, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0, "active")'
        );
        $displayHandle = $platform === 'instagram'
            ? ($igUsername !== '' ? '@' . $igUsername : $pageName)
            : $pageName;
        $webhookToken = substr(hash('sha256', $companyId . '-' . $pageId . '-' . random_bytes(8)), 0, 48);
        $ins->execute([
            $companyId, $pageName, $displayHandle, $provider, $webhookToken,
            $metaUserId, $userAccessToken,
            $pageId, $pageAccessToken,
            $igId ?: null, $igUsername ?: null,
            $fields, $userTokenExpiresAt,
        ]);
        $createdCount++;
    }

    // Subscribe the Page to our webhook so we start getting events.
    // A failure here is non-fatal — the channel row exists; the operator
    // can retry subscribe from the channel detail page later.
    $subResp = meta_subscribe_page_to_app($pageId, $pageAccessToken, $fields);
    if (!$subResp['ok']) {
        $errors[] = $pageName . ': subscribe failed — ' . $subResp['error'];
    }
}

log_activity(
    $companyId,
    (int)$user['id'],
    'meta_connected',
    'channel',
    null,
    sprintf(
        '%s OAuth: %d Page(s) connected, %d re-connected/skipped%s',
        $platform,
        $createdCount,
        $skippedCount,
        $errors ? ' (' . count($errors) . ' subscribe errors)' : ''
    )
);

if ($createdCount === 0 && $skippedCount === 0) {
    redirect('/admin/channels.php?error=' . rawurlencode('No usable ' . ($platform === 'instagram' ? 'IG Business accounts' : 'Pages') . ' found on this account.'));
}

// Non-fatal partial-error path: still show success but flag subscribe errors.
if (!empty($errors)) {
    redirect('/admin/channels.php?connected=' . $platform
        . '&error=' . rawurlencode(implode('; ', $errors)));
}

redirect('/admin/channels.php?connected=' . $platform);
