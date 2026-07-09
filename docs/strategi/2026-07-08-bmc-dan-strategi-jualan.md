# BMC & Strategi Jualan — LAMASY

**Tanggal:** 8 Juli 2026 · **Status:** Living document
**Sumber:** data internal (pricing live, coin_ledger), [strategi-kompetitif](2026-07-04-strategi-kompetitif.md), [sales-kit](../jualan/sales-kit.md)

> **Satu kalimat:** Produk & harga sudah menang head-to-head vs Smartlink. Yang belum ada = antrean orang yang mencoba. Semua energi ke **distribusi**, bukan fitur baru.

---

## BAGIAN 1 — Business Model Canvas

### 1. Customer Segments (untuk siapa)
- **Primer:** owner laundry kiloan/satuan **1–3 outlet** yang masih manual (buku/Excel) atau pakai aplikasi lama yang mahal/ribet. Segmen "naik kelas dari manual → digital".
- **Sekunder:** chain laundry multi-outlet (butuh HQ, laporan konsolidasi, kontrol coin per-outlet).
- **Niche pembeda:** owner yang butuh **laporan keuangan formal** (SAK EMKM) untuk ajukan KUR/pinjaman bank — hampir tak ada kompetitor yang punya ini.
- **BUKAN target (sekarang):** laundry self-service IoT/koin (kandang Smartlink — butuh hardware & support lapangan).

### 2. Value Propositions (kenapa pilih kami)
1. **AI-native** — briefing performa harian, chat data bisnis, import Excel otomatis, voice order, scan struk. Kompetitor tak punya AI.
2. **Laporan keuangan standar akuntansi** (Laba Rugi, Neraca, Arus Kas, Buku Besar SAK EMKM) — bukan sekadar rekap omset.
3. **Bayar per pakai, tanpa langganan bulanan** — 1 nota Rp 50 (½ harga Smartlink), sepi = biaya turun sendiri.
4. **QRIS 0% potongan** — pakai QRIS milik owner, uang langsung ke rekeningnya (Smartlink potong 1% via gateway).
5. **Pindah gampang** — AI Migration: upload Excel export aplikasi lama → data pelanggan & transaksi masuk ±1 jam. Friksi pindah ≈ nol.
6. **Lengkap** — POS, antar-jemput+kurir, portal pelanggan+QR, loyalty/poin/referral, payroll, piutang B2B, inventori, absensi selfie+GPS, produksi 6-tahap.

### 3. Channels (cara menjangkau)
- **Komunitas owner laundry** — grup Facebook & WhatsApp (kanal utama, gratis).
- **Konten edukasi** — "hitung untung laundry" → tarik ke fitur laporan SAK EMKM.
- **Program affiliate** — komisi Rp 100rb/aktivasi (sudah live di sistem, tarik reseller/supplier/teknisi mesin).
- **Cold outreach WA/DM** — 10 chat/hari ke owner laundry (script di sales-kit).
- **Demo self-serve** — `lamasy.harpy.id/demo` (tanpa daftar, klik langsung terasa).
- **(Nanti)** Play Store setelah trial mulai mengalir.

### 4. Customer Relationships (cara jaga)
- **Self-service** — onboarding H1 (wizard paksa), tur sistem otomatis, demo.
- **Nurturing otomatis** — email H1/H5/H7 (loss-aversion), grace, suspend (cron trial_lifecycle).
- **CS personal via WA** — respon langsung owner (keunggulan kecil-tapi-intim vs kompetitor besar).
- **AI sebagai retensi** — briefing harian bikin owner buka app tiap pagi (stickiness).

### 5. Revenue Streams (dari mana uang)
| Sumber | Nominal | Sifat |
|---|---|---|
| **Aktivasi outlet** | Rp 800rb promo (normal 1jt) | Sekali/outlet — **revenue nyata utama** |
| Coin pemakaian | Rp 1/coin · 50/nota · +100 WA | Recurring kecil (umpan, bukan mesin revenue) |
| Top-up coin | bonus s.d. +15% | Recurring |
| (Parkir) paket/kuota bulanan | — | Premature di 1 tenant |

**Realita jujur:** 300 order/bln ≈ Rp 45rb coin/bln. Coin = **umpan akuisisi**, bukan penghasil. Uang riil = **aktivasi outlet**. Maka: makin banyak outlet aktivasi = makin sehat. Jangan naikkan harga coin.

### 6. Key Resources (aset kunci)
- **Kode & fitur** — 1 developer, produk sudah matang (11 fitur AI, laporan formal).
- **Infrastruktur AI** — akses Claude API (moat teknis vs kompetitor non-AI).
- **Brand keluarga Harpy** — kredibilitas + welcome kit fisik.
- **Demo tenant** — panggung jualan hidup.

### 7. Key Activities (kegiatan inti)
- **P0: Distribusi** — cold outreach, konten grup, rekrut affiliate. **Ukur: trial signup/minggu.**
- Maintain uptime + AI cost efficiency.
- Support & onboarding tenant baru.
- **BUKAN:** bikin fitur baru terus (produk sudah cukup; scope creep = musuh).

### 8. Key Partnerships (mitra)
- **Affiliate** — reseller, supplier laundry, teknisi mesin (jaringan ke owner).
- **Midtrans** — payment gateway untuk terima aktivasi (⚠️ masih sandbox — gerbang revenue belum buka).
- **Hostinger** — hosting + cron.
- **Google/Claude** — AI + Places API (leads).

### 9. Cost Structure (biaya)
| Biaya | Sifat |
|---|---|
| **AI API** (Claude) | Variabel per pemakaian — margin dijaga di AI Usage & Margin |
| Hosting (Hostinger) | Tetap kecil |
| Email (deliverability harpy.id) | Tetap kecil |
| Welcome kit fisik | Variabel per aktivasi |
| Komisi affiliate | Rp 100rb per aktivasi (variabel, hanya saat menghasilkan) |
| Waktu developer (1 orang) | Biaya terbesar tak-terlihat — lindungi dengan fokus |

**Insight cost:** struktur biaya rendah & sebagian besar variabel (bayar saat ada transaksi/aktivasi). Break-even bukan soal biaya, tapi soal **volume tenant**.

---

## BAGIAN 2 — Strategi Jualan (eksekusi)

### Prinsip
- **Bottleneck = distribusi, bukan produk.** Ukur signup/minggu, bukan fitur/minggu.
- **Jangan sebut Smartlink** di publik (kolom kompetitor generik) — hindari perang terbuka selagi kecil.
- **Pesan harga akurat:** "biaya per transaksi ½ + tanpa langganan + QRIS 0%". BUKAN "kompetitor langganan bulanan" (salah untuk Smartlink).

### Funnel & target
```
Chat/konten  →  Klik demo  →  Daftar trial  →  Aktivasi bayar (Rp 800rb)
   (kanal)       (bukti)       (14hr+30rb coin)    (REVENUE)
```
- **Metrik utara:** jumlah trial signup/minggu. Target awal: **trial pertama minggu ini**, lalu >10/bulan.
- **Metrik uang:** aktivasi berbayar pertama.

### Ritme mingguan (untuk 1 orang)
- **Senin–Jumat:** 10 chat WA cold/hari (script sales-kit §2) + balas komen grup.
- **2×/minggu:** 1 post grup FB (selang-seling versi cerita / versi harga; grup berbeda).
- **Sabtu:** follow-up H+2 semua yang belum balas.
- **Ukur:** SA → Registrasi & Leads (berapa chat → klik demo → daftar).

### 3 senjata perpindahan (untuk owner yang sudah pakai app lain)
1. **AI Migration** — "pindah dari app lama dalam 1 jam, data ikut." Kompetitor non-AI tak bisa membalas.
2. **QRIS 0%** — "uang langsung ke rekeningmu, tak dipotong 1%."
3. **AI briefing + laporan SAK EMKM** — dua hal yang tak dimiliki kompetitor.

### Aset yang sudah siap
- Sales kit (pitch 30 detik, script WA, post FB, objection handling, cheat-sheet angka).
- Carousel FB 6 slide + caption (`~/Desktop/lamasy-marketing/carousel/`).
- Demo tenant hidup (`lamasy.harpy.id/demo`).
- 27 leads OSM + generator Places API (siap tembak).

### Blokir yang harus dibuka SEBELUM scaling (urutan)
1. **🔴 Midtrans production** — masih sandbox → pembeli tak bisa bayar aktivasi. **Gerbang revenue #1.**
2. **🟡 Cron trial_lifecycle** — pastikan jalan (nurturing = mesin konversi trial).
3. **🟢 Rotasi secret** yang sempat terekspos.

### Yang JANGAN dikerjakan (fokus)
- IoT/hardware, QRIS gateway (lawan filosofi 0%), perang durasi trial, paket bulanan, fitur baru non-esensial. Semua = pengalih dari distribusi.

### Review berikutnya
Revisit saat: signup >10/bln, atau mau kerjakan WA Auto Sender, atau Smartlink ubah harga/luncurkan AI.
