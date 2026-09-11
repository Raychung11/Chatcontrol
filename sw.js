/**
 * AiServe Inbox - Service Worker
 *
 * Lightweight by design: caches static assets aggressively so the app shell
 * loads instantly when installed, but DOES NOT intercept any HTML / API
 * traffic. Conversations and webhook flow stay 100% network-fresh - we
 * never want stale chat data.
 */

const CACHE = 'aiserve-static-v1';
const STATIC_ASSETS = [
  '/manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(STATIC_ASSETS).catch(() => {}))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);

  // Only intercept static assets. Everything else goes straight to network.
  const isStatic =
    url.pathname.startsWith('/assets/') ||
    url.pathname === '/manifest.json' ||
    url.pathname === '/favicon.ico';

  if (!isStatic) return;

  event.respondWith(
    caches.match(event.request).then(
      (cached) =>
        cached ||
        fetch(event.request).then((resp) => {
          // Cache the response for next time (clone before consuming).
          if (resp.ok && resp.status === 200) {
            const copy = resp.clone();
            caches.open(CACHE).then((cache) => cache.put(event.request, copy)).catch(() => {});
          }
          return resp;
        })
    )
  );
});

// Notify pages when a new SW is ready (so the app can show "refresh for
// the latest version" if it wants to). Optional - not used yet.
self.addEventListener('message', (event) => {
  if (event.data === 'skip-waiting') self.skipWaiting();
});

// -----------------------------------------------------------------
// Web Push — new customer message notifications (RFC 8291 aes128gcm)
//
// The dispatcher (inc/push.php) encrypts a small JSON payload and
// posts it to the push service; the browser wakes this SW even if
// every tab is closed. We render a system notification and route
// the click back into the portal.
//
// Payload shape (from push_send_to_user in inc/push.php):
//   { title, body, url, tag, icon?, badge? }
// -----------------------------------------------------------------
self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; }
  catch (_) { data = { title: 'New message', body: event.data ? event.data.text() : '' }; }

  const title = data.title || 'New message';
  const opts = {
    body:  data.body || '',
    icon:  data.icon  || '/assets/img/icon-192.png',
    badge: data.badge || '/assets/img/icon-192.png',
    // tag replaces a prior notification with the same key — so
    // two customer messages on the same conversation collapse into
    // one on the lock screen instead of stacking.
    tag:   data.tag || 'aiserve-msg',
    renotify: true,
    // The URL to open on click, plus any extras a future feature
    // might want to read from the notification.
    data:  { url: data.url || '/inbox/' },
    requireInteraction: false,
  };

  event.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/inbox/';

  // Focus an existing tab on the same origin if one is open on any
  // AiServe page — the agent jumps straight there without spawning
  // yet another tab. Only fall back to openWindow when nothing is
  // open.
  event.waitUntil((async () => {
    const clientsList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of clientsList) {
      try {
        // Same origin only.
        const u = new URL(c.url);
        if (u.origin === self.location.origin) {
          await c.focus();
          if ('navigate' in c) { try { await c.navigate(target); } catch (_) {} }
          return;
        }
      } catch (_) {}
    }
    if (self.clients.openWindow) {
      await self.clients.openWindow(target);
    }
  })());
});
