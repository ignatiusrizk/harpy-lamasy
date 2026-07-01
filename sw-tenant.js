// sw-tenant.js — DINONAKTIFKAN (kill switch / self-destruct).
//
// SW offline sebelumnya (cache shell POS + navigationFallback) menyebabkan
// navigasi di webview APK gagal → memicu Capacitor errorPath (offline.html)
// pada SEMUA halaman. Untuk stabil: SW tidak lagi meng-intercept apa pun,
// menghapus cache lama, dan meng-unregister dirinya. Semua request → network natif.
// (Offline-POS via SW akan didesain ulang terpisah bila diperlukan.)

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    try {
      const keys = await caches.keys();
      await Promise.all(
        keys.filter(k => k.startsWith('lamasy-tenant')).map(k => caches.delete(k))
      );
    } catch (_) {}
    try { await self.registration.unregister(); } catch (_) {}
    try { await self.clients.claim(); } catch (_) {}
  })());
});

// Tidak ada listener 'fetch' → SW tidak pernah meng-intercept request.
