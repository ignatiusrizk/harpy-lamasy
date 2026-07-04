# Pull-to-Refresh — Design Spec

> LAMASY. Tanggal: 2026-07-01.

## Goal

Gestur "tarik ke bawah untuk menyegarkan" di halaman tenant pada perangkat sentuh: saat di posisi paling atas, tarik konten ke bawah melewati ambang → `location.reload()`. Global, klien-only, tak butuh hook per-halaman.

## Keputusan (terkunci)
- Aksi: **reload halaman penuh** (`location.reload()`).
- Platform: **semua perangkat sentuh** (APK + mobile web). Desktop (mouse) tak terpengaruh.
- Lokasi: satu blok JS+CSS di `components.php` `renderGlobalJsHelpers()` → semua halaman tenant.

## Arsitektur

Blok IIFE baru di `components.php` (dekat handler global lain). 

**Aktivasi:** hanya jika perangkat sentuh — `('ontouchstart' in window) || navigator.maxTouchPoints > 0`.

**Elemen indikator:** satu `<div id="ptrIndicator">` fixed di atas-tengah (tersembunyi default), berisi lingkaran + spinner. Dibuat via JS saat init, style via CSS injected.

**Gestur (listener di `document`, touchmove non-passive):**
- `touchstart`: catat `startY = touch.clientY`; arm hanya bila `window.scrollY <= 0` DAN tak ada overlay terbuka DAN single-touch. Else disarm.
- `touchmove`: bila armed & `deltaY = clientY - startY > 0` & masih `scrollY <= 0`:
  - `e.preventDefault()` (cegah rubber-band/scroll).
  - `pull = min(deltaY * 0.5, MAX=90)` (resistensi 0.5).
  - Geser/opacity indikator proporsional; tandai "siap" bila `pull >= THRESHOLD (70)`.
- `touchend`: bila armed & `pull >= THRESHOLD` → indikator ke mode spinner + `location.reload()`. Else animasi balik + sembunyikan. Reset state.

**Guard overlay:** reuse pola deteksi overlay dari smart-back — bila ada elemen visible ber-class `/(modal|overlay|backdrop|drawer|sheet|popup)/i` dengan penanda terbuka → jangan arm (biar scroll di dalam modal / drawer tak memicu reload).

## Edge Cases
| Kondisi | Perilaku |
|---|---|
| Halaman ter-scroll ke bawah | Tak arm (start hanya saat scrollY<=0) |
| Modal/drawer terbuka | Tak arm (guard overlay) |
| Multi-touch (pinch/zoom) | Abaikan (arm hanya single-touch) |
| Tarik ke atas / geser samping | deltaY<=0 → tak aktif |
| Belum lewat ambang lalu lepas | Batal, indikator balik |
| Desktop (mouse) | Handler tak terpasang (bukan touch) |

## Keamanan
- Murni klien, tak ada endpoint/DB/auth. `location.reload()` memuat ulang halaman yang sama.

## Testing
- `php -l components.php`.
- Manual (device): tarik di posisi atas → indikator muncul & reload; tarik saat modal terbuka → tak reload; scroll biasa di tengah → normal; pinch-zoom → tak memicu.

## Out of Scope
- Re-fetch data tanpa reload (dipilih reload penuh).
- Halaman portal pelanggan/kurir (fokus shell tenant via components.php; bisa ditambah nanti).
- Custom animasi elastis kompleks.

## Files
**Modify:** `components.php` — tambah IIFE pull-to-refresh + CSS indikator di `renderGlobalJsHelpers()`.

## References
- `components.php` (handler global: back button, showToast), spec smart-back (pola deteksi overlay), [[project_native_app_strategy]].
