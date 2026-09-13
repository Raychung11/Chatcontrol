<?php
/**
 * Per-widget PWA manifest.
 *
 * URL: /widget_manifest.php?c=<channel_token>
 *
 * When a customer visits /chat.php?c=TOKEN and installs "Add to home
 * screen", their phone shows the workspace's brand (name + logo), not
 * a generic AiServe icon. Each widget installs as its own app so a
 * phone can have both "Kopetro Restaurant" and "Kedai Ali" on the
 * home screen if they're regulars of both.
 *
 * scope = /chat.php ensures the installed app opens the widget on
 * launch instead of somehow ending up on the operator dashboard.
 * That path prefix is more specific than the operator manifest's
 * scope=/ so both installs coexist on the same phone / same browser.
 */

require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/channels.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$token = trim((string)($_GET['c'] ?? ''));
if ($token === '' || !ctype_alnum($token)) {
    http_response_code(404);
    exit('{}');
}

$channel = channel_by_token($token);
if (!$channel || $channel['provider'] !== 'web_chat' || $channel['status'] !== 'active') {
    http_response_code(404);
    exit('{}');
}

// Resolve display name + icons.
$companyId   = (int)$channel['company_id'];
$stmt        = aiserve_db()->prepare('SELECT name, logo FROM companies WHERE id = ? LIMIT 1');
$stmt->execute([$companyId]);
$company     = $stmt->fetch() ?: [];
$companyName = trim((string)($company['name'] ?? 'Chat'));
$widgetTitle = trim((string)($channel['web_chat_title'] ?? '')) ?: $companyName;

// Short name is limited to 12 chars in most launchers before it gets
// truncated ugly. Snip cleanly on a word boundary if possible.
$shortName = $widgetTitle;
if (mb_strlen($shortName) > 12) {
    $shortName = mb_substr($widgetTitle, 0, 12);
    $lastSpace = mb_strrpos($shortName, ' ');
    if ($lastSpace !== false && $lastSpace >= 6) $shortName = mb_substr($shortName, 0, $lastSpace);
}

// Prefer the workspace's uploaded logo; fall back to a generic chat
// icon (uses the operator's PWA icon endpoint with a chat glyph
// overlay). company_logo.php handles both cases — returns the uploaded
// PNG if present, else a lettered fallback based on company name.
$iconBase = '/assets/img/company_logo.php?company_id=' . $companyId . '&v=' . pwa_icon_version();

echo json_encode([
    'name'             => $widgetTitle,
    'short_name'       => $shortName,
    'description'      => 'Chat with ' . $companyName,
    'start_url'        => '/chat.php?c=' . rawurlencode($token),
    'scope'            => '/chat.php',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#ffffff',
    'theme_color'      => '#25D366',
    'categories'       => ['business', 'communication'],
    'icons'            => [
        [
            'src'     => $iconBase . '&size=192',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => $iconBase . '&size=512',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => $iconBase . '&size=512&maskable=1',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
