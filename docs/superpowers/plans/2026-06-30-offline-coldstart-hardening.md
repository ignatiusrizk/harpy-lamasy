# Offline Cold-Start Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hilangkan error Chrome (`ERR_NAME_NOT_RESOLVED`) saat APK dibuka offline, dan biarkan kasir masuk POS + bikin order offline walau cold-start (app dibuka dari nol saat sinyal mati).

**Architecture:** Pertahanan berlapis. (1) Service Worker `sw-tenant.js` nge-cache shell POS (stale-while-revalidate) + navigation fallback untuk semua route. (2) `login.php` mendaftarkan SW agar ter-install sejak login online pertama. (3) Saat ganti scope, cache shell POS ikut dibersihkan. (4) Di repo native, `capacitor.config.json` + `www/offline.html` jadi fallback bundle untuk kasus first-launch (SW belum ada). Mekanisme order offline + sync sudah ada dari fitur Offline Order POS dan **tidak disentuh**.

**Tech Stack:** Service Worker (Cache API), vanilla JS, PHP 8, Capacitor 7.6.7 (Android), Node (test harness + `node --check`).

## Global Constraints

- `server.url` di `capacitor.config.json` **tetap** `https://lamasy.harpy.id/login` — jangan diubah; routing offline ditangani SW + `errorPath`.
- **Tidak ada login offline.** Mode offline hanya tersedia setelah kasir login online minimal sekali.
- Order offline **tidak nge-POST** ke server — hanya enqueue IndexedDB (mekanisme existing, jangan disentuh).
- Cache shell POS **harus dibersihkan saat ganti scope** (tenant/outlet/user) — anti bocor antar user.
- Brand offline: background `#0F1C3A`, aksen teal `#35E8D5`, ikon 📡, judul "Tidak ada koneksi". Konsisten dengan `offlinePage()` di `sw-tenant.js`.
- Android only (iOS di luar scope). APK target = **b12**.
- HTTPS only; `allowNavigation` tetap `["lamasy.harpy.id"]`.
- Dua repo: **repo PHP** `/Users/rizky/Documents/lamasy` (Task 1–4) dan **repo native** `~/Documents/lamasy-app` (Task 5). Tiap repo commit terpisah.

---

## File Structure

| File | Repo | Tanggung jawab |
|---|---|---|
| `sw-tenant.js` | PHP | Cache shell POS (SWR) + navigation fallback ke offlinePage/POS cached |
| `assets/offline-pos.js` | PHP | (existing) tambah purge cache shell POS di `clearScope()` |
| `login.php` | PHP | Daftarkan SW di `<head>` |
| `tests/offline/test_sw_navfallback.js` | PHP | Node harness: uji keputusan fetch-handler SW |
| `~/Documents/lamasy-app/www/offline.html` | native | Halaman offline bundle (first-launch) |
| `~/Documents/lamasy-app/capacitor.config.json` | native | Tambah `server.errorPath` |

---

### Task 1: SW — cache shell POS (SWR) + navigation fallback

**Files:**
- Modify: `sw-tenant.js` (fetch handler + helpers)
- Test: `tests/offline/test_sw_navfallback.js` (NEW)

**Interfaces:**
- Consumes: `CACHE` (const, sudah ada = `'lamasy-tenant-v2'`), `offlinePage()` (sudah ada, return `Response` HTML offline), `STATIC_ASSETS`, `READ_MOSTLY_PATHS`, `cacheFirst()`, `networkFirstWithCache()` (semua sudah ada).
- Produces: `POS_PATHS` (const array `['/pos','/pos.php']`), `staleWhileRevalidate(req)` → `Promise<Response>`, `navigationFallback(req)` → `Promise<Response>`. Task 2 memakai nama cache `CACHE` & path `/pos`,`/pos.php`.

- [ ] **Step 1: Tulis test harness yang gagal**

Buat `tests/offline/test_sw_navfallback.js`:

```js
// test_sw_navfallback.js — uji keputusan fetch-handler sw-tenant.js tanpa framework.
// Jalankan: node tests/offline/test_sw_navfallback.js   (butuh Node 18+ utk global fetch/Response/URL)
const fs = require('fs');
const vm = require('vm');
const path = require('path');

let pass = 0, fail = 0;
const ok = (c, m) => c ? (pass++, console.log('  ok   - ' + m)) : (fail++, console.error('  FAIL - ' + m));
const urlOf = (r) => (typeof r === 'string' ? r : r.url);

// Fake Cache API berbagi satu store (url -> Response)
function makeCaches(seed) {
  const store = new Map(Object.entries(seed || {}));
  const cache = {
    match: (r) => Promise.resolve(store.get(urlOf(r))),
    put: (r, resp) => { store.set(urlOf(r), resp); return Promise.resolve(); },
    delete: (r) => Promise.resolve(store.delete(urlOf(r))),
    addAll: () => Promise.resolve(),
  };
  return {
    _store: store,
    open: () => Promise.resolve(cache),
    match: (r) => Promise.resolve(store.get(urlOf(r))),
    keys: () => Promise.resolve(['lamasy-tenant-v2']),
    delete: () => Promise.resolve(true),
  };
}

function loadSW(caches, fetchImpl) {
  const code = fs.readFileSync(path.join(__dirname, '../../sw-tenant.js'), 'utf8');
  const handlers = {};
  const sandbox = {
    self: {
      addEventListener: (t, cb) => { handlers[t] = cb; },
      location: { origin: 'https://lamasy.harpy.id' },
      skipWaiting: () => Promise.resolve(),
      clients: { claim: () => Promise.resolve() },
    },
    caches, fetch: fetchImpl, Response, URL, console, Promise,
  };
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);
  return { handlers, sandbox };
}

function fire(handlers, sandbox, reqUrl, mode, fetchImpl) {
  sandbox.fetch = fetchImpl;
  const event = { request: { url: reqUrl, method: 'GET', mode }, respondWith(p) { this._res = p; } };
  handlers.fetch(event);
  return event._res;
}

(async () => {
  // (1) navigasi offline ke /login tanpa cache → offlinePage (ada teks "Tidak ada koneksi")
  {
    const caches = makeCaches();
    const { handlers, sandbox } = loadSW(caches, () => Promise.reject(new Error('offline')));
    const res = await fire(handlers, sandbox, 'https://lamasy.harpy.id/login', 'navigate', () => Promise.reject(new Error('offline')));
    ok(!!res, 'navigasi /login offline → respondWith dipanggil (tidak lolos ke browser)');
    const txt = res ? await res.text() : '';
    ok(/Tidak ada koneksi/.test(txt), 'navigasi /login offline tanpa cache → offlinePage');
  }

  // (2) navigasi offline ke /login DENGAN /pos cached → serve POS cached
  {
    const caches = makeCaches({ '/pos': new Response('<html>POS CACHED</html>', { headers: { 'Content-Type': 'text/html' } }) });
    const { handlers, sandbox } = loadSW(caches, () => Promise.reject(new Error('offline')));
    const res = await fire(handlers, sandbox, 'https://lamasy.harpy.id/login', 'navigate', () => Promise.reject(new Error('offline')));
    const txt = res ? await res.text() : '';
    ok(/POS CACHED/.test(txt), 'navigasi /login offline + /pos cached → serve POS cached');
  }

  // (3) navigasi online ke /pos → stale-while-revalidate menaruh ke cache
  {
    const caches = makeCaches();
    const onlineResp = () => Promise.resolve(new Response('<html>FRESH POS</html>', { status: 200, headers: { 'Content-Type': 'text/html' } }));
    const { handlers, sandbox } = loadSW(caches, onlineResp);
    const res = await fire(handlers, sandbox, 'https://lamasy.harpy.id/pos', 'navigate', onlineResp);
    const txt = res ? await res.text() : '';
    ok(/FRESH POS/.test(txt), 'navigasi /pos online → serve hasil network');
    // beri kesempatan promise put() selesai
    await new Promise(r => setTimeout(r, 10));
    ok(caches._store.has('https://lamasy.harpy.id/pos'), 'navigasi /pos online → shell di-cache (SWR)');
  }

  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `node tests/offline/test_sw_navfallback.js`
Expected: FAIL — test (1)/(2) gagal karena `/login` saat ini jatuh ke "Default: network-only" (`respondWith` tak dipanggil → `res` undefined), dan `/pos` belum di-SWR.

- [ ] **Step 3: Implement — tambah POS_PATHS + helper + restruktur fetch handler**

Di `sw-tenant.js`, tambah const setelah `READ_MOSTLY_PATHS` (sekitar baris 20):

```js
const POS_PATHS = ['/pos', '/pos.php'];
```

Ganti seluruh blok `self.addEventListener('fetch', ...)` (baris 41–85) menjadi:

```js
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

  // Navigasi lain (/, /landing.php, /login, route apa pun): network-first,
  // gagal offline → cached exact → POS cached → halaman offline brand.
  if (req.mode === 'navigate') {
    e.respondWith(navigationFallback(req));
    return;
  }

  // Default: network-only (fetch API non-navigasi, mis. action=list)
});
```

Tambah dua helper baru tepat sebelum `function offlinePage()` (sekitar baris 104):

```js
function staleWhileRevalidate(req) {
  return caches.open(CACHE).then(cache =>
    cache.match(req).then(cached => {
      const network = fetch(req).then(resp => {
        if (resp && resp.ok) cache.put(req, resp.clone());
        return resp;
      }).catch(() => null);
      return cached || network.then(r => r || offlinePage());
    })
  );
}

function navigationFallback(req) {
  return fetch(req).catch(() =>
    caches.match(req).then(cached =>
      cached || caches.match('/pos').then(pos => pos || offlinePage())
    )
  );
}
```

Catatan: blok skip `url.pathname === '/' || '/landing.php'` yang lama **dihapus** — navigasi ke `/` dan landing kini lewat `navigationFallback` (tetap fresh dari network saat online; offlinePage saat offline; tidak di-cache).

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `node tests/offline/test_sw_navfallback.js`
Expected: `3 passed, 0 failed` (sebenarnya 5 assert), exit 0.

- [ ] **Step 5: Lint syntax SW**

Run: `node --check sw-tenant.js`
Expected: tidak ada output, exit 0.

- [ ] **Step 6: Commit**

```bash
git add sw-tenant.js tests/offline/test_sw_navfallback.js
git commit -m "feat(offline): SW cache shell POS (SWR) + navigation fallback — tutup error Chrome cold-start offline"
```

---

### Task 2: SW cache cleanup saat ganti scope

**Files:**
- Modify: `assets/offline-pos.js` (fungsi `clearScope()`, sekitar baris 86)

**Interfaces:**
- Consumes: nama cache berawalan `lamasy-tenant` (dari Task 1 / existing `CACHE`), path `/pos`, `/pos.php`.
- Produces: tidak ada interface baru.

- [ ] **Step 1: Baca konteks `clearScope()`**

Run: `sed -n '80,95p' assets/offline-pos.js`
Expected: terlihat `async clearScope()` yang me-`clear()` tiap store IndexedDB.

- [ ] **Step 2: Implement — purge cache shell POS di `clearScope()`**

Di akhir body `clearScope()` (sebelum penutup `}` fungsi), tambah:

```js
      // Purge shell POS dari semua cache tenant — anti bocor antar user/outlet.
      if (typeof caches !== 'undefined' && caches.keys) {
        try {
          const keys = await caches.keys();
          for (const k of keys) {
            if (!k.startsWith('lamasy-tenant')) continue;
            const c = await caches.open(k);
            await c.delete('/pos');
            await c.delete('/pos.php');
          }
        } catch (e) { /* cache purge best-effort */ }
      }
```

- [ ] **Step 3: Lint syntax**

Run: `node --check assets/offline-pos.js`
Expected: tidak ada output, exit 0.

- [ ] **Step 4: Verifikasi manual (di plan reviewer / E2E nanti)**

Tidak ada unit test JS untuk IndexedDB di repo ini. Diverifikasi via Manual E2E #4 (logout → cache POS bersih). Catat sebagai item E2E.

- [ ] **Step 5: Commit**

```bash
git add assets/offline-pos.js
git commit -m "feat(offline): bersihkan cache shell POS saat ganti scope (anti bocor antar user)"
```

---

### Task 3: Daftarkan Service Worker di `login.php`

**Files:**
- Modify: `login.php` (head, setelah baris 332 `<title>`)

**Interfaces:**
- Consumes: `/sw-tenant.js` (scope `/`).
- Produces: tidak ada interface baru.

- [ ] **Step 1: Verifikasi belum ada registrasi**

Run: `grep -n "serviceWorker" login.php`
Expected: tidak ada hasil (belum terdaftar).

- [ ] **Step 2: Implement — sisipkan snippet registrasi SW**

Di `login.php`, tepat setelah baris `<title>Login — LAMASY</title>` (baris 332), sisipkan:

```html
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw-tenant.js', { scope: '/' })
      .catch(err => console.warn('SW tenant register failed:', err));
  });
}
</script>
```

(Snippet identik dengan `components.php:111-118`.)

- [ ] **Step 3: Lint PHP**

Run: `php -l login.php`
Expected: `No syntax errors detected in login.php`

- [ ] **Step 4: Verifikasi snippet ada**

Run: `grep -c "serviceWorker.register('/sw-tenant.js'" login.php`
Expected: `1`

- [ ] **Step 5: Commit**

```bash
git add login.php
git commit -m "feat(offline): daftarkan SW di halaman login — install sejak login online pertama"
```

---

### Task 4: Push repo PHP (deploy)

**Files:** tidak ada perubahan file; hanya integrasi.

- [ ] **Step 1: Pull dulu (mungkin ada sesi paralel)**

Run: `git pull --rebase origin main`
Expected: sukses tanpa konflik (kalau konflik, selesaikan hanya pada file yang disentuh plan ini).

- [ ] **Step 2: Jalankan ulang test SW + lint sebagai sanity**

Run: `node tests/offline/test_sw_navfallback.js && node --check sw-tenant.js && node --check assets/offline-pos.js && php -l login.php`
Expected: test `passed, 0 failed`; semua lint bersih.

- [ ] **Step 3: Push (auto-deploy Hostinger)**

```bash
git push origin main
```
Expected: push sukses; situs `lamasy.harpy.id` ter-deploy.

- [ ] **Step 4: Verifikasi SW live di produksi**

Run: `curl -sS -o /dev/null -w "%{http_code}\n" https://lamasy.harpy.id/sw-tenant.js`
Expected: `200`. Lalu `curl -sS https://lamasy.harpy.id/sw-tenant.js | grep -c navigationFallback` → `1` (versi baru live).

---

### Task 5: Native — offline.html + errorPath + rebuild APK b12

**Files (repo `~/Documents/lamasy-app`):**
- Create: `~/Documents/lamasy-app/www/offline.html`
- Modify: `~/Documents/lamasy-app/capacitor.config.json`

**Interfaces:**
- Consumes: `server.url` existing (`https://lamasy.harpy.id/login`).
- Produces: file lokal `offline.html` di webDir → dirujuk `server.errorPath`.

- [ ] **Step 1: Buat `www/offline.html`**

Buat `~/Documents/lamasy-app/www/offline.html`:

```html
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Offline — LAMASY</title>
  <style>
    html,body{margin:0;min-height:100%;background:#0F1C3A;color:#fff;
      font-family:-apple-system,Roboto,system-ui,sans-serif;
      display:flex;align-items:center;justify-content:center;box-sizing:border-box;padding:40px}
    .box{max-width:340px;text-align:center}
    .icon{font-size:64px;margin-bottom:20px}
    h2{margin:0 0 12px;color:#35E8D5;font-size:22px}
    p{color:rgba(255,255,255,.7);line-height:1.55;margin:0 0 24px;font-size:14px}
    button{background:#35E8D5;color:#0F1C3A;border:0;padding:12px 24px;
      border-radius:8px;font-weight:700;font-size:14px;cursor:pointer}
  </style>
</head>
<body>
  <div class="box">
    <div class="icon">📡</div>
    <h2>Tidak ada koneksi</h2>
    <p>Sambungkan internet sekali dulu untuk menyiapkan mode offline. Setelah itu, POS bisa dipakai walau sinyal mati.</p>
    <button onclick="location.href='https://lamasy.harpy.id/login'">Coba lagi</button>
  </div>
</body>
</html>
```

- [ ] **Step 2: Tambah `errorPath` ke `capacitor.config.json`**

Ubah `~/Documents/lamasy-app/capacitor.config.json` — di dalam objek `server`, tambah `"errorPath": "offline.html"`:

```json
{
  "appId": "id.harpy.lamasy",
  "appName": "LAMASY",
  "webDir": "www",
  "server": {
    "url": "https://lamasy.harpy.id/login",
    "errorPath": "offline.html",
    "cleartext": false,
    "androidScheme": "https",
    "allowNavigation": ["lamasy.harpy.id"]
  },
  "android": {
    "allowMixedContent": false
  }
}
```

- [ ] **Step 3: Validasi JSON**

Run: `python3 -m json.tool ~/Documents/lamasy-app/capacitor.config.json > /dev/null && echo OK`
Expected: `OK`

- [ ] **Step 4: Sync Capacitor (salin www + config ke android/)**

Run: `cd ~/Documents/lamasy-app && npx cap sync android`
Expected: `sync` sukses; `offline.html` tersalin ke `android/app/src/main/assets/public/offline.html`.

Verifikasi: `ls ~/Documents/lamasy-app/android/app/src/main/assets/public/offline.html` → file ada.

> Catatan errorPath: Capacitor 7 mendukung `server.errorPath` (load file lokal dari webDir saat URL utama gagal). Jika setelah build ternyata errorPath tidak memicu (mis. layar putih bukan offline.html), fallback: override `MainActivity` dengan `WebViewClient.onReceivedError` yang memanggil `webView.loadUrl("file:///android_asset/public/offline.html")`. Tentukan hanya bila Step 6 (E2E first-launch) gagal.

- [ ] **Step 5: Commit (repo native)**

```bash
cd ~/Documents/lamasy-app
git add www/offline.html capacitor.config.json
git commit -m "feat(native): offline.html bundle + server.errorPath — anti error Chrome saat cold-start offline (b12)"
```

- [ ] **Step 6: Build APK b12 (USER — butuh JDK 21 + Android SDK)**

Run: `cd ~/Documents/lamasy-app && ./build-apk.sh`
Expected: APK ter-build, `versionCode` naik ke b12, file `~/Desktop/LAMASY-v<ver>-b12-debug.apk` tercipta.

---

## Manual E2E (USER, di device — setelah Task 5)

- [ ] **E2E-1 Cold-start offline setelah online:** install APK b12 → login online → buka POS sekali (biar shell + katalog ke-cache) → tutup app total → **mode pesawat** → buka app → **POS cached terbuka** (bukan error Chrome) → buat order layanan+tier+tunai → struk kode `OFF-...` → matikan pesawat → auto-sync → nomor asli muncul, kas tercatat, indikator pending = 0, lacak via kode lama jalan.
- [ ] **E2E-2 First-launch offline:** uninstall lalu install APK b12 di device yang belum pernah buka app (atau clear data) → **mode pesawat** → buka app → muncul `offline.html` brand (📡 "Tidak ada koneksi"), **bukan** error Chrome → tombol "Coba lagi".
- [ ] **E2E-3 CSRF sync:** setelah E2E-1, pastikan `sync_offline` sukses (tidak ada error CSRF) walau shell awal disajikan dari cache.
- [ ] **E2E-4 Logout cleanup:** dengan ada order pending → tekan Logout → peringatan pending muncul; login user/outlet berbeda → buka app offline → **tidak** melihat POS milik user sebelumnya (cache shell sudah dibersihkan oleh `clearScope`).

---

## Self-Review

**Spec coverage:**
- SW cache shell POS (SWR) → Task 1 ✓
- SW navigation fallback semua route → Task 1 ✓
- Registrasi SW di /login → Task 3 ✓
- Native errorPath + offline.html bundle → Task 5 ✓
- Cache shell dibersihkan saat ganti scope → Task 2 ✓
- APK b12 rebuild → Task 5 Step 6 ✓
- Edge: CSRF sync valid → E2E-3 ✓; logout cleanup → Task 2 + E2E-4 ✓; navigasi non-POS offline → Task 1 (navigationFallback → offlinePage) ✓
- Testing SW (cache-shell, navigation fallback, cleanup) → Task 1 harness + E2E ✓
- Out of scope (login offline, iOS, ganti server.url, ubah mekanisme sync) → tidak ada task yang melanggar ✓

**Placeholder scan:** Tidak ada TBD/TODO. Catatan errorPath→MainActivity fallback bersyarat eksplisit (hanya bila E2E-2 gagal), bukan placeholder.

**Type consistency:** `POS_PATHS`, `staleWhileRevalidate`, `navigationFallback`, `CACHE`, `/pos`/`/pos.php`, `offlinePage()` konsisten antara Task 1 & 2. Nama cache `lamasy-tenant*` dipakai sama di Task 1 (existing `CACHE`) & Task 2 (prefix match).
