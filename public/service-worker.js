/* NOVA Finance service worker — static assets only (never cache HTML redirects) */
const CACHE = 'nova-shell-v5';
const SHELL = [
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
    caches.open(CACHE)
      .then((cache) =>
        Promise.all(
          SHELL.map((url) =>
            cache.add(url).catch(() => {
              /* ignore missing optional assets during install */
            })
          )
        )
      )
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) =>
        Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // API: network only
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(req).catch(
        () =>
          new Response(
            JSON.stringify({
              success: false,
              error: { code: 'OFFLINE', message: 'You are offline.' },
            }),
            { headers: { 'Content-Type': 'application/json' }, status: 503 }
          )
      )
    );
    return;
  }

  // HTML navigations: network only — never serve cached redirects (Safari crashes)
  if (req.mode === 'navigate' || req.destination === 'document') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/offline.html'))
    );
    return;
  }

  // Static assets: cache-first, never store redirects
  event.respondWith(
    caches.match(req).then((cached) => {
      const fetched = fetch(req)
        .then((res) => {
          if (res.ok && !res.redirected && res.type === 'basic') {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(req, copy));
          }
          return res;
        })
        .catch(() => cached || caches.match('/offline.html'));
      return cached || fetched;
    })
  );
});
