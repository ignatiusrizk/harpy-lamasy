# Biaya Lainnya — Design Spec

## Goal

Beri owner cara menambahkan 1 biaya tambahan bebas (label + nominal, owner
tulis sendiri apa saja) per order — terpisah dari `biaya_tambahan` (biaya
express/antar-jemput yang sudah ada dan dihitung otomatis dari tier). Bisa
diisi saat order dibuat di POS, atau ditambah/diubah belakangan lewat
halaman Orders.

## Cakupan (disepakati saat brainstorming)

- **1 baris per order** — cukup 1 label + 1 nominal, bukan daftar/list biaya.
- **Bisa diisi di 2 tempat**: POS (saat buat order baru) DAN Orders (edit
  order yang sudah ada).
- Label bebas ketik apa saja (mis. "Biaya Packing Kardus", "Parkir",
  "Cuci Karpet Extra") — TIDAK ada daftar preset yang dikonfigurasi dulu.

## Bugfix yang dibundel (ditemukan saat eksplorasi, disetujui user)

Saat order diedit lewat halaman Orders dan item-nya berubah, total dihitung
ulang sbg `subtotal − diskon` SAJA — **`biaya_tambahan` (express fee) ikut
hilang dari total**, baik di preview client (`recalcEdit()`) maupun
perhitungan final server (`orders.php` action `update`). Ini pre-existing,
tidak terkait fitur ini, tapi dibetulkan sekalian karena baris kode yang
sama persis harus disentuh untuk menambahkan `biaya_lainnya` ke rumus
total — meninggalkannya rusak akan bikin perilaku baru & lama tidak
konsisten (Biaya Lainnya ke-hitung, Biaya Express malah hilang).

Fix: `biaya_tambahan` milik order (snapshot dari saat order dibuat — Orders
TIDAK mengelola ulang express tier, jadi nilainya dipertahankan apa adanya,
bukan dihitung ulang dari item) ikut ditambahkan kembali ke rumus total di
`orders.php`, di sisi server MAUPUN di preview client `recalcEdit()`.

## Skema Data

2 kolom baru di `hl_transaksi`:

```sql
ALTER TABLE hl_transaksi
  ADD COLUMN biaya_lainnya DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER biaya_tambahan,
  ADD COLUMN biaya_lainnya_label VARCHAR(100) NULL DEFAULT NULL AFTER biaya_lainnya;
```

`biaya_lainnya` selalu numerik (default 0 = tidak ada biaya lain).
`biaya_lainnya_label` nullable — kalau nilainya >0 tapi label kosong, semua
tempat yang menampilkan WAJIB fallback ke teks "Biaya Lainnya" generik.

## Rumus Total (di SEMUA tempat yang menghitung total order)

```
total = max(0, subtotal − diskon − redeem_poin − member_diskon
                + biaya_tambahan + biaya_lainnya)
```

`biaya_lainnya` diperlakukan PERSIS sejajar `biaya_tambahan` dalam rumus —
sama-sama komponen PENAMBAH total, ditambahkan setelah semua pengurang.

## Perubahan per File

### 1. Migrasi DB
`migrations/2026-08-28-biaya-lainnya.sql` — 2 kolom di atas.

### 2. POS — buat order baru (`pos.php`)
- Server (action `save`): tambah pembacaan `biaya_lainnya` (`floatval`,
  clamp `>=0`, TIDAK di-recompute/anti-tamper server-side seperti
  `biaya_tambahan` — ini memang input manual bebas, sama kelasnya dgn
  `diskon`) dan `biaya_lainnya_label` (`substr(trim(strip_tags(...)),0,100)`).
  Masuk ke rumus `$total` yang sudah ada (baris `subtotal - diskonTotal +
  biayaTbh` → tambah `+ $biayaLainnya`). Ikut disimpan ke kolom baru
  memakai pola defensive-check yang sudah dipakai kolom opsional lain
  (`parfum`, `foto_masuk`, dst) di fungsi ini.
- Form: 2 input baru (teks label + angka nominal) — DITEMPATKAN sbg section
  baru terpisah, bukan di dalam box "Biaya Tambahan" yang sudah ada (box itu
  read-only/auto-derive dari tier, TIDAK boleh dicampur dgn field manual
  yang bisa owner ketik bebas — akan membingungkan mana yang auto mana yang
  manual).
- JS `recalc()`: tambah `biayaLainnya` ke rumus total (baris `total =
  subtotal - diskon - redeemValue + biayaTbh` → tambah `+ biayaLainnya`).
- JS payload `submitCreate` (nama actual: variabel `payload` sebelum
  `fetch('pos.php?action=save')`): tambah 2 field baru.

### 3. Orders — edit order yang sudah ada (`orders.php`)
- Query awal (`$oldRow`, action `update`): tambah `biaya_tambahan,
  biaya_lainnya, biaya_lainnya_label` ke SELECT — dipakai utk preserve
  `biaya_tambahan` (snapshot, tidak diedit di sini) dan sbg fallback kalau
  request TIDAK mengirim `biaya_lainnya` (mis. panggilan lain yg cuma ubah
  status, JANGAN sampai nilai lama ke-reset ke 0 kalau field ini gak
  dikirim).
- Rumus total server: masukkan `biaya_tambahan` (dari `$oldRow`, preserved
  apa adanya) DAN `biaya_lainnya` (dari `$data`, fallback ke `$oldRow`
  kalau tak dikirim) — bugfix + fitur baru dalam 1 perubahan rumus.
- `UPDATE hl_transaksi SET ...`: tambah `biaya_lainnya=?,
  biaya_lainnya_label=?` ke `$setParts`/`$params`.
- Form edit modal: baris baru "Biaya Lainnya" (2 input: teks label kecil +
  angka nominal), pola sama dgn baris "Diskon"/"DP/Bayar" yang sudah ada
  (gated `CAN_EDIT_ORDER` — read-only span kalau user tak punya izin edit).
- JS `recalcEdit()`: tambah `biaya_tambahan` (dari data order yang sedang
  dibuka, TIDAK diedit di modal ini) DAN `biaya_lainnya` ke rumus total
  preview — bugfix + fitur baru.
- JS payload `saveEdit()`: tambah `biaya_lainnya`, `biaya_lainnya_label`.

### 4. Struk (`core/StrukGenerator.php`)
Baris baru muncul di KEDUA renderer (`renderThermal` — retail thermal,
DAN `renderPdf` — invoice B2B, supaya konsisten, mengikuti pola
`biaya_tambahan` yang juga muncul di keduanya), tepat setelah baris biaya
tambahan yang sudah ada:

```
if biaya_lainnya > 0:
    label = biaya_lainnya_label (trim) atau "Biaya Lainnya" kalau kosong
    tampilkan baris: label | +Rp {biaya_lainnya}
```

`$hasBreakdown`/`$hasBreakdownPdf` (flag yang menentukan apakah baris
Subtotal ikut ditampilkan) ditambah kondisi `|| $biayaLainnya > 0`.

### 5. Export CSV (`hq/export.php`)
Tambah kolom `biaya_lainnya` ke daftar kolom yang sudah ada (baris yang
sudah menyertakan `biaya_tambahan`) — supaya laporan ekspor tetap lengkap.

## Yang TIDAK berubah

- Dashboard/laporan keuangan lain TIDAK disentuh — semua sudah pakai kolom
  `total` yang otomatis mencakup `biaya_lainnya` begitu masuk rumus di atas.
- `biaya_tambahan` (express) TETAP dihitung otomatis dari tier seperti
  sekarang — fitur ini tidak mengubah cara dia dihitung di POS saat create,
  hanya memastikan dia TIDAK HILANG lagi saat order di-edit.
- Tidak ada daftar preset/kategori biaya yang dikonfigurasi di settings.
