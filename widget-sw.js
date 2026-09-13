/**
 * Widget service worker.
 *
 * Scope is /chat.php so the operator app's SW (scope /) doesn't fight
 * this one for widget URLs. Registration installs THIS worker for the
 * widget's URL; the operator SW never claims widget routes.
 *
 * Deliberately minimal: browsers require a registered SW to make a page
 * "installable" as a PWA, but we don't want to aggressively cache widget
 * responses (poll endpoints, dynamic session state). So:
 *
 *   install    — activate immediately
 *   activate   — claim clients
 *   fetch      — network-first with a graceful offline placeholder for
 *                the shell only
 */

const OFFLINE_HTML = `<!doctype html><html><body style="font-family:-apple-system,sans-serif;text-align:center;padding:60px 20px;color:#334155;">
<div style="font-size:48px;">📴</div>
<h2>You're offline</h2>
<p>Reconnect and try again — your conversation is safe on our server.</p>
</body></html>`;

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Only intercept navigations to the widget shell — everything else
    // (JSON polls, images, widget_manifest) goes straight to the network
    // so we never serve stale chat state.
    const isShellNav = req.mode === 'navigate' && new URL(req.url).pathname === '/chat.php';
    if (!isShellNav) return;

    event.respondWith(
        fetch(req).catch(() => new Response(OFFLINE_HTML, {
            headers: { 'Content-Type': 'text/html; charset=utf-8' },
            status: 200,
        }))
    );
});
