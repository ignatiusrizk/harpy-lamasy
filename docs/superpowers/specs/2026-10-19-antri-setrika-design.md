# Status "Antri Setrika" — Design

Tanggal: 2026-10-19

## Latar belakang

Setrika masih manual dan lebih lambat daripada pengering, sehingga cucian yang sudah kering
menumpuk di kolom Kering dan tidak terlihat mana yang sudah siap disetrika. Kita tambah satu
status antrean di antara Kering dan Setrika supaya antrean kelihatan dan bisa diprioritaskan.

Alur baru: `masuk → cuci → kering → antri_setrika → setrika → siap → diambil`.

## Tujuan

- Antrean setrika terlihat sebagai kolom tersendiri di Kanban.
- Order express dan yang deadline-nya dekat otomatis di atas.
- Pelanggan tidak melihat istilah internal baru.

## Di luar cakupan

- Scanner IoT di mesin (ditunda).
- Batas antrean yang bisa diatur per outlet (ambang header ditulis tetap di kode).
- Estimasi waktu tunggu otomatis.
- Memindahkan otomatis order yang sudah berstatus `kering` ke `antri_setrika`.

## Data

`hl_transaksi.status_proses` dan `hl_proses_log.status_lama/status_baru` memakai ENUM / kolom
status. Nilai baru: **`antri_setrika`**, ditempatkan di antara `kering` dan `setrika`.

Migrasi: `migrations/2026-10-19-antri-setrika.sql` (ALTER ENUM, sekali, berlaku semua tenant
karena DB bersama). `tenant/migrations/tenant_schema.sql` ikut diperbarui untuk tenant baru.

**Urutan rilis wajib: migrasi dijalankan DULU, baru kode di-push.** Jika terbalik, MySQL
menyimpan string kosong tanpa error (kasus bug `'b2b'` sebelumnya). Setelah migrasi,
verifikasi dengan `SHOW COLUMNS` sebelum push.

Order yang saat ini berstatus `kering` tidak diubah.

## Kanban (`kanban.php`)

- `$statuses` dan query kolom memuat `antri_setrika`; kolom baru "Antri Setrika" di antara
  Kering dan Setrika.
- `NEXT`: `kering → antri_setrika → setrika`. Daftar `$allowed` pada handler pindah status
  ditambah `antri_setrika`.
- Urutan kartu di kolom Antri Setrika:
  1. Order express dulu. Express = order punya minimal satu item dengan
     `express_tier_nama` terisi (diturunkan dari `hl_transaksi_item`, tanpa kolom baru).
     Sesama express diurutkan `estimasi_selesai` terdekat (fallback `created_at + estimasi_jam`,
     sama seperti `timerClass`).
  2. Order biasa di bawahnya, FIFO menurut waktu masuk antrean = `created_at` terbaru di
     `hl_proses_log` dengan `status_baru='antri_setrika'` untuk order itu.
- Kartu express diberi penanda ⚡.
- Header kolom menampilkan jumlah antrean; jika lebih dari 10, header diberi warna peringatan.
- Waktu log memakai `date('Y-m-d H:i:s')` PHP (bukan `NOW()`), sesuai pelajaran bug timezone.

## Tempat lain yang ikut diperbarui

| File | Perubahan |
|---|---|
| `orders.php` | `$allowed` / `$statuses`, `$stampHandled`, label, badge `.b-antri_setrika`, opsi filter |
| `dashboard.php` | `IN ('cuci','kering','setrika')` → tambah `antri_setrika`; warna & label status; stepper |
| `laporan.php` | `statusLabel` |
| `components.php` | daftar status + label |
| `absensi.php` | filter "order dalam proses" |
| `core/AIChatData.php` | deskripsi ENUM untuk konteks AI |
| `track.php`, `cek.php` | `antri_setrika` ditampilkan di langkah **Kering** ("menunggu disetrika"), tidak jadi langkah baru; progres 60% di `cek.php` |
| `core/MigrationImporter.php` | tidak berubah (impor tidak memakai status baru) |

Notifikasi dan poin loyalty tetap dipicu status `siap`.

## Penanganan error & edge case

- Status tak dikenal ditolak handler (`Param invalid`), perilaku lama.
- Order express tanpa `estimasi_selesai` memakai fallback `created_at + estimasi_jam`.
- Mundur dari `setrika` ke `antri_setrika` diizinkan lewat Kanban drag, entri log baru
  menggeser posisi FIFO (dianggap masuk antrean lagi).

## Pengujian

- Migrasi: `SHOW COLUMNS` memuat `antri_setrika` di urutan benar; insert uji lalu rollback.
- Kanban: pindah Kering → Antri Setrika → Setrika; log tertulis dengan jam WIB benar.
- Urutan: dua order express (deadline berbeda) + tiga order biasa → express dulu, lalu FIFO.
- Tampilan pelanggan: `track.php` / `cek.php` tidak menampilkan istilah "antri_setrika".
- `php -l` semua file yang diubah.
