<?php
/**
 * PWA manifest, served dynamically so we can stamp every icon URL with
 * the current version. Same file replaces the previous static
 * /manifest.json — pwa_head_tags() now points at /manifest.php.
 *
 * Android/Chrome consult the manifest for the icons shown on the
 * install prompt + the home-screen icon after install. Without the
 * version query parameter they'd cache the icon indefinitely (icon.php
 * emits Cache-Control: immutable), so uploading a new brand icon via
 * /admin/branding.php would appear to do nothing.
 */

require_once __DIR__ . '/inc/helpers.php';

header('Content-Type: application/manifest+json; charset=utf-8');
// Manifest itself is short-lived — we want browsers to recheck this
// file often so an icon version bump propagates quickly.
header('Cache-Control: public, max-age=300');

$v = pwa_icon_version();

echo json_encode([
    'name'             => 'AiServe Inbox',
    'short_name'       => 'AiServe',
    'description'      => 'Shared WhatsApp Inbox with AI reply assist, knowledge base, and live multi-agent collaboration.',
    'start_url'        => '/dashboard.php',
    'scope'            => '/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#ffffff',
    'theme_color'      => '#25D366',
    'categories'       => ['business', 'productivity', 'communication'],
    'icons'            => [
        [
            'src'     => '/assets/img/icon.php?size=192&v=' . $v,
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => '/assets/img/icon.php?size=512&v=' . $v,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => '/assets/img/icon.php?size=512&maskable=1&v=' . $v,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
    'shortcuts' => [
        [
            'name'        => 'Inbox',
            'short_name'  => 'Inbox',
            'description' => 'Jump straight to the shared inbox',
            'url'         => '/inbox/index.php',
            'icons'       => [[
                'src'   => '/assets/img/icon.php?size=96&v=' . $v,
                'sizes' => '96x96',
                'type'  => 'image/png',
            ]],
        ],
        [
            'name'        => 'Dashboard',
            'short_name'  => 'Dashboard',
            'description' => 'Open your dashboard',
            'url'         => '/dashboard.php',
            'icons'       => [[
                'src'   => '/assets/img/icon.php?size=96&v=' . $v,
                'sizes' => '96x96',
                'type'  => 'image/png',
            ]],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
