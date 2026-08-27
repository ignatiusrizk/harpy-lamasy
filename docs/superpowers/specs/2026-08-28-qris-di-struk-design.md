# QRIS di Struk — Design Spec

## Goal

Tampilkan gambar QRIS outlet (yang sudah di-upload lewat halaman Pembayaran) di
struk — supaya customer yang order via antar-jemput (tidak ketemu langsung
dengan staf) tidak perlu tanya "bayarnya kemana", tinggal scan dari struk yang
mereka terima.

## Kapan QRIS muncul

Kondisi (SEMUA harus terpenuhi):
1. `hl_transaksi.status_bayar` IN `('belum_bayar', 'dp')` DAN `sisa_bayar > 0`.
   Order Lunas tidak menampilkan QRIS.
2. Outlet punya `qris_image` ter-upload (kalau kosong, blok QRIS tidak
   dirender sama sekali — tidak ada error).
3. Toggle "Tampilkan QRIS" di Kustomisasi Struk (retail) untuk outlet
   tersebut ON (default ON).

Toggle #3 adalah **satu sumber kebenaran** yang mengontrol SEMUA channel di
bawah — mati di satu tempat, mati di semua tempat.

## Channel yang terdampak

### 1. Kustomisasi Struk (toggle baru)
- **File:** `struk.php`, `core/StrukGenerator.php`
- Kolom baru `hl_struk_template.show_qris TINYINT(1) DEFAULT 1`.
- UI: checkbox baru "Tampilkan QRIS (saat belum lunas)" di `struk.php`,
  ditempatkan setelah toggle "Sisa Bayar" yang sudah ada — pola sama persis
  (`checkRow('show_qris', 'Tampilkan QRIS (saat belum lunas)', t)`).
- Field `show_qris` ditambahkan ke whitelist load & save (2 array literal di
  `struk.php`, dan default value di `StrukGenerator::defaultTemplate()`).

### 2. Struk cetak thermal (& otomatis ikut modal preview POS)
- **File:** `core/StrukGenerator.php` → `renderThermal()`
- Fungsi ini sama persis dipakai untuk cetak fisik dan preview modal POS
  setelah simpan order (satu fungsi render, dua pemakai) — sudah dikonfirmasi
  user oke QRIS ikut tampil juga di preview modal.
- Blok baru diletakkan setelah baris "Sisa Bayar" / "Bayar" yang sudah ada,
  sebelum blok "QR Code Tracking" yang sudah ada:
  ```
  [gambar qris_image, max-width sesuai lebar kertas]
  qris_label (kalau ada)
  "Sisa Bayar: Rp {sisa_bayar} — masukkan nominal ini saat scan"
  ```
- `$outlet` yang dipassing ke `render()`/`renderThermal()` sudah berisi
  `qris_image`/`qris_label` (berasal dari `TenantResolver::getOutlet()`,
  `SELECT *` — tidak perlu query tambahan).

### 3. Halaman Lacak Pesanan (`track.php`, termasuk portal `/p` yang redirect ke sini)
- Tambahkan `o.qris_image, o.qris_label` ke 2 query SELECT yang sudah ada
  (by `no_order` dan by `hp`).
- Load `show_qris` dari `hl_struk_template` (tipe `retail`) untuk
  `outlet_id` order tsb — pakai `StrukGenerator::loadTemplate()` yang sudah
  ada (return default `show_qris=>1` kalau outlet belum pernah simpan
  template).
- Blok QRIS baru ditaruh tepat di bawah baris "Pembayaran" yang sudah ada di
  detail order, dengan nominal sisa bayar ditulis besar di sampingnya.

### 4. Pesan WA Nota (`pos.php` action=`wa_nota`)
- WA cuma teks (wa.me), tidak bisa embed gambar — jadi cukup tambah 1 baris
  kalimat penunjuk kalau order belum lunas & `show_qris` ON, mengarahkan ke
  link tracking yang sudah ada di pesan itu:
  > "💳 Bayar via QRIS: buka link di atas"
- Baris ini hanya muncul kalau kondisi QRIS terpenuhi (lihat "Kapan QRIS
  muncul"); kalau tidak, teks pesan tetap seperti sekarang (tidak berubah).

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
