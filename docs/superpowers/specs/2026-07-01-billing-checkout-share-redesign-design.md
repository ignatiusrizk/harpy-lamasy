# Billing-Checkout: Native Share/Save + Redesign — Design Spec

> LAMASY. Tanggal: 2026-07-01.

## Goal

(A) Tombol **Simpan QR** & **Bagikan** di halaman pembayaran benar-benar berfungsi di **APK** (share gambar QR ke WhatsApp dll, simpan file) lewat plugin Capacitor native. (B) **Redesign** halaman `billing-checkout.php` (standalone, template lama) agar match tampilan app: status bar hitam + safe-area, header ringkas sticky, kartu & tombol bergaya harpy-erp.css. Alur & logika pembayaran tidak berubah.

## Latar

- `billing-checkout.php` = halaman **standalone** (`<!DOCTYPE>`/`<style>` sendiri, bukan app shell `components.php`). Elemen: `.wrap` > `.topbar`(back) + `.card`(ringkasan: amount, timer, Order ID) + `.card`(QRIS: `<img qr>` + tombol Simpan/Bagikan) atau VA + `.status`(polling).
- Share/Save pakai web API (`navigator.share`, download `<a>`). Di **Capacitor Android WebView** ini sering tak berfungsi (terutama file). Proxy QR same-origin (`?action=qr_img`) sudah ada (membantu PWA).
- **lamasy-app belum punya** `@capacitor/share` / `@capacitor/filesystem`.

## Keputusan Desain (terkunci)

### A. Native share/save
- **lamasy-app**: tambah `@capacitor/share` + `@capacitor/filesystem` (deps + `npx cap sync`).
- **billing-checkout JS**: deteksi `window.Capacitor?.isNativePlatform?.()`.
  - **Native path**:
    - Ambil blob QR dari proxy (`qrProxy`) → base64.
    - **Bagikan**: `Filesystem.writeFile({path:'qris-<order>.png', data:<base64>, directory:'CACHE'})` → dapat `uri` (`getUri`) → `Share.share({title:'QRIS Pembayaran', text:<amt>, files:[uri]})`.
    - **Simpan**: `Filesystem.writeFile({..., directory:'DOCUMENTS'})` → toast "Tersimpan di Files (Documents)".
  - **Web path (PWA/browser)**: pertahankan `navigator.share`/download existing sebagai fallback.
  - Deteksi plugin defensif: kalau `Capacitor.Plugins.Share`/`Filesystem` tak ada (APK lama), fallback ke web path (jangan error).
- **APK rebuild b15** oleh user (`build-apk.sh`).

### B. Redesign layout
- Halaman tetap **standalone** (tak dipaksa masuk app shell penuh), tapi distyle konsisten:
  - Tambah `<meta name="theme-color" content="#000000">` + `viewport-fit=cover` + **strip hitam safe-area** (sama pola shell) + status bar (APK: pakai StatusBar plugin bila ada).
  - **Header sticky** ringkas: judul "Pembayaran" + tombol **Kembali** (←) kiri. Warna navy brand.
  - **Kartu** pakai token: `background:#fff;border-radius:14px;box-shadow;border`. Amount besar (mono), timer badge, ref-row rapi.
  - **Tombol** Simpan/Bagikan/Copy pakai gaya konsisten (mirip `hl-btn` / `act-btn` yang diperbarui), full-width friendly di mobile.
  - **Mobile-first**, aman di layar kecil (tak overflow).
  - QR image responsif (max-width, center).
- Isi dinamis (QR/VA/timer/status/polling) & endpoint **tidak diubah**.

## Komponen & File

| File | Aksi | Tanggung jawab |
|---|---|---|
| `~/Documents/lamasy-app/package.json` | MODIFY | + `@capacitor/share`, `@capacitor/filesystem` |
| `~/Documents/lamasy-app` (android) | BUILD | `npx cap sync android` + `build-apk.sh` → **APK b15** (USER) |
| `billing-checkout.php` (JS) | MODIFY | `shareQR()`/`downloadQR()` cabang native (Share+Filesystem) + fallback web |
| `billing-checkout.php` (HTML/CSS) | MODIFY | redesign: meta status bar + strip hitam, header sticky, kartu/tombol gaya baru, mobile-first |

## Alur (native share)

1. User tap **Bagikan** → `isNativePlatform()` true.
2. `fetch(qrProxy)` → blob → base64.
3. `Filesystem.writeFile(CACHE)` → `getUri` → `Share.share({files:[uri], text})`.
4. Share sheet Android muncul dgn gambar QR → pilih WhatsApp → terkirim sbg gambar.
5. Error/plugin absent → fallback web (`navigator.share`/open tab).

## Error Handling / Edge Cases

| Kondisi | Perilaku |
|---|---|
| APK lama (b≤14, tanpa plugin) | `Capacitor.Plugins.Share` undefined → fallback web path (tak error). |
| Proxy QR gagal (blob null) | Native: share teks saja (`Share.share({text})`); web: existing fallback. |
| Bukan QRIS (VA) | Tombol Simpan/Bagikan tak tampil (existing) — tak terpengaruh. |
| Filesystem permission ditolak | try/catch → toast gagal + fallback web share. |
| PWA (bukan native) | Web path (navigator.share) — tak berubah. |

## Keamanan

- Proxy `qr_img` existing: scope tenant + domain Midtrans (anti-SSRF) — tak diubah.
- Native file ditulis ke CACHE/DOCUMENTS app sendiri; tak ada data sensitif selain gambar QR publik.
- Tak ada perubahan logika/otorisasi pembayaran.

## Testing

- **Manual (APK b15)**: buka pembayaran QRIS → Bagikan → share sheet dgn gambar QR → kirim WA (terkirim gambar). Simpan → file ada di Documents. Fallback: di PWA tetap jalan (navigator.share/download).
- **Manual (PWA/desktop)**: redesign tampil rapi; Simpan/Bagikan web tetap jalan.
- **Regresi**: timer countdown, polling status, copy nominal/order/VA tetap jalan. VA view tetap normal.
- **Lint**: `php -l billing-checkout.php`.
- **APK build**: `npx cap sync` sukses, `build-apk.sh` → b15 (USER).

## Out of Scope

- Ubah alur/logika/gateway pembayaran atau data.
- Simpan langsung ke Galeri/Photos (MediaStore) — pakai Documents dulu.
- Memaksa halaman masuk app shell penuh (tetap standalone, hanya distyle konsisten).
- iOS.

## References
- [[project-native-app-strategy]] — Capacitor plugins
- `billing-checkout.php` (topbar/card/qr/shareQR/downloadQR/fetchQrBlob, proxy `qr_img`), `~/Documents/lamasy-app`
