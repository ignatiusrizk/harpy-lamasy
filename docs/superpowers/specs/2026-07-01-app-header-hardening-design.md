# App Header Hardening — Design Spec

> LaMaSy. Tanggal: 2026-07-01.

## Goal

Header aplikasi (top bar navy) terasa seperti app native: **nempel (sticky) di semua halaman**, **mulus ke status bar OS dengan ikon kontras (putih)** tanpa celah/beda warna, dan sedikit **polish "floating app bar"** (shadow saat scroll). Warna & isi header tetap; hanya behavior + integrasi status bar yang dirapikan.

## Latar (kondisi sekarang)

- **Outlet shell** `.ol-top` ([components.php](../../components.php)): **sudah** `position:sticky;top:0;z-index:900`.
- **HQ layout** `.hq-top` ([hq/_layout_open.php](../../hq/_layout_open.php)): **belum** sticky → hilang saat scroll.
- `theme-color`=`#0F1C3A` di keduanya. `apple-mobile-web-app-status-bar-style`=`black-translucent`.
- **APK** (Capacitor): `StatusBar.setOverlaysWebView({overlay:false})` + `setBackgroundColor('#0F1C3A')` + `setStyle('LIGHT')` (teks putih) — [components.php ~149].

## Keputusan Desain (terkunci)

- **Sticky di semua halaman**: `.hq-top` dibuat sticky (`top:0; z-index` selaras `.ol-top`). Outlet tak berubah.
- **Status bar mulus + kontras**:
  - **PWA**: `viewport-fit=cover` + `env(safe-area-inset-top)` sebagai padding-top header, agar header ngisi penuh sampai belakang notch/status bar (solid navy, tanpa celah). `theme-color` navy tetap → Chrome pakai ikon terang.
  - **APK**: pertahankan StatusBar plugin (`overlay:false`, bg navy, style LIGHT). Verifikasi konsisten; tak perlu safe-area (webview turun di bawah status bar, status bar sudah navy).
- **Polish app-bar**: shadow header muncul saat konten di-scroll (`.scrolled` class via JS `scroll` listener), transisi halus. Tinggi header konsisten.
- **Isi & warna header tidak diubah** (menu, badge, judul, logout tetap). Bukan rebrand.
- Terapkan seragam via CSS/JS di shell bersama; tidak menyentuh isi masing-masing halaman.

## Out of Scope

- Redesain visual/rebrand header, ubah isi menu.
- Hide-on-scroll (header sembunyi saat scroll ke bawah, muncul saat ke atas) — bisa fase lanjut.
- Perubahan sidebar / bottom nav.

## Komponen & File

| File | Aksi | Tanggung jawab |
|---|---|---|
| `hq/_layout_open.php` | MODIFY | `.hq-top` → sticky (top:0 + z-index + bg solid); `viewport-fit=cover` di viewport meta; `env(safe-area-inset-top)` padding pada header. |
| `components.php` | MODIFY | Outlet shell: `viewport-fit=cover` di viewport meta (bila belum); `env(safe-area-inset-top)` padding pada `.ol-top`; JS `scroll` listener → toggle `.scrolled` (shadow); pertahankan StatusBar plugin. |
| (CSS inline di kedua layout) | MODIFY | `.ol-top.scrolled`/`.hq-top.scrolled{box-shadow:...}` + transition. |

## Alur / Perilaku

1. Muat halaman → header solid navy dari ujung atas (safe-area terisi), ikon status bar putih.
2. Scroll → header tetap nempel; setelah scrollTop>4px, class `.scrolled` aktif → shadow halus muncul (efek floating). Scroll balik ke atas → shadow hilang.
3. APK: status bar navy + teks putih (plugin), konten mulai di bawahnya.

## Edge Cases

| Kondisi | Perilaku |
|---|---|
| Demo banner aktif (`.has-demo-banner`) | `.ol-top{top:45px}` existing tetap; safe-area padding diletakkan agar tak dobel dengan banner (banner yang kena safe-area, atau header saat tanpa banner). Pastikan tidak ada celah/tumpang tindih. |
| PWA tanpa notch (status bar biasa) | `env(safe-area-inset-top)`=0 → tak ada padding ekstra; layout sama seperti sekarang. |
| Perangkat non-native (browser desktop) | safe-area=0, sticky tetap jalan, shadow-on-scroll tetap jalan — aman. |
| HQ header + sidebar | `.hq-top` sticky tak boleh menutupi/di-tutupi sidebar; z-index diselaraskan (di bawah overlay modal, di atas konten). |

## Testing

- **Manual (device/PWA + APK)**: buka halaman panjang di outlet & HQ → scroll → header tetap nempel + shadow muncul; status bar solid navy + ikon putih, tak ada celah; demo mode (bila ada) tak bikin dobel/celah.
- **Lint**: `php -l` file yang disentuh.
- **Regresi**: sidebar toggle, bell/menu, switch outlet tetap jalan (header interaktif tak rusak oleh perubahan sticky/z-index).

## References
- [[project-native-app-strategy]] — Capacitor StatusBar
- `components.php` (renderTopbar, `.ol-top`, StatusBar JS), `hq/_layout_open.php` (`.hq-top`)
