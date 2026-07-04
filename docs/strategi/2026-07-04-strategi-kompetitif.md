# Strategi Kompetitif LAMASY — vs Smartlink & Pasar Aplikasi Laundry

**Tanggal:** 4 Juli 2026 · **Status:** Living document · **Sumber:** scrape smartlink.id (homepage, produk, biaya, S&K), `docs/riset/ilaundry-fitur.md`, data internal (`saas_coin_pricing`, `coin_ledger`, `saas_coin_bundles`, `saas_billing_config`).

> **Satu kalimat:** Produk & harga LAMASY sudah lebih baik dari cukup untuk menang head-to-head — yang belum ada adalah **antrean orang yang mencobanya**. Prioritas = distribusi, lalu bereskan janji WA/QRIS sebelum antrean datang.

---

## 1. Peta Medan

### Smartlink (kompetitor utama — smartlink.id)
- **Ekosistem 5 aplikasi:** Owner/Manager, Kasir, Kurir, Operasional, **Konsumen** (customer app).
- **IoT Snapbridge:** nyalakan/batasi mesin dari aplikasi (self-service & kontrol pemakaian).
- **Pembayaran:** QRIS terintegrasi (**fee 1%/transaksi**), EDC, tunai, "Ambil Tanpa Pelunasan".
- **WA:** Auto Sender via **device sendiri** (aktivasi berbayar), nota text/PDF manual, blast.
- **Lain:** payroll+komisi otomatis, faktur massal (hotel), inventori, nomor nota premium, akses SPV.
- **Model uang:** aktivasi **Rp 1.250.000**/outlet permanen (±PPN 11%) + koin **Rp 1** + **100 koin/nota** + 1% QRIS + add-on. **Bukan langganan bulanan.**
- **Trial:** 30 hari + 20.000 koin, tanpa syarat.
- **Moat sebenarnya:** umur (±2016), brand "No.1", ekosistem hardware/IoT, jaringan distribusi.
- Harga add-on (WA/QRIS device/nomor premium/faktur massal) **tidak dipublikasikan** di situs; S&K publik tak menjelaskan expiry koin/refund.

### iLaundry (benchmark sekunder — detail di `docs/riset/ilaundry-fitur.md`)
- i-laundry.com: POS dry-clean klasik, berat hardware (RFID, biometrik, garment photos, rail position).
- CodeCanyon: SaaS multi-branch, mirip LAMASY minus AI.

### Posisi LAMASY (per 4 Jul 2026)
- **Menang fitur:** AI 11 fitur (briefing, chat data, migration, voice order, scan struk, upselling, churn), laporan **SAK EMKM formal** (L/R, Neraca, Arus Kas, Buku Besar), antar-jemput + kurir, portal pelanggan + QR tracking, loyalty/referral/member tier, piutang B2B, payroll + bonus rule, produksi 6-stage, checklist, inventori + transfer antar-outlet.
- **Menang harga:** lihat §2.
- **Kalah/celah:** IoT (tidak ada), QRIS di POS (di-hold), WA auto-send (belum ada gateway — wa.me manual), customer app native (portal web ada), umur brand.
- **Fakta terkeras (internal):** 1 tenant nyata; pemakaian coin sepanjang masa ±1.210 (mayoritas QA). **Produk bukan bottleneck — distribusi bottleneck-nya.**

---

## 2. Perbandingan Harga (post-adjustment 4 Jul 2026)

| Komponen | **LAMASY** | Smartlink | Posisi |
|---|---:|---:|---|
| Nilai koin | Rp 1 | Rp 1 | Seri |
| Buat nota/transaksi | **50 coin** | 100 koin | Menang 2× |
| Kirim nota via WA (PDF) | **100 coin** (nota+WA = 150) | 100 koin (nota+WA = 200) | Menang |
| QRIS | belum ada | 1%/trx | Celah (lihat §4) |
| Aktivasi/outlet | **Rp 800rb** (promo; normal 1jt) | Rp 1,25jt | Menang |
| Bonus topup | s.d. **+15%** (Rp500rb→575rb) | — | Menang |
| Trial | 14 hari + **30.000 coin** | **30 hari** + 20.000 koin | Durasi kalah (sadar), coin menang |
| Print struk | Gratis | Gratis | Seri |
| Langganan bulanan | Tidak ada | Tidak ada | Seri |

**Keputusan pricing 4 Jul 2026** (semua live + audit di `saas_coin_pricing_history`):
1. Trial 7→**14 hari**, trial coin 10rb→**30.000** (add-outlet.php + TenantProvisioner; nurture/banner di-anchor ke *sisa hari*).
2. `send_wa_nota` 150→**100** (jalur nota+WA menang telak).
3. Landing direposisi: **JANGAN** klaim "kompetitor langganan bulanan" (Smartlink juga coin) → angle: *biaya per transaksi hemat + AI included*.
4. Ekonomi coin = **umpan akuisisi**, bukan mesin revenue (300 order/bln ≈ Rp45rb). Revenue nyata = aktivasi 800rb + (nanti) QRIS/WA. Jangan naikkan harga coin; paket/kuota bulanan tetap **diparkir**.

---

## 3. Strategi Utama — Urutan Prioritas

### P0 — Distribusi (mulai sekarang)
Masalah #1: tidak ada trial masuk. Seluruh mesin konversi (onboarding H1, AI boost, nurturing, stickiness, wizard layanan, import) sudah live dan menganggur.
- **Kanal:** komunitas owner laundry (grup FB/WA), konten "hitung untung laundry" → tarik ke laporan SAK EMKM, program **affiliate** (sudah ada di sistem, komisi Rp100rb).
- **Senjata perpindahan:** **AI Migration Mapper** — pitch: *"Pindah dari Smartlink dalam 1 jam, data pelanggan & transaksi ikut."* Smartlink tak bisa membalas ini (tak punya AI).
- **Ukuran sukses:** trial signup/minggu — bukan fitur baru/minggu.

### P1 — Bereskan "janji tanpa dapur" (sebelum ramai)
- **WA Gateway.** 6 fitur WA berbayar tapi kirim otomatis belum ada (wa.me manual). Risiko kepercayaan saat tenant nyata bayar coin dan sadar itu manual. Pilihan: (a) bangun gateway (juga engine retensi Smartlink), atau (b) label jujur "via WA kamu" + gratiskan yang manual. **Putuskan sebelum kampanye akuisisi.**
- **QRIS di POS.** 1% Smartlink = mesin uang + lock-in yang tumbuh mengikuti omzet merchant. Kita sudah pegang Midtrans (billing). Aktifkan saat tenant mulai puluhan → prioritas monetisasi #1, jauh di atas coin per-fitur.

### P2 — Quick win produk (effort kecil, dari benchmark)
- ✅ Auto-reminder cucian belum diambil (LIVE 4 Jul — kartu "Belum Diambil" + WA + ambang per-outlet).
- ☐ Foto kondisi item saat intake POS (anti-sengketa; reuse infra foto produksi/kas).
- ☐ Posisi rak order siap (1 kolom; kasir cepat menemukan).
- ☐ Disclaimer/surat pernyataan di struk (perlindungan hukum; 1 field config).

### JANGAN dikerjakan
- **IoT/Snapbridge-style** — kandang Smartlink (hardware, distribusi fisik, support lapangan). `mesin.php` + QR self-service cukup untuk segmen kecil. Menang di software & AI, bukan kabel.
- **Perang durasi trial** (ikut 30 hari) — 14 hari + 30rb coin + AI gratis sudah kompetitif; loss-aversion kita justru butuh deadline terasa.
- RFID, sinkron akuntansi eksternal, multi-bahasa — tunda sampai ada permintaan nyata.
- Paket/kuota bulanan — tetap parkir (premature di 1 tenant).

---

## 4. Positioning & Pesan

- **Headline positioning:** *ERP laundry AI-native dengan laporan keuangan standar akuntansi (SAK EMKM)* — dua hal yang Smartlink & iLaundry sama-sama tidak punya.
- **Pesan harga:** "biaya per transaksi setengah kompetitor + tanpa langganan" (akurat, sudah di landing). Bukan "mereka langganan bulanan" (salah utk Smartlink).
- **Pesan perpindahan:** import AI dari Excel/Smartlink — friksi pindah ≈ nol.
- Nama Smartlink **tidak disebut** di landing (kolom kompetitor tetap generik) — hindari perang terbuka selagi kecil.

## 5. Risiko & PR Operasional
- Cron `trial_lifecycle.php` di hPanel **belum diverifikasi jalan** — seluruh nurturing/stickiness bergantung padanya.
- Rotasi secret yang sempat terekspos di sesi (DB pass, SA basic auth, API key) — belum dilakukan.
- Single developer vs kompetitor 10 tahun — pilih pertempuran (fokus P0/P1, tolak scope creep).
- Deliverability email (SPF/DKIM harpy.id) — welcome/nurture sempat delay; pantau.

## 6. Review Berikutnya
Revisit dokumen ini saat: (a) trial signup > 10/bulan, (b) keputusan WA gateway diambil, atau (c) Smartlink mengubah harga/meluncurkan AI.
