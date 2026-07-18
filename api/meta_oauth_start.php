<?php
/**
 * POST /api/meta_oauth_start.php
 *
 * Kicks off the Facebook Login OAuth flow. We stash which platform
 * (facebook / instagram) the operator wanted so we can label the
 * channels correctly on callback, plus a CSRF-bound `state` param that
 * Facebook echoes back — we compare it in the callback to prevent
 * cross-site OAuth attacks.
 *
 * We POST (not GET) here so the button click is CSRF-protected end to end.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/meta_api.php';

$user = require_login();

if (!is_post()) {
    http_response_code(405);
    exit('Method Not Allowed');
}
csrf_check();

if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin only.');
}
if (is_impersonating()) {
    // Connecting a Page while impersonating would attach it to the
    // impersonated workspace via session state that's easy to confuse.
    // Force the operator to sign out of the impersonation first.
    redirect('/dashboard.php');
}
if (!meta_is_configured()) {
    redirect('/admin/channel_connect_meta.php?platform=facebook');
}

$platform = (string)($_POST['platform'] ?? 'facebook');
if (!in_array($platform, ['facebook', 'instagram'], true)) {
    $platform = 'facebook';
}

// Anti-CSRF state token bound to this session. Facebook echoes it back on
// the callback — we compare and reject on mismatch.
$state = bin2hex(random_bytes(16));
$_SESSION['meta_oauth_state']      = $state;
$_SESSION['meta_oauth_platform']   = $platform;
$_SESSION['meta_oauth_company_id'] = (int)$user['company_id'];
$_SESSION['meta_oauth_user_id']    = (int)$user['id'];

$authUrl = meta_build_auth_url(
    meta_redirect_uri(),
    META_OAUTH_SCOPES,
    $state
);

header('Location: ' . $authUrl, true, 302);
exit;
