# Bottom Navigation Bar (Outlet Shell) — Design Spec

> LaMaSy. Tanggal: 2026-07-01.

## Goal

Tambah bottom navigation bar khas mobile di **shell outlet** (kasir/operasional): bar tetap di bawah layar berisi 5 slot — Beranda, Order, **POS (tombol tengah menonjol)**, Customer, Menu — untuk akses cepat tanpa buka drawer. Hanya mobile; desktop tetap sidebar.

## Keputusan (terkunci)
- Shell: **outlet saja** (kasir/operasional). HQ menyusul (out of scope).
- Item: `🏠 Beranda(/dashboard)` · `📋 Order(/orders)` · `🛒 POS(/pos, FAB tengah)` · `👥 Customer(/customer)` · `☰ Menu(buka drawer)`.
- POS = tombol bulat teal terangkat di tengah.
- Permission-aware: item disembunyikan kalau role tak punya izin (reuse `perm` array menu existing di `renderTopbar`).
- Mobile-only (≤900px, breakpoint sama dgn sidebar).

## Arsitektur

Semua di **`components.php` → `renderTopbar($activePage)`** (pembangun outlet shell). Bar dirender sekali per halaman outlet, jadi otomatis ada di semua halaman outlet. CSS di `harpy-erp.css` (atau blok `<style>` di renderTopbar).

### Data item (reuse menu array)
`renderTopbar` sudah punya definisi menu dgn `label`/`url`/`perm`/`perms`. Bottom bar pakai subset tetap:
```
Beranda  → /dashboard   (perm: null — selalu ada)
Order    → /orders      (perms: orders.view_all|orders.view_own)
POS      → /pos         (perm: pos.view)   [FAB tengah]
Customer → /customer    (perm: pelanggan.view)
Menu     → toggle drawer (selalu ada)
```
Cek izin pakai **`hasPermission($perm)`** (helper yang sama dipakai closure visibilitas menu di `renderTopbar`): `perm === null` → selalu tampil; ada `perms[]` → tampil bila salah satu `hasPermission()` true. Item yang tak diizinkan **tidak dirender**. (Untuk konsistensi, boleh reuse closure visibilitas item yang sudah ada di `renderTopbar`.)

### Layout & FAB
- `<nav class="ol-bottomnav">` fixed bottom, z-index di atas konten (di bawah modal & drawer). Tinggi ~58px + `env(safe-area-inset-bottom)`.
- 5 slot flex merata. Slot POS = tombol bulat teal (Ø ~56px) terangkat (`margin-top:-22px`), ikon 🛒.
- Tiap slot: ikon + label kecil (11px). Slot aktif (cocok `$activePage`) warna teal; lainnya abu (#6B7280).
- **Fallback**: kalau POS tak diizinkan → FAB dihilangkan, bar jadi item rata (tanpa tombol tengah), tetap valid (tak ada slot kosong).
- "Menu" `onclick` = `document.getElementById('olShell').classList.toggle('open')` (drawer existing).

### Active state
Tentukan dari `$activePage` (variabel yang sudah dilewatkan ke `renderTopbar`): map activePage → slot (dashboard→Beranda, orders/kanban→Order, pos→POS, customer→Customer). Selain itu tak ada slot aktif (mis. halaman di dalam Menu).

### Padding konten
Di mobile, `.ol-main` (atau body outlet) diberi `padding-bottom: calc(58px + env(safe-area-inset-bottom) + 8px)` agar konten paling bawah tak ketutup bar. Desktop: 0 (bar hidden).

### Visibilitas
- CSS: `.ol-bottomnav{display:none}` default; `@media (max-width:900px){.ol-bottomnav{display:flex}}`.
- Hanya di outlet shell (renderTopbar). Portal pelanggan/kurir/superadmin/publik (shell lain) tak terpengaruh.

## Edge Cases
| Kondisi | Perilaku |
|---|---|
| Role tanpa `pos.view` | Slot POS (FAB) tak dirender → bar 4 item rata |
| Role tanpa `orders`/`pelanggan` perm | Slot itu tak dirender |
| Desktop (>900px) | Bar hidden (sidebar dipakai) |
| Halaman di dalam Menu (mis. Kas, Laporan) | Tak ada slot aktif; item Menu bisa ditandai aktif opsional |
| Modal/drawer terbuka | Bar tetap di bawahnya (z-index bar < modal/drawer) |
| iPhone home-indicator | `env(safe-area-inset-bottom)` mencegah item ketutup |

## Keamanan
- Murni UI klien-render server-side. Item URL = halaman existing (guard/permission per halaman tetap berlaku). Tak ada endpoint baru.

## Testing
- `php -l components.php`.
- Manual (mobile): bar muncul di halaman outlet (dashboard/pos/orders/customer), tidak di portal/HQ/publik; active state benar; FAB→/pos; Menu buka drawer; item tersembunyi bila tanpa izin; konten bawah tak ketutup; desktop tak ada bar.

## Out of Scope (v1)
- HQ shell bottom nav (menyusul, spec terpisah).
- Badge notifikasi di ikon.
- Animasi transisi antar-tab.
- Menyembunyikan hamburger topbar (tetap ada; redundan-aman).

## Files
**Modify:** `components.php` (`renderTopbar` — markup bar + logika perm/active), `harpy-erp.css` (style `.ol-bottomnav` + FAB + padding mobile).

## References
- `components.php` `renderTopbar()` (~542), `#olShell` drawer toggle (~816), menu array + perms (~558-602), `$activePage`.
