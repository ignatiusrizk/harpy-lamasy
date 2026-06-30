# Offline Cold-Start Hardening — Design Spec

> LaMaSy. Tanggal: 2026-06-30. Approach A (tambal cold-start, perluas yang sudah ada).

## Goal

App (APK Capacitor thin-shell) **tidak boleh menampilkan error Chrome** (`ERR_NAME_NOT_RESOLVED`) saat dibuka tanpa sinyal, dan kasir tetap bisa **masuk POS + membuat order offline** walau app baru dibuka dari nol (cold start / relaunch) saat internet mati. Order yang dibuat offline ter-sync otomatis saat online (mekanisme sudah ada dari fitur Offline Order POS).

## Latar / Masalah

Fitur **Offline Order POS** ([spec 2026-06-29](2026-06-29-offline-order-pos-design.md)) sudah live: IndexedDB queue, `sync_offline` idempoten via `offline_uuid`, snapshot katalog, struk kode sementara `OFF-...`. **Tetapi** seluruh desainnya berasumsi *"POS dibuka online dulu"* — supaya Service Worker + katalog ter-cache.

Kasus yang belum ketutup = **cold-start saat offline**. Bukti: APK dibuka tanpa sinyal → halaman error bawaan Chrome ("Webpage not available — net::ERR_NAME_NOT_RESOLVED" untuk `https://lamasy.harpy.id/login`).

Tiga lubang penyebab:

1. **Entry `server.url` = `/login`** — login butuh server; saat offline gagal di pintu masuk dan `/login` tidak ditangani SW.
2. **SW belum cache shell POS** — `sw-tenant.js` cuma menangani `orders`, `customer`, `kanban`, `dashboard` (READ_MOSTLY_PATHS). `/pos` lolos ke "network-only" → gagal offline.
3. **First-launch offline** — app belum pernah online sekali pun → SW belum ter-install → pasti error Chrome. Hanya bisa ditangani dari sisi APK (bundle lokal).

## Keputusan Desain (terkunci)

- **Pendekatan:** Approach A — perluas Service Worker + tambah fallback lokal di APK. **Bukan** mini-POS terpisah (tolak duplikasi UI), **bukan** rewrite SPA.
- **Pertahanan berlapis:** SW shell-cache → SW navigation fallback → native `errorPath`. Tidak ada jalur tersisa yang lolos ke error Chrome.
- **Auth offline:** tidak ada login offline. Shell POS ter-autentikasi disajikan dari cache + cookie sesi webview. Kasir **wajib login online minimal sekali** sebelum mode offline tersedia. Konsekuensi diterima.
- **Order offline tidak nge-POST** — hanya enqueue ke IndexedDB (sudah ada). Server tetap authoritative; semua efek (nomor, kas, poin, push, coin) dijalankan server saat `sync_offline`.
- **`server.url` tetap `/login`** — perubahan entry di-minimalkan untuk turunkan risiko ke alur online. Routing offline ditangani SW navigation fallback (bukan ganti entry point). `errorPath` native menangani kasus tanpa-SW.

## Arsitektur — Pertahanan Berlapis

| # | Kondisi app dibuka offline | Penanganan | Hasil |
|---|---|---|---|
| 1 | Pernah buka POS online sebelumnya | **SW**: serve shell POS dari cache (stale-while-revalidate) | Masuk POS, bisa bikin order offline |
| 2 | SW aktif tapi route diminta tak ter-cache (mis. `/login`) | **SW navigation fallback** | Diarahkan ke POS cached (jika ada), atau halaman offline brand |
| 3 | Belum pernah online sama sekali (SW belum ter-install) | **Native `errorPath`** (bundle di APK) | Halaman offline brand di dalam APK — **bukan** error Chrome |

Mekanisme komposisi di Capacitor Android: webview navigasi ke `server.url`. Kalau DNS gagal **dan** SW belum aktif → `WebViewClient.onReceivedError` → load `errorPath` lokal. Kalau SW sudah aktif (dari sesi online sebelumnya) → SW intercept fetch navigasi → serve cache / fallback brand sebelum errorPath sempat jalan.

## Komponen & File

### Repo PHP (web)

| File | Aksi | Tanggung jawab |
|---|---|---|
| `sw-tenant.js` | MODIFY | (a) Tambah `/pos` + `/pos.php` ke shell-cache **stale-while-revalidate**. (b) Tambah **navigation fallback**: request `mode === 'navigate'` yang gagal network → cached exact-match → else cached `/pos` → else `offlinePage()`. Menutup `/login` & semua route. (c) Saat `activate`/logout, pastikan entri shell POS ikut dibersihkan bersama cache versi lama. |
| `login.php` | MODIFY (kecil) | **Terkonfirmasi:** `login.php` tidak memuat `components.php` dan tidak mendaftarkan SW. Tambahkan snippet registrasi SW yang sama persis dengan `components.php:111-118` (register `/sw-tenant.js` scope `/` pada `window load`) ke `<head>` `login.php`, agar SW ter-install sejak layar login online pertama. Karena scope `/`, SW yang ter-install dari mana pun langsung mengontrol navigasi `/login` berikutnya. |

Catatan: `pos.php` **tidak perlu** diubah untuk fitur ini — offline-pos.js, enqueue, struk tempCode, dan `sync_offline` sudah ada. Verifikasi saja shell HTML POS layak di-cache (tidak ada nonce per-request yang memecah cache).

### Repo native (`~/Documents/lamasy-app`)

| File | Aksi | Tanggung jawab |
|---|---|---|
| `capacitor.config.json` | MODIFY | Tambah `server.errorPath: "offline.html"` (fallback lokal saat load remote gagal & SW belum aktif). |
| `www/offline.html` | **NEW** | Halaman offline brand (gaya sama dengan `offlinePage()` di SW): ikon 📡, "Tidak ada koneksi", tombol "Coba lagi" (reload `server.url`). Untuk first-launch: pesan "Sambungkan internet sekali dulu untuk menyiapkan mode offline". Murni statis, **tanpa dependensi remote**. |
| `android/` (rebuild) | BUILD | `npx cap sync android` + `build-apk.sh` → **APK b12**. (Dijalankan user; butuh JDK 21 + Android SDK.) |

## Alur

### Cold-start offline — kasir sudah pernah online
1. App dibuka offline → webview minta `/login` → gagal network.
2. SW (sudah aktif dari sesi sebelumnya) intercept → `/login` tak ter-cache → navigation fallback → serve **shell POS cached**.
3. Kasir bikin order (katalog dari IndexedDB) → masuk antrian → struk tempCode. (alur existing)
4. Sinyal balik → auto-sync → nomor asli + kas + poin (alur existing).

### Cold-start offline — first launch (belum pernah online)
1. App dibuka offline → webview minta `/login` → gagal, SW belum ada.
2. Capacitor `onReceivedError` → load `www/offline.html` (bundle APK).
3. Pesan brand "Sambungkan internet sekali dulu…" + tombol Coba lagi. **Bukan error Chrome.**

### Online normal
Tidak berubah: `server.url` `/login` → login → role landing. SW ter-install/aktif, cache shell POS saat POS dibuka. Stale-while-revalidate me-refresh shell di belakang layar.

## Error Handling / Edge Cases

| Kondisi | Perilaku |
|---|---|
| CSRF token di shell POS cached jadi stale | Aman untuk **bikin** order offline (tak nge-POST). **Verifikasi** (item testing): saat online, `sync_offline` tetap pakai token valid — bila shell disajikan dari cache, pastikan token di-refresh (stale-while-revalidate me-load HTML baru) atau sync mengambil token segar sebelum POST. |
| Data dinamis di HTML cached basi (mis. daftar order hari ini) | Diterima. Sumber kebenaran offline = IndexedDB katalog, bukan HTML. |
| Logout / ganti outlet/tenant | Shell POS cached **ikut dibersihkan** bersama katalog + antrian (anti bocor antar user/outlet). Peringatan existing "ada N order belum ter-sync" tetap berlaku. |
| Navigasi ke route non-POS saat offline (mis. laporan) | SW navigation fallback → halaman offline brand (📡) dengan tombol Coba lagi, bukan error Chrome. |
| `errorPath` tidak didukung versi Capacitor terpasang | Fallback: custom `WebViewClient.onReceivedError` di `MainActivity` yang `loadUrl("file:///android_asset/public/offline.html")`. (Tentukan saat implementasi setelah cek Capacitor 7.) |

## Keamanan

- Shell POS cached = halaman ter-autentikasi milik satu tenant/outlet/user → **wajib dibersihkan saat logout & ganti scope** (sejalan aturan existing fitur offline).
- `sync_offline` tetap lewat guard tenant/outlet + `verifyCsrf()`; server tidak percaya scope dari payload.
- `www/offline.html` murni statis tanpa data sensitif & tanpa panggilan remote.
- `server.url` tetap HTTPS-only; `allowNavigation` tetap `lamasy.harpy.id`.

## Testing

### SW (web)
- Cache-shell: `/pos` di-cache saat online; offline → SW serve `/pos` cached.
- Navigation fallback: navigasi gagal offline ke route tak-ter-cache → `offlinePage()` (bukan error/`undefined`); navigasi ke `/login` offline → POS cached jika ada, else offlinePage.
- Cleanup: setelah simulasi logout, entri shell POS cached hilang.

### Manual E2E (device, APK b12)
1. **Cold-start offline setelah online**: login online → buka POS sekali → tutup app total → mode pesawat → buka app → **POS cached kebuka** → buat order layanan+tier+tunai → struk tempCode → matikan pesawat → auto-sync → nomor asli muncul, kas tercatat, pending = 0.
2. **First-launch offline**: install APK b12 di device yang belum pernah buka app → mode pesawat → buka app → muncul `offline.html` brand (📡), **bukan** error Chrome → tombol Coba lagi.
3. **CSRF sync**: setelah skenario 1, pastikan `sync_offline` sukses (token valid) walau shell awal dari cache.
4. **Logout cleanup**: ada antrian → logout → peringatan muncul; setelah logout, cache shell POS bersih.

### Lint
- `node --check sw-tenant.js`; PHP lint file yang disentuh (bila ada).

## Out of Scope

- Offline untuk halaman selain POS (orders/kanban/laporan tetap online-only; offline = halaman brand).
- Login offline / autentikasi tanpa server.
- Background Sync API murni tanpa buka app (andalkan event `online` + buka app; bisa ditambah nanti).
- Perubahan mekanisme sync/idempotency (sudah ada & tak disentuh).
- iOS (Android dulu).
- Ganti `server.url`/arsitektur entry point.

## Pembagian Kerja

| Langkah | Siapa |
|---|---|
| Modifikasi `sw-tenant.js` + registrasi SW di `/login` (repo PHP) | **Claude** |
| `www/offline.html` + `capacitor.config.json` errorPath (repo native) | **Claude** |
| Verifikasi `errorPath` Capacitor 7 / fallback MainActivity | **Claude** (cek) → **User** bila butuh build |
| `npx cap sync` + `build-apk.sh` → APK b12 | **User** (butuh JDK 21 + Android SDK) |
| Manual E2E di device (mode pesawat) | **User** |

## References
- [[project-offline-order-pos]] — fitur dasar yang di-hardening
- [[project-native-app-strategy]] — Capacitor thin-shell
- [spec Offline Order POS](2026-06-29-offline-order-pos-design.md)
- `sw-tenant.js`, `pos.php`, `~/Documents/lamasy-app/capacitor.config.json`
