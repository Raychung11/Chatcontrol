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
