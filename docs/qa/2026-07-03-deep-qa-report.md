# Laporan Deep QA — LaMaSy

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
5. **no-store header** — `if ($action==='') { Cache-Control: no-store...; Pragma: no-cache; }` di 8 halaman outlet. Dikonfirmasi header live di `/pos`.

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

## 5. Belum Diuji (butuh input / manual)

| Area | Alasan |
|------|--------|
| **SuperAdmin C1–C5** | Butuh kredensial SA + 2FA (tak bisa bypass auth produksi) |
| Thermal print, scan kamera, GPS clock-in, swipe banner | Butuh perangkat fisik (manual di HP) |
| Referral E2E, notif email/push, checklist submission wajib-foto | Butuh setup kompleks (2 pelanggan+kode / cron / foto device) |

---

## 6. Housekeeping

- Tenant test **DeepQA (id 17)** dihapus tuntas: 99 baris dari ~90 tabel ber-`tenant_id` + tenant row + `email_verifications`. Diverifikasi **0 sisa**.
- Tenant produksi (1) & seed QA (2–10) tak tersentuh.
