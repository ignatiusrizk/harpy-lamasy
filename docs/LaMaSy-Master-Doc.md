# LaMaSy — Master Documentation

> **Audiens:** Internal & Investor. **Tanggal:** 2026-06-30. **Status:** Live di produksi (https://lamasy.harpy.id).
> Catatan: metrik bisnis (jumlah tenant, outlet aktif, GMV, MRR) ditandai **`[isi: owner]`** — diisi pemilik dengan data riil; dokumen ini tidak mengarang angka.

---

## 1. Ringkasan Eksekutif

**LaMaSy** adalah platform **SaaS manajemen bisnis laundry** end-to-end untuk UMKM hingga jaringan multi-outlet di Indonesia. Satu sistem menggantikan tumpukan alat terpisah (kasir, buku kas, WhatsApp manual, spreadsheet stok, absensi) dengan operasi terintegrasi: terima order → produksi → antar-jemput → bayar → laporan, plus loyalitas pelanggan, penggajian, dan analitik bertenaga AI.

Dibangun **multi-tenant** sejak awal: tiap pemilik bisnis (tenant) punya banyak outlet, datanya terisolasi, dan pemilik melihat konsolidasi seluruh jaringannya lewat tampilan **HQ**. Di atasnya ada **control-plane SaaS** (super admin) untuk mengelola pelanggan SaaS, billing, paket, dan kesehatan platform.

**Pembeda utama:** kedalaman vertikal (fitur khusus laundry, bukan POS generik) + **AI-native** (input order via suara, scan struk jadi entri kas, deteksi pelanggan churn, asisten chat) + **mobile & offline-capable**.

---

## 2. Masalah & Pasar

Bisnis laundry kiloan/satuan di Indonesia umumnya dijalankan dengan: nota kertas, catatan kas manual, WhatsApp untuk notifikasi pelanggan, dan spreadsheet (kalau ada) untuk stok & gaji. Akibatnya: piutang menumpuk tak tertagih, stok bocor, sulit tahu laba riil, dan tak ada loyalitas pelanggan yang terukur. Pemilik multi-outlet kehilangan visibilitas konsolidasi.

LaMaSy menargetkan: **owner laundry single-outlet yang ingin rapi**, dan **jaringan multi-outlet/franchise** yang butuh kontrol terpusat. `[isi: owner — ukuran pasar, segmen, geografi target]`

---

## 3. Arsitektur Produk — 3 Lapisan

| Lapisan | Pengguna | Fungsi |
|---|---|---|
| **Operasional Outlet** | Kasir, staf produksi, kurir | Jalankan operasi harian: POS, kanban produksi, antar-jemput, kas, absensi |
| **HQ (Headquarter)** | Owner / manajer jaringan | Konsolidasi lintas outlet: dashboard, laporan keuangan, master katalog, penggajian, loyalty, SDM, broadcast, AI insight |
| **Control-Plane SaaS** | Super admin (operator LaMaSy) | Kelola tenant, billing & paket, harga coin AI, kesehatan platform, churn-risk, impersonate untuk support |

Isolasi data dijamin di **setiap** query lewat scoping `tenant_id` + `outlet_id` yang bersumber dari sesi server (bukan input klien).

---

## 4. Katalog Fitur

### 4.1 Penjualan & Order
- **POS** (`pos.php`) — kasir terima order: pilih layanan + tier express, pelanggan, pembayaran (tunai/transfer/QRIS), diskon, voucher, redeem poin, potong deposit. **Input order via suara** (AI parse) & **mode offline** (order saat internet mati, sync otomatis).
- **Orders & Kanban** (`orders.php`, `kanban.php`) — daftar & papan status produksi (masuk → cuci → kering → setrika → siap), pelunasan (single & bulk), tombol WA pelanggan.
- **Struk** (`struk.php`, `StrukGenerator`) — cetak nota + QR lacak.
- **Booking mandiri pelanggan** (`self.php`) — pelanggan booking via QR mesin/outlet.

### 4.2 Produksi
- **Produksi** (`produksi.php`) — pencatatan tahap proses per order, upload foto bukti, tanda tangan, integrasi scanner QR (`hl_proses_input`).

### 4.3 Antar-Jemput (Delivery)
- **Antar-Jemput** (`antar-jemput.php`) — dispatcher tugaskan kurir (zona, mode antar per-outlet).
- **Aplikasi Kurir** (`kurir.php`) — kurir mobile: daftar tugas, update status, modal selesai (foto + tanda tangan). Master kurir (`kurir-master.php`).

### 4.4 Pelanggan, Loyalty & Referral
- **Pelanggan & Member** (`customer.php`, `member.php`) — database pelanggan, segmentasi otomatis, member tier berbayar (diskon + masa aktif).
- **Loyalty poin** (`loyalty.php`, `Loyalty`) — kumpul poin per transaksi, tukar jadi diskon / reward katalog (dikelola HQ per-outlet).
- **Voucher/Kupon & Promo** (`promo.php`).
- **Deposit saldo** (`deposit.php`, `DepositManager`).
- **Referral (ajak teman)** (`Referral`) — kode/link share; pengajak & teman dapat poin saat order pertama teman lunas; anti-abuse + cap; opt-in per owner.
- **Portal Pelanggan** (`pelanggan.php`, PWA) — pelanggan lihat status order, riwayat, poin, hadiah, kode referral; login via token.

### 4.5 Inventori & Pembelian
- **Inventori** (`inventori.php`) — stok bahan, mutasi, alert stok kritis; auto-beban bahan ke laporan keuangan.
- **Pembelian / Purchase Order** (`pembelian.php`) — generate daftar belanja PDF; supplier (`hq/supplier.php`).

### 4.6 Mesin
- **Mesin** (`mesin.php`) — monitor mesin cuci/kering, sesi, booking publik via kode mesin (`/self?m=KODE`).

### 4.7 SDM, Absensi & Penggajian
- **Karyawan & Role** (`karyawan.php`, `hq/roles.php`) — RBAC berbasis permission, role kustom per tenant.
- **Absensi** (`absensi.php`) — clock-in selfie wajib + geofence ketat (config per-outlet, peta), jadwal shift (telat/lembur otomatis, `ShiftCalc`).
- **Penggajian** (`hq/penggajian.php`) — gaji + aturan bonus (`BonusEvaluator`), slip gaji cetak/PDF, bagi-hasil (`BagiHasilCalculator`).

### 4.8 Keuangan & Laporan
- **Kas** (`kas.php`) — kas masuk/keluar, **scan struk via AI** (foto struk → isi form + bukti), kategori, anomaly detection.
- **Laporan** (`laporan.php`) — harian, bulanan, **laba/rugi (SAK EMKM)**, produktivitas karyawan; export CSV/print.
- **Piutang B2B** (`piutang.php`) — tagihan pelanggan korporat, pelunasan, kalkulasi (`FinancialCalculator`).
- **Keuangan HQ** (`hq/keuangan.php`) — konsolidasi finansial lintas outlet.

### 4.9 Suite AI (Anthropic Claude)
- **Voice Order** (`VoiceOrderParser`) — ucapan → order terstruktur.
- **Scan Struk** (`ReceiptScanner`) — foto struk belanja → entri kas.
- **AI Insight & Chat** (`AIInsight`, `AIChatData`, `hq/ai-chat.php`) — tanya-jawab data bisnis, ringkasan.
- **Deteksi Churn** (`AIChurnDetector`, `hq/ai-churning.php`) — identifikasi pelanggan berisiko hilang.
- **Migrasi data** (`AIMigrationMapper`) — impor data dari sistem lama (mis. Smartlink) dengan pemetaan cerdas.
- **Tata kelola AI** — metering berbasis **coin** (`CoinLedger`), rate-limit per fitur (`AIRateLimiter`), budget (`AIBudget`), persona (`AIPersona`). Owner bayar pemakaian AI lewat coin → model monetisasi tambahan.

### 4.10 Notifikasi & Komunikasi
- **Push FCM** (`PushSender`) — notifikasi real-time per-role/event ke app.
- **Email otomatis** (`DailyReport`, `Mailer`, `Notifier`) — laporan harian + alert anomali, kontrol per-channel owner.
- **WhatsApp link** — kirim status/struk ke pelanggan.
- **Broadcast** (`hq/broadcast.php`) — pengumuman massal.

### 4.11 Onboarding & Pengalaman
- **Landing & registrasi mandiri** (`landing.php`, `register.php`, verifikasi email).
- **Demo mode** (`demo.php`) — coba sistem dengan data contoh.
- **Splash/tips** (`SplashManager`), dukungan (`support.php`), TOS/privacy.

---

## 5. Control-Plane SaaS (Super Admin)

Operator LaMaSy mengelola seluruh bisnis SaaS dari `superadmin/`:
- **Clients/Tenant** — daftar pelanggan SaaS, detail, onboarding wizard, suspend.
- **Billing & Paket** (`packages.php`, `billing.php`, `coin_pricing.php`) — paket langganan, harga coin AI, pembayaran (Midtrans, `MidtransClient`).
- **Kesehatan Platform** (`health.php`, `PlatformHealthRecorder`) — uptime, pemakaian AI per fitur, error.
- **Churn-risk tenant** (`churn_risk.php`), **affiliate program** (`affiliates.php`, `AffiliateAuth`).
- **Impersonate** (support: masuk sebagai tenant), **2FA** (`Sa2FA`), audit.

---

## 6. Fondasi Teknis

- **Backend:** PHP 8 (tanpa framework berat — core class terstruktur), **MariaDB/MySQL** (~125 tabel, shared-DB multi-tenant).
- **Multi-tenant:** `TenantResolver` (resolusi sesi), `TenantQuery` (query auto-scoped tenant+outlet), `TenantProvisioner` (provisioning tenant baru + seed). Isolasi data di setiap query.
- **Frontend:** server-rendered PHP + vanilla JS (tanpa SPA berat) → ringan & cepat di koneksi lemah; **PWA** (service worker) untuk portal pelanggan & app operasional.
- **Aplikasi mobile:** **Capacitor** (thin-shell + remote webview) → satu basis kode web jalan sebagai app Android. **Push FCM** live, **STT (voice)** native, **mode offline** (IndexedDB queue + sync idempoten).
- **AI:** integrasi **Anthropic Claude** (`AnthropicClient`) dengan metering coin, rate-limit, budget per tenant.
- **Pembayaran:** Midtrans (langganan SaaS + bisa QRIS order).
- **Keamanan:** RBAC berbasis permission per tenant; proteksi **CSRF** menyeluruh (interceptor fetch global + `verifyCsrf`); audit log; verifikasi email; 2FA super admin; error logging terpusat (`ErrorLogger`).
- **Deploy:** auto-deploy via `git push` ke hosting (Hostinger), domain `lamasy.harpy.id`.
- **Kualitas:** alur pengembangan terdokumentasi (spec → plan → implementasi per-task + review berlapis); test CLI untuk logika inti.

---

## 7. Model Bisnis & Monetisasi

- **Langganan SaaS** per tenant (paket bertingkat) — `[isi: owner — harga paket, tier]`.
- **Coin AI** — pemakaian fitur AI dibayar via coin (revenue tambahan berbasis pemakaian).
- **Affiliate program** — akuisisi via afiliasi.
- `[isi: owner — MRR, ARPU, jumlah tenant berbayar, churn rate, CAC/LTV]`

---

## 8. Diferensiasi & Keunggulan Kompetitif

1. **Vertikal-dalam, bukan POS generik** — alur produksi laundry, antar-jemput, tier express, member, semua native.
2. **AI-native** — voice order, scan struk, churn detection, chat data; metered & terkontrol.
3. **Multi-outlet sejak desain** — konsolidasi HQ + kontrol terpusat, siap franchise.
4. **Mobile + offline** — tetap jalan saat internet mati (kritis untuk outlet Indonesia).
5. **Ringan** — server-rendered, hemat data, cepat di perangkat & jaringan low-end.
6. **Self-serve + demo** — onboarding mandiri menurunkan biaya akuisisi.

---

## 9. Status & Roadmap

**Live di produksi** dengan modul: POS (+ voice + offline), produksi, antar-jemput, inventori, mesin, pelanggan/loyalty/referral, absensi/penggajian, kas/laporan/piutang, portal pelanggan, suite AI, billing SaaS, super admin. `[isi: owner — jumlah tenant/outlet live]`

**Sedang/akan datang (indikatif):**
- Pengayaan loyalty (tier otomatis dari belanja, poin expiry, bonus ultah).
- Pendalaman aplikasi native + rilis store (kini thin-shell, hold sampai PWA feature-complete).
- QRIS & WhatsApp Business API (saat ini WA via link).
- `[isi: owner — prioritas roadmap, target rilis]`

---

## 10. Glosarium

- **Tenant** — satu pelanggan SaaS (pemilik bisnis laundry).
- **Outlet** — satu cabang fisik milik tenant.
- **HQ** — tampilan konsolidasi pemilik atas seluruh outlet-nya.
- **Coin** — unit pemakaian fitur AI yang dibeli tenant.
- **Tier Express** — level kecepatan layanan (mis. reguler, kilat) dengan biaya tambahan.
- **SAK EMKM** — standar akuntansi UMKM Indonesia (dipakai laporan laba/rugi).

---

*Dokumen hidup — perbarui saat fitur/metrik berubah. Sumber kebenaran teknis: kode di repo + spec/plan di `docs/superpowers/`.*
