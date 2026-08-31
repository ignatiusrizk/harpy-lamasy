# Biaya Lainnya sebagai Master Data (Tier) — Design Spec

## Goal

Ganti model "Biaya Lainnya" dari free-text manual (dibuat sebelumnya, sudah
live tapi belum pernah dipakai order nyata) jadi **master data yang
dikonfigurasi sekali di settings, lalu otomatis diterapkan ke SEMUA order**
di POS — tanpa perlu interaksi apa pun dari kasir saat input order.

## Keputusan dari brainstorming

1. **Tidak ada opsi ketik manual** — biaya lain WAJIB berasal dari daftar
   master yang sudah dikonfigurasi (beda dari rencana awal yang masih
   sempat mengizinkan override manual).
2. **Nilai per Jenis Biaya: Flat (Rp) ATAU Persen** (dari subtotal order) —
   sama seperti Tier Express.
3. **Otomatis, bukan pilihan kasir** — Jenis Biaya yang statusnya Aktif di
   setup **langsung ikut diterapkan ke SETIAP order** begitu order dibuat
   di POS. Tidak ada dropdown/checklist apa pun di layar POS.
4. **Boleh lebih dari 1 Aktif sekaligus, dijumlah** — kalau owner
   mengaktifkan lebih dari 1 Jenis Biaya, SEMUA yang aktif kena ke tiap
   order, masing-masing tampil sebagai baris terpisah di struk.
5. **Murni otomatis di Orders juga — tidak bisa diutak-atik per-order.**
   Snapshot yang ter-generate otomatis saat order dibuat bersifat permanen;
   tidak ada cara hapus/tambah baris individual di halaman Orders. Ini
   konsisten dengan Biaya Express yang juga tidak bisa diedit manual di
   Orders (murni snapshot dari saat pembuatan).

Konsekuensi dari keputusan #4: constraint lama "1 baris per order" (dari
desain free-text sebelumnya) **sudah tidak berlaku** — sekarang bisa
banyak baris per order, sehingga butuh tabel detail baru (lihat Skema
Data).

## Skema Data

### Tabel master baru: `hl_biaya_lainnya_tier`

Pola identik `hl_express_tier`, tanpa `estimasi_jam` (konsep khusus
express, tidak relevan di sini):

```sql
CREATE TABLE hl_biaya_lainnya_tier (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NULL,               -- NULL = berlaku semua outlet (global)
  nama VARCHAR(50) NOT NULL,
  tipe_biaya ENUM('flat','percent') NOT NULL DEFAULT 'flat',
  nilai_biaya DECIMAL(12,2) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  urutan INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_active (tenant_id, is_active)
);
```

### Tabel detail baru: `hl_transaksi_biaya_lainnya`

Snapshot PER BARIS per order — dibuat sekali saat order dibuat, tidak
pernah diubah lagi sesudahnya (matching prinsip snapshot `biaya_tambahan`).

```sql
CREATE TABLE hl_transaksi_biaya_lainnya (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  transaksi_id INT NOT NULL,
  nama VARCHAR(50) NOT NULL,        -- snapshot nama tier saat itu
  nominal DECIMAL(12,2) NOT NULL,   -- snapshot hasil hitung (flat langsung / persen sudah dihitung)
  INDEX idx_transaksi (transaksi_id)
);
```

### Kolom `hl_transaksi` yang sudah ada — perlakuan

- **`biaya_lainnya`** (DECIMAL) — TETAP DIPAKAI, sekarang berarti **total
  SUM semua baris** aktif yang diterapkan ke order itu (rollup), pola sama
  seperti `subtotal` adalah rollup dari `hl_transaksi_item`. Rumus total
  di `pos.php`/`orders.php`/struk **TIDAK BERUBAH SAMA SEKALI** — semua
  sudah pakai `biaya_lainnya` sbg satu angka.
- **`biaya_lainnya_label`** (VARCHAR) — **DIHAPUS** (migrasi `DROP
  COLUMN`). Sudah tidak relevan karena bisa lebih dari 1 label sekarang;
  detail nama per baris pindah ke `hl_transaksi_biaya_lainnya.nama`. Kolom
  ini belum pernah terisi data nyata (fitur sebelumnya belum dipakai order
  sungguhan), aman dihapus tanpa migrasi data.

## Komponen Baru: `core/BiayaLainnyaTier.php`

Class baru, pola identik `core/ExpressTier.php` tapi dihitung dari
**subtotal order** (bukan per-item, karena ini biaya level-order, bukan
per-baris-layanan):

```php
class BiayaLainnyaTier
{
    // Ambil semua tier aktif utk tenant+outlet (global outlet_id=NULL + override outlet spesifik)
    public static function activeForTenant(int $tenantId, ?int $outletId = null): array;

    // Hitung fee 1 tier: flat → nilai_biaya langsung, percent → round(subtotal * nilai_biaya/100)
    public static function calcFee(array $tier, float $subtotal): float;

    // Hitung SEMUA tier aktif sekaligus → [['nama'=>.., 'nominal'=>..], ...]
    public static function calcAppliedFees(int $tenantId, ?int $outletId, float $subtotal): array;
}
```

`calcAppliedFees()` inilah yang dipakai di SERVER SIDE `pos.php` saat
create order (anti-tamper — TIDAK dipercaya dari input klien, sama seperti
`biaya_tambahan`/Express Tier).

## Perubahan per File

### 1. `layanan.php` — CRUD "Jenis Biaya Lainnya"

Section baru, letaknya bersebelahan dengan section "Tier Express" yang
sudah ada (owner sudah biasa cari konfigurasi biaya tambahan di halaman
ini). 3 action baru, pola identik `tier_list`/`tier_save`/`tier_delete`:
- `biaya_lainnya_list` — list semua tier tenant ini
- `biaya_lainnya_save` — create/update (validasi: nama wajib, nilai >= 0,
  tipe_biaya in [flat,percent])
- `biaya_lainnya_delete`

UI: tabel list (Nama, Tipe, Nilai, status Aktif via toggle, tombol
Edit/Hapus) + modal tambah/edit — reuse pola HTML/JS Tier Express (cuma
tanpa field Estimasi Jam).

### 2. `pos.php` — hapus input manual, terapkan otomatis

- **Hapus** section HTML "Biaya Lainnya (opsional)" (2 input label+nominal
  yang sudah ada) dari form.
- **Tambah** box read-only baru (mirip box "Biaya Tambahan" yang sudah
  ada) menampilkan breakdown Jenis Biaya yang otomatis kena ke order ini
  — kasir cuma LIHAT, tidak ada interaksi:
  ```
  💰 Biaya Lainnya (otomatis):
     Biaya Admin        Rp 2.000
     PPN 2%              Rp 600
  ```
  Muncul hanya kalau ada tier aktif dgn hasil hitung > 0; tersembunyi
  kalau tidak ada tier aktif sama sekali.
- **JS**: saat halaman load, fetch tier aktif (endpoint baru, pola sama
  `action=express_tiers`) sekali di awal. Di `recalc()`, setiap kali
  `subtotal` berubah, hitung ulang breakdown (flat tetap, persen ikut
  berubah proporsional ke subtotal terbaru) — persis pola `biayaTbh`
  sudah ada, ditambah loop utk multi-tier.
- **Server (action `save`)**: hapus pembacaan `$data['biaya_lainnya']`/
  `$data['biaya_lainnya_label']` dari client. Ganti dengan
  `BiayaLainnyaTier::calcAppliedFees($tid, $oid, $subtotal)` — hasilnya
  di-`array_sum` utk isi kolom `biaya_lainnya` (rollup), dan setiap
  barisnya di-INSERT ke `hl_transaksi_biaya_lainnya`.

### 3. `orders.php` — REVERT ke read-only (batalkan Task 3 versi lama)

- **Hapus** 2 input edit (`edit_biaya_lainnya`, `edit_biaya_lainnya_label`)
  dari modal edit — field ini SEKARANG TIDAK ADA yang bisa diedit sama
  sekali.
- **Hapus** dari `editStateJSON()` (field `bl`/`bll` yang baru ditambah
  fix terakhir — dibalik lagi krn sudah tidak ada input-nya).
- **Hapus** `$biayaLainnyaBerubah` dari kondisi `$berubah`/permission-gate
  (balik ke `$itemsChanged || $diskonBerubah` seperti semula SEBELUM fitur
  ini ada) — karena biaya lainnya tidak lagi bisa diubah dari form ini,
  tidak ada lagi yang perlu di-gate.
- **Hapus** logic baca/fallback `$biayaLainnya`/`$biayaLainnyaLabel` dari
  `$data` di action `update` — total tetap pakai `biaya_lainnya` LAMA dari
  `$oldRow` (snapshot, persis sama treatment-nya dengan `biaya_tambahan`
  yang sudah ada).
- **Tambah** tampilan read-only breakdown (query `hl_transaksi_biaya_lainnya`
  by `transaksi_id`, render list nama+nominal) di modal detail order —
  supaya owner/kasir tetap bisa LIHAT apa saja yang kena, walau tidak bisa
  ubah.

### 4. `core/StrukGenerator.php` — render breakdown multi-baris

- `generate()`: tambah query `SELECT nama, nominal FROM
  hl_transaksi_biaya_lainnya WHERE transaksi_id=? ORDER BY id`, kirim
  sebagai parameter baru ke `render()`/`renderThermal()`/`renderPdf()`.
- `renderThermal()`/`renderPdf()`: ganti blok "1 baris biaya_lainnya
  dengan label" (dari implementasi sebelumnya) jadi LOOP render semua
  baris dari array biaya lainnya yg dipassing — tiap baris nama+nominal
  masing-masing, escaping tetap sama (`self::esc()`/`htmlspecialchars`).
- `$hasBreakdown` tetap: `|| !empty($biayaLainnyaRows)`.

### 5. `hq/export.php` — tidak berubah

Kolom `biaya_lainnya` (rollup) yang sudah ditambahkan sebelumnya tetap
valid & cukup utk export flat CSV. Kolom `biaya_lainnya_label` yang mau
dihapus memang sudah ada di SELECT-nya — **perlu dihapus dari situ juga**
(kolom fisiknya di-DROP di Task migrasi, SELECT yang masih menyebutnya
akan error).

## Migrasi

```sql
-- 1) Tabel master
CREATE TABLE hl_biaya_lainnya_tier (...);  -- lihat skema di atas

-- 2) Tabel detail snapshot per order
CREATE TABLE hl_transaksi_biaya_lainnya (...);  -- lihat skema di atas

-- 3) Bersihkan kolom lama yg sudah tidak relevan
ALTER TABLE hl_transaksi DROP COLUMN biaya_lainnya_label;
```

Kolom `hl_transaksi.biaya_lainnya` (rollup) TIDAK di-drop — tetap dipakai.

## Yang TIDAK termasuk scope

- Tidak ada opsi ad-hoc/manual override di POS (sudah diputuskan wajib
  dari daftar).
- Tidak ada kemampuan edit/waive biaya lainnya per-order di Orders (murni
  snapshot otomatis, sama seperti Biaya Express).
- Tidak ada breakdown per-baris di export CSV — cukup rollup (`biaya_lainnya`
  total), konsisten dgn bagaimana `biaya_tambahan` juga tidak di-breakdown
  di export.
- Riwayat order LAMA yang pernah pakai `biaya_lainnya_label` (dari fitur
  free-text sebelumnya) — TIDAK ADA, karena belum pernah dipakai order
  nyata (data test yang sempat dibuat sudah dihapus). Tidak perlu migrasi
  data.
