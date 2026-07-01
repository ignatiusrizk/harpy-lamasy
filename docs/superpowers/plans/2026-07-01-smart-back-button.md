# Smart Hardware Back Button (APK) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti handler hardware back APK jadi berjenjang: tutup overlay teratas → kalau bukan dashboard ke `/dashboard` → di dashboard double-tap untuk keluar.

**Architecture:** Satu perubahan di `components.php` — mengganti isi callback `App.addListener('backButton', …)` yang sudah ada (tetap dalam guard `Capacitor.Plugins.App` yang membuatnya APK-only) + menambah helper `closeTopOverlay()`/`isOpenOverlay()`/`isVisible()` dan state `backExitArmed` di dalam IIFE yang sama.

**Tech Stack:** Vanilla JS embedded di PHP (`components.php`), @capacitor/app (`exitApp`/`minimizeApp`), `showToast(msg,type)` global (components.php:1070).

## Global Constraints

- APK only — handler tetap ter-guard `var App = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App; if (!App || !App.addListener) return;`. PWA/desktop tak boleh terpengaruh.
- Jangan pakai `history.back()` — dari halaman non-dashboard, back → `location.href = '/dashboard'`.
- Overlay dianggap terbuka HANYA jika class cocok `/(modal|overlay|backdrop|drawer|sheet|popup)/i` DAN visible DAN punya penanda terbuka (`open`/`active`/`show`/`visible` atau inline `display:flex|block`) — cegah menutup accordion/sidebar persisten.
- Di dashboard: back pertama → toast + arm 2 detik; back kedua ≤2s → `exitApp` (fallback `minimizeApp`).
- Home = `/dashboard`. Tak menyentuh modal existing, tombol back di layar, atau gesture PWA.

---

### Task 1: Ganti handler backButton jadi berjenjang

**Files:**
- Modify: `components.php` — blok IIFE `App.addListener('backButton', …)` (saat ini ~baris 156-172)

**Interfaces:**
- Consumes: `window.Capacitor.Plugins.App` (`addListener`, `exitApp`, `minimizeApp`), `showToast(msg, type)` (opsional, guard `typeof`).
- Produces: tidak ada interface untuk task lain (fitur mandiri).

- [ ] **Step 1: Baca blok saat ini untuk memastikan anchor**

Run: `sed -n '156,173p' components.php`
Expected: terlihat IIFE `// ── Hardware back button (native app) …` dengan `var openModal = document.querySelector('.modal.open, [data-modal-open="1"]')` dan `if (e && e.canGoBack) { window.history.back(); … }`.

- [ ] **Step 2: Ganti seluruh IIFE dengan versi berjenjang**

Ganti blok dari baris komentar `// ── Hardware back button (native app): …` sampai penutup `})();`-nya dengan:

```php
    // ── Hardware back button (native app): berjenjang — tutup overlay → dashboard → double-tap keluar ──
    (function(){
      try {
        var App = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App;
        if (!App || !App.addListener) return;
        var backExitArmed = false;

        function isVisible(el){
          if (!el) return false;
          var cs = window.getComputedStyle(el);
          if (cs.display === 'none' || cs.visibility === 'hidden') return false;
          // position:fixed → offsetParent null tapi tetap terlihat; jadi jangan andalkan offsetParent saja
          return el.getClientRects().length > 0;
        }
        function isOpenOverlay(el){
          var cls = (el.className && el.className.toString) ? el.className.toString() : '';
          if (!/(modal|overlay|backdrop|drawer|sheet|popup)/i.test(cls)) return false;
          if (!isVisible(el)) return false;
          return /\b(open|active|show|visible)\b/.test(cls)
                 || el.style.display === 'flex' || el.style.display === 'block';
        }
        function closeTopOverlay(){
          var nodes = document.querySelectorAll(
            '[class*="modal"],[class*="overlay"],[class*="backdrop"],[class*="drawer"],[class*="sheet"],[class*="popup"]'
          );
          var cands = [];
          for (var i = 0; i < nodes.length; i++){ if (isOpenOverlay(nodes[i])) cands.push(nodes[i]); }
          if (!cands.length) return false;
          cands.sort(function(a, b){
            return (parseInt(window.getComputedStyle(a).zIndex, 10) || 0)
                 - (parseInt(window.getComputedStyle(b).zIndex, 10) || 0);
          });
          var top = cands[cands.length - 1];
          var btn = top.querySelector('.modal-close, [data-close], [onclick*="close" i]');
          if (btn) { btn.click(); return true; }
          top.classList.remove('open', 'active', 'show', 'visible');
          if (top.style.display) top.style.display = 'none';
          return true;
        }

        App.addListener('backButton', function(e){
          // 1) Tutup overlay teratas bila ada
          if (closeTopOverlay()) return;
          // 2) Bukan di dashboard → ke dashboard (bukan history.back)
          var p = location.pathname.replace(/\/$/, '');
          if (p !== '/dashboard' && p !== '/login' && p !== '') { location.href = '/dashboard'; return; }
          // 3) Di dashboard → double-tap untuk keluar
          if (!backExitArmed) {
            backExitArmed = true;
            setTimeout(function(){ backExitArmed = false; }, 2000);
            if (typeof showToast === 'function') { try { showToast('Tekan sekali lagi untuk keluar', 'info'); } catch(_){} }
            return;
          }
          try { App.exitApp(); } catch(_){ try { App.minimizeApp(); } catch(__){} }
        });
      } catch(e) {}
    })();
```

- [ ] **Step 3: Lint PHP**

Run: `php -l components.php`
Expected: `No syntax errors detected in components.php`

- [ ] **Step 4: Verifikasi isi handler baru & jejak lama hilang**

Run: `grep -c "closeTopOverlay\|isOpenOverlay\|backExitArmed" components.php`
Expected: `>= 3` (helper + state hadir).

Run: `sed -n '156,210p' components.php | grep -c "canGoBack\|history.back\|data-modal-open"`
Expected: `0` (logika lama sudah tidak ada di handler).

Run: `grep -c "if (!App || !App.addListener) return;" components.php`
Expected: `>= 1` (guard APK-only tetap ada).

- [ ] **Step 5: Commit**

```bash
git add components.php
git commit -m "feat(nav): smart hardware back APK — tutup overlay → dashboard → double-tap keluar"
```

---

## Manual E2E (USER, di device — butuh APK b12+)

- [ ] **E2E-1 Modal:** buka modal (mis. Edit outlet di /hq/outlet) → tekan back → modal tertutup, tetap di halaman (tidak pindah/keluar).
- [ ] **E2E-2 Drawer:** buka side-menu drawer → back → drawer tertutup.
- [ ] **E2E-3 Halaman → dashboard:** di /orders (tanpa overlay) → back → pindah ke /dashboard.
- [ ] **E2E-4 Double-tap keluar:** di /dashboard → back → toast "Tekan sekali lagi untuk keluar"; back lagi ≤2 detik → app keluar; kalau tunggu >2 detik lalu back → toast lagi (tidak keluar).
- [ ] **E2E-5 Regresi:** buka/tutup modal via tombol X biasa tetap normal; navigasi antar halaman via menu normal; buka app sebagai PWA di browser → tombol back browser berperilaku seperti biasa (handler APK tak aktif).

---

## Self-Review

**Spec coverage:**
- Hierarki tutup-overlay → dashboard → double-tap → Task 1 Step 2 ✓
- Heuristik overlay (class-pattern + visible + penanda terbuka) + anti salah-tutup → `isOpenOverlay` ✓
- Tutup via `.modal-close`/`[data-close]`/`[onclick*=close]` lalu fallback balik-pola → `closeTopOverlay` ✓
- Pilih overlay teratas (z-index, else DOM terakhir) → sort + `cands[last]` ✓
- APK-only guard dipertahankan → guard `if (!App…) return` ✓
- Toast opsional (fallback tetap arm exit) → `typeof showToast` guard ✓
- `exitApp` fallback `minimizeApp` → try/catch bersarang ✓
- Home /dashboard, tak pakai history.back → Step 2 + Step 4 verifikasi ✓
- Testing: php -l + grep + Manual E2E → Steps 3-4 + E2E ✓

**Placeholder scan:** Tidak ada TBD/TODO. Semua langkah berisi kode/lengkap perintah.

**Type consistency:** `closeTopOverlay()`→bool, `isOpenOverlay(el)`→bool, `isVisible(el)`→bool, `backExitArmed` bool state — konsisten dalam satu IIFE. Nama fungsi dipakai sama di Step 2 & verifikasi Step 4.

**Catatan eksekusi:** Kerjakan di branch baru dari `main` (`feat/smart-back`). Satu task; E2E hardware-back milik user (butuh device + APK). PWA tak berubah.
