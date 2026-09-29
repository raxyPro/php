/* rctask service worker.
 * - App files (CSS, JS, icons): cache first, refreshed in the background.
 * - Pages: network first; offline shows the last loaded app page, else offline.html.
 * - api.php GET: network first, falls back to the last copy (read-only offline).
 * - api.php POST (saves, deletes, AI parse): always network, never cached.
 * Bump VERSION whenever you change app.js / app.css so phones pick up the update.
 */
const VERSION = 'rctask-v2';
const SHELL = [
  'assets/app.css?v=2',
  'assets/app.js?v=2',
  'assets/icon.svg',
  'assets/icon-192.png',
  'assets/icon-512.png',
  'manifest.webmanifest',
  'offline.html',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

const put = (req, res) => {
  if (res && res.ok && !res.redirected && res.type !== 'opaqueredirect') {
    const copy = res.clone();
    caches.open(VERSION).then((c) => c.put(req, copy));
  }
  return res;
};

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // POSTs go straight to the server
  const url = new URL(req.url);

  // Google Fonts: stale-while-revalidate
  if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    e.respondWith(caches.match(req).then((hit) => {
      const net = fetch(req).then((r) => put(req, r)).catch(() => hit);
      return hit || net;
    }));
    return;
  }
  if (url.origin !== location.origin) return;

  const file = url.pathname.split('/').pop();

  // Sign-in pages are never cached
  if (['login.php', 'register.php', 'logout.php'].includes(file)) return;

  // API reads: network first, last copy when offline
  if (file === 'api.php') {
    e.respondWith(fetch(req).then((r) => put(req, r)).catch(() =>
      caches.match(req).then((hit) => hit || new Response(JSON.stringify({ error: 'You are offline.' }), {
        status: 503, headers: { 'Content-Type': 'application/json' },
      }))
    ));
    return;
  }

  // Page loads: network first, cached app page or offline.html when offline
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).then((r) => {
      if (file === '' || file === 'index.php') put(new Request('index.php'), r);
      return r;
    }).catch(() => caches.match('index.php').then((hit) => hit || caches.match('offline.html'))));
    return;
  }

  // Static files: cache first, update in background
  e.respondWith(caches.match(req).then((hit) => {
    const net = fetch(req).then((r) => put(req, r)).catch(() => hit);
    return hit || net;
  }));
});
