# Billing-Checkout: Native Share/Save + Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign `billing-checkout.php` agar konsisten dengan app + bikin Simpan QR/Bagikan berfungsi native di APK (via @capacitor/share + @capacitor/filesystem), dengan fallback web.

**Architecture:** Web-only redesign (light theme, header sticky, safe-area/status-bar hitam, kartu/tombol gaya harpy-erp.css). Native share/save mendeteksi Capacitor → Filesystem.writeFile + Share.share (fallback ke `navigator.share`/download di PWA). Plugin ditambah di repo lamasy-app; APK b15 di-build user.

**Tech Stack:** PHP (billing-checkout.php), CSS/JS, Capacitor 7 (@capacitor/share, @capacitor/filesystem), harpy-erp.css tokens.

## Global Constraints

- Redesign: tema **light** match app (bg off-white, kartu putih, navy/teal). Status bar **hitam** (`theme-color=#000000` + strip safe-area) + `viewport-fit=cover`. Header **sticky** ringkas dgn tombol Kembali.
- Logika/alur/endpoint pembayaran **tidak diubah** (QR/VA/timer/polling/proxy `qr_img` tetap).
- Native path pakai `window.Capacitor?.isNativePlatform?.()` + `window.Capacitor.Plugins.{Share,Filesystem}`; **plugin absent → fallback web, jangan error**.
- Filesystem directory: `'CACHE'` (share) / `'DOCUMENTS'` (save). Share file pakai `uri` dari `writeFile`.
- `php -l billing-checkout.php` bersih. Repo web auto-deploy dari `main`; sesi paralel aktif → worktree + rebase.
- lamasy-app: tambah deps, `npx cap sync`, `build-apk.sh` → **b15** (USER).

---

## File Structure

| File | Aksi | Tanggung jawab |
|---|---|---|
| `billing-checkout.php` | MODIFY | head (meta status-bar + strip hitam) + `<style>` redesign light + HTML header + JS native share/save |
| `~/Documents/lamasy-app/package.json` | MODIFY | + `@capacitor/share`, `@capacitor/filesystem` |
| `~/Documents/lamasy-app` android | BUILD | cap sync + APK b15 (USER) |

---

### Task 1: Redesign layout (light theme + status bar + header)

**Files:**
- Modify: `billing-checkout.php` (head meta 147-151, `<style>` 152-181, body header 184-187)

**Interfaces:**
- Consumes: existing PHP vars (`$itemName`, `$amount`, `$payment`, dll) — tak diubah.
- Produces: tampilan baru; class names dipertahankan (`.card`, `.amount`, `.timer`, `.qr-wrap`, `.ref-row`, `.mini-btn`, `.qr-actions`, `.act-btn`, `.va-box`, `.status`, `#toast`) agar JS existing tetap jalan.

- [ ] **Step 1: Ganti head meta (status bar hitam + safe-area)**

Ganti baris 148-149 (charset+viewport) menjadi:
```html
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#000000">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
```

- [ ] **Step 2: Ganti seluruh blok `<style>` (152-181) dengan tema light match app**

```html
<style>
  :root{ --off:#F3F4F6; --navy:#0F1C3A; --teal:#35E8D5; --teal-d:#1CC4B2; --ink:#1F2937; --ash:#6B7280; }
  *{box-sizing:border-box}
  body{ font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:var(--off); color:var(--ink); margin:0; padding:0;
        min-height:100vh; -webkit-font-smoothing:antialiased; }
  /* Bar hitam area status bar / notch */
  body::before{ content:""; position:fixed; top:0; left:0; right:0; height:env(safe-area-inset-top,0px); background:#000; z-index:1000; pointer-events:none; }
  /* Header sticky */
  .bc-top{ position:sticky; top:0; z-index:90; background:var(--navy); color:#fff;
           padding:calc(env(safe-area-inset-top,0px) + 12px) 16px 12px; display:flex; align-items:center; gap:12px; }
  .bc-top .back-btn{ display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.18);
                     color:#fff; padding:8px 12px; border-radius:10px; font-size:13.5px; font-weight:600; text-decoration:none; cursor:pointer; }
  .bc-top .back-btn:active{ transform:scale(.97); }
  .bc-top .bc-title{ font-size:15px; font-weight:800; }
  .wrap{ max-width:480px; margin:0 auto; padding:16px 16px 40px; }
  .card{ background:#fff; border:1px solid #EEF1F8; border-radius:16px; padding:20px; margin-bottom:14px; box-shadow:0 1px 6px rgba(15,28,58,.06); }
  h1{ font-size:20px; font-weight:800; color:var(--navy); margin:0 0 4px; }
  h3{ font-size:15px; font-weight:800; color:var(--navy); margin:0 0 14px; }
  .item{ color:var(--ash); font-size:13px; margin-bottom:18px; }
  .amount{ font-size:30px; font-weight:800; font-family:'DM Mono',monospace; color:var(--navy); margin:14px 0; }
  .timer{ background:#FFF7ED; border:1px solid #FED7AA; color:#B45309; padding:10px 14px; border-radius:10px; font-size:13px; text-align:center; font-weight:600; }
  .qr-wrap{ text-align:center; padding:18px; background:#fff; border:1px solid #E5E9F2; border-radius:14px; }
  .qr-wrap img{ max-width:240px; width:100%; height:auto; }
  .va-box{ display:flex; align-items:center; justify-content:space-between; gap:10px; background:#F0FDFA; border:1px solid #99F6E4; padding:14px; border-radius:12px; margin:8px 0; }
  .va-num{ font-family:'DM Mono',monospace; font-size:18px; font-weight:800; color:var(--teal-d); word-break:break-all; }
  button.copy{ background:var(--teal); color:var(--navy); border:none; padding:8px 14px; border-radius:8px; font-weight:800; cursor:pointer; white-space:nowrap; }
  .status{ text-align:center; padding:14px; font-size:13px; color:var(--ash); }
  .status.paid{ color:var(--teal-d); font-weight:800; }
  .ref-row{ display:flex; align-items:center; justify-content:space-between; gap:10px; background:#F7F8FC; border:1px solid #EEF1F8; padding:11px 12px; border-radius:12px; margin-top:12px; }
  .ref-row .lbl{ font-size:11px; color:var(--ash); font-weight:600; }
  .ref-row .val{ font-family:'DM Mono',monospace; font-size:13px; color:var(--navy); word-break:break-all; font-weight:700; }
  .mini-btn{ background:#EAFBF8; color:var(--teal-d); border:1px solid #99F6E4; padding:7px 13px; border-radius:9px; font-weight:800; font-size:12px; cursor:pointer; white-space:nowrap; }
  .mini-btn:active{ transform:scale(.97); }
  .qr-actions{ display:flex; gap:10px; margin-top:16px; }
  .qr-actions .act-btn{ flex:1; display:inline-flex; align-items:center; justify-content:center; gap:7px; background:var(--navy); color:#fff; border:none; padding:13px; border-radius:12px; font-weight:800; font-size:14px; cursor:pointer; }
  .qr-actions .act-btn.alt{ background:var(--teal); color:var(--navy); }
  .qr-actions .act-btn:active{ transform:scale(.98); }
  #toast{ position:fixed; left:50%; bottom:28px; transform:translateX(-50%) translateY(20px); background:var(--navy); color:#fff; padding:11px 18px; border-radius:10px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:1100; }
  #toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
</style>
```

- [ ] **Step 3: Ganti markup header (184-187) → header sticky ber-judul**

Ganti:
```php
<div class="wrap">
  <div class="topbar">
    <a class="back-btn" href="#" onclick="goBack();return false;">&larr; Kembali</a>
  </div>
```
menjadi:
```php
<header class="bc-top">
  <a class="back-btn" href="#" onclick="goBack();return false;">&larr; Kembali</a>
  <span class="bc-title">Pembayaran</span>
</header>
<div class="wrap">
```
(Hapus `<div class="topbar">…</div>` lama; `.wrap` tetap dibuka.)

- [ ] **Step 4: Lint + verifikasi struktur**

Run: `php -l billing-checkout.php` → `No syntax errors detected`.
Run: `grep -c "class=\"card\"\|class=\"amount\"\|class=\"qr-actions\"" billing-checkout.php` → ≥3 (class dipertahankan untuk JS).

- [ ] **Step 5: Commit**

```bash
git add billing-checkout.php
git commit -m "feat(billing-checkout): redesign light theme match app — status bar hitam + safe-area, header sticky, kartu/tombol harpy-erp"
```

---

### Task 2: lamasy-app — tambah plugin Share + Filesystem

**Files:**
- Modify: `~/Documents/lamasy-app/package.json`

**Interfaces:**
- Produces: `window.Capacitor.Plugins.Share`, `window.Capacitor.Plugins.Filesystem` di webview APK.

> Kerja di repo `~/Documents/lamasy-app` (git terpisah). Bukan worktree repo web.

- [ ] **Step 1: Tambah dependency**

Run:
```bash
cd ~/Documents/lamasy-app && npm install @capacitor/share@^7 @capacitor/filesystem@^7
```
Expected: `package.json` dependencies bertambah `@capacitor/share` + `@capacitor/filesystem` (versi 7.x selaras `@capacitor/core@7`).

- [ ] **Step 2: Sync ke Android**

Run: `cd ~/Documents/lamasy-app && npx cap sync android`
Expected: sync sukses; plugin ter-register (`android/app/src/main/.../plugins` atau capacitor.plugins.json memuat Share & Filesystem).

- [ ] **Step 3: Commit (repo lamasy-app)**

```bash
cd ~/Documents/lamasy-app
git add package.json package-lock.json android/
git commit -m "feat(native): tambah @capacitor/share + @capacitor/filesystem (utk share/save QR billing-checkout)"
```

> Catatan: build APK di Task 4 (butuh JDK 21 + Android SDK, user).

---

### Task 3: billing-checkout JS — native share/save + fallback web

**Files:**
- Modify: `billing-checkout.php` (JS: `downloadQR`, `shareQR`; tambah helper native)

**Interfaces:**
- Consumes: `qrProxy` (existing const), `fetchQrBlob()` (existing), `orderId` (existing), `Capacitor.Plugins.{Share,Filesystem}`.
- Produces: perilaku native saat di APK; fallback web tak berubah.

- [ ] **Step 1: Tambah helper native (sebelum `downloadQR`)**

Sisipkan sebelum `async function downloadQR()`:
```js
function bcIsNative(){ try { return !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()); } catch(e){ return false; } }
function bcPlugins(){ return (window.Capacitor && window.Capacitor.Plugins) || {}; }
function bcAmtTxt(){ return 'Pembayaran QRIS LAMASY Rp ' + (<?= (int)$amount ?>).toLocaleString('id-ID') + ' — Order ' + orderId; }
async function blobToBase64(blob){
  return new Promise(function(res, rej){ var r=new FileReader(); r.onloadend=function(){ res(String(r.result).split(',')[1]||''); }; r.onerror=rej; r.readAsDataURL(blob); });
}
// Return true kalau native menangani; false → caller fallback web.
async function nativeQr(mode){ // mode: 'share' | 'save'
  if (!bcIsNative()) return false;
  var P = bcPlugins(); var Filesystem = P.Filesystem, Share = P.Share;
  if (!Filesystem) return false;
  var blob = await fetchQrBlob();
  if (!blob) {
    if (mode==='share' && Share) { try { await Share.share({ title:'QRIS Pembayaran', text:bcAmtTxt() }); return true; } catch(e){ if(e&&e.name==='AbortError') return true; } }
    return false;
  }
  var b64 = await blobToBase64(blob);
  var name = 'qris-' + orderId + '.png';
  try {
    if (mode==='save') {
      await Filesystem.writeFile({ path:name, data:b64, directory:'DOCUMENTS' });
      showToast('QR tersimpan di Files (Documents)');
      return true;
    }
    var w = await Filesystem.writeFile({ path:name, data:b64, directory:'CACHE' });
    var uri = (w && w.uri) ? w.uri : null;
    if (!uri && Filesystem.getUri) { try { var g = await Filesystem.getUri({ path:name, directory:'CACHE' }); uri = g && g.uri; } catch(e){} }
    if (Share && uri) { try { await Share.share({ title:'QRIS Pembayaran', text:bcAmtTxt(), files:[uri] }); return true; } catch(e){ if(e&&e.name==='AbortError') return true; } }
    if (Share) { try { await Share.share({ title:'QRIS Pembayaran', text:bcAmtTxt() }); return true; } catch(e){ if(e&&e.name==='AbortError') return true; } }
  } catch(e){ /* fallback web */ }
  return false;
}
```

- [ ] **Step 2: Panggil native dulu di `downloadQR`**

Di awal `downloadQR()` (setelah cek `if (!qrUrl)`), tambah:
```js
  if (await nativeQr('save')) return;
```
(Sisa fungsi = fallback web download existing.)

- [ ] **Step 3: Panggil native dulu di `shareQR`**

Di awal `shareQR()` (baris pertama fungsi), tambah:
```js
  if (await nativeQr('share')) return;
```
(Sisa fungsi = fallback `navigator.share`/open tab existing.)

- [ ] **Step 4: Lint**

Run: `php -l billing-checkout.php` → bersih.

- [ ] **Step 5: Commit**

```bash
git add billing-checkout.php
git commit -m "feat(billing-checkout): Simpan/Bagikan QR native via @capacitor/share+filesystem (fallback web di PWA/APK lama)"
```

---

### Task 4: Integrasi — deploy web + build APK b15

- [ ] **Step 1: Pull + lint + push (web)**

Run: `git pull --rebase origin main && php -l billing-checkout.php && git push origin main`
Expected: deploy `lamasy.harpy.id`.

- [ ] **Step 2: Verifikasi redesign (PWA/desktop)**

Buka halaman pembayaran (QRIS pending) di browser → tampil tema light, header sticky, status bar/strip hitam, kartu rapi; Simpan/Bagikan web tetap jalan; timer & polling jalan.

- [ ] **Step 3: Build APK b15 (USER — butuh JDK 21 + Android SDK)**

Run: `cd ~/Documents/lamasy-app && ./build-apk.sh`
Expected: `~/Desktop/LaMaSy-v<ver>-b15-debug.apk`, versionCode naik ke b15.

- [ ] **Step 4: E2E native (device, APK b15)**

Buka pembayaran QRIS di APK → **Bagikan** → share sheet Android muncul dgn **gambar QR** → kirim WhatsApp (terkirim sbg gambar). **Simpan QR** → file `qris-<order>.png` ada di Documents. Fallback tetap aman bila plugin absent.

---

## Manual E2E (USER)

- [ ] Redesign: halaman pembayaran tampil match app (light, header sticky, status bar hitam) di PWA & APK.
- [ ] APK b15: Bagikan → gambar QR ke WhatsApp; Simpan → tersimpan di Files.
- [ ] Regresi: countdown timer, polling "Menunggu pembayaran", copy Nominal/Order/VA, tampilan VA tetap normal.

## Self-Review

**Spec coverage:**
- Redesign (status bar hitam+safe-area, header sticky, kartu/tombol app style, mobile-first, light) → Task 1 ✓
- Native plugins → Task 2 ✓
- Native share/save branch + fallback web → Task 3 ✓
- APK b15 build + E2E → Task 4 ✓
- Logika pembayaran tak diubah (class dipertahankan, endpoint tetap) → Task 1 Step 4 + constraints ✓
- Edge: plugin absent→fallback, blob null→share teks, VA view unaffected, PWA web path → Task 3 helper ✓
- Out of scope (galeri MediaStore, iOS, app shell penuh, ubah gateway) → tidak ada task yang melanggar ✓

**Placeholder scan:** Tidak ada TBD. Kode lengkap di tiap step.

**Type consistency:** `nativeQr('share'|'save')→bool`, `bcIsNative`, `bcPlugins`, `blobToBase64`, `qrProxy`/`fetchQrBlob`/`orderId` (existing) konsisten Task 3. Class CSS dipertahankan agar JS Task existing (`copyText`, `downloadQR`, `shareQR`) tetap merujuk elemen yang sama.
