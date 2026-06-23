# PWA Tenant Polish — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans atau superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Polish PWA tenant-side (POS, orders, kanban, dashboard) supaya install-able + offline-capable di Android & iOS, tanpa native app rewrite.

**Architecture:** Frontend-only changes. Tambah manifest tenant + service worker tenant + install prompt UI. Existing PWA pelanggan portal scope `/pelanggan` tetap separate.

**Tech Stack:** Vanilla JS service worker, Web App Manifest, no framework.

## Global Constraints

- **NO backend changes** — pure frontend
- **NO DB migration** — zero schema impact
- **NO break existing pelanggan portal PWA** (scope `/pelanggan` separate)
- **Auto-deploy compatible** — push ke main, Hostinger deploy ~15s
- **Service worker versioning** — bump `CACHE` const setiap kali tambah/ubah cached resource
- **iOS Safari quirks** — tidak punya `beforeinstallprompt`, harus manual instructional banner

---

### Task 1: Generate icon assets

**Files:**
- Create: `assets/icon-192.png` (192×192)
- Create: `assets/icon-512.png` (512×512)
- Create: `assets/icon-maskable-192.png` (192×192 with 10% safe-zone padding)
- Create: `assets/icon-maskable-512.png` (512×512 with safe-zone)
- Create: `assets/apple-touch-icon-180.png` (180×180)

**Interfaces:**
- Produces: PNG files served via `/assets/*`
- Consumed by: manifest-tenant.json (Task 2)

**Pre-req:** User provide source icon (recommended 1024×1024 PNG with transparent bg, atau pakai existing `assets/logo.png`).

- [ ] **Step 1: Verify source icon ada**

Run: `ls -la /Users/rizky/Documents/lamasy/assets/logo.png`
Expected: file exists. Kalau tidak, minta user upload source ke `/tmp/lamasy-icon-source.png` first.

- [ ] **Step 2: Generate sizes via ImageMagick (atau online tool)**

Run:
```bash
cd /Users/rizky/Documents/lamasy/assets
SRC=logo.png  # atau path source kalau di-upload terpisah

# Standard icons
magick "$SRC" -resize 192x192 icon-192.png
magick "$SRC" -resize 512x512 icon-512.png
magick "$SRC" -resize 180x180 apple-touch-icon-180.png

# Maskable: add 10% padding di semua sisi (safe zone untuk adaptive icon Android)
magick "$SRC" -resize 154x154 -background "#0F1C3A" -gravity center -extent 192x192 icon-maskable-192.png
magick "$SRC" -resize 410x410 -background "#0F1C3A" -gravity center -extent 512x512 icon-maskable-512.png
```

Kalau `magick` (ImageMagick v7) tidak ada, pakai `convert` (v6).

- [ ] **Step 3: Verify hasil**

Run: `cd /Users/rizky/Documents/lamasy/assets && ls -la icon-*.png apple-touch-icon*.png && identify icon-*.png`
Expected: 5 file generated, sizes match.

- [ ] **Step 4: Commit binary assets**

```bash
cd /Users/rizky/Documents/lamasy
git add assets/icon-192.png assets/icon-512.png assets/icon-maskable-192.png assets/icon-maskable-512.png assets/apple-touch-icon-180.png
git commit -m "chore(pwa): generate tenant app icon assets

5 sizes: 192/512 standard + 192/512 maskable + 180 apple-touch.
Generated dari assets/logo.png via ImageMagick.
Maskable include 10% safe-zone padding (background #0F1C3A LAMASY navy)."
```

---

### Task 2: Manifest tenant-app

**Files:**
- Create: `assets/manifest-tenant.json`

**Interfaces:**
- Produces: `/assets/manifest-tenant.json` served sebagai application/manifest+json
- Consumed by: components.php `<link rel="manifest">` (Task 4)
- References: icons from Task 1

- [ ] **Step 1: Write manifest-tenant.json**

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
  "lang": "id",
  "dir": "ltr",
  "icons": [
    {"src": "/assets/icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any"},
    {"src": "/assets/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any"},
    {"src": "/assets/icon-maskable-192.png", "sizes": "192x192", "type": "image/png", "purpose": "maskable"},
    {"src": "/assets/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"}
  ],
  "shortcuts": [
    {
      "name": "Order Baru",
      "short_name": "POS",
      "description": "Buat order laundry baru",
      "url": "/pos",
      "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]
    },
    {
      "name": "Daftar Order",
      "short_name": "Orders",
      "description": "Lihat semua order",
      "url": "/orders",
      "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]
    },
    {
      "name": "Kanban",
      "short_name": "Kanban",
      "description": "Board status produksi",
      "url": "/kanban",
      "icons": [{"src": "/assets/icon-192.png", "sizes": "192x192"}]
    }
  ],
  "categories": ["business", "productivity"]
}
```

- [ ] **Step 2: Verify JSON valid**

Run: `cd /Users/rizky/Documents/lamasy && python3 -c "import json; json.load(open('assets/manifest-tenant.json'))" && echo OK`
Expected: prints "OK".

- [ ] **Step 3: Commit**

```bash
git add assets/manifest-tenant.json
git commit -m "feat(pwa): manifest tenant-app

Scope '/', start_url '/dashboard' — install ke home screen Android
Chrome / iOS Safari install-able pakai 'Add to Home Screen'.

Shortcuts: POS, Orders, Kanban — right-click app icon Android = quick
launch menu.

Theme/background #0F1C3A (LAMASY navy)."
```

---

### Task 3: Service worker tenant-app

**Files:**
- Create: `sw-tenant.js` (root, supaya scope `/`)

**Interfaces:**
- Produces: SW script registered di components.php (Task 4)
- Cache name: `lamasy-tenant-v1` (bump version on assets change)

**Important:** SW must be served from root atau use `Service-Worker-Allowed` header. Untuk simplicity, taruh di root `/sw-tenant.js` supaya scope default = root.

- [ ] **Step 1: Write sw-tenant.js**

```js
// sw-tenant.js — Service Worker tenant LAMASY (POS, orders, kanban)
// Scope: '/' (everything except /pelanggan/* which has its own SW)

const CACHE = 'lamasy-tenant-v1';

const STATIC_ASSETS = [
  '/assets/harpy-erp.css',
  '/assets/manifest-tenant.json',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  '/assets/logo.png',
];

const READ_MOSTLY_PATHS = [
  '/orders',
  '/customer',
  '/kanban',
  '/dashboard',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => c.addAll(STATIC_ASSETS).catch(err => console.warn('Some assets failed cache:', err)))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE && k.startsWith('lamasy-tenant')).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // Skip pelanggan portal — handled by sw.js
  if (url.pathname.startsWith('/pelanggan')) return;

  // Skip non-GET (writes harus selalu network)
  if (e.request.method !== 'GET') return;

  // Skip cross-origin (CDN, fonts, dll)
  if (url.origin !== self.location.origin) return;

  // Static assets: cache-first
  if (STATIC_ASSETS.includes(url.pathname) || url.pathname.startsWith('/assets/')) {
    e.respondWith(cacheFirst(e.request));
    return;
  }

  // Write actions: network-only (jangan cache POST sucks anyway, ini extra paranoia)
  if (url.search.includes('action=save')
      || url.search.includes('action=create')
      || url.search.includes('action=update')
      || url.search.includes('action=delete')
      || url.search.includes('action=bulk_')) {
    return; // default network handling
  }

  // Read-mostly pages: network-first dengan cache fallback
  if (READ_MOSTLY_PATHS.some(p => url.pathname === p || url.pathname.startsWith(p + '/') || url.pathname.startsWith(p + '?'))) {
    e.respondWith(networkFirstWithCache(e.request));
    return;
  }

  // Default: network-only
});

function cacheFirst(req) {
  return caches.match(req).then(cached => cached || fetch(req).then(resp => {
    if (resp.ok) caches.open(CACHE).then(c => c.put(req, resp.clone()));
    return resp;
  }));
}

function networkFirstWithCache(req) {
  return fetch(req).then(resp => {
    if (resp.ok) {
      const clone = resp.clone();
      caches.open(CACHE).then(c => c.put(req, clone));
    }
    return resp;
  }).catch(() => caches.match(req).then(cached => cached || offlinePage()));
}

function offlinePage() {
  return new Response(
    '<!doctype html><html lang="id"><head><meta charset=utf-8>'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>Offline — LAMASY</title>'
    + '<style>body{font-family:system-ui,sans-serif;margin:0;padding:40px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh;box-sizing:border-box}'
    + '.box{max-width:340px;margin:60px auto}.icon{font-size:64px;margin-bottom:20px}'
    + 'h2{margin:0 0 12px;color:#35E8D5}p{color:rgba(255,255,255,.7);line-height:1.55;margin:0 0 24px}'
    + 'button{background:#35E8D5;color:#0F1C3A;border:0;padding:12px 24px;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer}'
    + '</style></head><body><div class="box">'
    + '<div class="icon">📡</div>'
    + '<h2>Tidak ada koneksi</h2>'
    + '<p>Halaman ini butuh internet untuk update data. Cek koneksi atau buka halaman yang sudah pernah dibuka.</p>'
    + '<button onclick="location.reload()">Coba lagi</button>'
    + '</div></body></html>',
    { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
  );
}
```

- [ ] **Step 2: Verify syntax**

Run: `cd /Users/rizky/Documents/lamasy && node -c sw-tenant.js 2>&1 | head -5`
Expected: no output (no syntax error). Kalau node tidak ada, skip (browser will catch).

- [ ] **Step 3: Commit**

```bash
git add sw-tenant.js
git commit -m "feat(pwa): service worker tenant-app

sw-tenant.js dengan strategy:
- Static assets: cache-first
- /pelanggan/*: SKIP (handled by sw.js portal)
- POST + write actions (action=save/create/update/delete/bulk_):
  network-only (no cache)
- Read-mostly pages (/orders, /customer, /kanban, /dashboard):
  network-first + cache fallback (stale-while-revalidate-like)
- Lain: default network

Offline fallback page: styled fullscreen dgn 'Coba lagi' button.

Cache name 'lamasy-tenant-v1' — bump version saat tambah asset baru."
```

---

### Task 4: Register manifest + SW di components.php

**Files:**
- Modify: `components.php` (atau `tenant_guard.php` kalau lebih pas)

**Interfaces:**
- Consumes: manifest-tenant.json (Task 2), sw-tenant.js (Task 3)

- [ ] **Step 1: Cari `<head>` section di components.php**

Run: `grep -n "renderHead\|<head>" /Users/rizky/Documents/lamasy/components.php | head -5`
Expected: function `renderHead()` ada (kalau pattern existing) atau direct `<head>` block.

- [ ] **Step 2: Insert PWA meta + SW register**

Edit `components.php` di section `<head>` render. Tambah block ini (skip kalau di portal pelanggan):

```php
<?php
// Skip PWA tenant register kalau di portal pelanggan (punya SW sendiri)
$_isPortalPelanggan = strpos($_SERVER['REQUEST_URI'] ?? '', '/pelanggan') === 0
                   || strpos($_SERVER['REQUEST_URI'] ?? '', '/p?') === 0;
if (!$_isPortalPelanggan): ?>
<link rel="manifest" href="/assets/manifest-tenant.json">
<meta name="theme-color" content="#0F1C3A">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="LAMASY">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw-tenant.js', { scope: '/' })
      .then(reg => { /* console.log('SW tenant registered'); */ })
      .catch(err => console.warn('SW tenant register failed:', err));
  });
}
</script>
<?php endif; ?>
```

- [ ] **Step 3: Smoke test via MCP browser**

Navigate ke `/dashboard`. Open DevTools (atau via mcp javascript_tool):
```js
({
  swCount: (await navigator.serviceWorker.getRegistrations()).length,
  manifestUrl: document.querySelector('link[rel=manifest]')?.href,
  themeColor: document.querySelector('meta[name=theme-color]')?.content
});
```
Expected: `swCount: 1, manifestUrl: '.../manifest-tenant.json', themeColor: '#0F1C3A'`.

- [ ] **Step 4: Commit**

```bash
git add components.php
git commit -m "feat(pwa): register manifest + SW di tenant pages

Skip otomatis kalau pages /pelanggan (portal punya SW + manifest sendiri).

Apple-specific meta tags (capable, status-bar-style, title) supaya
iOS Safari Add-to-Home Screen experience proper."
```

---

### Task 5: Install prompt banner

**Files:**
- Modify: `components.php` (banner HTML + JS)
- Modify: `assets/harpy-erp.css` (style banner)

- [ ] **Step 1: Tambah banner HTML di components.php top body**

Tambah sebelum sidebar/main render (skip portal pelanggan):

```php
<?php if (!$_isPortalPelanggan): ?>
<div id="pwaInstallBanner" class="pwa-banner" style="display:none">
  <img src="/assets/icon-192.png" width="40" height="40" class="pwa-banner-icon" alt="LAMASY">
  <div class="pwa-banner-text">
    <div class="pwa-banner-title">Install LAMASY</div>
    <div class="pwa-banner-sub">Akses cepat dari home screen, tanpa buka browser tiap kali.</div>
  </div>
  <button onclick="installPWA()" class="hl-btn hl-btn-primary hl-btn-sm">Install</button>
  <button onclick="dismissInstallBanner()" class="hl-btn hl-btn-outline hl-btn-sm pwa-banner-dismiss">Nanti</button>
</div>

<div id="pwaIosBanner" class="pwa-banner" style="display:none">
  <img src="/assets/icon-192.png" width="40" height="40" class="pwa-banner-icon" alt="LAMASY">
  <div class="pwa-banner-text">
    <div class="pwa-banner-title">Pasang ke Home Screen</div>
    <div class="pwa-banner-sub">Tap tombol <strong>⎘</strong> Safari → pilih <strong>"Tambah ke Layar Utama"</strong>.</div>
  </div>
  <button onclick="dismissInstallBanner()" class="hl-btn hl-btn-outline hl-btn-sm pwa-banner-dismiss">Tutup</button>
</div>
<?php endif; ?>
```

- [ ] **Step 2: Tambah JS install handler**

Append ke script block di `<head>` (sesudah SW register):

```js
// PWA install prompt handling
let deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredInstallPrompt = e;
  if (!localStorage.getItem('pwa_install_dismissed')
      && !window.matchMedia('(display-mode: standalone)').matches) {
    setTimeout(() => {
      const el = document.getElementById('pwaInstallBanner');
      if (el) el.style.display = 'flex';
    }, 3000); // delay 3s biar tidak intrusive
  }
});

function installPWA() {
  if (!deferredInstallPrompt) return;
  deferredInstallPrompt.prompt();
  deferredInstallPrompt.userChoice.then(choice => {
    document.getElementById('pwaInstallBanner').style.display = 'none';
    if (choice.outcome === 'accepted') {
      localStorage.setItem('pwa_install_dismissed', 'installed');
    }
    deferredInstallPrompt = null;
  });
}

function dismissInstallBanner() {
  localStorage.setItem('pwa_install_dismissed', String(Date.now()));
  document.getElementById('pwaInstallBanner')?.style.setProperty('display', 'none');
  document.getElementById('pwaIosBanner')?.style.setProperty('display', 'none');
}

// iOS detection — Safari tidak punya beforeinstallprompt event
function _isIOSSafari() {
  const ua = navigator.userAgent;
  const isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
  const isSafari = /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);
  return isIOS && isSafari;
}

window.addEventListener('load', () => {
  if (_isIOSSafari()
      && !window.matchMedia('(display-mode: standalone)').matches
      && !navigator.standalone
      && !localStorage.getItem('pwa_install_dismissed')) {
    setTimeout(() => {
      const el = document.getElementById('pwaIosBanner');
      if (el) el.style.display = 'flex';
    }, 5000); // delay 5s biar customer experience
  }
});
```

- [ ] **Step 3: Tambah CSS untuk banner di harpy-erp.css**

```css
.pwa-banner {
  position: fixed;
  bottom: 16px;
  left: 16px;
  right: 16px;
  z-index: 9000;
  background: #0F1C3A;
  color: #fff;
  border-radius: 12px;
  padding: 12px;
  gap: 12px;
  align-items: center;
  box-shadow: 0 8px 24px rgba(0,0,0,.3);
  max-width: 480px;
  margin: 0 auto;
  animation: pwaSlideUp .3s ease;
}
@keyframes pwaSlideUp {
  from { transform: translateY(120%); opacity: 0; }
  to   { transform: translateY(0); opacity: 1; }
}
.pwa-banner-icon { border-radius: 8px; flex-shrink: 0; }
.pwa-banner-text { flex: 1; min-width: 0; }
.pwa-banner-title { font-weight: 700; font-size: 14px; }
.pwa-banner-sub { font-size: 11px; color: rgba(255,255,255,.7); line-height: 1.4; margin-top: 2px; }
.pwa-banner-dismiss { color: #fff !important; border-color: rgba(255,255,255,.3) !important; }
```

- [ ] **Step 4: Commit**

```bash
git add components.php assets/harpy-erp.css
git commit -m "feat(pwa): install prompt banner — Android + iOS

Banner muncul setelah delay 3-5 detik kalau:
- Browser support beforeinstallprompt (Chrome/Edge Android) DAN
- Belum install (display-mode: standalone false) DAN
- User belum dismiss (localStorage check)

iOS Safari: separate banner dgn instruksi manual karena Safari tidak
punya install event. Detection via UA + navigator.standalone.

CSS: fixed bottom dengan slide-up animation, z-index 9000."
```

---

### Task 6: E2E test PWA via MCP browser

**Files:** (no changes, verification only)

- [ ] **Step 1: Push semua + tunggu deploy**

```bash
git push
sleep 20  # auto-deploy ~15s
```

- [ ] **Step 2: Test manifest valid**

Via MCP browser navigate `/dashboard`, eval:
```js
const r = await fetch('/assets/manifest-tenant.json');
const j = await r.json();
({status: r.status, name: j.name, scope: j.scope, iconCount: j.icons.length, shortcutCount: j.shortcuts.length});
```
Expected: `status: 200, name: 'LAMASY — Laundry POS', scope: '/', iconCount: 4, shortcutCount: 3`

- [ ] **Step 3: Test SW registered**

```js
const regs = await navigator.serviceWorker.getRegistrations();
const sw = regs.find(r => r.scope.endsWith('/'));
({swActive: !!sw?.active, scope: sw?.scope, scriptURL: sw?.active?.scriptURL});
```
Expected: `swActive: true, scope: '.../', scriptURL: '.../sw-tenant.js'`

- [ ] **Step 4: Test cache populated**

```js
const cache = await caches.open('lamasy-tenant-v1');
const keys = await cache.keys();
({cachedCount: keys.length, sampleUrl: keys[0]?.url});
```
Expected: `cachedCount: >= 5` (static assets cached).

- [ ] **Step 5: Test install button visible (Chrome DevTools method)**

Manual: Buka Chrome DevTools → Application → Manifest. Verify "Add to home screen" button enabled. Klik untuk simulate install.

ATAU di Chrome menu: click ⋮ → "Install LAMASY..." kalau available.

- [ ] **Step 6: Test offline fallback**

DevTools → Network tab → set "Offline". Refresh `/orders`. Expect: cached page render (kalau pernah dibuka online), atau offline fallback page kalau belum pernah cache.

Reset network. Verify normal page kembali.

- [ ] **Step 7: Manual real-device test**

Test di Android Chrome real device:
- Buka `https://lamasy.harpy.id/dashboard`
- Tunggu banner install muncul (~3s)
- Klik "Install" → verify app appear di home screen
- Buka dari home screen → verify standalone mode (no browser UI)

Test di iOS Safari real device:
- Buka URL
- Tunggu iOS banner muncul (~5s)
- Follow instruksi manual: tap ⎘ → "Tambah ke Layar Utama"
- Verify icon LAMASY muncul di home screen
- Buka dari home screen → verify standalone

- [ ] **Step 8: Report hasil ke user**

Format:
- ✅ Manifest valid: name=X, scope=Y, icons=Z
- ✅ SW registered scope=/
- ✅ Cache populated: N items
- ⚠️ Real device test: butuh user verify di HP

---

### Task 7: (Opsional) Cleanup + documentation

**Files:**
- Modify: `README.md` atau buat `docs/PWA.md` jelaskan install instructions

- [ ] **Step 1: Tulis docs/PWA.md (opsional)**

```markdown
# LAMASY Tenant App — Install Guide

## Android (Chrome / Edge / Samsung Internet)
1. Buka https://lamasy.harpy.id/ di browser
2. Login dengan akun owner/kasir
3. Setelah 3 detik di dashboard, banner "Install LAMASY" muncul
4. Tap "Install" → icon LAMASY muncul di home screen

## iOS Safari (iPhone / iPad)
1. Buka https://lamasy.harpy.id/ di Safari (BUKAN Chrome iOS)
2. Tap tombol ⎘ Share di bar bawah
3. Scroll → tap "Tambah ke Layar Utama" / "Add to Home Screen"
4. Beri nama "LAMASY" → tap "Tambah"

## Verify install
- Icon LAMASY muncul di home screen
- Buka dari icon → app full-screen tanpa browser UI
- Indikator install: dashboard tidak menampilkan banner install lagi

## Offline mode
- Pages yang sering diakses (orders, customer, kanban, dashboard) bisa
  dibuka offline kalau pernah dibuka online sebelumnya
- POS create order TETAP butuh internet (transaksi disubmit ke server)
- Indikator offline: banner kuning "📡 Offline" muncul kalau koneksi putus
```

- [ ] **Step 2: Commit + push**

```bash
git add docs/PWA.md
git commit -m "docs: PWA install guide untuk tenant"
git push
```

---

## Rollback Strategy

Kalau setelah deploy ada masalah (SW broken, manifest invalid, dll):

1. **Revert PHP changes**: `git revert <commit>` untuk components.php
2. **Unregister SW di client**: deploy 1 file `sw-tenant.js` baru yang isi:
   ```js
   self.addEventListener('install', () => self.skipWaiting());
   self.addEventListener('activate', (e) => {
     e.waitUntil(self.registration.unregister().then(() => self.clients.matchAll()).then(clients => clients.forEach(c => c.navigate(c.url))));
   });
   ```
   Ini akan unregister SW di browser saat user buka next time.
3. **Manifest invalid**: rename `manifest-tenant.json` → `manifest-tenant.json.disabled`, deploy. Browser stop reading manifest.

Test setelah rollback: navigate `/dashboard` → DevTools Application → verify SW dan Manifest hilang.

---

## Self-Review Checklist

- [ ] Task 1-5 ada exact file path
- [ ] Step counts plausible (~1-1.5 hari total)
- [ ] No DB / backend changes
- [ ] Test plan covers MCP browser + real device
- [ ] Rollback strategy clear
- [ ] iOS Safari quirks addressed (separate banner)
- [ ] Service worker tidak conflict dengan portal pelanggan (scope skip)

## Execution Handoff

Plan saved to `docs/superpowers/plans/2026-06-24-pwa-tenant-polish.md`.

**Recommended execution:** Inline (executing-plans skill) — task list relatively linear, low risk per task.

**Estimated session:** 1-1.5 hari kerja efektif. Bisa dibagi:
- Sesi 1 (3-4 jam): Task 1-3 (icons, manifest, SW)
- Sesi 2 (2-3 jam): Task 4-5 (register + install prompt)
- Sesi 3 (2 jam): Task 6-7 (E2E test + docs)

Atau gas semua dalam 1 sesi penuh kalau focus.

## Pre-execution Checklist

Sebelum mulai eksekusi:
- [ ] User confirm source icon — pakai `assets/logo.png` existing, atau upload icon baru?
- [ ] User pilih theme color — `#0F1C3A` LAMASY navy default. Mau ubah?
- [ ] User confirm app name — `LAMASY — Laundry POS`. Mau ubah?
- [ ] Test device tersedia (Android Chrome real device + iOS Safari real device kalau bisa)?
