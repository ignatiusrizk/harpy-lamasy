# Thermal Print Plugin (Bluetooth) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cetak struk POS langsung ke printer thermal Bluetooth dari native app tanpa dialog — render struk HTML existing jadi bitmap (html2canvas) → kirim ke plugin BT Classic. Fallback `window.print()` di web.

**Architecture:** 2 repo. `lamasy-app` (Capacitor): pasang plugin BT thermal + rebuild APK. `lamasy` (PHP): vendor html2canvas + `assets/js/thermal-print.js` helper (`window.ThermalPrint`) + integrasi `pos.php` (tombol cetak, modal pilih printer, toggle auto-cetak, auto-cetak setelah simpan). Plugin native = jembatan; logika cetak di JS halaman remote.

**Tech Stack:** Capacitor 6 (Android), plugin BT thermal printer (BT Classic SPP + print image), html2canvas (vendor lokal), PHP/JS vanilla.

## Global Constraints

- 2 repo: native `~/Documents/lamasy-app`, konten `/Users/rizky/Documents/lamasy` (PHP, auto-deploy Hostinger).
- Plugin BT: **Bluetooth Classic (SPP)** + **print image/bitmap**, Capacitor 6 compat, maintained. Verifikasi sebelum pakai (Task 1). Kalau tak cocok → eskalasi user (BLE / ESC/POS text).
- Bitmap dari struk HTML existing (html2canvas), lebar 384px (58mm) / 576px (80mm) ikut format outlet. Render 2× → downscale + threshold monokrom.
- `window.ThermalPrint` API: `isAvailable()`, `listPrinters()`, `getPrinter()`, `setPrinter({mac,name})`, `autoEnabled()`, `setAuto(bool)`, `renderBitmap(node,widthPx)`, `print(node,widthPx)`.
- localStorage per-device: `lamasy_printer` ({mac,name} JSON), `lamasy_print_auto` ('1'/'0').
- Fallback: `!isAvailable()` → `window.print()` existing (NO regresi web/desktop).
- UI printer/toggle = modal dari POS, hanya tampil di app (cek `window.Capacitor`), hidden di web.
- Cetak lokal → TIDAK potong coin (beda dari WA nota / generate server).
- Vendor html2canvas LOKAL (no CDN). PHP lint tiap file (`/opt/homebrew/bin/php -l`).
- APK build via `~/Documents/lamasy-app/build-apk.sh` (auto-increment versionCode).

---

## Task 1: Native — Pasang Plugin BT + Manifest + APK

**Files (lamasy-app):**
- Modify: `package.json` (dep plugin), `android/app/src/main/AndroidManifest.xml` (izin BT)
- Build: APK

**Interfaces:**
- Produces: plugin BT ter-bundle di APK; `window.Capacitor.Plugins.<X>` tersedia di webview. Dokumentasikan **nama plugin + API aktual** (method connect/list/printImage) di report → dikonsumsi Task 2.

- [ ] **Step 1: Pilih & verifikasi plugin**

Cari plugin Capacitor 6 yang dukung BT Classic SPP + print image. Kandidat (cek npm: maintained, Capacitor 6, BT Classic, printImage/printBase64):
- `capacitor-thermal-printer`
- `@kduma-autoid/capacitor-bluetooth-printer`
- `thermal-printer-capacitor`

```bash
cd ~/Documents/lamasy-app
npm view capacitor-thermal-printer version peerDependencies 2>&1 | head
npm view @kduma-autoid/capacitor-bluetooth-printer version 2>&1 | head
```
Pilih yang: Capacitor `^6`, ada method list paired + print image/bitmap, README jelas. **Kalau tak ada yang cocok → STOP, lapor user (opsi BLE / ESC/POS).**

- [ ] **Step 2: Install plugin**
```bash
cd ~/Documents/lamasy-app
npm install <plugin-terpilih>
npx cap sync android 2>&1 | tail -5
```

- [ ] **Step 3: Manifest izin BT**

Pastikan `android/app/src/main/AndroidManifest.xml` punya (tambah dalam `<manifest>`, sebelum `<application>`):
```xml
<uses-permission android:name="android.permission.BLUETOOTH" android:maxSdkVersion="30" />
<uses-permission android:name="android.permission.BLUETOOTH_ADMIN" android:maxSdkVersion="30" />
<uses-permission android:name="android.permission.BLUETOOTH_CONNECT" />
<uses-permission android:name="android.permission.BLUETOOTH_SCAN" />
```
(Plugin mungkin auto-merge sebagian — verifikasi hasil merge ada `BLUETOOTH_CONNECT`.)

- [ ] **Step 4: Build APK + verifikasi plugin terdaftar**
```bash
cd ~/Documents/lamasy-app && ./build-apk.sh 2>&1 | grep -E "BUILD|✅|versionCode"
# verifikasi plugin masuk
grep -ri "<plugin-package>" android/app/src/main/assets/capacitor.plugins.json 2>/dev/null || \
  cat android/app/src/main/assets/capacitor.plugins.json
```
Expected: BUILD SUCCESSFUL + plugin terdaftar di capacitor.plugins.json.

- [ ] **Step 5: Commit (lamasy-app)**
```bash
cd ~/Documents/lamasy-app && git add package.json package-lock.json
git commit -m "feat(print): pasang plugin BT thermal printer <nama>"
```

**Report:** tulis nama plugin + API aktual (cara list paired devices, cara printImage/base64, nama di window.Capacitor.Plugins) — Task 2 butuh ini.

---

## Task 2: Vendor html2canvas + Helper thermal-print.js

**Files (lamasy PHP):**
- Create: `assets/vendor/html2canvas.min.js`
- Create: `assets/js/thermal-print.js`

**Interfaces:**
- Consumes: nama plugin + API aktual (Task 1 report)
- Produces: global `window.ThermalPrint` (API di Global Constraints)

- [ ] **Step 1: Vendor html2canvas (lokal, no CDN)**
```bash
cd /Users/rizky/Documents/lamasy
mkdir -p assets/vendor
curl -sL https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js -o assets/vendor/html2canvas.min.js
ls -la assets/vendor/html2canvas.min.js   # ~ 200KB
head -c 80 assets/vendor/html2canvas.min.js
```
Expected: file ~200KB, diawali komentar/min JS html2canvas.

- [ ] **Step 2: Tulis `assets/js/thermal-print.js`**

```javascript
/* thermal-print.js — cetak struk ke printer BT thermal (native app) atau fallback window.print().
   Plugin native: window.Capacitor.Plugins.<PLUGIN> — SESUAIKAN nama+method dari Task 1 report. */
(function () {
  var LS_PRINTER = 'lamasy_printer';
  var LS_AUTO    = 'lamasy_print_auto';

  function plugin() {
    try { return (window.Capacitor && window.Capacitor.Plugins) ? window.Capacitor.Plugins.ThermalPrinter : null; }
    catch (e) { return null; }
  }
  function isNative() {
    return !!(window.Capacitor && typeof window.Capacitor.isNativePlatform === 'function' && window.Capacitor.isNativePlatform());
  }

  var TP = {
    isAvailable: function () { return isNative() && !!plugin(); },

    getPrinter: function () {
      try { return JSON.parse(localStorage.getItem(LS_PRINTER) || 'null'); } catch (e) { return null; }
    },
    setPrinter: function (p) { localStorage.setItem(LS_PRINTER, JSON.stringify(p)); },
    autoEnabled: function () { return localStorage.getItem(LS_AUTO) === '1'; },
    setAuto: function (b) { localStorage.setItem(LS_AUTO, b ? '1' : '0'); },

    // Daftar printer ter-pair. SESUAIKAN nama method ke plugin Task 1 (mis. .list()/.getBondedDevices()).
    listPrinters: async function () {
      var pl = plugin(); if (!pl) return [];
      var res = await pl.list();                 // TODO: sesuaikan ke API plugin
      var arr = res.devices || res || [];
      return arr.map(function (d) { return { mac: d.address || d.mac, name: d.name || d.address }; });
    },

    // Render node struk → base64 PNG monokrom selebar widthPx (384/576)
    renderBitmap: async function (node, widthPx) {
      var clone = node.cloneNode(true);
      var holder = document.createElement('div');
      holder.style.cssText = 'position:fixed;left:-99999px;top:0;background:#fff;width:' + widthPx + 'px;';
      clone.style.width = widthPx + 'px';
      clone.style.margin = '0';
      holder.appendChild(clone);
      document.body.appendChild(holder);
      try {
        var canvas = await html2canvas(clone, { backgroundColor: '#fff', scale: 2, width: widthPx, logging: false });
        // downscale ke widthPx + threshold monokrom
        var out = document.createElement('canvas');
        out.width = widthPx; out.height = Math.round(canvas.height * (widthPx / canvas.width));
        var ctx = out.getContext('2d');
        ctx.drawImage(canvas, 0, 0, out.width, out.height);
        var img = ctx.getImageData(0, 0, out.width, out.height), d = img.data;
        for (var i = 0; i < d.length; i += 4) {
          var v = (d[i] * 0.299 + d[i+1] * 0.587 + d[i+2] * 0.114) < 160 ? 0 : 255;
          d[i] = d[i+1] = d[i+2] = v; d[i+3] = 255;
        }
        ctx.putImageData(img, 0, 0);
        return out.toDataURL('image/png');
      } finally { document.body.removeChild(holder); }
    },

    // Cetak node ke printer tersimpan. SESUAIKAN printImage ke API plugin Task 1.
    print: async function (node, widthPx) {
      var pl = plugin(); if (!pl) throw new Error('Printer tidak tersedia');
      var pr = TP.getPrinter(); if (!pr || !pr.mac) throw new Error('Printer belum dipilih');
      var base64 = await TP.renderBitmap(node, widthPx);
      var b64 = base64.replace(/^data:image\/png;base64,/, '');
      await pl.connect({ address: pr.mac });     // TODO: sesuaikan
      await pl.printImage({ base64: b64 });      // TODO: sesuaikan (mungkin .printBase64 / .printBitmap)
      try { await pl.disconnect(); } catch (e) {}
    }
  };
  window.ThermalPrint = TP;
})();
```

CATATAN: 3 baris ber-`TODO` (listPrinters/connect/printImage) DISESUAIKAN ke API plugin aktual dari Task 1 report. Kalau plugin auto-connect saat print, hapus connect/disconnect.

- [ ] **Step 3: Verify**
```bash
cd /Users/rizky/Documents/lamasy
node -e "new Function(require('fs').readFileSync('assets/js/thermal-print.js','utf8')); console.log('thermal-print.js syntax OK')"
grep -nE "isAvailable|listPrinters|renderBitmap|print:" assets/js/thermal-print.js
```

- [ ] **Step 4: Commit**
```bash
git add assets/vendor/html2canvas.min.js assets/js/thermal-print.js
git commit -m "feat(print): vendor html2canvas + ThermalPrint helper (BT bitmap + fallback)"
```

---

## Task 3: Modal Pilih Printer + Toggle Auto-cetak (POS)

**Files (lamasy PHP):**
- Modify: `pos.php` (load script + tombol gear printer + modal)

**Interfaces:**
- Consumes: `window.ThermalPrint` (Task 2)
- Produces: UI pilih printer + set auto; fungsi JS `posOpenPrinterModal()`

- [ ] **Step 1: Load html2canvas + thermal-print.js di pos.php**

Di `<head>` atau sebelum script POS (cek pola load asset existing; pakai `?v=filemtime`):
```php
<script src="/assets/vendor/html2canvas.min.js?v=<?= @filemtime(__DIR__.'/assets/vendor/html2canvas.min.js') ?: '1' ?>"></script>
<script src="/assets/js/thermal-print.js?v=<?= @filemtime(__DIR__.'/assets/js/thermal-print.js') ?: '1' ?>"></script>
```

- [ ] **Step 2: Tombol + modal printer (tampil hanya di app)**

Tambah markup modal (sebelum `</body>` / dekat struk panel). Tombol pemicu (mis. ikon ⚙️🖨 dekat tombol Print Struk):
```html
<button id="btnPrinterSetting" class="btn btn-teal-sm" style="display:none" onclick="posOpenPrinterModal()">🖨 Printer</button>

<div id="printerModal" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:18px;max-width:340px;width:90%">
    <h3 style="margin:0 0 12px;font-size:16px;color:#0F1C3A">🖨 Printer Thermal</h3>
    <div style="font-size:13px;color:#374151;margin-bottom:6px">Printer terpilih: <strong id="printerCurrent">—</strong></div>
    <button class="btn btn-teal-sm" onclick="posPickPrinter()">Pilih / Ganti Printer</button>
    <div id="printerList" style="margin-top:10px"></div>
    <label style="display:flex;gap:8px;align-items:center;margin-top:14px;font-size:13px">
      <input type="checkbox" id="printerAuto" onchange="ThermalPrint.setAuto(this.checked)">
      Auto-cetak struk setelah simpan order
    </label>
    <p style="font-size:11px;color:#9CA3AF;margin:10px 0 0">Printer harus sudah di-pair di Setelan Bluetooth HP dulu.</p>
    <div style="text-align:right;margin-top:14px"><button class="btn" onclick="document.getElementById('printerModal').style.display='none'">Tutup</button></div>
  </div>
</div>
```

- [ ] **Step 3: JS modal**

Dalam script POS:
```javascript
// Tampilkan tombol printer hanya kalau di app + plugin ada
document.addEventListener('DOMContentLoaded', function () {
  if (window.ThermalPrint && ThermalPrint.isAvailable()) {
    var b = document.getElementById('btnPrinterSetting'); if (b) b.style.display = '';
  }
});
function posOpenPrinterModal() {
  var pr = ThermalPrint.getPrinter();
  document.getElementById('printerCurrent').textContent = pr ? pr.name : '—';
  document.getElementById('printerAuto').checked = ThermalPrint.autoEnabled();
  document.getElementById('printerList').innerHTML = '';
  document.getElementById('printerModal').style.display = 'flex';
}
async function posPickPrinter() {
  var box = document.getElementById('printerList');
  box.innerHTML = '<div style="font-size:12px;color:#6B7280">Memuat printer ter-pair…</div>';
  try {
    var list = await ThermalPrint.listPrinters();
    if (!list.length) { box.innerHTML = '<div style="font-size:12px;color:#DC2626">Tak ada printer ter-pair. Pair dulu di Setelan Bluetooth HP.</div>'; return; }
    box.innerHTML = list.map(function (p) {
      return '<button class="btn btn-teal-sm" style="display:block;width:100%;margin:4px 0;text-align:left" ' +
             'onclick=\'posSelectPrinter(' + JSON.stringify(p).replace(/'/g, "&#39;") + ')\'>' + esc(p.name) + '</button>';
    }).join('');
  } catch (e) { box.innerHTML = '<div style="font-size:12px;color:#DC2626">Gagal: ' + esc(e.message) + '</div>'; }
}
function posSelectPrinter(p) {
  ThermalPrint.setPrinter(p);
  document.getElementById('printerCurrent').textContent = p.name;
  showToast('✅ Printer dipilih: ' + p.name, 'success');
}
```

- [ ] **Step 4: Lint + commit**
```bash
cd /Users/rizky/Documents/lamasy && /opt/homebrew/bin/php -l pos.php
git add pos.php
git commit -m "feat(print): modal pilih printer + toggle auto-cetak di POS (app-only)"
```

---

## Task 4: Integrasi Cetak — Tombol + Auto-cetak

**Files (lamasy PHP):**
- Modify: `pos.php` (`printStruk()` + hook setelah simpan order)

**Interfaces:**
- Consumes: `window.ThermalPrint`, node struk POS, `printStruk()` existing (line ~1382), toast sukses simpan (~2228)

- [ ] **Step 1: Cari node struk + lebar dari format outlet**

Baca pos.php: temukan (a) node struk yang dirender (markup `<div class="struk">` ~line 2286 / fungsi pembangunnya), (b) `printStruk()` existing (~1382), (c) handler sukses simpan order (~2228 `showToast('✅ Order ...')`). Tentukan lebar: kalau ada info format outlet thermal_58 → 384, else default 80mm → 576. (Kalau format tak tersedia di client, default 576 + catat untuk tuning Task 5.)

- [ ] **Step 2: Helper cetak struk POS**

Tambah fungsi yang ambil node struk + cetak (BT atau fallback):
```javascript
function posStrukNode() {
  // node struk yang sudah dibangun POS (sesuaikan selector ke markup existing)
  return document.querySelector('.struk');
}
function posStrukWidthPx() {
  // 58mm→384, 80mm→576. Sesuaikan kalau format outlet tersedia di client.
  return (window.POS_STRUK_FORMAT === 'thermal_58') ? 384 : 576;
}
async function posCetakStruk() {
  var node = posStrukNode();
  if (!node) { showToast('Struk belum siap', 'error'); return; }
  if (window.ThermalPrint && ThermalPrint.isAvailable() && ThermalPrint.getPrinter()) {
    try {
      showToast('🖨 Mencetak…', 'info');
      await ThermalPrint.print(node, posStrukWidthPx());
      showToast('✅ Struk tercetak', 'success');
    } catch (e) {
      showToast('❌ ' + (e.message || 'Gagal cetak') + ' — coba lagi / pakai dialog', 'error');
    }
  } else if (window.ThermalPrint && ThermalPrint.isAvailable()) {
    // app tapi belum pilih printer
    showToast('Pilih printer dulu (🖨 Printer)', 'error');
    posOpenPrinterModal();
  } else {
    window.print();  // fallback web/desktop
  }
}
```

- [ ] **Step 3: Arahkan tombol Print Struk ke posCetakStruk()**

Ubah `onclick="printStruk()"` (line ~1382) → `onclick="posCetakStruk()"`. Kalau `printStruk()` punya logika lain (mis. print iframe), pertahankan sebagai fallback di dalam `posCetakStruk` cabang `window.print()` (panggil `printStruk()` alih-alih `window.print()` kalau itu mekanisme cetak web existing).

- [ ] **Step 4: Auto-cetak setelah simpan order**

Di handler sukses simpan (setelah `showToast('✅ Order ... tersimpan!')` ~line 2228, SETELAH struk node terbangun), tambah:
```javascript
if (window.ThermalPrint && ThermalPrint.isAvailable() && ThermalPrint.getPrinter() && ThermalPrint.autoEnabled()) {
  setTimeout(function(){ posCetakStruk(); }, 400); // beri waktu struk node render
}
```
(Pastikan node struk sudah ter-render saat ini — kalau struk dibangun async/iframe, panggil setelah event render selesai.)

- [ ] **Step 5: Lint + commit**
```bash
cd /Users/rizky/Documents/lamasy && /opt/homebrew/bin/php -l pos.php
git add pos.php
git commit -m "feat(print): tombol cetak BT + auto-cetak setelah simpan (fallback window.print)"
```

---

## Task 5: E2E Device + Tuning + Deploy

**Files:** None (deploy + iterasi)

- [ ] **Step 1: Deploy server + rebuild APK**
```bash
cd /Users/rizky/Documents/lamasy && git push origin main
cd ~/Documents/lamasy-app && ./build-apk.sh 2>&1 | grep -E "BUILD|✅|versionCode"
```

- [ ] **Step 2: Smoke (server)**
```bash
curl -s -o /dev/null -w "GET /assets/js/thermal-print.js %{http_code}\n" "https://lamasy.harpy.id/assets/js/thermal-print.js"
curl -s -o /dev/null -w "GET /pos %{http_code}\n" "https://lamasy.harpy.id/pos"
```
Expected: thermal-print.js 200, /pos 302 (auth).

- [ ] **Step 3: E2E device (USER — dengan printer)**

| # | Test | Expected |
|---|------|----------|
| 1 | Install APK → /pos → tombol 🖨 Printer muncul (app) | tampil |
| 2 | Pair printer di OS → Pilih Printer | printer terlist & tersimpan |
| 3 | Buat order → Cetak Struk | tercetak (logo+QR+layout) tanpa dialog |
| 4 | Aktifkan auto-cetak → simpan order | otomatis cetak |
| 5 | Printer mati → cetak | toast error, app tak crash |
| 6 | Buka /pos di browser web | tombol Printer hilang; Cetak → dialog (fallback) |
| 7 | Kualitas: teks tajam, QR ter-scan | OK (kalau tidak → tuning Step 4) |

- [ ] **Step 4: Tuning (iteratif, berdasar hasil device)**

Sesuaikan di `thermal-print.js`: lebar (384/576), `scale`, threshold (160) untuk ketajaman; di `posStrukWidthPx()` mapping format. Commit + redeploy tiap iterasi (server-side, tak perlu rebuild APK kecuali ubah plugin).

- [ ] **Step 5: Update README lamasy-app + ledger**

Tambah catatan plugin BT di README lamasy-app (nama plugin + cara pair). Update progress ledger.

---

## Self-Review Checklist

### Spec Coverage
- ✅ §3.2 plugin BT → Task 1
- ✅ §3.3 thermal-print.js helper + API → Task 2
- ✅ §3.4 flow (deteksi/pilih/render/cetak/auto) → Task 2 (helper) + Task 4 (integrasi)
- ✅ §4.1 tombol cetak → Task 4; §4.2 modal printer + toggle → Task 3; §4.3 permission → Task 1 Step 3
- ✅ §5 edge (printer mati, belum pilih, web fallback, izin, lebar, mono) → Task 2/4
- ✅ §6 security (lokal, vendor lokal) → Task 2
- ✅ §7 testing → Task 5

### Placeholder Scan
✓ Helper code lengkap. 3 baris ber-`TODO` (listPrinters/connect/printImage) SENGAJA — bergantung API plugin aktual yg baru diketahui di Task 1; diarahkan disesuaikan dari Task 1 report. Task 4 Step 1 mengarahkan implementer baca pos.php untuk selector/node persis (markup besar, tak diduplikasi di plan).

### Type/Name Consistency
- ✅ `window.ThermalPrint` API konsisten: isAvailable/listPrinters/getPrinter/setPrinter/autoEnabled/setAuto/renderBitmap/print (Task 2 def ↔ Task 3/4 pakai)
- ✅ localStorage keys `lamasy_printer`/`lamasy_print_auto` konsisten
- ✅ lebar 384/576 (thermal_58/80) konsisten spec↔plan
- ✅ fallback window.print() konsisten

### Risiko (eskalasi kalau perlu)
- Task 1: plugin tak cocok (BT Classic+image+cap6) → STOP + lapor user.
- Plugin API beda dari asumsi (connect/printImage) → sesuaikan 3 baris TODO di Task 2 dari report Task 1.
- Node struk POS di iframe vs div → Task 4 Step 1 verifikasi; kalau iframe cross-doc, render dari template JS struk (markup ~line 2286) ke container offscreen.
