# Bottom Navigation Bar (Outlet Shell) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bottom navigation bar mobile di shell outlet — Beranda · Order · POS(FAB tengah) · Customer · Menu — hanya tampil ≤900px, permission-aware, reuse drawer existing.

**Architecture:** Ditambahkan di `components.php` `renderTopbar()` (pembangun outlet shell) tepat setelah `</header>` topbar, di-gate `!$minimalMode` (HQ shell pakai renderTopbar dgn minimalMode=true → tak dapat bar). Visibility item pakai `navItemVisible()` + `$iconMap` yang sudah ada. Styling di `harpy-erp.css`.

**Tech Stack:** PHP (renderTopbar), CSS (harpy-erp.css). Tanpa JS baru (Menu pakai toggle drawer existing).

## Global Constraints
- Outlet shell saja (`!$minimalMode`). HQ (minimalMode=true) TIDAK dapat bar.
- Mobile-only ≤900px (breakpoint sama sidebar). Desktop: bar hidden.
- Item permission-aware via `navItemVisible($item, $user)` (helper existing). Item tanpa izin tak dirender.
- Slot: Beranda(/dashboard, selalu) · Order(/orders) · **POS(/pos, FAB tengah)** · Customer(/customer) · Menu(toggle `#olShell`).
- Active state dari `$activePage` (dashboard→Beranda; orders/kanban→Order; pos→POS; customer→Customer).
- POS tanpa izin → FAB tak dirender (bar jadi item rata, tak ada slot kosong).
- Konten tak ketutup: `.ol-content` padding-bottom di mobile + `env(safe-area-inset-bottom)`.

---

### Task 1: Bottom nav bar di outlet shell

**Files:**
- Modify: `components.php` — `renderTopbar()`, sisipkan markup bar setelah `</header>` (baris ~1001), sebelum `<main class="ol-content">` (~1003)
- Modify: `harpy-erp.css` — style `.ol-bottomnav` + FAB + mobile media query + padding `.ol-content`

**Interfaces:**
- Consumes: `$minimalMode`, `$activePage`, `$user`, `$navGroups`, `navItemVisible(array $item, array $user): bool` — semua sudah ada di `renderTopbar`. Drawer toggle: `document.getElementById('olShell').classList.toggle('open')`.
- Produces: elemen `<nav class="ol-bottomnav">` (fixed, mobile-only).

- [ ] **Step 1: Sisipkan markup bar di `renderTopbar` (components.php)**

Cari blok (sekitar baris 1001-1003):
```php
        </header>

        <main class="ol-content">
```
Ganti menjadi (sisipkan bar di antara `</header>` dan `<main>`):
```php
        </header>

        <?php if (!$minimalMode):
          // Bottom nav (mobile only, CSS handle visibilitas ≤900px)
          $bnOrder    = navItemVisible($navGroups['operasional']['items']['orders'], $user);
          $bnPos      = navItemVisible($navGroups['operasional']['items']['pos'], $user);
          $bnCustomer = navItemVisible($navGroups['master']['items']['customer'], $user);
          $bnAct = fn(array $keys) => in_array($activePage, $keys, true) ? ' active' : '';
        ?>
        <nav class="ol-bottomnav" aria-label="Navigasi utama">
          <a href="/dashboard" class="bn-item<?= $bnAct(['dashboard']) ?>">
            <span class="bn-ic">🏠</span><span class="bn-lb">Beranda</span></a>
          <?php if ($bnOrder): ?>
          <a href="/orders" class="bn-item<?= $bnAct(['orders','kanban']) ?>">
            <span class="bn-ic">📋</span><span class="bn-lb">Order</span></a>
          <?php endif; ?>
          <?php if ($bnPos): ?>
          <a href="/pos" class="bn-fab<?= $bnAct(['pos']) ?>" aria-label="POS">
            <span class="bn-fab-ic">🛒</span></a>
          <?php endif; ?>
          <?php if ($bnCustomer): ?>
          <a href="/customer" class="bn-item<?= $bnAct(['customer']) ?>">
            <span class="bn-ic">👥</span><span class="bn-lb">Customer</span></a>
          <?php endif; ?>
          <button type="button" class="bn-item"
                  onclick="document.getElementById('olShell').classList.toggle('open')">
            <span class="bn-ic">☰</span><span class="bn-lb">Menu</span></button>
        </nav>
        <?php endif; ?>

        <main class="ol-content">
```

- [ ] **Step 2: Lint PHP**

Run: `php -l components.php`
Expected: `No syntax errors detected in components.php`

- [ ] **Step 3: Tambah CSS di `harpy-erp.css`**

Tambahkan di akhir file `harpy-erp.css`:
```css
/* ── Bottom navigation bar (outlet, mobile only) ── */
.ol-bottomnav { display: none; }
@media (max-width: 900px) {
  .ol-bottomnav {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 40;
    display: flex; align-items: center; justify-content: space-around;
    background: #fff; border-top: 1px solid #E5E7EB;
    padding: 6px 4px calc(6px + env(safe-area-inset-bottom, 0px));
    box-shadow: 0 -2px 12px rgba(0,0,0,.06);
  }
  .ol-bottomnav .bn-item {
    flex: 1 1 0; min-width: 0;
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    background: none; border: 0; cursor: pointer; padding: 4px 0;
    color: #6B7280; font-size: 11px; font-weight: 600; font-family: inherit;
    text-decoration: none;
  }
  .ol-bottomnav .bn-item.active { color: #14B8A6; }
  .ol-bottomnav .bn-ic { font-size: 19px; line-height: 1; }
  .ol-bottomnav .bn-lb { line-height: 1; }
  .ol-bottomnav .bn-fab {
    flex: 0 0 auto; width: 56px; height: 56px; border-radius: 50%;
    margin: -22px 4px 0; background: #35E8D5; color: #0F1C3A;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(53,232,213,.5); text-decoration: none;
  }
  .ol-bottomnav .bn-fab-ic { font-size: 24px; line-height: 1; }
  .ol-bottomnav .bn-fab.active { outline: 3px solid rgba(53,232,213,.35); }
  /* Konten tak ketutup bar */
  .ol-content { padding-bottom: calc(70px + env(safe-area-inset-bottom, 0px)); }
  /* Sembunyikan bar saat drawer terbuka (drawer punya menu lengkap) */
  .ol-shell.open .ol-bottomnav { display: none; }
}
```

- [ ] **Step 4: Commit**

```bash
git add components.php harpy-erp.css
git commit -m "feat(nav): bottom navigation bar outlet (mobile) — Beranda/Order/POS(FAB)/Customer/Menu, permission-aware"
```

---

## Manual E2E (mobile / lebar ≤900px)
- [ ] Buka halaman outlet (dashboard/pos/orders/customer) di mobile → bar muncul di bawah; item aktif di-highlight teal sesuai halaman.
- [ ] Tombol: Beranda→/dashboard, Order→/orders, FAB→/pos, Customer→/customer; **Menu** buka drawer sidebar.
- [ ] FAB POS tampil menonjol di tengah (owner/kasir dgn pos.view). Login user tanpa `pos.view` (mis. role terbatas) → FAB hilang, bar tetap rapi.
- [ ] Konten paling bawah tiap halaman tidak ketutup bar; di iPhone home-indicator tak menutupi item.
- [ ] Buka drawer (Menu) → bar tersembunyi selama drawer terbuka.
- [ ] Desktop (>900px) → bar tidak muncul (sidebar dipakai).
- [ ] Halaman HQ (minimalMode) & portal pelanggan/kurir/publik → bar TIDAK muncul.

---

## Self-Review
**Spec coverage:**
- Outlet shell only, `!$minimalMode` → Step 1 gate ✓
- Mobile-only ≤900px → CSS media query ✓
- 5 slot Beranda/Order/POS(FAB)/Customer/Menu → Step 1 markup ✓
- Permission-aware via `navItemVisible` → Step 1 ($bnOrder/$bnPos/$bnCustomer) ✓
- POS tanpa izin → FAB hilang, bar rata → `if ($bnPos)` ✓
- Active state dari `$activePage` (orders+kanban→Order) → `$bnAct` ✓
- Menu buka drawer existing → `#olShell` toggle ✓
- Konten padding + safe-area → CSS `.ol-content` ✓
- Bar di bawah overlay + hidden saat drawer open → z-index 40 + `.ol-shell.open .ol-bottomnav{display:none}` ✓

**Placeholder scan:** Tidak ada TBD/TODO; markup & CSS lengkap.

**Type consistency:** `navItemVisible($item,$user):bool`, `$navGroups[...]['items'][key]`, `$activePage`, `$minimalMode` — sesuai definisi di renderTopbar. Class `.ol-bottomnav/.bn-item/.bn-fab/.bn-ic/.bn-lb/.bn-fab-ic` konsisten antara markup & CSS. `.ol-content` & `.ol-shell.open` cocok dengan struktur shell existing.

**Catatan eksekusi:** Kerjakan di branch baru dari `main` (`feat/bottom-nav`). Satu task, dua file (components.php + harpy-erp.css). E2E visual mobile milik user. Verifikasi z-index: bar=40; kalau ada modal/overlay yg z-index-nya <40 dan muncul di bawah bar, naikkan z-index overlay itu (jarang — `.hl-modal-overlay` umumnya di atas).
