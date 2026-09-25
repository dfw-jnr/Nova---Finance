/* NOVA Finance service worker — static assets only (never cache HTML redirects) */
const CACHE = 'nova-shell-v9';
const SHELL = [
  '/offline.html',
  '/manifest.json',
  '/assets/css/app.css?v=9',
  '/assets/css/components.css?v=9',
  '/assets/js/api.js?v=9',
  '/assets/js/ui.js?v=9',
  '/assets/js/transactions.js?v=9',
  '/assets/js/charts.js?v=9',
  '/assets/js/receipt.js?v=9',
  '/assets/js/app.js?v=9',
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

  // HTML: network only (no cached redirects)
  if (req.mode === 'navigate' || req.destination === 'document') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/offline.html'))
    );
    return;
  }

  // CSS/JS: network-first so footer/style fixes reach phones quickly
  if (/\.(css|js)$/.test(url.pathname) || url.searchParams.has('v')) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res.ok && !res.redirected && res.type === 'basic') {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(req, copy));
          }
          return res;
        })
        .catch(() => caches.match(req).then((c) => c || caches.match('/offline.html')))
    );
    return;
  }

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
