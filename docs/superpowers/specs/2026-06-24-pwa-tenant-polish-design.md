# PWA Tenant Polish — Design Spec

**Tanggal:** 2026-06-24 (Asia/Jakarta)
**Status:** Draft — ready for plan generation
**Scope:** Polish PWA untuk tenant-side (kasir / owner) supaya install-able + offline-capable, **tanpa** pindah ke native app.

## Latar Belakang

State PWA saat ini cuma cover portal pelanggan (`/pelanggan/*`):
- ✅ `assets/manifest.json` ada — TAPI scope `/pelanggan` only
- ✅ `sw.js` ada — TAPI cache cuma `/pelanggan/*` paths
- ❌ Tenant-side (POS, orders, kanban, dashboard) **tidak install-able**, tidak ada offline cache
- ❌ Icon cuma 1 file, no multiple sizes proper
- ❌ No install prompt UI untuk kasir
- ❌ No push notification setup

User di outlet sering ngeluh harus buka Chrome → ketik URL manual setiap pagi. Native app jadi ide muncul tapi effort $30-50K + 6 bulan. PWA polish = 1-2 minggu untuk 80% UX gain.

## Tujuan

1. **Tenant app install-able** — owner/kasir bisa "Add to Home Screen" di Android Chrome / iOS Safari, dapat icon di home screen
2. **Offline-capable** untuk read-only pages — kasir tetap bisa LIHAT data (orders list, customer list, struk template) walau internet putus
3. **Splash screen + theme** — looks native saat dibuka
4. **Install prompt UI** — banner "Install LAMASY" di dashboard kalau belum install
5. **Push notification web** (Phase 2 opsional) — notifikasi customer ada order baru tanpa harus buka aplikasi

## Non-Tujuan

- **NOT** offline WRITE — submit order offline butuh sync queue. Defer ke spec terpisah.
- **NOT** native app (React Native / Flutter / Capacitor) — keep web tech
- **NOT** rewrite frontend
- **NOT** push notif full setup (Firebase Cloud Messaging) — opsional Phase 2

## Pendekatan

### Step 1: Manifest tenant-app

Buat `assets/manifest-tenant.json` baru (atau extend existing). Scope `/`:

```json
{
  "name": "LAMASY — Laundry POS",
  "short_name": "LAMASY",
  "description": "POS & manajemen laundry untuk kasir & owner",
  "start_url": "/dashboard",
  "scope": "/",
  "display": "standalone",
  "orientation": "any",
  "theme_color": "#0F1C3A",
  "background_color": "#0F1C3A",
  "icons": [
    {"src": "/assets/icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any"},
    {"src": "/assets/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any"},
    {"src": "/assets/icon-maskable-192.png", "sizes": "192x192", "type": "image/png", "purpose": "maskable"},
    {"src": "/assets/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"}
  ],
  "shortcuts": [
    {"name": "Order Baru", "url": "/pos", "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]},
    {"name": "Daftar Order", "url": "/orders", "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]},
    {"name": "Kanban", "url": "/kanban", "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]}
  ],
  "categories": ["business", "productivity"]
}
```

### Step 2: Generate proper icons

User design icon LAMASY (256×256 source), generate sizes via online tool atau ImageMagick:
- `icon-192.png` (192×192) — Android home screen
- `icon-512.png` (512×512) — splash screen high-res
- `icon-maskable-192.png` — Android adaptive icon (padding 10% safe zone)
- `icon-maskable-512.png` — high-res maskable
- `apple-touch-icon-180.png` (180×180) — iOS home screen
- `favicon.ico` — browser tab

### Step 3: Service worker tenant-app

Buat `sw-tenant.js` baru (atau extend existing `sw.js`). Strategy:

```js
// sw-tenant.js — Service Worker tenant app
const CACHE = 'lamasy-tenant-v1';

// Static shells (selalu cache)
const STATIC_ASSETS = [
  '/assets/harpy-erp.css',
  '/assets/manifest-tenant.json',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  // CSS framework, fonts
];

// Pages yang berguna kalau offline (read-only fallback)
const OFFLINE_PAGES = [
  '/orders',          // lihat list orders dari cache terakhir
  '/customer',        // lihat customer list
  '/kanban',          // lihat board status terakhir
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // Skip non-GET
  if (e.request.method !== 'GET') return;

  // Static: cache-first
  if (STATIC_ASSETS.includes(url.pathname) || url.pathname.startsWith('/assets/')) {
    e.respondWith(cacheFirst(e.request));
    return;
  }

  // POS / order create / write actions: network-only (no cache, must be online)
  if (url.pathname === '/pos' ||
      url.search.includes('action=save') ||
      url.search.includes('action=create')) {
    e.respondWith(fetch(e.request));
    return;
  }

  // Pages yang read-mostly: network-first + cache fallback (stale-while-revalidate)
  if (OFFLINE_PAGES.some(p => url.pathname.startsWith(p))) {
    e.respondWith(networkFirstWithCache(e.request));
    return;
  }

  // Lain-lain: network-only
});

function cacheFirst(req) {
  return caches.match(req).then(cached => cached || fetch(req).then(resp => {
    if (resp.ok) caches.open(CACHE).then(c => c.put(req, resp.clone()));
    return resp;
  }));
}

function networkFirstWithCache(req) {
  return fetch(req).then(resp => {
    if (resp.ok) caches.open(CACHE).then(c => c.put(req, resp.clone()));
    return resp;
  }).catch(() => caches.match(req).then(cached => cached || new Response(
    '<!doctype html><meta charset=utf-8>'
    + '<style>body{font-family:sans-serif;padding:40px;text-align:center;color:#666}</style>'
    + '<h2>📡 Offline</h2><p>Halaman ini butuh internet untuk update data.</p>'
    + '<p>Cek koneksi atau buka halaman yang sudah di-cache.</p>'
    + '<a href="javascript:location.reload()" style="color:#35E8D5">Coba lagi</a>',
    { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
  )));
}
```

### Step 4: Register service worker + manifest di tenant pages

Edit `components.php` (atau `tenant_guard.php`) untuk include di `<head>` setiap tenant page:

```php
<link rel="manifest" href="/assets/manifest-tenant.json">
<meta name="theme-color" content="#0F1C3A">

<!-- iOS PWA meta tags -->
<link rel="apple-touch-icon" href="/assets/apple-touch-icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="LAMASY">

<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw-tenant.js')
      .then(reg => console.log('SW registered:', reg.scope))
      .catch(err => console.warn('SW register failed:', err));
  });
}
</script>
```

Skip kalau di pelanggan portal (sudah ada SW sendiri).

### Step 5: Install prompt banner

JS snippet di dashboard.php (atau components.php top):

```js
let deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredInstallPrompt = e;
  // Tampilkan banner kalau belum dismiss
  if (!localStorage.getItem('pwa_install_dismissed')) {
    document.getElementById('pwaInstallBanner')?.classList.remove('hidden');
  }
});

function installPWA() {
  if (!deferredInstallPrompt) return;
  deferredInstallPrompt.prompt();
  deferredInstallPrompt.userChoice.then(choice => {
    if (choice.outcome === 'accepted') {
      console.log('PWA installed');
    }
    document.getElementById('pwaInstallBanner')?.classList.add('hidden');
    deferredInstallPrompt = null;
  });
}

function dismissInstallBanner() {
  localStorage.setItem('pwa_install_dismissed', '1');
  document.getElementById('pwaInstallBanner')?.classList.add('hidden');
}
```

Plus HTML banner di dashboard.php (atas page, conditional):

```html
<div id="pwaInstallBanner" class="hidden" style="background:#0F1C3A;color:#fff;padding:12px 16px;display:flex;gap:12px;align-items:center">
  <img src="/assets/icon-192.png" width="40" height="40" style="border-radius:8px">
  <div style="flex:1">
    <div style="font-weight:700">Install LAMASY</div>
    <div style="font-size:12px;color:rgba(255,255,255,.7)">Akses cepat dari home screen, tanpa buka browser.</div>
  </div>
  <button onclick="installPWA()" class="hl-btn hl-btn-primary hl-btn-sm">Install</button>
  <button onclick="dismissInstallBanner()" class="hl-btn hl-btn-outline hl-btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3)">Nanti</button>
</div>
```

### Step 6: iOS-specific Add-to-Home prompt (manual)

iOS Safari **tidak punya `beforeinstallprompt`** event. Harus kasih instruksi manual. Detect iOS + show banner berbeda:

```js
function isIOS() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}

if (isIOS() && !window.matchMedia('(display-mode: standalone)').matches
    && !localStorage.getItem('pwa_install_dismissed')) {
  // Show iOS-specific banner: "Tap ⎘ → Add to Home Screen"
  document.getElementById('pwaIosBanner')?.classList.remove('hidden');
}
```

### Step 7: Apple-specific assets (opsional)

Apple splash screen image. Either skip (Apple bisa generate dari icon) atau provide:
- `apple-touch-startup-image-*.png` various sizes (effort tinggi karena banyak resolusi)

Skip dulu, accept default Apple-generated splash.

## Komponen

### File baru

| File | Action | LOC change |
|------|--------|------------|
| `assets/manifest-tenant.json` | Create | +30 |
| `sw-tenant.js` | Create | +80 |
| `assets/icon-192.png` etc | Create (user provide source) | binary |
| `components.php` | Edit `<head>` insertion + JS install handler | +25 |
| `dashboard.php` | Edit (install banner HTML) | +15 |

Total ~150 baris diff + binary assets.

## Testing strategy

### Manual via MCP browser:
1. Buka `/dashboard` di Chrome desktop — verify manifest valid (DevTools → Application → Manifest)
2. Verify service worker registered (DevTools → Application → Service Workers)
3. Trigger install via DevTools "Install" button
4. Disconnect network (DevTools → Network → Offline) — buka `/orders` — verify cache fallback
5. Reconnect — verify network-first works again

### Real device:
1. Buka di Android Chrome — verify install banner muncul → install → check home screen icon
2. Buka di iOS Safari — verify ada Add to Home Screen prompt → tap → check home screen
3. Offline test: enable airplane mode → buka app dari home screen → verify offline state OK

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Service worker cache stale data | MEDIUM | Versioning via `lamasy-tenant-v1`, `v2`, dll. Bump version per release. |
| Cache fill device storage | LOW | Browser auto-evict LRU. Limit cache size <50MB. |
| Install prompt nag user | LOW | localStorage dismiss persisted. Hanya show pertama kali. |
| iOS install UX kurang clear | MEDIUM | Banner instruksi manual + screenshot. Test di Safari iOS real. |
| Service worker conflict dengan existing `/sw.js` (pelanggan portal) | MEDIUM | Pelanggan portal scope `/pelanggan` punya SW sendiri. Tenant SW scope `/` exclude `/pelanggan/*` agar tidak override. |

### Mitigation untuk SW conflict:

```js
// sw-tenant.js
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  // Skip /pelanggan/* — biarkan SW portal-pelanggan handle
  if (url.pathname.startsWith('/pelanggan')) return;
  // ... rest
});
```

## Out of Scope (Phase 2)

- **Web Push notifications** — perlu VAPID keys + subscription endpoint + send infra. Effort tambahan ~1 minggu.
- **Offline WRITE / sync queue** — order create offline lalu sync. Effort ~3-4 minggu.
- **Background sync** — kalau internet hidup lagi, auto-retry failed requests. Effort ~1 minggu.
- **Capacitor wrap** — submit ke Play/App Store. Effort 3-4 minggu kalau decide.

## Timeline Estimate

| Step | Effort |
|------|--------|
| 1. Manifest tenant-app | 30 menit |
| 2. Generate icons (user provide source) | 1-2 jam |
| 3. Service worker tenant-app | 2-3 jam |
| 4. Register di components.php | 30 menit |
| 5. Install prompt banner + JS | 1 jam |
| 6. iOS-specific banner | 30 menit |
| 7. Testing MCP + real device | 2 jam |

**Total: 1-1.5 hari** untuk solo dev. Kalau include desainer icon proper: tambah 1-2 hari.

## Implementation Plan

Pisah file: `docs/superpowers/plans/2026-06-24-pwa-tenant-polish.md` (next step setelah spec approved).

## Dependencies

- **User provide**: source icon 512×512 (atau lebih besar) dengan transparency. Saya bisa generate sizes dari source pakai ImageMagick/Squoosh CLI.
- **No backend changes** — semua frontend-only.
- **No DB migration** — zero schema impact.
- **Auto-deploy compatible** — push ke main, Hostinger deploy 15s. Service worker akan update otomatis di client (versioning manages it).
