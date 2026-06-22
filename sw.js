// sw.js — Service Worker minimal untuk PWA pelanggan
const CACHE = 'lamasy-pelanggan-v1';
const STATIC_ASSETS = [
  '/assets/logo.png',
  '/assets/manifest.json',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATIC_ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  // Static assets: cache-first
  if (STATIC_ASSETS.some(a => url.pathname === a) || url.pathname.startsWith('/assets/')) {
    e.respondWith(
      caches.match(e.request).then(cached => cached || fetch(e.request).then(resp => {
        if (resp.ok) caches.open(CACHE).then(c => c.put(e.request, resp.clone()));
        return resp;
      }))
    );
    return;
  }
  // Portal pages: network-first, fallback offline message
  if (url.pathname === '/pelanggan' || url.pathname.startsWith('/pelanggan-order')) {
    e.respondWith(
      fetch(e.request).catch(() => new Response(
        '<!doctype html><meta charset=utf-8><div style="font-family:sans-serif;padding:40px;text-align:center;color:#666"><h2>&#x1F4E1; Offline</h2><p>Tidak ada koneksi internet.</p><a href="javascript:location.reload()" style="color:#35E8D5">Coba lagi</a></div>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
      ))
    );
    return;
  }
  // Other: default
});
