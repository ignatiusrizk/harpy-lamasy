// sw-tenant.js — Service Worker tenant LAMASY (POS, orders, kanban, dashboard)
// Scope: '/' — exclude /pelanggan/* (portal pelanggan has own SW di /sw.js)

const CACHE = 'lamasy-tenant-v2';

const STATIC_ASSETS = [
  '/assets/harpy-erp.css',
  '/assets/manifest-tenant.json',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  '/assets/logo.png',
  '/assets/offline-pos.js',
];

const READ_MOSTLY_PATHS = [
  '/orders',
  '/customer',
  '/kanban',
  '/dashboard',
];

const POS_PATHS = ['/pos', '/pos.php'];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => c.addAll(STATIC_ASSETS).catch(err => console.warn('[SW] Some assets failed cache:', err)))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => k !== CACHE && k.startsWith('lamasy-tenant'))
            .map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);

  // Skip pelanggan portal (handled by /sw.js)
  if (url.pathname.startsWith('/pelanggan')) return;
  // Skip kurir mobile + droppoint (different auth flow)
  if (url.pathname.startsWith('/kurir') || url.pathname.startsWith('/droppoint')) return;
  // Skip superadmin (different domain segment)
  if (url.pathname.startsWith('/superadmin')) return;

  // Skip non-GET (writes harus selalu network)
  if (req.method !== 'GET') return;
  // Skip cross-origin
  if (url.origin !== self.location.origin) return;

  // Static assets: cache-first
  if (STATIC_ASSETS.includes(url.pathname) || url.pathname.startsWith('/assets/')) {
    e.respondWith(cacheFirst(req));
    return;
  }

  // Write actions: network-only (extra paranoia, browsers also skip POST cache)
  if (url.search.includes('action=save')
      || url.search.includes('action=create')
      || url.search.includes('action=update')
      || url.search.includes('action=delete')
      || url.search.includes('action=bulk_')) {
    return; // default network
  }

  // POS shell (navigasi halaman saja, bukan fetch API): stale-while-revalidate
  if (req.mode === 'navigate' && POS_PATHS.includes(url.pathname)) {
    e.respondWith(staleWhileRevalidate(req));
    return;
  }

  // Read-mostly pages: network-first + cache fallback
  if (READ_MOSTLY_PATHS.some(p =>
        url.pathname === p ||
        url.pathname.startsWith(p + '/') ||
        url.pathname.startsWith(p + '.php'))) {
    e.respondWith(networkFirstWithCache(req));
    return;
  }

  // Navigasi halaman lain (mis. /hq/*, /login, /) → JANGAN di-intercept SW.
  // Biarkan webview/browser memuat natively supaya redirect (mis. 302 → /login)
  // & session diikuti benar, dan tak memicu errorPath APK. Cold-start offline
  // ditangani Capacitor errorPath (offline.html) di sisi APK.

  // Default: network-only
});

function cacheFirst(req) {
  return caches.match(req).then(cached => cached || fetch(req).then(resp => {
    if (resp.ok) caches.open(CACHE).then(c => c.put(req, resp.clone()));
    return resp;
  }).catch(() => cached || new Response('Offline', { status: 503 })));
}

function networkFirstWithCache(req) {
  return fetch(req).then(resp => {
    if (resp.ok) {
      const clone = resp.clone();
      caches.open(CACHE).then(c => c.put(req, clone)).catch(() => {});
    }
    return resp;
  }).catch(() => caches.match(req).then(cached => cached || offlinePage()));
}

function staleWhileRevalidate(req) {
  return caches.open(CACHE).then(cache =>
    cache.match(req).then(cached => {
      const network = fetch(req).then(resp => {
        if (resp && resp.ok && !resp.redirected) cache.put(req, resp.clone()).catch(() => {});
        return resp;
      }).catch(() => null);
      return cached || network.then(r => r || offlinePage());
    })
  );
}

function offlinePage() {
  return new Response(
    '<!doctype html><html lang="id"><head><meta charset=utf-8>'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>Offline — LAMASY</title>'
    + '<style>'
    + 'body{font-family:system-ui,-apple-system,sans-serif;margin:0;padding:40px;'
    + 'text-align:center;background:#0F1C3A;color:#fff;min-height:100vh;box-sizing:border-box;'
    + 'display:flex;align-items:center;justify-content:center}'
    + '.box{max-width:340px}.icon{font-size:64px;margin-bottom:20px}'
    + 'h2{margin:0 0 12px;color:#35E8D5}'
    + 'p{color:rgba(255,255,255,.7);line-height:1.55;margin:0 0 24px}'
    + 'button{background:#35E8D5;color:#0F1C3A;border:0;padding:12px 24px;'
    + 'border-radius:8px;font-weight:700;font-size:14px;cursor:pointer}'
    + '</style></head><body><div class="box">'
    + '<div class="icon">📡</div>'
    + '<h2>Tidak ada koneksi</h2>'
    + '<p>Halaman ini butuh internet untuk update data. Cek koneksi atau buka halaman yang sudah pernah dibuka sebelumnya.</p>'
    + '<button onclick="location.reload()">Coba lagi</button>'
    + '</div></body></html>',
    { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
  );
}
