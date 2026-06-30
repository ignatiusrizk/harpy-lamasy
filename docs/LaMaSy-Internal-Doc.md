# LaMaSy — Dokumentasi Internal

> **Audiens:** Tim internal (produk, engineering, operasional). **Tanggal:** 2026-06-30.
> **Status:** Live di produksi — https://lamasy.harpy.id
> Dokumen referensi kerja. Sumber kebenaran teknis: kode di repo + spec/plan di `docs/superpowers/`.

---

## 1. Apa Itu LaMaSy

Platform **SaaS manajemen bisnis laundry** end-to-end, multi-tenant. Satu pemilik bisnis (tenant) mengelola banyak outlet; data terisolasi per tenant+outlet; pemilik melihat konsolidasi lewat tampilan **HQ**. Di atasnya ada **control-plane** (super admin) untuk operator LaMaSy.

Alur inti: terima order → produksi → antar-jemput → bayar → laporan; ditambah loyalty, penggajian, inventori, dan fitur AI.

## 2. Arsitektur 3 Lapisan

| Lapisan | Pengguna | Cakupan |
|---|---|---|
| Operasional Outlet | Kasir, staf produksi, kurir | POS, kanban produksi, antar-jemput, kas, absensi |
| HQ | Owner / manajer jaringan | Dashboard konsolidasi, laporan keuangan, master katalog, penggajian, loyalty, SDM, broadcast, AI insight |
| Control-Plane SaaS | Super admin (operator LaMaSy) | Tenant, billing & paket, harga coin AI, kesehatan platform, churn-risk, impersonate |

**Isolasi data:** setiap query di-scope `tenant_id` + `outlet_id` dari sesi server (`TenantResolver` / `TenantQuery`), bukan input klien.

## 3. Peta Fitur (per Modul)

### Penjualan & Order
- **POS** (`pos.php`): order layanan + tier express, pelanggan, bayar (tunai/transfer/QRIS), diskon, voucher, redeem poin, potong deposit. **Voice order** (AI parse) + **mode offline** (IndexedDB queue → sync idempoten).
- **Orders/Kanban** (`orders.php`, `kanban.php`): status produksi (masuk→cuci→kering→setrika→siap), pelunasan single (`bayar`) & bulk (`bulk_pay`), tombol WA.
- **Struk** (`struk.php`, `StrukGenerator`) + QR lacak. **Self-booking** (`self.php`) via QR.

### Produksi
- `produksi.php` — tahap proses per order, foto bukti, tanda tangan, scanner QR (`hl_proses_input`).

### Antar-Jemput
- `antar-jemput.php` (dispatcher + zona, mode antar per-outlet), `kurir.php` (app kurir: tugas, status, selesai+foto+ttd), `kurir-master.php`.

### Pelanggan, Loyalty & Referral
- Pelanggan/member (`customer.php`, `member.php`, `MemberTier`, `SegmentasiManager`).
- Loyalty poin (`Loyalty`): earn saat `status_proses='siap'` (kanban/orders); redeem di POS.
- Voucher/promo (`promo.php`), deposit (`DepositManager`).
- **Referral** (`Referral`, opt-in): kode/link → poin dua-duanya saat order pertama teman **LUNAS** (idempoten, cap, anti-abuse). Hook payout: `bayar` + `bulk_pay` + POS lahir-lunas.
- Portal pelanggan (`pelanggan.php`, PWA) via `portal_token`.

### Inventori & Pembelian
- `inventori.php` (stok bahan, mutasi, alert kritis, auto-beban ke `FinancialCalculator`), `pembelian.php` (PO PDF), supplier.

### Mesin
- `mesin.php` (monitor + sesi + booking publik via kode mesin).

### SDM, Absensi & Penggajian
- Karyawan + RBAC permission (`karyawan.php`, `hq/roles.php`).
- Absensi (`absensi.php`): selfie wajib + geofence ketat per-outlet, jadwal shift (`ShiftCalc`, telat/lembur).
- Penggajian (`hq/penggajian.php`): gaji + bonus (`BonusEvaluator`), slip PDF, bagi-hasil (`BagiHasilCalculator`).

### Keuangan & Laporan
- Kas (`kas.php`): masuk/keluar, **scan struk AI** (`ReceiptScanner`), anomaly (`AnomalyDetector`).
- Laporan (`laporan.php`): harian, bulanan, laba/rugi (SAK EMKM), produktivitas; export.
- Piutang B2B (`piutang.php`), Keuangan HQ (`hq/keuangan.php`).

### Suite AI (Anthropic Claude — `AnthropicClient`)
- Voice order (`VoiceOrderParser`), scan struk (`ReceiptScanner`), insight/chat (`AIInsight`, `AIChatData`, `hq/ai-chat.php`), churn (`AIChurnDetector`, `hq/ai-churning.php`), migrasi data (`AIMigrationMapper`).
- Tata kelola: coin metering (`CoinLedger`), rate-limit (`AIRateLimiter`), budget (`AIBudget`), persona (`AIPersona`).

### Notifikasi
- Push FCM (`PushSender`), email otomatis (`DailyReport`, `Notifier`, `NotifPrefs` per-channel), WA link, broadcast.

### Onboarding
- Landing/registrasi (`register.php`, verifikasi email), demo mode, splash/tips (`SplashManager`), TOS/privacy, billing checkout.

## 4. Control-Plane SaaS (`superadmin/`)
- Clients/tenant + onboarding wizard + suspend; billing/paket/coin pricing (`MidtransClient`, `BillingConfig`); health (`PlatformHealthRecorder`); churn-risk; affiliate (`AffiliateAuth`); impersonate; 2FA (`Sa2FA`); audit.

## 5. Stack & Konvensi Teknis
- **PHP 8 + MariaDB** (~125 tabel, shared-DB multi-tenant). Tanpa framework berat; core class di `core/`.
- **Frontend:** server-rendered PHP + vanilla JS; PWA (service worker: `sw-tenant.js` operasional, `sw.js` portal).
- **Mobile:** Capacitor thin-shell + remote webview → app Android. Push FCM, STT voice, offline (IndexedDB + sync).
- **Pembayaran:** Midtrans. **AI:** Anthropic Claude (metered coin).
- **Keamanan:** RBAC per tenant; CSRF menyeluruh (interceptor fetch global + `verifyCsrf`); audit log; verifikasi email; 2FA SA; `ErrorLogger`.
- **Deploy:** auto-deploy `git push origin main` → Hostinger (`lamasy.harpy.id`).
- **Proses dev:** brainstorm (spec) → writing-plans → subagent-driven (implementer + review berlapis + final review). Spec/plan tersimpan di `docs/superpowers/`. Test = skrip CLI (`tests/`).

## 6. Konvensi Penting (untuk Developer)
- **Verifikasi nama kolom REAL** dari kode/SHOW COLUMNS sebelum nulis query (skema bisa beda dari asumsi).
- Scoping `tenant_id`+`outlet_id` wajib di semua query.
- Fitur baru = opt-in per tenant bila relevan (pola `loyalty_enabled`, `referral_enabled`).
- HQ pages: stub `requirePermission`/`logAudit` bila perlu (`hq_guard.php`).
- Ada sesi paralel di repo → pull sebelum push, commit hanya file sendiri.

## 7. Status Modul (Live)
POS (+voice+offline) · Produksi · Antar-Jemput · Inventori · Mesin · Pelanggan/Loyalty/Referral · Absensi/Penggajian · Kas/Laporan/Piutang · Portal Pelanggan · Suite AI · Billing SaaS · Super Admin.

**Backlog/known (indikatif):** pengayaan loyalty (tier otomatis, poin expiry, bonus ultah); notif master off-switch; checklist thumbnail di HQ; QRIS + WA Business API; pendalaman native + rilis store; E2E manual beberapa fitur baru (offline, voice, checklist, referral).

## 8. Glosarium
- **Tenant**: pelanggan SaaS (pemilik laundry). **Outlet**: cabang fisik. **HQ**: konsolidasi pemilik. **Coin**: unit pemakaian AI. **Tier Express**: level kecepatan layanan. **SAK EMKM**: standar akuntansi UMKM.
