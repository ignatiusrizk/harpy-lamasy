# Thermal Print Plugin (Bluetooth) — Design Spec

**Tanggal:** 25 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Cetak struk langsung ke printer thermal **Bluetooth** dari native app (Capacitor Android) **tanpa dialog** Android. Sekarang struk = HTML + `window.print()` yang selalu munculin dialog. Phase 2 native (plugin pertama). Sesuai [[project-native-app-strategy]].

**Scope v1:**
- Cetak struk POS ke printer BT Classic (SPP) sebagai **bitmap** (render dari struk HTML existing via html2canvas)
- Pilih/simpan printer per-device
- Trigger: tombol "Cetak Struk" manual + toggle "auto-cetak setelah simpan order"
- Fallback `window.print()` kalau bukan di app / plugin tak tersedia
- Lebar ikut format outlet (thermal_58 → 384px, thermal_80 → 576px)

**Out of scope v1:**
- Cetak label/barcode terpisah, laporan, invoice A4
- USB / network printer
- Multi-printer simultan
- ESC/POS text mode (pakai bitmap)

---

## 2. Background

- Native app = Capacitor thin-shell, webview load `https://lamasy.harpy.id` (lihat [[project-native-app-strategy]]). Toolchain Android sudah terpasang; APK build via `~/Documents/lamasy-app/build-apk.sh`.
- Struk existing: `core/StrukGenerator.php` render thermal_58/thermal_80 (HTML + `window.print()`, lihat renderThermal). POS (`pos.php`) sudah punya markup struk client-side (CSS `.struk*`) untuk preview/WA nota.
- Di app, `window.print()` = dialog Android print framework (tak langsung ke printer BT).
- **Thin-shell key:** plugin native di-bundle di APK, bridge JS di-inject ke remote page → halaman PHP bisa panggil `window.Capacitor.Plugins.<Printer>`. Logika cetak = JS di halaman remote; plugin native = jembatan ke printer.
- User punya printer BT untuk tes. BT Classic SPP umum untuk printer thermal mobile.

---

## 3. Arsitektur

### 3.1 Pembagian 2 repo

| Layer | Repo | Isi |
|-------|------|-----|
| Native shell | `lamasy-app` | Install plugin BT thermal + manifest perm + rebuild APK |
| Konten | `lamasy` (PHP) | JS helper cetak + UI pilih printer + toggle + integrasi POS + fallback |

95% logika di server (update tanpa rebuild APK). Plugin native dipasang **sekali**.

### 3.2 Plugin

Capacitor BT printer dukung **Bluetooth Classic (SPP)** + **print bitmap/image**. Final dipilih saat planning — wajib verifikasi: BT Classic SPP, print image, maintained, Capacitor 6 compat. Kandidat: `capacitor-thermal-printer`, `@kduma-autoid/capacitor-bluetooth-printer`. Fallback kalau tak cocok: plugin BLE (tergantung printer) atau ESC/POS text mode (ganti pendekatan — eskalasi ke user).

### 3.3 Komponen Server

```
assets/vendor/html2canvas.min.js      vendor lokal (no CDN)
assets/js/thermal-print.js            helper ThermalPrint (window.ThermalPrint)
pos.php                               tombol Cetak + auto-cetak setelah simpan
modal dari POS (ikon printer)  UI pilih printer + toggle auto-cetak
```

**`window.ThermalPrint` API (JS, jalan di halaman remote):**
```
ThermalPrint.isAvailable()          → bool (Capacitor native + plugin ada)
ThermalPrint.listPrinters()         → [{mac, name}]  (BT ter-pair)
ThermalPrint.getPrinter()           → {mac,name}|null  (localStorage)
ThermalPrint.setPrinter({mac,name}) → simpan localStorage (per-device)
ThermalPrint.autoEnabled()          → bool  (localStorage toggle)
ThermalPrint.setAuto(bool)
ThermalPrint.renderBitmap(node,widthPx) → base64 PNG (html2canvas + threshold mono)
ThermalPrint.print(node, widthPx)   → render + kirim ke printer; resolve/throw
```

localStorage keys (per-device): `lamasy_printer` ({mac,name}), `lamasy_print_auto` ('1'/'0').

### 3.4 Data Flow

```
DETEKSI: ThermalPrint.isAvailable()
  └─ false → tombol Cetak = window.print() (fallback existing)

PILIH PRINTER (Settings, sekali):
  listPrinters() → user pilih → setPrinter({mac,name})
  (printer harus sudah di-pair di Setelan Bluetooth HP dulu)

CETAK (tombol / auto):
  1. ambil node struk (markup existing di POS), set lebar 384/576 (format outlet)
  2. renderBitmap → html2canvas (render 2×, downscale, threshold monokrom) → base64 PNG
  3. plugin.printImage({mac, base64}) → printer, tanpa dialog
  4. toast "✅ Tercetak" / error "❌ Printer tak terhubung — Coba lagi"

AUTO-CETAK:
  setelah order POS tersimpan + isAvailable + getPrinter() + autoEnabled()
    → ThermalPrint.print(...) otomatis
```

---

## 4. UI Spec

### 4.1 Tombol Cetak (POS / struk)
"🖨 Cetak Struk" — selalu tampil. Di app + printer terpilih → cetak BT. Else → window.print().

### 4.2 Setting Printer (modal dari POS)
```
🖨 Printer Thermal (perangkat ini)
Printer terpilih: [RPP02-xxxx ▼]   [Pilih / Ganti]
[ ] Auto-cetak struk setelah simpan order
ⓘ Printer harus sudah di-pair di Setelan Bluetooth HP.
(Hanya muncul di aplikasi Android; di web disembunyikan)
```
Pilih → modal daftar printer ter-pair (listPrinters) → tap pilih → tersimpan.

### 4.3 Permission
Android 12+ : runtime `BLUETOOTH_CONNECT` (+ `BLUETOOTH_SCAN` kalau scan). Plugin minta saat pertama konek; manifest deklarasi izin.

---

## 5. Edge Cases

| Skenario | Handle |
|----------|--------|
| Printer mati/jauh | Toast error + "Coba lagi"; tawarkan window.print() |
| Belum pilih printer (app) | Arahkan ke Setting "Pilih Printer" |
| Belum di-pair OS | Pesan "Pair dulu di Setelan Bluetooth HP" |
| Bukan di app (web/desktop) | window.print() otomatis (no regresi) |
| Izin BT ditolak | Pesan + link buka setelan izin |
| Lebar kertas (58/80) | Ikut format struk outlet (thermal_58/80) |
| Kualitas bitmap | Render 2× → downscale + threshold hitam-putih |
| Coin | Cetak lokal → TIDAK potong coin |
| html2canvas gagal | Catch → toast + fallback window.print() |

---

## 6. Security & Privasi
- Struk berisi data order — render & cetak **lokal di device**, tak kirim ke pihak ke-3.
- Tidak ada kredensial. Plugin BT izin runtime standar.
- localStorage printer = per-device (mac+nama), bukan data sensitif.
- Vendor html2canvas lokal (no CDN, hindari supply-chain).

---

## 7. Testing Plan

### 7.1 Yang bisa diverifikasi tanpa device
- thermal-print.js: isAvailable() false di web → fallback window.print()
- UI setting tersembunyi di web, muncul di app (cek `window.Capacitor`)
- renderBitmap hasilkan base64 PNG dari node (bisa cek di browser tanpa printer)
- Lint PHP + struktur

### 7.2 Yang HARUS di device (user, dengan printer)
| # | Test | Expected |
|---|------|----------|
| 1 | Pair printer di OS → Settings Pilih Printer | printer muncul & tersimpan |
| 2 | Tombol Cetak Struk | struk tercetak (logo+QR+layout) tanpa dialog |
| 3 | Auto-cetak ON → simpan order | otomatis tercetak |
| 4 | Auto-cetak OFF → simpan order | tidak auto cetak |
| 5 | Printer mati saat cetak | toast error, app tak crash |
| 6 | Lebar 58 vs 80 | sesuai format outlet |
| 7 | Buka di web (bukan app) | tombol cetak → dialog browser (fallback) |
| 8 | Kualitas cetak | teks tajam, QR ter-scan |

---

## 8. Implementation Phasing

5 task (~3-5 hari efektif):
1. Native: install plugin + manifest perm + rebuild APK
2. Vendor html2canvas + `thermal-print.js` helper (detect/list/render/print/settings)
3. UI Pilih Printer + toggle auto-cetak (settings, hidden di web)
4. Integrasi POS: tombol Cetak + auto-cetak setelah simpan
5. E2E device + tuning lebar/threshold + deploy

---

## 9. Files Inventory

### New
- `assets/vendor/html2canvas.min.js`
- `assets/js/thermal-print.js`
- (lamasy-app) plugin di package.json + manifest perm

### Modified
- `pos.php` — tombol Cetak + auto-cetak
- modal dari POS — UI printer + toggle
- (lamasy-app) `package.json`, AndroidManifest, rebuild APK

### Schema
- None (printer setting = localStorage per-device, bukan DB)

---

## 10. Success Criteria
- ✅ Di app + printer terpilih: struk tercetak ke BT tanpa dialog (logo+QR+layout)
- ✅ Tombol manual + toggle auto-cetak
- ✅ Pilih/simpan printer per-device
- ✅ Fallback window.print() di web (no regresi)
- ✅ Lebar ikut format outlet; error handling (printer mati/izin)
- ✅ Plugin native sekali pasang; logika cetak server-side (update tanpa rebuild)

---

## 11. References
- `core/StrukGenerator.php` — renderThermal (thermal_58/80), window.print() existing
- `pos.php` — markup struk client-side (.struk*), alur simpan order
- [[project-native-app-strategy]] — thin-shell, plugin via bridge ke remote page
- Capacitor BT printer plugin (final saat planning) — BT Classic SPP + print bitmap
- html2canvas (vendor lokal)
- Build APK: `~/Documents/lamasy-app/build-apk.sh`
