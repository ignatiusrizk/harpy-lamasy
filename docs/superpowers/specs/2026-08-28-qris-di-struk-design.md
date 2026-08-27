# QRIS & Rekening di Struk — Design Spec

## Goal

Tampilkan alat bayar yang relevan di struk — QRIS outlet (upload di halaman
Pembayaran) kalau metode bayarnya QRIS, atau nomor rekening terdaftar kalau
metode bayarnya Transfer Bank — supaya customer yang order via antar-jemput
(tidak ketemu langsung dengan staf) tidak perlu tanya "bayarnya kemana",
tinggal scan/transfer dari struk yang mereka terima.

## Kapan QRIS muncul

Kondisi (SEMUA harus terpenuhi):
1. `hl_transaksi.status_bayar` IN `('belum_bayar', 'dp')` DAN `sisa_bayar > 0`.
   Order Lunas tidak menampilkan QRIS.
2. `hl_transaksi.metode_bayar === 'qris'` — HANYA tampil kalau ini memang
   metode yang dipilih staf saat input order. Metode lain (cash/transfer/
   kosong) tidak memicu blok QRIS.
3. Outlet punya `qris_image` ter-upload (kalau kosong, blok QRIS tidak
   dirender sama sekali — tidak ada error).
4. Toggle "Tampilkan QRIS" di Kustomisasi Struk (retail) untuk outlet
   tersebut ON (default ON).

## Kapan Info Rekening muncul (BARU, ditambahkan menyusul QRIS)

Kondisi (SEMUA harus terpenuhi) — pola sama persis dengan QRIS, cuma beda
metode & sumber data:
1. `status_bayar` IN `('belum_bayar', 'dp')` DAN `sisa_bayar > 0`.
2. `metode_bayar === 'transfer'`.
3. Template punya `rekening_bank` & `rekening_nomor` terisi (kalau kosong,
   blok tidak dirender).
4. Toggle "Info Rekening Pembayaran" (`show_rekening` — KOLOM SUDAH ADA di
   `hl_struk_template`, sebelumnya cuma dipakai invoice B2B) ON.

`rekening_bank`, `rekening_nomor`, `rekening_atas_nama` — 3 kolom yang sudah
ada di `hl_struk_template`, saat ini cuma bisa diisi & dipakai di invoice PDF
B2B (`renderPdf`). Untuk fitur ini, field yang SAMA dibuka juga di tab
template retail (saat ini UI-nya di `struk.php` dibatasi `isB2b ? ... : ''`
— dihilangkan pembatasannya supaya retail juga bisa isi rekening sendiri,
independen dari isian B2B karena memang row template terpisah per `tipe`).

Kedua toggle (`show_qris`, `show_rekening`) masing-masing **independen** —
bukan satu toggle gabungan — karena masing-masing sudah otomatis exclusive
lewat pengecekan `metode_bayar` (order yang sama tidak akan menampilkan
QRIS dan Rekening bersamaan, karena `metode_bayar` cuma satu nilai).

## Channel yang terdampak

### 1. Kustomisasi Struk (toggle baru + buka field rekening utk retail)
- **File:** `struk.php`, `core/StrukGenerator.php`
- Kolom baru `hl_struk_template.show_qris TINYINT(1) DEFAULT 1`. Kolom
  `show_rekening`/`rekening_bank`/`rekening_nomor`/`rekening_atas_nama`
  SUDAH ADA, tidak perlu migrasi — tinggal dibuka aksesnya utk tipe retail.
- UI di `struk.php`:
  - Checkbox baru "Tampilkan QRIS (saat belum lunas & metode QRIS)" —
    ditempatkan setelah toggle "Sisa Bayar" yang sudah ada, pola sama
    persis (`checkRow('show_qris', ..., t)`).
  - Section "Info Rekening Pembayaran" (checkbox + 3 input Bank/Nomor/Atas
    Nama) yang sekarang cuma muncul di `isB2b ? ... : ''` — hilangkan
    pembatasan itu supaya section yang SAMA juga muncul di tab retail.
    Ubah label checkbox jadi lebih umum: "Tampilkan Rekening (saat belum
    lunas & metode Transfer)".
- Field `show_qris` ditambahkan ke whitelist load & save (2 array literal di
  `struk.php`) dan default value di `StrukGenerator::defaultTemplate()`.
  Field rekening yang sudah ada di whitelist TIDAK perlu diubah (sudah ada),
  cuma perlu dipastikan default `show_rekening` utk row retail baru
  tetap masuk akal (`0` — biar owner aktifkan manual setelah isi datanya,
  beda dgn QRIS yg default `1` krn datanya sudah otomatis ada dari
  `outlets.qris_image`).

### 2. Struk cetak thermal (& otomatis ikut modal preview POS)
- **File:** `core/StrukGenerator.php` → `renderThermal()`
- Fungsi ini sama persis dipakai untuk cetak fisik dan preview modal POS
  setelah simpan order (satu fungsi render, dua pemakai) — sudah dikonfirmasi
  user oke QRIS/Rekening ikut tampil juga di preview modal.
- Blok baru diletakkan setelah baris "Sisa Bayar" / "Bayar" yang sudah ada,
  sebelum blok "QR Code Tracking" yang sudah ada. Dua varian, saling
  eksklusif krn beda kondisi `metode_bayar`:
  ```
  // metode_bayar === 'qris' && show_qris && outlet.qris_image:
  [gambar qris_image, max-width sesuai lebar kertas]
  qris_label (kalau ada)
  "Sisa Bayar: Rp {sisa_bayar} — masukkan nominal ini saat scan"

  // metode_bayar === 'transfer' && show_rekening && rekening_bank+rekening_nomor:
  "Transfer ke:"
  "{rekening_bank} — {rekening_nomor}"
  "a.n. {rekening_atas_nama}" (kalau ada)
  "Sisa Bayar: Rp {sisa_bayar}"
  ```
- `$outlet` yang dipassing ke `render()`/`renderThermal()` sudah berisi
  `qris_image`/`qris_label` (berasal dari `TenantResolver::getOutlet()`,
  `SELECT *` — tidak perlu query tambahan). `$tmpl` (hasil `loadTemplate()`)
  sudah berisi field rekening juga, tidak perlu query tambahan.

### 3. Halaman Lacak Pesanan (`track.php`, termasuk portal `/p` yang redirect ke sini)
- Tambahkan `o.qris_image, o.qris_label` ke 2 query SELECT yang sudah ada
  (by `no_order` dan by `hp`).
- Load template lewat `StrukGenerator::loadTemplate()` yang sudah ada (return
  default kalau outlet belum pernah simpan template) untuk `outlet_id` order
  tsb — dapat `show_qris`, `show_rekening`, `rekening_bank`, `rekening_nomor`,
  `rekening_atas_nama` sekaligus.
- Blok baru (QRIS ATAU Rekening, sesuai `metode_bayar`, sama seperti di
  struk cetak) ditaruh tepat di bawah baris "Pembayaran" yang sudah ada di
  detail order, dengan nominal sisa bayar ditulis besar di sampingnya.

### 4. Pesan WA Nota (`pos.php` action=`wa_nota`)
- WA cuma teks (wa.me), tidak bisa embed gambar — jadi cukup tambah 1 baris
  kalimat penunjuk kalau kondisi terpenuhi, mengarahkan ke link tracking
  yang sudah ada di pesan itu (di situ customer baru lihat gambar QRIS-nya):
  > "💳 Bayar via QRIS: buka link di atas" (kalau kondisi QRIS terpenuhi)
  > "🏦 Transfer ke {rekening_bank} {rekening_nomor}: buka link di atas
  >   utk detail" (kalau kondisi Rekening terpenuhi)
- Baris ini hanya muncul kalau salah satu kondisi di atas terpenuhi; kalau
  tidak, teks pesan tetap seperti sekarang (tidak berubah).

## Nominal tidak ter-encode di QR

QRIS yang dipakai adalah gambar statis hasil upload manual (bukan integrasi
gateway) — nominalnya TIDAK ada di dalam kode QR itu sendiri. Karena itu di
semua channel, angka "Sisa Bayar: Rp X" selalu ditulis eksplisit di sebelah
gambar QR, supaya customer tahu harus input manual jumlah itu di aplikasi
e-wallet/bank mereka saat scan.

## Crop twibon QRIS (manual, saat upload)

**File:** `payment-settings.php`

Banyak gambar QRIS official dari bank/e-wallet punya "twibon" (border,
logo, teks "Scan Me") di sekeliling kode QR aslinya — kalau di-upload apa
adanya, pas dicetak di struk thermal 58mm kode QR-nya jadi kecil dan susah
di-scan.

Server cuma punya GD (bukan Imagick), dan deteksi otomatis posisi kode QR di
dalam gambar sembarang adalah masalah computer-vision yang tidak reliable
untuk sekali coba — salah crop = QRIS jadi tidak valid = customer gagal
bayar. Jadi pendekatan yang dipakai: **crop manual di layar, sekali saat
upload**, bukan auto-detect.

Alur baru di halaman Pembayaran:
1. User pilih file gambar QRIS seperti biasa (`<input type="file">`).
2. Begitu file dipilih (event `change`), JS load gambar ke `<canvas>` dan
   tampilkan overlay kotak crop (persegi, draggable + resizable via handle
   di sudut) di atas preview gambar. Kotak awal = full image (kalau memang
   sudah cuma QR polos tanpa twibon, user tinggal submit tanpa geser apa
   pun).
3. User geser/perbesar-kecilkan kotak supaya pas menutupi area kode QR saja.
4. Saat submit, JS crop kanvas sesuai kotak terpilih, convert ke Blob
   (`canvas.toBlob`, format PNG), lalu kirim Blob itu sebagai isi field
   `qris_image` di `FormData` — MENGGANTIKAN file asli, bukan tambahan.
5. Validasi server-side (`payment-settings.php`) TIDAK berubah — tetap cek
   MIME/size/dimensi minimum 400×400, tapi sekarang mengecek hasil crop
   (karena itu yang benar-benar di-upload), bukan file asli.
6. Vanilla JS + Canvas API saja, tidak menambah dependency/library eksternal
   — konsisten dengan pola proyek ini yang menghindari vendor/library berat
   kalau bisa dikerjakan native.

## Yang TIDAK termasuk di scope ini

- Tidak ada integrasi payment gateway otomatis (Midtrans/Tripay) — itu
  keputusan lama yang sengaja di-hold ([[project_native_app_strategy]]),
  tidak berubah oleh fitur ini.
- Tidak ada auto-crop/auto-deteksi posisi QR di gambar twibon.
- Invoice PDF B2B (`renderPdf`, format a4/a5) tidak disentuh — scope ini
  hanya struk retail thermal + channel digital customer (track/WA).
- Modal preview struk B2B / channel lain yang tidak disebut di atas tidak
  berubah.

## Catatan sampingan (bukan bagian scope, cuma temuan)

Ditemukan saat eksplorasi: kolom `hl_struk_template.show_qr_wa` (toggle "QR
Tracking" yang sudah ada) ternyata **tidak benar-benar mengontrol apa pun** —
blok QR tracking di `renderThermal()` selalu tampil tanpa cek flag ini sama
sekali (dead toggle, sudah begini dari sebelum fitur ini). Tidak diperbaiki
di sini karena di luar scope QRIS, tapi dicatat untuk perbaikan terpisah
kalau diperlukan.
