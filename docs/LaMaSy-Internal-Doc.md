# LaMaSy — Dokumentasi Internal (Lengkap)

> **Audiens:** Tim internal — Produk, Engineering, Operasional. **Tanggal:** 2026-06-30.
> **Status:** Live di produksi — https://lamasy.harpy.id
> **Sumber kebenaran:** kode di repo + spec/plan di `docs/superpowers/`. Dokumen ini ringkasan kerja; saat ragu, ikuti kode.

---

## Daftar Isi
1. Gambaran & Prinsip Desain
2. Multi-Tenancy (cara kerja isolasi data)
3. Peran & Hak Akses (RBAC)
4. Model Data (per domain)
5. Modul Operasional Outlet (detail)
6. Modul HQ
7. Control-Plane SaaS
8. Suite AI & Tata Kelola Coin
9. Alur End-to-End (workflows)
10. Notifikasi
11. Mobile, PWA & Offline
12. Keamanan
13. Deployment & Operasional
14. Proses & Konvensi Pengembangan
15. Status Modul & Backlog
16. Glosarium

---

## 1. Gambaran & Prinsip Desain

LaMaSy adalah **SaaS manajemen bisnis laundry** end-to-end, multi-tenant. Satu pemilik bisnis = satu **tenant**, punya banyak **outlet**. Data setiap tenant terisolasi; pemilik melihat konsolidasi seluruh outletnya lewat tampilan **HQ**. Operator LaMaSy mengelola seluruh tenant lewat **control-plane** (super admin).

**Prinsip desain:**
- **Server-authoritative.** Nomor order, saldo poin/deposit, harga, gating coin — semua diputus server, bukan klien. Klien hanya UI.
- **Tenant+outlet scoping di setiap query** (tak ada pengecualian).
- **Ringan & tahan jaringan lemah.** Server-rendered + vanilla JS, PWA, mode offline. Realita lapangan Indonesia.
- **Opt-in per tenant** untuk fitur besar (pola `loyalty_enabled`, `referral_enabled`) — owner memutuskan pakai/tidak.
- **Best-effort untuk efek samping non-kritis** (referral, push, anomaly) — tak pernah menggagalkan transaksi inti.

**Tech ringkas:** PHP 8, MariaDB (~125 tabel), tanpa framework berat (core class di `core/`), vanilla JS + PWA, app Android via Capacitor, AI Anthropic Claude, pembayaran Midtrans. Auto-deploy `git push` → Hostinger.

## 2. Multi-Tenancy (Isolasi Data)

- **Shared-DB, shared-schema.** Semua tenant berbagi satu database; pemisahan via kolom `tenant_id` (+ `outlet_id` untuk data per-cabang) di setiap tabel operasional (`hl_*`).
- **`core/TenantResolver.php`** — menentukan tenant + outlet aktif dari sesi login (bukan dari input request). Sumber identitas tunggal.
- **`core/TenantQuery.php`** — helper query yang otomatis menyuntik scope `tenant_id`/`outlet_id` (mis. `insert`, `update`, `delete`, `raw`, `rawOne`).
- **`core/TenantProvisioner.php`** — provisioning tenant baru: buat record, seed master (layanan, bahan default, role, dll).
- **HQ** = query lintas-outlet dalam satu tenant (agregasi), tetap di-scope `tenant_id`.
- **Pindah outlet:** `select-outlet.php` / `switch-outlet.php` mengubah outlet aktif di sesi.
- **Aturan keras developer:** jangan pernah percaya `tenant_id`/`outlet_id` dari request; ambil dari `TenantResolver`.

## 3. Peran & Hak Akses (RBAC)

- **Berbasis permission**, role kustom per tenant. Tabel: `hl_roles`, `hl_permissions`, `hl_role_permissions`, `hl_users`, `hl_karyawan_outlet`.
- Pengecekan: `requirePermission('x.y')` (blok halaman) + `hasPermission('x.y')` (cek aksi). Owner-level via `TenantResolver::isOwnerLevel()`.
- Role bawaan a.l.: owner, admin/operasional, kurir (mobile terbatas). Owner bisa bikin role + assign permission + memilih event push per-role (`hl_role_push_event`).
- **Super admin** punya RBAC terpisah: `sa_roles`, `sa_permissions`, `sa_role_permissions`, `super_admins` + 2FA (`saas_sa_2fa_codes`).

## 4. Model Data (per Domain)

~89 tabel operasional (prefix `hl_`) + ~37 tabel platform/SaaS. Dikelompokkan:

### Inti transaksi & pelanggan
`hl_transaksi`, `hl_transaksi_item`, `hl_order_notes`, `hl_pelanggan`, `hl_pelanggan_member`, `hl_layanan`, `hl_layanan_master`, `hl_express_tier`, `hl_parfum`, `hl_payment_methods`, `hl_struk_template`.

### Produksi & antar-jemput
`hl_proses_input`, `hl_proses_log`, `hl_checklist_template`, `hl_checklist_submission`, `hl_antar_jemput`, `hl_zona_antar`, `hl_kurir`, `hl_drop_points`.

### Mesin
`hl_mesin`, `hl_mesin_cycle`, `hl_mesin_sesi`.

### Loyalty, deposit & promo
`hl_loyalty_log`, `hl_poin_reward`, `hl_poin_reward_outlet`, `hl_member_tier`, `hl_deposit_topup`, `hl_deposit_usage`, `hl_deposit_bonus_tier`, `hl_deposit_refund`, `hl_voucher`, `hl_promo`, `hl_promo_outlets`, `hl_referral`, `hl_leads`.

### Inventori & aset
`hl_bahan`, `hl_bahan_mutasi`, `hl_bahan_stok` (view), `hl_opname`, `hl_opname_item`, `hl_po`, `hl_po_item`, `hl_supplier`, `hl_aset_tetap`.

### Keuangan & akuntansi
`hl_kas`, `hl_kas_bank`, `hl_kas_bank_mutasi`, `hl_piutang`, `hl_liabilitas`, `hl_coa` (chart of accounts), `hl_jurnal_manual`, `hl_laporan_cache`, `hl_investor`, `hl_bagi_hasil`, `hl_komisi_rekap`.

### SDM & penggajian
`hl_users`, `hl_karyawan_outlet`, `hl_absensi`, `hl_izin`, `hl_shift`, `hl_jadwal_shift`, `hl_shift_handover`, `hl_gaji`, `hl_gaji_komponen`, `hl_bonus_rule`, `hl_bonus_rule_outlet`.

### Sistem & AI
`hl_users`, `hl_roles`/`hl_permissions`/`hl_role_permissions`, `hl_role_push_event`, `hl_device_token`, `hl_audit_log`, `hl_notif_log`, `hl_rate_limits`, `hl_login_attempts`, `hl_ai_cache`, `hl_ai_usage`, `hl_ai_outreach_log`, `hl_broadcast`/`hl_broadcast_recipient`, `hl_splash_seen`/`hl_splash_tips`, `hl_delete_request`, `hl_affiliate`/`hl_affiliate_referral`/`hl_affiliate_payout`, `hl_migration_jobs`/`hl_migration_mapping_templates`.

### Platform / SaaS (tanpa prefix hl_)
`tenants`, `outlets`, `tenant_notes`, `super_admins`, `sa_*` (roles/permissions/2FA), `saas_packages`, `saas_billing_config`, `saas_payments`, `saas_manual_payments`, `saas_coin_pricing(_history)`, `saas_coin_bundles`, `coin_ledger`, `saas_platform_health`, `saas_platform_config`, `saas_email_templates`, `saas_banners`, `saas_announcements(_reads)`, `saas_demo_sessions`, `saas_tos_versions`, `saas_wa_log`, `saas_error_log`, `saas_impersonation_log`, `superadmin_logs`, `support_tickets(_replies)`, `registration_requests`/`registration_attempts`, `email_verifications`, `onboarding_progress`, `payments`.

> Catatan kapasitas: `hl_transaksi` membawa kolom status ganda — `status_proses` (alur produksi: masuk→cuci→kering→setrika→siap) dan `status_bayar` (belum_bayar/dp/lunas), plus `offline_ref`/`offline_uuid` (order offline), `express_tier_nama`/`biaya_express`. Item di `hl_transaksi_item` (kolom: `jumlah`, `harga_satuan`, `nama_layanan`, `satuan`, `express_tier_nama`, `biaya_express`).

## 5. Modul Operasional Outlet (Detail)

### 5.1 POS — `pos.php`
- Pilih layanan (dari katalog ter-cache) + tier express, pelanggan (cari/baru), metode bayar (tunai/transfer/QRIS), diskon, voucher, redeem poin, potong deposit.
- **Voice order:** rekam suara → `api/voice_order_parse.php` → `core/VoiceOrderParser.php` (Claude) → form terisi.
- **Offline:** `assets/offline-pos.js` (IndexedDB: katalog cache + antrian order) → endpoint `pos.php?action=catalog_snapshot` & `sync_offline` (idempoten via `offline_uuid`), `core/OrderCreator.php::createOffline`. Struk offline pakai kode sementara `OFF-<dev>-<seq>`; saat sync dapat nomor asli + alias.
- Kontrol online-only (deposit/redeem/voucher) auto-disable saat offline.

### 5.2 Orders & Kanban — `orders.php`, `kanban.php`
- Daftar order + filter; papan produksi (drag status). Aksi pelunasan: `bayar` (per-order) & `bulk_pay` (massal) → set `status_bayar='lunas'`, catat kas, tombol WA pelanggan.
- **Hook efek:** earn poin saat `status_proses='siap'`; payout referral saat order pertama teman `lunas`.

### 5.3 Produksi — `produksi.php`
- Catat tahap proses per order (`hl_proses_input`/`hl_proses_log`), upload foto bukti, tanda tangan kanvas, scanner QR (html5-qrcode lokal). Checklist wajib-foto per item (`core/Checklist.php`, `hl_checklist_*`).

### 5.4 Antar-Jemput — `antar-jemput.php`, `kurir.php`, `kurir-master.php`
- Dispatcher buat tugas + assign kurir per zona (`hl_zona_antar`), mode antar per-outlet. App kurir mobile: daftar tugas, langkah status, modal selesai (foto + ttd). Integrasi POS/produksi (auto-create antar).

### 5.5 Mesin — `mesin.php`, `self.php`
- Monitor mesin cuci/kering + sesi (`hl_mesin_*`), booking publik pelanggan via kode mesin (`/self?m=KODE`).

### 5.6 Kas & Keuangan Outlet — `kas.php`, `deposit.php`, `piutang.php`, `pembelian.php`, `inventori.php`
- Kas masuk/keluar + kategori; **scan struk AI** (`api/kas_struk_scan.php` → `core/ReceiptScanner.php`) → isi form + bukti; anomaly detection (`core/AnomalyDetector.php`).
- Deposit pelanggan (`core/DepositManager.php`): topup, pakai, bonus tier, refund.
- Piutang B2B; pembelian/PO (PDF, `api/inventori_po.php`); inventori (stok, mutasi, opname, alert kritis, auto-beban ke `FinancialCalculator`).

### 5.7 SDM & Absensi — `karyawan.php`, `absensi.php`
- Karyawan + role; absensi clock-in **selfie wajib + geofence ketat** (config per-outlet, peta Leaflet), jadwal shift (`core/ShiftCalc.php`: telat/lembur), izin, handover shift.

### 5.8 Checklist, Audit, Settings, Support
- Checklist operasional (`checklist.php`), audit log (`audit.php`), pengaturan outlet (`outlet-settings.php`, `settings.php`, `payment-settings.php`), support (`support.php`), laporan owner (`owner_report.php`, pengaturan notif per-channel).

## 6. Modul HQ (`hq/`)

Konsolidasi lintas-outlet untuk owner/manajer jaringan:
- **Dashboard** konsolidasi, **Laporan** (`hq/laporan.php`) & **Keuangan** (`hq/keuangan.php`, jurnal/COA).
- **Master katalog** (`hq/layanan.php`), **Inventori** konsolidasi + transfer antar-outlet (`hq/inventori.php`, `hq/mutasi.php`), **Supplier**.
- **Mesin**, **Pelanggan**, **Loyalty** (`hq/loyalty.php`: reward + referral settings), **Promo**.
- **SDM** (`hq/sdm.php`, `hq/karyawan.php`, `hq/roles.php`), **Penggajian** (`hq/penggajian.php`: gaji + bonus + slip + bagi-hasil).
- **Broadcast** (pengumuman massal), **Drop-point** (`hq/droppoint.php`), **AI chat & churn** (`hq/ai-chat.php`, `hq/ai-churning.php`), **Coin info**, **Investor** (`hq/investor.php`), **Export**, **Billing** (`hq/billing.php`), **Settings**, **Support**.

## 7. Control-Plane SaaS (`superadmin/`)

Operator LaMaSy:
- **Clients/Tenant:** daftar, detail (`client_detail.php`), onboarding wizard, suspend, notes.
- **Billing & Paket:** `packages.php`, `billing-config.php`, `payments.php`, `coin_pricing.php` (+ history), coin bundles; gateway `core/MidtransClient.php`, settler `core/PaymentSettler.php`, `core/BillingConfig.php`.
- **Platform:** `health.php` (+ `core/PlatformHealthRecorder.php`), `ai_usage.php`, error log, audit (`superadmin_logs`).
- **Pertumbuhan/retensi:** `churn_risk.php`, `affiliates.php` (`core/AffiliateAuth.php`), `announcements.php`, `banners.php`, `broadcast.php`.
- **Operasional:** `registrations.php`/`registration_wizard.php`/`onboarding.php`, `impersonate.php` (+ log) untuk support, `support.php`, **2FA** (`core/Sa2FA.php`, `login-2fa.php`), `migrations.php`.

## 8. Suite AI & Tata Kelola Coin

- **Klien:** `core/AnthropicClient.php` (panggil Claude, parse JSON terstruktur).
- **Fitur:** Voice order (`VoiceOrderParser`), scan struk (`ReceiptScanner`), insight & chat data (`AIInsight`, `AIChatData`, `hq/ai-chat.php`), deteksi churn + outreach (`AIChurnDetector`, `hl_ai_outreach_log`, `hq/ai-churning.php`), migrasi data sistem lama (`AIMigrationMapper`, `hl_migration_*`), persona (`AIPersona`).
- **Tata kelola (monetisasi):** pemakaian AI dibayar **coin** (`core/CoinLedger.php`, `coin_ledger`, `saas_coin_pricing`, `saas_coin_bundles`); rate-limit per fitur (`core/AIRateLimiter.php`, `hl_rate_limits`); budget per tenant (`core/AIBudget.php`); cache (`hl_ai_cache`); pemakaian (`hl_ai_usage`). Super admin atur harga coin per fitur + limit/hari.

## 9. Alur End-to-End (Workflows)

**Order → Selesai → Bayar:**
1. POS buat order (`hl_transaksi` + items) → `status_proses=masuk`, `status_bayar` sesuai pembayaran.
2. Produksi geser status di kanban → saat `status_proses=siap`: **earn poin** pelanggan + notif WA "siap diambil".
3. Pelunasan (`bayar`/`bulk_pay`) → `status_bayar=lunas`, catat kas; jika pelanggan ini referee referral & ini order pertama lunas → **payout referral** (poin pengajak + teman, idempoten).

**Order offline:** offline → antri lokal (kode sementara) → online → `sync_offline` (idempoten) → nomor asli + alias `offline_ref` (tetap bisa dilacak).

**Antar-jemput:** POS/produksi auto-create tugas → dispatcher assign kurir → kurir update status → selesai (foto+ttd).

**Penggajian:** absensi + shift (`ShiftCalc`) → komponen gaji + evaluasi bonus (`BonusEvaluator`) → slip PDF (`api/payslip.php`) + bagi-hasil.

**AI:** request fitur AI → cek rate-limit + budget + saldo coin → panggil Claude (cache bila bisa) → potong coin (`CoinLedger`) → catat `hl_ai_usage`.

**Billing SaaS:** tenant pilih paket → Midtrans → webhook `api/midtrans-webhook.php` → `PaymentSettler` aktifkan langganan / top-up coin.

## 10. Notifikasi

- **Push FCM** (`core/PushSender.php`, `api/push_register.php`, `hl_device_token`) — event per-role (`hl_role_push_event`).
- **Email otomatis** (`core/DailyReport.php`, `core/Mailer.php`, `core/Notifier.php`) — laporan harian + alert anomali; channel diatur per-kategori owner (`core/NotifPrefs.php`, `tenants.notif_settings`). Dijalankan pseudo-cron via `tenant_guard`.
- **WhatsApp** via link (status/struk), log `hl_notif_log`/`saas_wa_log`. **Broadcast** massal (`hq/broadcast.php`).

## 11. Mobile, PWA & Offline

- **App Android:** Capacitor (thin-shell) memuat webview remote ke `lamasy.harpy.id` → satu basis kode web. Native: push FCM, STT (voice), izin RECORD_AUDIO. versionCode auto-increment per build.
- **PWA:** service worker `sw-tenant.js` (operasional, scope `/`) + `sw.js` (portal pelanggan). Cache read-pages + fallback offline.
- **Offline POS:** IndexedDB (`assets/offline-pos.js`) — katalog cache + antrian order + sync runner idempoten; UI banner + indikator pending.

## 12. Keamanan

- **Multi-tenant isolation** (lihat §2) — scope di setiap query.
- **RBAC** per tenant (§3); super admin RBAC + **2FA**.
- **CSRF** menyeluruh: interceptor `fetch` global (di `components.php`) menambah `X-CSRF-Token` ke semua POST same-origin + `verifyCsrf()` di endpoint tulis.
- **Auth:** verifikasi email (`email_verifications`), rate-limit login (`hl_login_attempts`, `core/RateLimiter.php`), portal pelanggan via token.
- **Audit:** `hl_audit_log` (tenant), `superadmin_logs`/`saas_impersonation_log` (platform). Error terpusat (`core/ErrorLogger.php`, `saas_error_log`).
- **AI governance:** rate-limit + budget + coin mencegah penyalahgunaan.
- **Privasi/compliance:** TOS/privacy (`tos.php`, `privacy.php`, `saas_tos_versions`, `accept-tos.php`), permintaan hapus data (`hl_delete_request`, `core/DeleteRequest.php`).
- **Kredensial:** kredensial DB master tak pernah masuk repo; service-account (FCM) di luar webroot & tak di-commit.

## 13. Deployment & Operasional

- **Hosting:** Hostinger, domain `lamasy.harpy.id`. **Deploy:** `git push origin main` → auto-deploy (deploy key SSH, write-enabled).
- **DB:** MariaDB; akses CLI via mysql client + `~/.my.cnf` (PROD). Migrasi = file SQL di `migrations/`, dijalankan langsung.
- **Build APK:** `~/Documents/lamasy-app/build-apk.sh` (auto-increment versionCode → Desktop). Icon adaptive teal.
- **Monitoring:** health recorder + AI usage + error log di super admin.

## 14. Proses & Konvensi Pengembangan

- **Alur:** brainstorming (→ spec di `docs/superpowers/specs/`) → writing-plans (→ plan) → **subagent-driven-development** (implementer per-task + review berlapis + final whole-branch review). Ledger di `.superpowers/sdd/`.
- **Test:** skrip CLI standalone di `tests/` pakai `tests/_assert.php` (`ok()`, `eqv()`), dijalankan `php tests/...` (SQLite/array fixture atau DB+rollback). Tidak pakai PHPUnit.
- **Aturan keras:**
  - Verifikasi nama kolom REAL (SHOW COLUMNS / baca kode) sebelum nulis query.
  - Scope `tenant_id`+`outlet_id` wajib.
  - Fitur besar = opt-in per tenant.
  - HQ pages: stub `requirePermission`/`logAudit` bila perlu.
  - Sesi paralel di repo → `git pull` sebelum push, commit hanya file sendiri.
  - Efek samping non-kritis = best-effort (try/catch), jangan gagalkan transaksi inti.

## 15. Status Modul & Backlog

**Live:** POS (+voice+offline), Produksi, Antar-Jemput, Inventori, Mesin, Pelanggan/Loyalty/Referral/Deposit, Absensi/Shift/Penggajian/Bonus, Kas/Laporan/Piutang/Keuangan HQ, Portal Pelanggan, Suite AI, Notifikasi (push+email+WA), Billing SaaS + Super Admin, App Android.

**Backlog (indikatif):**
- Pengayaan loyalty: tier otomatis dari belanja, poin kedaluwarsa, bonus ulang tahun.
- Notif master off-switch (spec sudah ada).
- Checklist wajib-foto: tampil thumbnail di HQ compliance.
- Pembayaran: QRIS in-app + WhatsApp Business API resmi.
- Pendalaman aplikasi native + rilis Play Store (kini thin-shell, hold sampai PWA feature-complete).
- **E2E manual** fitur baru: offline POS (airplane mode), voice order, checklist wajib-foto, referral.
- Minor teknis terdokumentasi di ledger SDD (mis. observability log di beberapa best-effort path).

## 16. Glosarium

| Istilah | Arti |
|---|---|
| Tenant | Pelanggan SaaS (pemilik bisnis laundry) |
| Outlet | Cabang fisik milik tenant |
| HQ | Tampilan konsolidasi pemilik atas seluruh outlet |
| Control-plane | Panel super admin (operator LaMaSy) |
| Coin | Unit pemakaian fitur AI yang dibeli tenant |
| Tier Express | Level kecepatan layanan (reguler/kilat) + biaya tambahan |
| status_proses | Status produksi order (masuk→cuci→kering→setrika→siap) |
| status_bayar | Status pembayaran order (belum_bayar/dp/lunas) |
| SAK EMKM | Standar akuntansi UMKM Indonesia (laporan laba/rugi) |
| offline_ref | Kode order sementara saat dibuat offline (alias permanen setelah sync) |

---

*Dokumen hidup — perbarui saat modul/skema berubah.*
