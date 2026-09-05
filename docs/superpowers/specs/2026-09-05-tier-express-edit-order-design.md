# Tier Express di Edit Order — Design Spec

## Goal

Saat order diedit lewat halaman Order (`orders.php`), item yang dipilih/ditambah
tidak punya opsi Tier Express sama sekali — beda dari POS (`pos.php`) yang
punya dropdown tier per item. Fitur ini menambahkan UI pilih Tier Express di
form edit item, dan sekaligus membetulkan bug data-loss yang ditemukan saat
investigasi (lihat bawah).

**Perluasan scope (revisi setelah design awal):** bukan cuma Tier Express —
**`biaya_lainnya` (Biaya Lainnya Tier) juga ikut direcompute** saat order
diedit, bukan snapshot beku lagi. Alasan: tier Biaya Lainnya bisa bertipe
persen dari subtotal (`BiayaLainnyaTier::calcFee()`) — kalau item order
berubah (subtotal berubah) tapi `biaya_lainnya` tetap dibekukan ke nilai
lama, angkanya jadi salah/gak nyambung ke subtotal baru. Aturan umum:
**setiap kali transaksi diedit dan item-nya berubah, SEMUA nilai yang
sifatnya auto-calculated (Tier Express per-item + Biaya Lainnya Tier
per-order) dihitung ulang dari kondisi terbaru** — bukan cuma Express.

## Bug yang ikut dibetulkan

`orders.php` action `update`, saat `items` berubah (`$itemsChanged=true`):
baris `hl_transaksi_item` lama di-`DELETE` lalu di-`INSERT` ulang, tapi
INSERT-nya **tidak menyertakan kolom `express_tier_nama`/`biaya_express`**
sama sekali ([orders.php:363-365](../../../orders.php#L363)). Akibatnya,
item yang aslinya punya Tier Express kehilangan data tier-nya begitu order
itu diedit — walau `hl_transaksi.biaya_tambahan` (total di header) tetap
benar karena sengaja dibekukan (snapshot). Efek: nota/struk yang di-generate
ulang nanti kehilangan badge "⚡ Express" di item, meski nominal total tetap
tepat.

## Keputusan dari brainstorming

1. **`biaya_tambahan` berhenti jadi snapshot beku — direcompute dari tier
   yang di-submit per item**, persis pola POS (server-side recompute,
   tidak percaya nilai fee dari klien). Ini pergeseran dari komentar lama
   di kode ("TIDAK di-recompute di sini") — sekarang MEMANG di-recompute,
   karena user butuh bisa nambah/ubah tier saat edit.
2. **Item yang tidak disentuh user otomatis tetap bawa tier lamanya** —
   bukan lewat logic "diff per item eksplisit", tapi natural side-effect
   dari round-trip yang jujur: saat order di-load buat edit,
   `express_tier_nama`/`biaya_express` tiap item ikut di-preload ke
   dropdown-nya. Kalau user gak ubah dropdown itu, nilainya submit apa
   adanya (sama kayak semula).
3. **Order lunas yang totalnya berubah gara-gara tier** (nambah/ganti/hapus
   tier bikin `biaya_tambahan` beda) **lewat gerbang `OrderEditResolver`
   yang sudah ada** — sama seperti perubahan item/diskon lain, TIDAK ada
   mekanisme baru.
4. Header `hl_transaksi.tipe_order` + `express_tier_nama` (ringkasan
   "tier dominan") ikut di-recompute pakai `ExpressTier::dominantTier()`
   yang sudah ada di `core/ExpressTier.php`, konsisten dengan cara POS
   menghitungnya saat create.
5. **`biaya_lainnya` ikut direcompute dari subtotal baru** —
   `BiayaLainnyaTier::calcAppliedFees($tid, $oid, $subtotalBaru)`, sama
   fungsi yang dipakai POS saat create. Breakdown-nya
   (`hl_transaksi_biaya_lainnya`) di-refresh (DELETE+INSERT) sama pola
   `hl_transaksi_item`, bukan lagi read-only snapshot. Ini otomatis
   dipicu tier apapun (flat atau persen) — user TIDAK perlu pilih apa-apa
   secara manual buat Biaya Lainnya (tetap otomatis sesuai desain
   aslinya), cuma NILAINYA yang sekarang ikut nurut ke subtotal terbaru
   alih-alih beku ke subtotal lama.

## Perubahan per Bagian

### 1. Backend — endpoint baru: daftar tier

`orders.php` action `express_tiers` (GET) — return
`ExpressTier::forTenant($tid, $oid)`, persis dipakai `pos.php` buat isi
dropdown. Tidak perlu permission khusus di luar akses halaman Order yang
sudah ada.

### 2. Backend — load order untuk edit

Sudah otomatis dapat `express_tier_nama`/`biaya_express` per item (query
`SELECT *` yang sudah ada) — tidak perlu ubah query. Yang perlu diubah:
JS yang membangun `editItems[]` dari respons ini harus ikut membawa kedua
field itu (sekarang di-drop diam-diam saat mapping).

### 3. Backend — action `update`

- Query tier aktif outlet ini (`ExpressTier::forTenant`) sekali di awal,
  bikin `$tierMap` (nama → row), sama pola `pos.php`.
- Saat proses `$data['items']` (blok yang sudah ada, sekitar
  [orders.php:361](../../../orders.php#L361)): untuk tiap item, hitung
  `biaya_express` dari `$tierMap` (validasi nama tier ada & aktif —
  nama tier yang tidak dikenal di-drop diam-diam, sama seperti POS),
  jumlahkan jadi `$biayaTambahanBaru`.
- INSERT `hl_transaksi_item` sertakan kolom `express_tier_nama`,
  `biaya_express` (defensif: cek kolom ada dulu via try/catch query,
  sama pola `$hasBiayaTipe`/`$hasTierNama` yang sudah dipakai di pos.php,
  untuk kompatibilitas kalau migration belum jalan di suatu environment).
- Hitung `$dom = ExpressTier::dominantTier(...)` dari items+fee, update
  `tipe_order` + `express_tier_nama` di header bersamaan dengan
  `biaya_tambahan` (ganti dari `$biayaTambahanLama` yang snapshot beku,
  jadi `$biayaTambahanBaru` yang direcompute).
- Setelah `$subtotal` baru dihitung (baris ~258-263, sudah ada): panggil
  `BiayaLainnyaTier::calcAppliedFees($tid, $oid, $subtotal)` →
  `$biayaLainnyaBaru` = SUM nominal-nya. `DELETE` +
  `INSERT` ulang `hl_transaksi_biaya_lainnya` per baris hasilnya (sama
  pola insert breakdown yang sudah ada di pos.php:592).
- **PENTING**: semua recompute ini (Express + Biaya Lainnya) HANYA jalan
  di dalam blok `if (!empty($data['items']) && $itemsChanged)` yang
  sudah ada — kalau items tidak berubah sama sekali, `biaya_tambahan`,
  `biaya_lainnya`, dan breakdown item-tier TIDAK disentuh (tetap persis
  seperti sebelumnya untuk kasus itu, gak ada alasan buat recompute
  kalau gak ada yang berubah).
- Total order (`$total` di baris ~275) pakai `$biayaTambahanBaru` +
  `$biayaLainnyaBaru`, bukan lagi `$biayaTambahanLama`/`$biayaLainnyaLama`,
  ketika `$itemsChanged`.

### 4. Frontend — `editItems[]` bawa field tier

Saat order di-load ke form edit (fungsi yang mengisi `editItems` dari
respons API get), sertakan `express_tier_nama` & `biaya_express` per item
(sekarang di-drop). Default `null`/`0` kalau kosong (item lama tanpa
tier, atau item baru).

### 5. Frontend — `renderEditItems()` dapat kolom Express

Tambah `<td data-lbl="Express">` per baris, isi `<select>` mirip
`pos.php` (opsi "⏱️ Reguler" + daftar tier dari `availableTiersEdit`
global var, di-load sekali via `action=express_tiers` saat halaman
Order dibuka/modal edit dibuka). `onchange` set
`editItems[i].express_tier_nama` + hitung ulang `biaya_express` client-side
buat preview (bukan sumber kebenaran — backend tetap recompute saat
submit, ini cuma buat live-preview subtotal di modal).

Item view-only (`!CAN_EDIT_ORDER`) tampilkan tier sebagai teks/badge, sama
pola kolom lain di baris itu (span read-only, bukan select).

### 6. `addEditRow()` / `addEditLayanan()`

Tambahkan `express_tier_nama:null, biaya_express:0` ke object item baru
(sekarang tidak ada field ini sama sekali), sama pola `pos.php`.

## Yang TIDAK termasuk scope

- Tidak ada UI baru untuk Biaya Lainnya di form edit — tetap murni
  otomatis (owner atur tier aktif di `layanan.php`, bukan dipilih manual
  per order). Yang berubah cuma NILAINYA sekarang direcompute mengikuti
  subtotal terbaru, bukan beku ke subtotal lama.
- Tidak ada perbaikan retroaktif untuk data lama yang mungkin sudah
  kehilangan `express_tier_nama`/`biaya_express` di level item, atau
  `biaya_lainnya` yang sudah kadung beku dari edit-edit sebelumnya (di
  luar scope — kalau perlu, itu proyek data-repair terpisah, keputusan
  sengaja: total uang historis tetap seperti tercatat, cuma order yang
  diedit SETELAH fix ini yang dapat perilaku baru).
- Tidak ada perubahan pada bagaimana POS (`pos.php`) bekerja — POS sudah
  benar, fitur ini menyamakan Order edit dengan POS.
