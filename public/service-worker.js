/* NOVA Finance service worker — app shell + offline queue sync handled in page JS */
const CACHE = 'nova-shell-v3';
const SHELL = [
  '/',
  '/offline.html',
  '/manifest.json',
  '/assets/css/app.css',
  '/assets/css/components.css',
  '/assets/js/api.js',
  '/assets/js/ui.js',
  '/assets/js/transactions.js',
  '/assets/js/charts.js',
  '/assets/js/app.js',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.pathname.startsWith('/api/')) {
    // Network-only for API
    event.respondWith(
      fetch(req).catch(() =>
        new Response(JSON.stringify({
          success: false,
          error: { code: 'OFFLINE', message: 'You are offline.' },
        }), { headers: { 'Content-Type': 'application/json' }, status: 503 })
      )
    );
    return;
  }

  event.respondWith(
    caches.match(req).then((cached) => {
      const fetched = fetch(req)
        .then((res) => {
          const copy = res.clone();
          if (res.ok && url.origin === self.location.origin) {
            caches.open(CACHE).then((cache) => cache.put(req, copy));
          }
          return res;
        })
        .catch(() => cached || caches.match('/offline.html'));
      return cached || fetched;
    })
  );
});
