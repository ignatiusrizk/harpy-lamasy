# Laporan Deep QA — LAMASY

**Tanggal:** 2026-07-03 (WIB)
**Cakupan:** Outlet (A1–A17), HQ (B1–B9), SuperAdmin (C), Publik/API (D), lintas-sistem + audit log
**Metode:** tenant test terisolasi (`DeepQA`, id 17) via register → verifikasi → login; QA fungsional pakai curl dengan cookie jar sendiri + verifikasi DB langsung (PDO ke DB produksi). Browser gstack tidak dipakai karena di-share sesi paralel. Static: `php -l`, sweep guard/CSRF/scope/no-store.

---

## 1. Ringkasan Eksekutif

- **252 file PHP** lolos lint, seluruh halaman outlet ada guard, isolasi tenant & portal bersih (tak ada IDOR / kebocoran antar-tenant/antar-customer).
- Jalur uang & log inti terverifikasi benar: POS, Kas, Piutang (coin idempoten), Loyalty (earn), Midtrans webhook (signature + idempoten + anti-tamper).
- **5 perbaikan** dikerjakan, ter-deploy, dan diverifikasi E2E.
- **2 temuan minor** tersisa sebagai catatan (bukan bug fungsional).
- Data test dibersihkan tuntas (0 sisa).

---

## 2. Temuan & Status

| # | Severitas | Lokasi | Deskripsi | Status |
|---|-----------|--------|-----------|--------|
| 1 | Medium | `hq/checklist.php` | Handler POST `save`/`delete` hanya cek permission, tak panggil `verifyCsrf()` → CSRF hole | ✅ Fixed & verified |
| 2 | Medium | `middleware/hq_guard.php` | `logAudit()` = no-op senyap → **semua aksi HQ tak punya jejak audit** walau kode memanggilnya dengan pesan detail | ✅ Fixed (infra) & verified |
| 2b | Medium | `hq/supplier.php`, `hq/promo.php`, `hq/pelanggan.php`, `hq/struk.php` | Handler tulis tak pernah memanggil `logAudit` sama sekali → aksi tak terekam | ✅ Fixed & verified |
| 3 | Low | `select-outlet.php` | POST ganti outlet tanpa proteksi CSRF (validasi kepemilikan sudah ada, jadi risiko kecil) | ✅ Fixed & verified |
| 4 | Minor | `pos/orders/kanban/kas/inventori/produksi/antar-jemput/mesin` | Tak ada `no-store` header → potensi cache basi di WebView APK bila shell PHP berubah | ✅ Fixed & verified |
| 5 | Low-Med | `superadmin/banners.php` | Handler `save`/`delete` tak panggil `logSuperAdminAction` → perubahan banner (tampil ke semua tenant) tak terekam | ✅ Fixed (verify E2E menunggu login SA) |

### Catatan verifikasi audit HQ (bukan bug)
- `hq/roles.php` — teraudit via `logRoleAction()` → tabel `superadmin_logs`.
- `hq/karyawan.php` — teraudit via helper lokal → `hl_audit_log`.
- `hq/outlet.php` — teraudit via `superadmin_logs`.
- `hq/investor.php` — read-only (tidak ada aksi tulis).

---

## 3. Perbaikan yang Dikerjakan (commit di `main`)

1. **CSRF `hq/checklist.php`** — tambah `verifyCsrf()` di `save` & `delete`. Frontend sudah kirim token via global fetch wrapper HQ, jadi tak break. Diverifikasi: token salah → ditolak, token benar → jalan.
2. **Audit HQ infra** — `hq_guard.logAudit()` ditulis ulang: insert ke `hl_audit_log` dengan scope tenant + `outlet_id NULL` + tangkap `ref_id` (param ke-4 yang dipanggil HQ pages). Diverifikasi (audit id tercatat).
3. **Audit 4 halaman HQ** — tambah `logAudit()` di titik sukses handler tulis `supplier`/`promo`/`pelanggan`/`struk`. Diverifikasi (supplier tercatat).
4. **CSRF `select-outlet.php`** — token disimpan di `$_SESSION['csrf_token']` (konsisten dengan tenant_guard), hidden field di form, verify sebelum ubah session outlet.
5. **no-store header** — `if ($action==='') { Cache-Control: no-store...; Pragma: no-cache; }` di 8 halaman outlet. Dikonfirmasi header live di `/pos`. (Catatan: no-store kemudian dijadikan global di `tenant_guard.php` oleh sesi lain.)
6. **Audit SA `banners.php`** — tambah `logSuperAdminAction()` di save/delete (perubahan banner ke semua tenant sebelumnya tak terekam).
7. **Badge approval-inbox** — SSR count ikutkan `DepositManager::pendingRefundCount` (samakan dgn API & isi list) + no-store.

### 3b. Perbaikan UI/UX (lanjutan)
- **Piutang B2B**: kartu metrik mobile stack 1-kolom (label kiri/angka kanan), filter jadi 1-baris scroll, modal select+date → custom.
- **Layanan**: harga auto-separator ribuan, dropdown Satuan/Status custom.
- **Banner dashboard**: swipe manual ikut jari (sliding track translateX + snap), auto-geser pause saat disentuh.
- **Side menu**: default grup TERBUKA lagi (bukan tertutup); label panjang WRAP ke bawah (bukan ellipsis); collapse manual diingat.
- **Approval Inbox**: tab overflow (grid 3-kolom → flex konten + scroll) + matikan tap-highlight WebView.
- **Absensi**: tab bar overflow (`.hl-tab{flex:1}` → flex konten + scroll).
- **Empty-state tabel**: `td[colspan]` di `hl-stack-mobile` dipaksa block+center (global — semua tabel).
- **Audit Log**: kartu "Modul Tersibuk" font normal+kapital; filter (2 select + 2 date) → custom + lebar seragam di mobile.
- **Drawer** (outlet + HQ): header tak lagi kepotong status bar APK (`env(safe-area-inset-top)`).

---

## 4. Cakupan Fungsional — LULUS

### Outlet
- **Auth & Onboarding:** register 3-step → email verify (token) → login → dashboard.
- **Add-outlet (trial):** outlet `trial`, 10.000 trial coin ke outlet, welcome-kit benar di-skip (bukan berbayar), cascade wilayah + auto kode pos.
- **Layanan:** CRUD, harga `8000.00`→`8.000` (grpRibu), auto-separator saat ketik, dropdown Satuan/Status custom, audit `create layanan`.
- **POS:** order multi-item; matematika benar (subtotal − diskon = total; total − DP = sisa); pelanggan baru auto-upsert; **DP → kas masuk otomatis**; audit `create orders`.
- **Orders:** update status-only **tidak** memicu log palsu "Layanan diupdate" (regresi tetap aman); hanya `hl_proses_log` "Status diubah".
- **Kas:** pengeluaran manual + audit.
- **Piutang B2B:** generate; **`mark_invoiced` idempoten** (200 coin sekali, panggil 2× tak dobel); invoice `INV/YYYY/MM/NNNN`; bayar sebagian → kas masuk "Pembayaran Piutang".
- **Loyalty:** config + reward CRUD; **earn poin dipicu saat status→`siap`** (bukan saat order — by design); `hl_loyalty_log` "earn".
- **Inventori:** mutasi masuk → stok naik + `hl_bahan_mutasi` + audit.
- **Karyawan:** create staff + akun.
- **Mesin:** create master.
- **Antar-Jemput:** dispatcher create (audit `antar_create`).

### HQ
- Dashboard konsolidasi; **isolasi tenant benar** (hanya outlet tenant sendiri, tak ada cross-tenant/IDOR di `hq/outlet.php?action=list`).
- Master layanan create; role create.
- **CSRF HQ aktif** (token `hl_csrf` terpisah; token salah → "CSRF mismatch").

### Publik / API
- **cek.php:** verify 4-digit HP — benar → tampil order, salah → "tidak cocok" (bug lama "selalu tidak cocok" tetap teratasi).
- **api/midtrans-webhook.php:** wajib verify signature (tolak invalid), **idempoten** (`saas_payments` status → "Already paid" tanpa kredit ulang), anti-tamper amount.
- **Portal customer** (`pelanggan-order.php`): query `WHERE no_order=? AND pelanggan_id=?` — order wajib milik akun portal (tak ada kebocoran antar-customer).

### Log terverifikasi muncul & sesuai
`hl_audit_log` (login, order, kas, inventori, karyawan, loyalty, + HQ pasca-fix), `hl_proses_log` (status tanpa log palsu), `hl_kas` (DP + pembayaran piutang auto), `coin_ledger` (deduct invoice, no dobel), `hl_loyalty_log` (earn).

---

## 4b. SuperAdmin — Static, Keamanan & Fungsional (LULUS)

**Static & keamanan:**
- **Guard:** 31 halaman SA semua ada proteksi session/`sa_guard` (0 gap).
- **CSRF:** semua handler POST SA pakai `saVerifyCsrf()` (0 gap; token salah → "CSRF invalid" — diverifikasi E2E).
- **HTTP Basic Auth (Layer 0):** konsol SA dilindungi Basic Auth level-app (`SA_BASIC_USER`/`SA_BASIC_PASS`) sebagai faktor kedua sebelum login; balas "Not Found" agar path tak terendus scanner. Login app + 2FA di atasnya = defense in depth solid.
- **Impersonate:** permission gate `clients.impersonate` + CSRF + POST-only + cegah nesting + log `saas_impersonation_log` (code-verified; live-test sengaja tak dijalankan — guardrail memblokir impersonate tenant nyata).
- **Jalur uang** (payments/coin_pricing/packages/billing-config/affiliates): permission + CSRF + audit `logSuperAdminAction()`. `billing.php` read-only.

**Fungsional (via akun SA dummy owner, sudah dihapus):**
- Login (Basic + app) → dashboard render ✓; clients list (9 tenant) ✓.
- **Topup coin** tenant → saldo naik + `coin_ledger` + `superadmin_logs` "topup_coin" ✓ (di-revert setelah tes).
- **Banners save/delete** → `superadmin_logs` "banner_create"/"banner_delete" ✓ — **konfirmasi E2E fix #5** (sebelumnya tak terekam).
- **De-native lmx + tema gelap** ter-include & tampil di halaman SA ✓; viewport-fit=cover + safe-area aktif.
- Cleanup: akun dummy + log/ledger uji dihapus, coin tenant di-revert (hanya SA asli tersisa).

## 4c. Sweep UI/UX Mobile Final (static, 11 halaman)

Halaman yang belum sempat dilihat via screenshot: customer, member, deposit, pembelian, mesin, produksi, kurir, outlet-settings, payment-settings, droppoint_manager, kurir-master.

Dicek untuk tiap vektor defect mobile yang ditemukan sepanjang sesi — **semua CLEAN, tak ada temuan**:
- Tabel overflow → tidak ada tabel; semua daftar berbasis kartu JS.
- Tab bar equal-width (biang overflow di absensi/approval) → tidak ada.
- `<select>`/`<input type=date>` native → ditangani global **lmx** otomatis.
- `input type=time` (tidak dicakup lmx) → tidak ada di 11 halaman ini.
- Grid kolom fixed / `repeat(N)` → tidak ada; pakai `hl-stat-grid` global responsif.
- Emoji-dobel di label/tombol (kasus Approval Inbox) → tidak ada.
- `width:###px` fixed / `white-space:nowrap` di container → tidak ada.

Kesimpulan: 11 halaman ini konsisten memakai sistem responsif global (lmx + kartu + hl-stat-grid) → **tidak ada perbaikan yang diperlukan**.

## 5. Belum Diuji (butuh input / manual)

| Area | Alasan |
|------|--------|
| **SuperAdmin fungsional (C1–C5)** | Butuh login SA; pembuatan akun SA otomatis diblok guardrail (grant privilege owner-level di produksi). Menunggu kredensial / akun dummy dari user |
| Thermal print, scan kamera, GPS clock-in, swipe banner | Butuh perangkat fisik (manual di HP) |
| Referral E2E, notif email/push, checklist submission wajib-foto | Butuh setup kompleks (2 pelanggan+kode / cron / foto device) |
| Offline order (airplane mode) E2E | Butuh device fisik |

### Backlog parkir (bukan blocker — catatan sesi lain)
- **Voice Order Task 5** — plugin STT butuh build APK baru (server-side sudah live & aman).
- **lmx** belum menyentuh halaman SuperAdmin (desktop, tak pakai renderHead) & `input type=time` (absensi) — **sengaja**.
- **QRIS + WA gateway** masih di-hold (keputusan produk).

---

## 6. Housekeeping

- Tenant test **DeepQA (id 17)** dihapus tuntas: 99 baris dari ~90 tabel ber-`tenant_id` + tenant row + `email_verifications`. Diverifikasi **0 sisa**.
- Tenant produksi (1) & seed QA (2–10) tak tersentuh.
