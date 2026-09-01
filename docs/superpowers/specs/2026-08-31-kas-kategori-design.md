# Kelola Kategori Kas — Design Spec

## Goal

Kategori transaksi Kas (Masuk/Keluar) saat ini hardcode di `kas.php` — 4
kategori Masuk + 8 kategori Keluar, ditulis langsung di HTML `<select>` dan
array JS `KAT`, tidak ada cara menambah/mengubah/menghapus lewat UI. Fitur
ini bikin kategori jadi data yang dikelola owner (CRUD), tanpa mengubah
perilaku existing (dropdown tetap tampil sama persis begitu fitur ini live).

## Keputusan dari brainstorming

1. **Kategori itu teks bebas (snapshot), bukan referensi hidup** — mengedit
   nama kategori di master **TIDAK** mengubah histori transaksi Kas lama
   yang sudah pakai nama itu. Menghapus kategori juga aman — tidak ada
   foreign key, `hl_kas.kategori` cuma kolom teks, transaksi lama tetap
   simpan apa adanya.
2. **Tenant-wide, bukan per-outlet** — 1 daftar kategori berlaku sama untuk
   semua outlet tenant (seperti chart-of-account bisnis), BEDA dari pola
   Tier Express/Biaya Lainnya yang punya opsi override per-outlet. Tabel
   baru TIDAK punya kolom `outlet_id`.
3. **Dikelola langsung di halaman Kas** (bukan Layanan) — tombol "⚙️ Kelola
   Kategori" di dekat form tambah transaksi.
4. **Migrasi seed otomatis** — 12 kategori existing (4 Masuk + 8 Keluar,
   dengan emoji masing-masing yang sudah ada di kode) di-insert ke tabel
   baru untuk SEMUA tenant yang sudah ada, supaya dropdown tidak berubah
   sama sekali begitu fitur ini live — user tinggal lanjut edit dari sana.
5. **Pemisahan tipe Masuk/Keluar tetap dipertahankan** — kategori Masuk
   tidak bisa dipakai di entri Keluar (dan sebaliknya), validasi sekarang
   terhadap tabel baru (JOIN tipe), bukan array hardcode.

## Skema Data

```sql
CREATE TABLE hl_kas_kategori (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(50) NOT NULL,
  tipe ENUM('masuk','keluar') NOT NULL,
  emoji VARCHAR(10) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  urutan INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_tipe_active (tenant_id, tipe, is_active)
);
```

Tidak ada `outlet_id` sama sekali (beda dari `hl_express_tier`/
`hl_biaya_lainnya_tier`) — sesuai keputusan #2.

**Seed data** (dijalankan utk SETIAP `tenant_id` yang ada di tabel
`outlets` saat migrasi berjalan — saat ini 2 tenant: 18, 31):

| nama | tipe | emoji |
|---|---|---|
| Penjualan Laundry | masuk | 💰 |
| Pelunasan Order | masuk | 🧾 |
| Pendapatan Lain | masuk | ➕ |
| Modal | masuk | 🏦 |
| Gaji Karyawan | keluar | 👥 |
| Bahan & Deterjen | keluar | 🧴 |
| Listrik & Air | keluar | ⚡ |
| Sewa Tempat | keluar | 🏠 |
| Peralatan | keluar | 🔧 |
| Transportasi | keluar | 🛵 |
| Operasional | keluar | ⚙️ |
| Lain-lain | keluar | 📌 |

## Perubahan per File

### 1. `kas.php` — backend: 3 action baru + validasi dari tabel

- `kas_kategori_list` — list semua kategori tenant ini (kedua tipe
  sekaligus, FE yang pisahkan by `tipe`).
- `kas_kategori_save` — create/update. Validasi: nama wajib, `tipe` harus
  `masuk`/`keluar`. Permission: `hasPermission('kas.create')` — modul kas
  cuma punya 2 permission (`kas.create`, `kas.delete`), tidak ada
  `kas.edit` terpisah, jadi save (create maupun update) pakai `kas.create`
  sama seperti action `save` transaksi Kas yang sudah ada.
- `kas_kategori_delete` — hard delete (aman, tidak ada FK). Permission:
  `hasPermission('kas.delete')`.
- Action `save` (yang sudah ada, transaksi Kas) — ganti blok validasi:
  ```php
  $_katMasuk  = ['Penjualan Laundry','Pelunasan Order','Pendapatan Lain','Modal'];
  $_katKeluar = ['Gaji Karyawan','Bahan & Deterjen','Listrik & Air','Sewa Tempat','Peralatan','Transportasi','Operasional','Lain-lain'];
  if ((...) in_array(...)) { error }
  ```
  jadi query ke `hl_kas_kategori`: kalau kategori yang dikirim ADA di
  tabel tapi `tipe`-nya BEDA dari tipe transaksi → tolak. Kalau kategori
  TIDAK ADA di tabel sama sekali (kategori custom/legacy lama) → TETAP
  DITERIMA (persis komentar existing "nilai custom/legacy di luar dua
  daftar ini tetap diterima" — perilaku ini TIDAK BERUBAH).

### 2. `kas.php` — frontend: dropdown dinamis + modal kelola

- Array JS hardcode `const KAT = {...}` **dihapus**, diganti fetch sekali
  saat halaman load (`loadKasKategori()`, pola sama `loadGlobalTiers()` di
  `pos.php`) → isi variabel `KAT` dari hasil `kas_kategori_list` (dikelompokkan
  by `tipe` client-side).
- `<select id="f_kategori">` yang isinya hardcode `<option>` — opsinya
  di-generate dinamis dari hasil fetch yang sama (supaya `.value`/`.options`
  tetap valid sbg "sumber kebenaran" sesuai komentar existing di kode).
- Tombol baru "⚙️ Kelola Kategori" + modal CRUD (list + form tambah/edit +
  toggle aktif + hapus) — pola HTML/JS SAMA PERSIS dengan modal Tier
  Express di `layanan.php` (`#modalTier`/`loadTiers()`/`renderTierList()`/
  dst), disederhanakan (tanpa field estimasi jam, tanpa field
  tipe_biaya/nilai — cuma nama + tipe(masuk/keluar) + emoji + status
  aktif + urutan).
- Field emoji: input teks bebas (placeholder salah satu emoji umum),
  BUKAN emoji picker — biar simpel, owner tinggal copy-paste emoji favorit
  atau kosongkan (fallback tampilan tanpa emoji/emoji default 🏷️).

### 3. Migrasi

```sql
CREATE TABLE hl_kas_kategori (...);  -- lihat skema di atas
INSERT INTO hl_kas_kategori (tenant_id, nama, tipe, emoji, urutan)
SELECT o.tenant_id, k.nama, k.tipe, k.emoji, k.urutan
FROM (SELECT DISTINCT tenant_id FROM outlets) o
CROSS JOIN (
  -- 12 baris seed di atas, VALUES literal
) k;
```
(Detail exact SQL ditulis di plan implementasi.)

## Yang TIDAK termasuk scope

- Tidak ada opsi per-outlet — tenant-wide saja (keputusan #2).
- Tidak ada rename massal ke histori transaksi lama saat kategori diedit
  (keputusan #1) — beda sepenuhnya dari update, ini snapshot.
- `laporan.php` (grouping kategori di laporan) **tidak disentuh** — sudah
  otomatis bekerja karena cuma `GROUP BY kategori` (teks), tidak peduli
  sumbernya hardcode atau tabel.
- Endpoint lama `action=kategori` (`SELECT DISTINCT kategori FROM hl_kas`)
  di `kas.php` — dicek TIDAK ADA pemanggilnya di frontend (dead code),
  DIBIARKAN apa adanya (di luar scope, bukan bagian fitur ini).
