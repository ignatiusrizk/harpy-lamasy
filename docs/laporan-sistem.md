# LAMASY — Laporan Sistem Lengkap

**Tanggal:** 24 Juni 2026
**Versi:** Production (lamasy.harpy.id)
**Repo:** github.com/ignatiusrizk/harpy-lamasy

LAMASY adalah platform SaaS multi-tenant untuk operasional laundry, dirancang dari UMKM single-outlet sampai chain multi-outlet. Sistem dibagi tiga lapisan akses: **Outlet** (operasional harian), **HQ** (manajemen multi-outlet owner), dan **SuperAdmin** (admin platform LAMASY).

---

## 1. Arsitektur Global

### 1.1 Model multi-tenant

```
┌──────────────────────────────────────────────────────────┐
│              LAMASY PLATFORM (SaaS)                       │
│  Shared DB + Shared Schema + tenant_id+outlet_id scoping  │
├──────────────────────────────────────────────────────────┤
│                                                            │
│   ┌──────────────┐      ┌──────────────┐                  │
│   │  Tenant A    │      │  Tenant B    │   ...            │
│   │  (Bisnis 1)  │      │  (Bisnis 2)  │                  │
│   ├──────────────┤      ├──────────────┤                  │
│   │ Outlet A1    │      │ Outlet B1    │                  │
│   │ Outlet A2    │      │ Outlet B2    │                  │
│   │ Outlet A3    │      │              │                  │
│   └──────────────┘      └──────────────┘                  │
│                                                            │
└──────────────────────────────────────────────────────────┘
```

- **Tenant** = bisnis laundry (1 owner, N outlets)
- **Outlet** = lokasi fisik dengan operasional independen
- **103 tabel database** dengan kolom `tenant_id` (+ `outlet_id` untuk transaksional)
- Setiap query difilter via `TenantResolver` / `TenantQuery` helper

### 1.2 Tech stack

| Layer | Tech |
|-------|------|
| Backend | PHP 8 vanilla (no framework) |
| Database | MariaDB Hostinger shared host |
| Frontend | HTML/CSS/JS dengan design system Ponytail (dark navy `#0F1C3A` + teal `#35E8D5`) |
| Auth | Session + HMAC-SHA256 signed tokens, CSRF per request |
| AI | Anthropic Claude (claude-sonnet-4-x, claude-haiku-4-5) dengan prompt caching |
| Email | Hostinger SMTP via PHPMailer |
| WhatsApp | Fonnte API (1000 msg/bulan free tier) |
| PWA | manifest + service worker |
| Hosting | Auto-deploy via git push, ~15s deploy time |
| Currency | IDR, timezone Asia/Jakarta UTC+7 |

### 1.3 Folder structure

```
/                       — Outlet level pages (PHP)
  /core/                — Reusable libraries (30 file)
  /assets/              — Static + uploads
    /banners/           — Banner image uploads (SA)
  /api/                 — Internal AJAX endpoints
  /hq/                  — HQ-level pages (30 file)
  /superadmin/          — SuperAdmin pages (24 file)
  /middleware/          — Auth/permission guards
  /db/                  — Schema + migrations
  /docs/                — Documentation (this file)
  /master/              — Hostinger config (gitignored)
```

### 1.4 Permission model — 3 lapisan

```
                    ┌─────────────────┐
                    │   SuperAdmin    │  ← Founder + tim LAMASY
                    │   (Platform)    │     RBAC: 5 default role +
                    │                 │     29 permissions
                    └────────┬────────┘
                             │ kelola
                             ↓
                    ┌─────────────────┐
                    │       HQ        │  ← Owner bisnis
                    │  (Multi-outlet) │     Gate: hq.access perm
                    └────────┬────────┘
                             │ kelola
                             ↓
                    ┌─────────────────┐
                    │     Outlet      │  ← Kasir, kurir, staff
                    │   (Single ops)  │     Role: owner, manager,
                    │                 │           kasir, kurir
                    └─────────────────┘
```

---

## 2. Flow Operasional

Section ini menggambarkan flow utama yang berjalan di sistem. Setiap flow include actor, langkah, dan data yang berubah.

### 2.1 Tenant Onboarding Flow

Dari calon customer datang sampai jadi tenant aktif yang transaksi.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Visitor]                                                       │
│       │ buka lamasy.harpy.id                                    │
│       ↓                                                          │
│  [Landing /landing.php] — marketing + fitur + CTA                │
│       │ klik "Daftar Gratis"                                    │
│       ↓                                                          │
│  [/register.php]                                                 │
│       • Isi: nama bisnis, owner, email, WA, password            │
│       • Setup nama outlet pertama                                │
│       • Submit                                                   │
│       ↓                                                          │
│  [DB] INSERT saas_tenants + hl_outlets + saas_users (owner)     │
│       • Auto-create coin balance: trial 10.000 coin             │
│       • Trial period: 7 hari                                     │
│       ↓                                                          │
│  [SaNotifier::tenantRegistered] → email ke SA dengan            │
│       permission registrations.view (owner + ops role)           │
│       ↓                                                          │
│  [Email Verification] → kirim link verify ke email tenant       │
│       ↓                                                          │
│  Tenant klik link → email verified → status = trial             │
│       ↓                                                          │
│  [SaNotifier::emailVerified] → notif SA                         │
│       ↓                                                          │
│  Tenant login pertama kali → /accept-tos.php (terima ToS)       │
│       ↓                                                          │
│  Splash onboarding tips muncul (8 tips dari hl_splash_tips)     │
│       ↓                                                          │
│  Product Tour walkthrough (6 step sidebar) auto-trigger         │
│       ↓                                                          │
│  Tenant mulai isi master data:                                   │
│       • TenantProvisioner.php seed default: layanan, parfum,    │
│         bahan baku, COA, kategori                                │
│       • Tenant CRUD karyawan, customer, layanan custom          │
│       ↓                                                          │
│  Transaksi pertama via POS → status = aktif                     │
│       ↓                                                          │
│  Setelah 7 hari trial:                                           │
│       ├─ Top-up coin sebelum habis → tetap aktif                │
│       └─ Coin habis → grace period 3 hari → suspended           │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 POS / Order Lifecycle

Order dari penerimaan sampai customer pulang dengan cucian bersih.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Customer datang ke outlet]                                     │
│       │                                                          │
│       ↓                                                          │
│  [Kasir buka /pos.php]                                           │
│       • Pilih customer existing ATAU input no.HP baru           │
│       • Pilih parfum (opsional)                                  │
│       • Add layanan: cuci complete, setrika, dll                │
│         (bisa inline-create kalau layanan blm ada)              │
│       • Tier express: Reguler / Express (+harga)                │
│       • Antar checkbox (toggle ke kurir flow)                   │
│       ↓                                                          │
│  [Estimasi otomatis] applyMaxEstimasi() — max dari semua        │
│       layanan + tier override                                    │
│       ↓                                                          │
│  [Promo & deposit cek]                                           │
│       • Auto-apply promo aktif                                   │
│       • Cek saldo deposit customer → tawarkan bayar deposit     │
│       • Cek member tier discount                                 │
│       ↓                                                          │
│  [Pembayaran] cash / transfer / deposit-deduct                  │
│       ↓                                                          │
│  [DB] INSERT hl_order + hl_order_item + hl_pembayaran           │
│       • status = "diterima"                                      │
│       • Auto-deduct stok bahan (FinancialCalculator)            │
│       • Auto-issue poin loyalty (Loyalty.php)                   │
│       ↓                                                          │
│  [Cetak struk] via StrukGenerator.php                           │
│       • Include QR code untuk track public + portal             │
│       ↓                                                          │
│  [Staff produksi buka /produksi.php]                            │
│       • Scan QR ATAU pilih order dari list                      │
│       • 6 tahap dengan signature canvas + foto opsional:        │
│           1. Terima → 2. Sortir → 3. Cuci →                    │
│           4. Setrika → 5. Packing → 6. Selesai                  │
│       • Setiap tahap: hl_proses_input INSERT (audit trail)      │
│       ↓                                                          │
│  Status order auto-update: diterima → diproses → siap           │
│       ↓                                                          │
│  [Auto-send WA] "Cucian siap diambil" (kalau status siap)       │
│       ↓                                                          │
│  Customer ambil:                                                 │
│       ├─ Datang ke outlet → kasir mark "selesai"                │
│       └─ Antar-jemput → flow 2.3                                │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.3 Antar-Jemput Flow

Order dengan pickup/delivery oleh kurir outlet.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [POS dengan checkbox Antar = ON]                                │
│       ↓                                                          │
│  Auto-create hl_antar_jemput record                             │
│       • Type: pickup, delivery, atau both                       │
│       • Zona detected dari alamat customer                       │
│       ↓                                                          │
│  [Dispatcher buka /antar-jemput.php]                            │
│       • List task: Belum di-assign / On the way / Done          │
│       • Filter by zona                                           │
│       • Klik assign → pilih kurir aktif                          │
│       ↓                                                          │
│  [Kurir terima notif WA] "Task baru: pickup dari Customer X"   │
│       ↓                                                          │
│  [Kurir buka /kurir (mobile)]                                   │
│       • List task hari ini                                       │
│       • Klik task → detail (alamat, customer, item)              │
│       • Step button:                                             │
│           [Berangkat] → [Sampai lokasi] → [Pickup done]         │
│       ↓                                                          │
│  Kembali ke outlet → status order = diproses                    │
│       ↓                                                          │
│  Produksi selesai → status order = siap                         │
│       ↓                                                          │
│  Auto-create antar-jemput delivery (kalau type=both)            │
│       ↓                                                          │
│  Kurir delivery:                                                 │
│       [Berangkat] → [Sampai lokasi] → [Done] dengan:            │
│           • Foto bukti penyerahan                                │
│           • Customer signature canvas                            │
│       ↓                                                          │
│  Status order = selesai, loyalty poin issued                    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.4 Self-Service Mesin Flow

Customer pakai mesin laundry sendiri (laundromat mode).

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Customer datang ke outlet self-service]                       │
│       ↓                                                          │
│  [Scan QR mesin] → /self.php?m=KODE                             │
│       ↓                                                          │
│  Halaman public (no login):                                      │
│       • Status mesin: tersedia / dipakai                        │
│       • Form: input HP + pilih cycle (cuci/keringkan/keduanya) │
│       ↓                                                          │
│  Submit → DB transaction (race-condition safe):                  │
│       • SELECT FOR UPDATE hl_mesin                               │
│       • Cek status — kalau busy, tolak                           │
│       • INSERT hl_mesin_sesi                                     │
│       • UPDATE hl_mesin SET status = 'dipakai'                  │
│       ↓                                                          │
│  Customer mulai cycle → timer countdown muncul                  │
│       ↓                                                          │
│  Cycle selesai → status = 'selesai', alert di dashboard staff  │
│       ↓                                                          │
│  Staff verify + reset → status = 'tersedia'                     │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.5 Customer Portal Flow

Customer akses history + saldo + poin via QR di struk.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Customer scan QR di struk] → /p?t=<portal_token>              │
│       ↓                                                          │
│  Token validasi (auto-gen saat customer dibuat):                 │
│       • Match hl_pelanggan.portal_token (UNIQUE)                │
│       • Set session pelanggan_id                                 │
│       ↓                                                          │
│  Redirect → /pelanggan (portal home, READ-ONLY)                 │
│       • Tab: Order Aktif / History / Hadiah / Saldo Deposit    │
│       • Member tier badge                                         │
│       • Poin balance + reward catalog                            │
│       ↓                                                          │
│  Klik order detail → /pelanggan-order?id=N                      │
│       • Timeline status (diterima → produksi → siap → selesai) │
│       • Item breakdown + harga                                   │
│       • Estimasi selesai                                         │
│       ↓                                                          │
│  Owner bisa regenerate token kalau leaked → token baru di       │
│       struk berikutnya, token lama invalid                       │
│                                                                  │
│  ⚠️ Portal READ-ONLY — tidak bisa edit/order. Untuk keamanan.   │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.6 Coin / Billing Flow

Bagaimana tenant bayar untuk pakai platform.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Tenant baru daftar]                                            │
│       ↓                                                          │
│  Auto-credit 10.000 coin (trial gratis 7 hari)                  │
│       ↓                                                          │
│  Tenant transaksi normal → pakai fitur basic gratis             │
│       ↓                                                          │
│  Tenant pakai fitur AI / premium → coin deducted per call       │
│       (pricing per fitur dari saas_coin_pricing)                 │
│       ↓                                                          │
│  Coin saldo turun → AIRateLimiter cek limit harian              │
│       ├─ Limit OK → call diizinkan                              │
│       └─ Limit habis → block, return user-friendly error         │
│       ↓                                                          │
│  Saldo coin < threshold (mis. 5.000):                            │
│       • Notif tenant via WA + email                              │
│       • Banner di dashboard tenant                                │
│       • SA dashboard alert "Coin Kritis"                         │
│       ↓                                                          │
│  Tenant top-up:                                                  │
│       ├─ Owner buka /deposit (atau coin-info)                    │
│       ├─ Pilih package (mis. 50K coin Rp 200K)                  │
│       ├─ Submit → SA review                                      │
│       └─ Bukti transfer upload                                   │
│       ↓                                                          │
│  [SA Finance role] approve di /superadmin/clients.php           │
│       • Klik "Topup" pada baris tenant                           │
│       • Input jumlah coin → DB transaction:                      │
│           UPDATE saas_tenants.coin_balance                       │
│           INSERT coin_ledger                                     │
│       ↓                                                          │
│  [SaNotifier::paymentReceived] → confirmation email              │
│       ↓                                                          │
│  Tenant lihat coin baru di dashboard → continue using            │
│                                                                  │
│  ──────  Kalau coin habis tanpa top-up:  ──────                 │
│                                                                  │
│  Saldo 0 → enter grace period 3 hari                            │
│       • Trial expiring notif via SaNotifier::trialExpiring       │
│       • Tenant masih bisa akses semua fitur                      │
│       ↓                                                          │
│  Grace habis → status = suspended                                │
│       • Tenant login → /account-suspended.php                    │
│       • Block semua page kecuali billing + support              │
│       • SaNotifier::outletSuspended → notif SA                  │
│       ↓                                                          │
│  Top-up → reactivate                                             │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.7 AI Request Flow

Tiap call ke Claude API.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [User trigger AI feature]                                       │
│   contoh: HQ owner klik "Generate AI Briefing" di dashboard     │
│       ↓                                                          │
│  [AIRateLimiter::checkAndIncrement] core/AIRateLimiter.php      │
│       • Cek hl_ai_usage WHERE tenant_id=X AND feature=Y         │
│         AND date=TODAY                                            │
│       • Bandingkan dengan saas_coin_pricing.daily_limit          │
│       ├─ Over limit → return error + block                       │
│       └─ OK → INSERT/UPDATE counter                              │
│       ↓                                                          │
│  [Cache check — hl_ai_cache]                                     │
│       • Hash prompt → cek cache (5-menit TTL via Anthropic       │
│         prompt cache + local DB cache untuk repeat queries)      │
│       ├─ Hit → return cached response (no API call)              │
│       └─ Miss → call Claude API                                  │
│       ↓                                                          │
│  [AnthropicClient::send] core/AnthropicClient.php               │
│       • Build prompt dengan AIPersona (system prompt)            │
│       • Inject data via AIChatData / AIInsight class            │
│       • Call API dengan cache_control breakpoint                 │
│       ↓                                                          │
│  Response received:                                              │
│       • Log usage: tokens_in, tokens_out, cost_idr,             │
│         cache_hit, feature, tenant_id, date                      │
│       • INSERT hl_ai_usage                                       │
│       • Deduct coin from saas_tenants.coin_balance              │
│       • INSERT coin_ledger entry                                 │
│       ↓                                                          │
│  Return response ke UI                                           │
│       ↓                                                          │
│  [SA tracking] /superadmin/ai_usage.php                          │
│       • Aggregate: total calls, cost, revenue, margin per fitur │
│       • Per tenant top usage                                     │
│       • Trend harian (cost vs revenue chart)                     │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.8 Smartlink Migration Flow

Tenant existing di kompetitor Smartlink pindah ke LAMASY dengan data Excel.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Tenant request migration ke SA]                                │
│       ↓                                                          │
│  [SA buka /superadmin/migrations.php]                            │
│       • Klik "New Migration Job"                                 │
│       • Pilih tenant + outlet target                              │
│       • Upload file Excel hasil export Smartlink                 │
│       ↓                                                          │
│  [MigrationImporter::parseExcel]                                 │
│       • Parse 2-tier header (Smartlink format quirky)            │
│       • Extract multi-item nota                                  │
│       ↓                                                          │
│  [AI mapping] core/AIMigrationMapper.php                         │
│       • Claude analyzes kolom Excel vs schema LAMASY             │
│       • Generate mapping suggestion                              │
│       • Cache template di hl_migration_mapping_templates         │
│         (next migration tenant sama → reuse mapping)             │
│       ↓                                                          │
│  [SA review mapping] confirm/edit field mapping                  │
│       ↓                                                          │
│  [Dry run] preview 10 rows pertama → cek correctness            │
│       ↓                                                          │
│  Confirm → import:                                                │
│       • INSERT batch hl_pelanggan, hl_order, hl_order_item      │
│       • Hitung kolom 'Total' (heuristic — handle biaya tambahan)│
│       • Job logged di hl_migration_jobs                          │
│       ↓                                                          │
│  Status: success / partial (with error log)                      │
│       ↓                                                          │
│  Tenant verify data → akses normal                               │
│                                                                  │
│  Note: Migration upload tidak deduct coin (gratis service)      │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.9 Support Ticket Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Tenant ada masalah]                                            │
│       ↓                                                          │
│  HQ owner buka /hq/support → "New Ticket"                       │
│       • Subject, body, attachment opsional                       │
│       • Submit                                                   │
│       ↓                                                          │
│  [SaNotifier::supportTicketCreated]                             │
│       • Email ke SA dengan permission support.view              │
│         (default: owner + support role)                          │
│       • Slack/WA notif opsional                                  │
│       ↓                                                          │
│  [SA Support staff buka /superadmin/support.php]                │
│       • Inbox semua tickets                                       │
│       • Filter: open / replied / resolved                        │
│       • Klik ticket → detail thread                              │
│       ↓                                                          │
│  Reply (dengan permission support.reply):                        │
│       • Tulis response                                            │
│       • Auto-send notif ke tenant via email + WA                │
│       ↓                                                          │
│  Multiple exchanges (thread)                                     │
│       ↓                                                          │
│  Mark closed (dengan permission support.close):                  │
│       • Status = resolved                                         │
│       • Tenant bisa rate kualitas response                       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.10 RBAC SuperAdmin Flow

Bagaimana team LAMASY di-onboard dengan akses yang appropriate.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Founder hire staff baru — mis. Support agent]                  │
│       ↓                                                          │
│  Owner SA buka /superadmin/settings.php → tab "Roles &           │
│  Permissions"                                                     │
│       ↓                                                          │
│  Decide role:                                                     │
│       ├─ Pakai default "support" role (6 perms: support.view,   │
│       │   support.reply, support.close, churn.view, dll)        │
│       └─ Atau create custom role mis. "support-senior" dengan    │
│           perms tambahan                                         │
│       ↓                                                          │
│  Buka tab "SA Team" → "+ Tambah"                                 │
│       • Username, email, password, full name                     │
│       • Pilih role dari dropdown                                  │
│       • Toggle: notif_enabled (terima email notif)              │
│       ↓                                                          │
│  Submit → DB INSERT super_admins + assign role_id                │
│       ↓                                                          │
│  Staff baru terima credentials                                   │
│       ↓                                                          │
│  Staff login /superadmin/login                                   │
│       • Verify credentials                                        │
│       • SaPermission::loadIntoSession() — load permissions       │
│         dari sa_role_permissions JOIN sa_permissions             │
│       • $_SESSION['sa_perms'] = ['support.view', ...]            │
│       ↓                                                          │
│  Setiap page SA: SaPermission::require('feature.action')         │
│       • Kalau perm hilang → 403 page                             │
│       • UI hide button/menu untuk perm yang ga ada               │
│       ↓                                                          │
│  Notif routing:                                                  │
│       • SaNotifier::resolveRecipients($event)                    │
│       • Find SA dengan permission yang match event               │
│         contoh: registrations.view → owner + ops staff           │
│       • Filter by notif_enabled + notif_events opt-in            │
│       • Send email (throttled 60s dedup)                         │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.11 Multi-Outlet HQ Impersonate Flow

Owner di HQ mau masuk ke outlet view untuk handle masalah spesifik.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Owner buka /hq/dashboard]                                      │
│       ↓                                                          │
│  Lihat "Kesehatan Outlet" card                                   │
│       • List outlet dengan health score                          │
│       • Tombol "Masuk" per outlet                                │
│       ↓                                                          │
│  Klik "Masuk" → link include:                                    │
│       /switch-outlet?outlet=N&t=<HMAC-token>                     │
│       • Token = hash_hmac('sha256', $outlet_id, $secret)        │
│       • Token validity ~60s                                       │
│       ↓                                                          │
│  Server verify token → set $_SESSION['outlet_id'] = N            │
│       ↓                                                          │
│  Redirect /dashboard (outlet view)                                │
│       • Tenant_guard cek session                                 │
│       • Render outlet-level UI dengan data outlet N              │
│       ↓                                                          │
│  Owner bisa do anything outlet-level                              │
│       ↓                                                          │
│  Mau balik ke HQ:                                                │
│       • Topbar dropdown → "Switch ke HQ View"                    │
│       • Clear outlet_id session                                  │
│                                                                  │
│  ⚠️ Tanpa token signing → fix bug "invalid_token" yang sempat   │
│  terjadi di session ini.                                         │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.12 Banner Carousel Flow (SA → Tenant Dashboard)

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [SA] /superadmin/banners.php → "+ Tambah Banner"               │
│       ↓                                                          │
│  Pilih mode:                                                     │
│       ├─ 📷 Gambar: upload (1600×400 optimal, max 800 KB)       │
│       │   → /assets/banners/bn_<ts>_<rand>.jpg                  │
│       └─ 🎨 Gradient: pilih preset / custom CSS                  │
│       ↓                                                          │
│  Set: judul, deskripsi, CTA label + URL, icon emoji,             │
│       text color, urutan, schedule (starts_at/ends_at)           │
│       ↓                                                          │
│  Save → INSERT saas_banners                                      │
│       ↓                                                          │
│  Tenant buka /dashboard:                                          │
│       • BannerLoader::activeForTenant(tenant_id)                 │
│       • WHERE is_active=1 AND now between starts_at/ends_at     │
│       • LIMIT 5 banner aktif                                     │
│       ↓                                                          │
│  Render carousel:                                                │
│       • Image mode → bg-image + dark gradient overlay (kiri)    │
│       • Gradient mode → CSS gradient bg                           │
│       • Auto-rotate setiap 6 detik                                │
│       • Dots indicator + click navigation                        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. Layer 1: Outlet (Operasional Harian)

**Audience:** Kasir, kurir, staff produksi, manager outlet, owner.
**~25 page** di root project.

### 3.1 Modul

**Transaksi & Order**
| Page | Fungsi |
|------|--------|
| `pos.php` | POS kasir — input order baru |
| `orders.php` | Daftar order + filter status |
| `kanban.php` | Kanban view drag-drop status |
| `produksi.php` | Staff produksi — 6 stage workflow |
| `mesin.php` | Master mesin + monitor real-time |

**Antar-Jemput**
| Page | Fungsi |
|------|--------|
| `antar-jemput.php` | Dispatcher view |
| `kurir.php` | Kurir mobile view (step-button + done modal) |
| `kurir-master.php` | CRUD kurir + account |

**Inventori & Master**
| Page | Fungsi |
|------|--------|
| `inventori.php` | Stok bahan + mutasi + PO PDF |
| `layanan.php` | Master layanan + kategori + tier |
| `promo.php` | Promo dengan apply outlet |
| `customer.php` | Master customer |
| `member.php` | Member tier system |
| `deposit.php` | Wallet customer |
| `loyalty.php` | Sistem poin + reward |
| `retention.php` | Dormant customer |

**Pelanggan-facing (public)**
| Page | Fungsi |
|------|--------|
| `pelanggan.php` | Portal home |
| `pelanggan-order.php` | Order detail |
| `self.php` | Self-service mesin booking |
| `track.php` / `cek.php` | Public order tracking |
| `p.php` | Portal token entry |

**Kas & Keuangan**
| Page | Fungsi |
|------|--------|
| `kas.php` | Kas harian + mutasi |
| `laporan.php` | Laporan SAK EMKM (Neraca/LR/Arus Kas) |
| `piutang.php` | Piutang B2B |

**HR**
| Page | Fungsi |
|------|--------|
| `karyawan.php` | Master karyawan |
| `absensi.php` | Clock-in/out |

**Lainnya**
| Page | Fungsi |
|------|--------|
| `dashboard.php` | Outlet dashboard + AI briefing |
| `checklist.php` | Opening/closing checklist |
| `audit.php` | Audit log |
| `approval-inbox.php` | Owner approve action sensitif |
| `ai.php` | AI feature toggle/usage |
| `add-outlet.php` | Wizard add outlet baru |
| `import.php` | Import data |

### 3.2 Role di Outlet

| Role | Akses |
|------|-------|
| `owner` | Full access semua fitur outlet |
| `manager` | Operasional + sebagian finansial |
| `kasir` | POS + customer + payment |
| `kurir` | Mobile view + task list (login redirect ke /kurir) |
| `produksi` | Produksi page only |

Permission stored di `hl_permissions` table — granular per fitur.

---

## 4. Layer 2: HQ (Multi-Outlet Owner)

**Audience:** Owner bisnis. Akses via `hq.access` permission.
**~30 page** di `/hq/`.

### 4.1 Modul

**Dashboard & Konsolidasi**
| Page | Fungsi |
|------|--------|
| `dashboard.php` | Konsolidasi metrik semua outlet, kesehatan outlet, switch impersonate |
| `outlet.php` | CRUD outlet, setting antar-jemput, zona kurir |

**Finansial Konsolidasi**
| Page | Fungsi |
|------|--------|
| `keuangan.php` | Laporan SAK EMKM gabungan |
| `laporan.php` | Custom report konsolidasi |
| `mutasi.php` | Transfer kas antar outlet |
| `billing.php` | Konsolidasi billing |
| `coin-info.php` | Info coin platform (saldo, history, top-up) |

**Operasional Lintas Outlet**
| Page | Fungsi |
|------|--------|
| `inventori.php` | Stok konsolidasi + transfer antar outlet |
| `mesin.php` | Mesin konsolidasi + report utilization |
| `karyawan.php` | Semua karyawan lintas outlet |
| `penggajian.php` | HQ-level payroll |
| `pelanggan.php` | Customer lintas outlet |
| `droppoint.php` | Drop point manager |

**Master & Konfigurasi**
| Page | Fungsi |
|------|--------|
| `layanan.php` | Layanan master HQ-level |
| `promo.php` | Promo apply outlet |
| `loyalty.php` | Sistem poin global |
| `bonus-rule.php` | Bonus rule target-based |
| `roles.php` | Roles & permission karyawan |
| `sdm.php` | SDM master |
| `struk.php` | Struk template |

**AI Tools (HQ-only)**
| Page | Fungsi |
|------|--------|
| `ai-chat.php` | Owner tanya-jawab data natural language |
| `ai-churning.php` | Churn risk detection |

**Komunikasi**
| Page | Fungsi |
|------|--------|
| `broadcast.php` | Broadcast WA ke customer |
| `support.php` | Support ticket dari HQ ke SA |

**Lainnya**
| Page | Fungsi |
|------|--------|
| `audit.php` | Audit log gabungan |
| `export.php` | Export data |
| `checklist.php` | Checklist template management |

### 4.2 HQ vs Outlet — beda apa?

| Aspect | Outlet | HQ |
|--------|--------|-----|
| Scope data | 1 outlet | semua outlet tenant |
| Karyawan view | yang assigned ke outlet ini | semua karyawan tenant |
| Finansial | per-outlet | konsolidasi |
| Master data | inherited dari HQ + override | source of truth |
| AI tools | briefing harian outlet | chat full data + churn |
| Switch outlet | tidak bisa | bisa impersonate |

---

## 5. Layer 3: SuperAdmin (Platform Admin)

**Audience:** Founder + tim LAMASY (ops/finance/support/viewer).
**~24 page** di `/superadmin/`.

### 5.1 Modul

**Dashboard & Health**
| Page | Fungsi |
|------|--------|
| `dashboard.php` | Total tenant, revenue, churn risk, AI abuse alerts |
| `health.php` | Platform health, AI usage stats, WA delivery, error log |

**Client Management**
| Page | Fungsi |
|------|--------|
| `clients.php` | List tenant + outlet, filter, suspend/topup/impersonate |
| `client_detail.php` | Detail tenant per-tenant |
| `add_outlet.php` | Manual add outlet untuk tenant |
| `impersonate.php` / `stop_impersonate.php` | Debug as tenant |

**Onboarding & Migration**
| Page | Fungsi |
|------|--------|
| `registrations.php` | Pending registrasi queue |
| `registration_wizard.php` | Manual onboarding step-by-step |
| `onboarding.php` | Onboarding tools |
| `migrations.php` | Smartlink Excel import dengan AI mapping |

**Finance**
| Page | Fungsi |
|------|--------|
| `billing.php` | Setup fee, coin topup, monthly revenue |
| `payments.php` | Payment log |
| `coin_pricing.php` | Coin pack pricing + AI daily limits |
| `packages.php` | Subscription tier management |

**AI Management**
| Page | Fungsi |
|------|--------|
| `ai_usage.php` | Cost vs revenue, margin per fitur/tenant |

**Communication**
| Page | Fungsi |
|------|--------|
| `support.php` | Ticket inbox |
| `announcements.php` | Platform announcement broadcast |
| `broadcast.php` | Mass WA/email broadcast |
| `banners.php` | Dashboard banner carousel (gradient/image) |
| `churn_risk.php` | Identify high-churn-risk tenants |

**Settings (multi-tab)**
| Tab | Fungsi |
|-----|--------|
| Maintenance | Toggle platform maintenance + whitelist IP |
| Demo | Manage demo tenant |
| ToS Versions | Manage Terms revisions |
| Splash Tips | Onboarding tips CRUD |
| Notifications | Per-SA email opt-in + event filter |
| SA Team | CRUD super admin accounts |
| Roles & Permissions | CRUD role + perm assignment |

### 5.2 RBAC SuperAdmin — Detail

**5 default role:**
| Role | Permissions Count | Untuk |
|------|-------------------|-------|
| `owner` | 29 (all) | Founder Rizky |
| `ops` | 7 | Registrasi + onboarding + impersonate |
| `finance` | 10 | Billing + payments + pricing + packages |
| `support` | 6 | Tickets + churn + broadcast |
| `viewer` | 10 | Dashboard + metric read-only |

**29 permissions (sample):**
- `super_admins.manage` — kelola SA team
- `clients.view` / `clients.suspend` / `clients.topup`
- `support.view` / `support.reply` / `support.close`
- `billing.view` / `billing.refund`
- `coin_pricing.edit`
- `packages.manage`
- `announcements.publish`
- `banners.publish`
- `broadcast.send`
- `registrations.view` / `registrations.approve`
- `migrations.run`
- ...

---

## 6. Integrasi AI

### 6.1 Fitur AI yang Live

| Fitur | Module | Layer | Pricing Coin |
|-------|--------|-------|--------------|
| AI Briefing harian | dashboard widget | Outlet + HQ | varies |
| AI Chat | conversational data query | HQ | per-call |
| AI Churning | churn risk detection | HQ | per-call |
| AI Insight | pattern + anomaly | HQ | per-call |
| AI Migration Mapper | Excel column matching | SA only | free (1x per migration) |
| AI Anomaly Alerts | real-time transaction watch | Outlet | per-call |
| AI Daily Report | auto-generated summary | HQ | per-call |
| AI Generate Nota | smart nota template | Outlet | per-call |
| AI Send WA | AI-rewritten WA message | Outlet | per-call |

### 6.2 Anti-Abuse — AIRateLimiter

```
                ┌─────────────────────────────────┐
                │   AIRateLimiter::check($feat)   │
                └────────────────┬────────────────┘
                                 │
                  ┌──────────────┼──────────────┐
                  ↓              ↓              ↓
            ┌───────────┐  ┌──────────┐  ┌────────────┐
            │ DB-backed │  │  Daily   │  │   Cost     │
            │  counter  │  │  limit   │  │  ceiling   │
            │ hl_ai_usage│  │  cek     │  │  cek       │
            └───────────┘  └──────────┘  └────────────┘
                  │              │              │
                  └──────────────┴──────────────┘
                                 ↓
                          OK or BLOCKED
```

- Tenant yang hit limit 3+ kali sehari → flagged di dashboard SA dengan violet AI shimmer
- Coin pricing per fitur di `saas_coin_pricing.daily_limit`
- Cache hit rate dilacak (Anthropic prompt cache + DB cache)

### 6.3 Tracking & Margin

`/superadmin/ai_usage.php` menampilkan:
- Total AI calls + cache rate
- Total tokens in/out
- Cost Anthropic (actual API cost dalam IDR)
- Revenue Coin (coin spent × harga per coin)
- Net Margin (revenue - cost)
- Per fitur breakdown
- Per tenant top usage (siapa yang abuse / power user)
- Trend harian (line chart)

---

## 7. Database Schema Overview

**103 tabel total**, dikelompokkan:

### 7.1 SaaS Platform Tables (saas_*)
- `saas_tenants` — tenant master + coin balance
- `saas_users` — user accounts per tenant
- `saas_banners` — banner carousel
- `saas_coin_pricing` — coin pack + AI limits
- `saas_sa_notif_log` — SA notification audit
- `saas_packages` — subscription tiers
- `saas_pricing` — pricing rules
- `super_admins` — SA accounts dengan `role_id`
- `sa_roles` / `sa_permissions` / `sa_role_permissions` — RBAC

### 7.2 Tenant Operational Tables (hl_*)
Selalu di-scope `tenant_id` (+ `outlet_id` untuk transaksional).

**Master data:**
- `hl_outlets` — outlets per tenant
- `hl_layanan` / `hl_layanan_master` — layanan
- `hl_parfum` — parfum
- `hl_bahan` / `hl_bahan_mutasi` / `hl_bahan_stok` (view) — inventori
- `hl_mesin` / `hl_mesin_cycle` / `hl_mesin_sesi` — mesin
- `hl_pelanggan` / `hl_pelanggan_member` — customer + membership
- `hl_member_tier` — tier definitions
- `hl_drop_points` — drop point locations
- `hl_kurir` — kurir master
- `hl_karyawan_outlet` — karyawan assignments

**Transaksional:**
- `hl_order` / `hl_order_item` / `hl_order_notes` — orders
- `hl_pembayaran` — payments
- `hl_proses_input` — produksi 6-stage audit
- `hl_antar_jemput` — pickup/delivery tasks

**Finansial:**
- `hl_kas` / `hl_kas_bank` / `hl_kas_bank_mutasi` — kas
- `hl_coa` — chart of accounts
- `hl_jurnal_manual` — manual journals
- `hl_aset_tetap` / `hl_liabilitas` — neraca
- `hl_piutang` — receivables
- `hl_laporan_cache` — report cache

**HR:**
- `hl_absensi` / `hl_izin` — attendance
- `hl_gaji` / `hl_gaji_komponen` — payroll
- `hl_komisi_rekap` — commission
- `hl_bonus_rule` / `hl_bonus_rule_outlet` — bonus rules

**Loyalty & Promo:**
- `hl_promo` / `hl_promo_outlets` — promotions
- `hl_loyalty_log` — poin history
- `hl_poin_reward` / `hl_poin_reward_outlet` — rewards
- `hl_deposit_topup` / `hl_deposit_usage` / `hl_deposit_refund` / `hl_deposit_bonus_tier`
- `hl_express_tier` — tier express pricing

**AI:**
- `hl_ai_usage` — per-tenant per-feature daily counter
- `hl_ai_cache` — response cache
- `hl_ai_outreach_log` — AI-generated outreach log

**Lainnya:**
- `hl_audit_log` — all action audit trail
- `hl_login_attempts` — security
- `hl_notif_log` — notification dispatch log
- `hl_broadcast` / `hl_broadcast_recipient` — broadcast
- `hl_checklist_template` / `hl_checklist_submission` — checklist
- `hl_migration_jobs` / `hl_migration_mapping_templates` — migrations
- `hl_delete_request` — UU PDP deletion requests
- `hl_permissions` — granular permission flags
- `hl_splash_seen` / `hl_splash_tips` — onboarding tips
- `coin_ledger` / `email_verifications`

---

## 8. Security & Compliance

### 8.1 Authentication
- Session-based dengan PHP native sessions
- Password hashed (bcrypt via `password_hash`)
- Login attempts dilog di `hl_login_attempts` (brute-force protection)
- Email verification wajib sebelum aktif

### 8.2 Authorization
- CSRF token per request (HMAC-SHA256 signed)
- Tenant scoping enforced di `TenantQuery::scoped()`
- Permission check di setiap action via `hl_permissions` (outlet) / `SaPermission` (SA)
- HQ guard via `hq.access` permission

### 8.3 Multi-Tenant Isolation
- Setiap query include WHERE tenant_id = $current_tenant
- Cross-tenant data access prevented at query layer (audit periodic)
- Outlet scoping pada transaksional table

### 8.4 Compliance Indonesia
- **UU PDP No 27/2022** — Personal Data Protection
  - Customer bisa request delete via `hl_delete_request`
  - Privacy policy di `/privacy`
- **UU PK 8/1999** — Consumer Protection
- **UU ITE 11/2008** — Electronic Information & Transactions
- **SAK EMKM** — laporan keuangan compliant (Neraca, LR, Arus Kas auto-generate)

### 8.5 Audit Trail
- `hl_audit_log` — log semua action sensitif
- `super_admin_audit` — SA-level action log
- Authentication events
- Permission changes
- Financial transactions

---

## 9. Komunikasi & Notifikasi

### 9.1 Email (Hostinger SMTP)
- Sender: noreply@harpy.id
- Templates di `core/Mailer.php`
- Use case: email verification, password reset, SA notif, broadcast

### 9.2 WhatsApp (Fonnte API)
- 1000 msg/bulan free tier
- Log di `hl_notif_log`
- Use case: order siap notif, kurir task assignment, broadcast, low coin alert

### 9.3 In-App Notif
- Splash tips at login (8 default tips, configurable di SA)
- Banner carousel di dashboard
- Alert banners (stok kritis, mesin selesai, AI abuse)
- Product Tour walkthrough (6-step, first-time + replay)

### 9.4 SuperAdmin Notif Routing
`core/SaNotifier.php`:
- 6 event types: tenantRegistered, emailVerified, outletActivated, supportTicketCreated, trialExpiring, outletSuspended
- Filter recipient via `SaPermission` match
- Per-SA opt-in via `super_admins.notify_enabled`
- Throttle 60s dedup per event_type

---

## 10. Numbers & Differentiator

### 10.1 Stats
- **103 DB tables**
- **61 PHP files** di root (outlet + public)
- **30 PHP files** di `/hq/`
- **24 PHP files** di `/superadmin/`
- **30 core libraries** di `/core/`
- **9 AI features** integrated
- **5 default SA roles** + 29 permissions
- **Auto-deploy** ~15s dari git push

### 10.2 Differentiator (Positioning)

1. **AI-first ERP** — "ERP modern dengan integrasi AI pertama di Indonesia untuk laundry SME"
2. **Multi-tenant + Multi-outlet** chain-friendly dengan HQ konsolidasi
3. **Self-service customer** via QR di struk (track + portal + reward)
4. **Antar-Jemput integrated** dengan kurir mobile app, signature + foto, tidak butuh 3rd party
5. **SAK EMKM compliant** — laporan keuangan otomatis siap untuk pajak
6. **Smartlink migration** — AI-mapped Excel import dari kompetitor
7. **Sistem coin** — pay-as-you-use untuk fitur premium (AI calls)
8. **Comprehensive audit** — semua action sensitif logged
9. **Modern UI** — dual-theme: Outlet/HQ (dark navy + teal Ponytail) + SA (AI Command Center dengan shimmer signature)
10. **PWA-ready** — installable ke device tanpa app store

---

## 11. Roadmap (Belum dikerjakan)

Mentioned di session tapi belum implement:

**HIGH PRIORITY**
- 2FA TOTP untuk SuperAdmin accounts
- Audit Log Viewer terdedicated untuk SA
- Extend Trial Manual + Manual Coin Adjustment UI di SA
- Email Template Editor

**ON HOLD**
- Native app strategy (Capacitor thin shell + remote webview — hold sampai PWA feature-complete)
- QRIS + WhatsApp Business API (hold separately dari roadmap utama)

**EXPLORATORY**
- Lighthouse baseline metrics
- Cloudflare Analytics integration
- Cloudflare panel: Auto-Minify + Brotli + Tiered Cache (manual setting user)

---

## 12. Design System Summary

### 12.1 Outlet + HQ (Ponytail)
- **Bg:** Dark navy `#0F1C3A`
- **Accent:** Brand Teal `#35E8D5`
- **Type:** Plus Jakarta Sans + DM Mono
- **Vibe:** Operational ERP, mobile-first, PWA-ready

### 12.2 SuperAdmin (AI Command Center)
- **Bg:** Obsidian `#0A0F1F` + subtle radial gradient + dot-grid
- **Surface:** Slate `#141B2D` glass blur cards
- **Brand Accent:** Teal `#35E8D5` (sama dengan tenant — brand continuity)
- **AI Accent:** Violet `#A78BFA` (semantic: AI features)
- **Type:** Inter + Inter Tight (display) + JetBrains Mono
- **Signature:** AI Shimmer (animated teal→violet gradient borders, pills, strips)
- **Vibe:** Premium AI tooling, modern dashboard

---

*Generated dari struktur codebase + DB schema + session knowledge, June 2026.*
*Untuk update: rebuild manual atau dari struktur live dengan command MCP browser test path.*
