# Smart Hardware Back Button (APK) — Design Spec

> LAMASY. Tanggal: 2026-07-01.

## Goal

Tombol back fisik/gesture Android di APK berperilaku **berjenjang & kontekstual**, bukan `history.back()` mentah: tutup overlay dulu bila ada, kalau tidak arahkan ke dashboard, dan di dashboard pakai double-tap untuk keluar (anti-kepencet). Hanya sisi klien, hanya aktif di APK; PWA & desktop tak terpengaruh.

## Latar / Masalah

- Handler `App.addListener('backButton', …)` sudah ada di `components.php` (~baris 157-172) tapi naif: deteksi modal hanya `.modal.open` / `[data-modal-open="1"]`, lalu `history.back()` bila `canGoBack`, else →`/dashboard`, else `minimizeApp()`.
- Kelemahan: (a) banyak modal tak ter-deteksi karena polanya beragam — dominan `.classList.add('open')`, tapi juga `style.display='block'/'flex'`, `.active`, `.show`, container `.modal-bg`/`.modal-overlay`/`.modal-backdrop`; (b) `history.back()` bisa mundur nyasar jauh; (c) side-menu drawer tak dianggap overlay; (d) di dashboard langsung minimize (gampang kepencet keluar).
- `per_outlet`/coin dsb tak relevan — ini murni UX navigasi APK.

## Keputusan Desain (terkunci)

- **Scope:** hardware back APK saja. Tombol back di layar (billing-checkout, tenant_guard) & gesture browser PWA **tidak** disentuh.
- **Modal handling:** best-effort heuristik — nol perubahan ke modal existing.
- **Di root (dashboard):** double-tap untuk keluar (toast + jendela 2 detik → `exitApp`).
- **Dari halaman non-dashboard:** langsung ke `/dashboard` (bukan `history.back()`).
- **Side-menu drawer terbuka** = overlay → back menutupnya dulu.
- **Home = `/dashboard`** (shell tenant). Kurir & portal pelanggan di luar scope.

## Arsitektur

Ganti isi callback `App.addListener('backButton', …)` di `components.php` dengan logika berjenjang. Tetap dalam guard yang ada (`var App = window.Capacitor?.Plugins?.App; if (!App || !App.addListener) return;`) sehingga hanya aktif di APK.

### Urutan penanganan (tiap event back)
1. **Tutup overlay teratas** bila ada (`closeTopOverlay()` return true) → `return`.
2. Kalau pathname (tanpa trailing slash) **bukan** `/dashboard` (dan bukan `''`/`/login`) → `location.href = '/dashboard'` → `return`.
3. Di dashboard:
   - `_backExitArmed` false → set true, `setTimeout(() => _backExitArmed=false, 2000)`, tampilkan toast "Tekan sekali lagi untuk keluar" → `return`.
   - `_backExitArmed` true → `App.exitApp()` (fallback `App.minimizeApp()`).

### `closeTopOverlay()` — heuristik
- Kumpulkan kandidat overlay **visible** yang class-nya cocok pola `/(modal|overlay|backdrop|drawer|sheet|popup)/i`.
  - "visible" = `el.offsetParent !== null` **atau** `getComputedStyle(el).display !== 'none' && visibility !== 'hidden'`, dan `el.getClientRects().length > 0`.
  - Filter tambahan: harus sedang "terbuka" — punya salah satu class `open`/`active`/`show`/`visible` **atau** inline `style.display` di-set ke `flex`/`block`. Ini mencegah sidebar desktop persisten (tanpa penanda open) ikut tertutup.
- Kalau tak ada → return false.
- Pilih **teratas**: kandidat dengan `z-index` numerik terbesar; kalau seri, yang terakhir di urutan DOM.
- Tutup:
  1. Kalau ada tombol tutup di dalamnya (`.modal-close`, `[data-close]`, `[onclick*="close" i]`) → klik yang pertama (biar efek samping seperti reset form/handler existing jalan). Return true.
  2. Else balikkan pola sendiri: `classList.remove('open','active','show','visible')`; kalau `el.style.display` ter-set → `el.style.display = 'none'`. Return true.

**Anti salah-tutup:** karena filter mensyaratkan class overlay-pattern **dan** penanda terbuka, elemen `.open` non-overlay (mis. grup menu collapsible, accordion) tidak akan tersentuh.

### Toast
Pakai `showToast(...)` bila fungsi global itu ada (dipakai di banyak halaman); fallback ke `alert`-less no-op + tetap arm exit (jangan blok exit hanya karena toast absen). Bentuk minimal bila perlu buat sendiri: elemen sekali-pakai fixed bawah, hilang 2 detik.

## Alur (contoh)

- Buka modal edit → back → modal tertutup, tetap di halaman.
- Drawer menu kebuka → back → drawer tertutup.
- Di `/orders` tanpa overlay → back → ke `/dashboard`.
- Di `/dashboard` → back → toast "Tekan sekali lagi…"; back lagi ≤2s → app keluar; kalau >2s → arm ulang (toast lagi).

## Error Handling / Edge Cases

| Kondisi | Perilaku |
|---|---|
| Dua overlay bertumpuk (mis. modal di atas drawer) | Tutup yang z-index/DOM teratas dulu; back berikutnya tutup berikutnya |
| Overlay dibuka via `display` inline tanpa class open | Terdeteksi via cek inline display; ditutup dengan set `display='none'` |
| `showToast` tidak ada di halaman | Skip toast, tetap arm exit (back kedua tetap keluar) |
| `App.exitApp` tak tersedia (versi plugin) | Fallback `App.minimizeApp()` |
| Elemen `.open` non-overlay (accordion, menu group) | TIDAK ditutup (filter wajib class overlay-pattern) |
| Sidebar desktop persisten | Tidak punya penanda "open" transien → tidak tertutup; lagipula scope APK = mobile |
| Halaman kurir/pelanggan | Handler ada di shell tenant; kalau ter-load, home tetap `/dashboard` (out of scope untuk penyesuaian) |

## Keamanan
- Murni klien, tak ada endpoint/DB. Tak ada data sensitif. Tak mengubah auth/CSRF.

## Testing
- **Otomatis (terbatas):** `php -l components.php`. Handler tetap ter-guard `Capacitor.Plugins.App` → tak jalan di PWA/desktop (verifikasi guard tak berubah).
- **Manual E2E di APK (user, butuh APK b12+):**
  1. Buka modal (mis. edit outlet) → back → modal tertutup, halaman tetap.
  2. Buka drawer menu → back → drawer tertutup.
  3. Di halaman non-dashboard (mis. /orders) → back → ke /dashboard.
  4. Di /dashboard → back → toast; back lagi ≤2s → app keluar; >2s → toast lagi (tak keluar).
  5. Regresi: alur normal (buka/tutup modal via tombol, navigasi antar halaman) tetap jalan; PWA di browser tak berubah.

## Out of Scope
- Tombol back di layar (`← Kembali` billing-checkout, tenant_guard) & gesture back browser PWA.
- Menstandarkan konvensi modal (pakai heuristik, bukan refactor).
- iOS (tak ada hardware back).
- Bottom navigation bar (fitur terpisah berikutnya).

## Files Inventory
**Modify:** `components.php` — ganti isi callback `backButton` (satu blok, ~baris 157-172); tambah helper `closeTopOverlay()` + state `_backExitArmed` di dalam IIFE yang sama.

## References
- `components.php` (handler backButton existing + `showToast`), [[project-native-app-strategy]], @capacitor/app `exitApp`/`minimizeApp`.
