# Mode Simple Self-Service via POS — Design Spec

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this from the eventual plan. This document is the spec, not the plan.

**Goal:** Beri outlet cara lebih pendek buat mencatat transaksi self-service (customer pakai mesin sendiri, staf cuma input di kasir) — tanpa QR/booking, tanpa wajib data pelanggan lengkap, dengan add-on (sabun/plastik) yang gampang ditambahkan.

**Konteks:** LaMaSy sudah punya sistem Mesin Self-Service berbasis QR-booking (`mesin.php` + `self.php`, "Approach A: Manual Confirm") — customer scan QR di mesin → pilih cycle → isi nama+telepon → book → staf konfirmasi mulai → nyalain mesin manual → countdown → tandai selesai. Owner (Harpy Laundry) merasa alur itu terlalu ribet untuk dipakai tiap hari, dan minta alternatif yang lebih simpel lewat POS biasa.

## Global Constraints

- Sistem Mesin Self-Service (QR-booking) yang sudah ada **TIDAK DIUBAH SAMA SEKALI** — fitur ini murni tambahan baru, berdampingan, bukan pengganti.
- Reuse field `kategori` yang sudah ada di `hl_layanan` — **JANGAN** tambah kolom database baru (mis. `is_addon`, `is_selfservice`). Semua deteksi berbasis string kategori.
- Nilai kategori pemicu: **`Self-Service`** (layanan utama). Nilai kategori add-on: **`Tambahan Self-Service`**. Perbandingan kategori di kode HARUS case-insensitive dan mengabaikan spasi/tanda hubung (normalize: lowercase, strip non-alfanumerik) — supaya "Self-Service", "self service", "SELFSERVICE" dst semua dikenali sama.
- Order dianggap "self-service" kalau **minimal 1 item di keranjang** berkategori `Self-Service` (setelah normalisasi). Tidak ada toggle/checkbox manual terpisah.
- Untuk order self-service: **Nama pelanggan tetap wajib**, **Nomor HP jadi opsional** (boleh kosong). Untuk order non-self-service: validasi nama+telepon wajib TETAP seperti sekarang, tidak berubah.
- Tombol add-on: model **"tap to add"**, bukan auto-masuk keranjang. Kalau add-on itu sudah ada di keranjang, tombolnya disabled (mencegah dobel).

---

## 1. Data: Kategori Baru di Layanan

**File:** `layanan.php`

- Tambah `'Self-Service'` dan `'Tambahan Self-Service'` ke `KAT_REKOMENDASI` (baris ~812) supaya muncul di datalist autocomplete kategori saat owner bikin/edit layanan.
- **Migrasi data existing (Harpy Laundry, tenant 18, outlet 13):** update `hl_layanan` — item dengan `nama` mengandung "SelfService" (case-insensitive) yang saat ini berkategori `Khusus` (`Pencucian SelfService`, `Pengeringan SelfService`) → ubah `kategori` jadi `Self-Service`. Dilakukan sekali via query langsung (bukan lewat UI), sebagai bagian dari implementasi — bukan migrasi otomatis untuk semua tenant lain (tenant lain yang mau pakai fitur ini tinggal bikin/edit layanan mereka sendiri dengan kategori yang benar).
- Item add-on (Sabun, Plastik, dst) untuk Harpy Laundry **dibuat manual oleh owner** lewat halaman Layanan yang sudah ada (bukan bagian dari migrasi/seed otomatis) — beri tahu owner setelah deploy supaya mereka isi sendiri nama+harga add-on yang mereka mau.

## 2. POS: Deteksi Kategori pada Item Keranjang

**File:** `pos.php`

Item yang di-push ke array `items` (JS) saat ini TIDAK menyimpan field `kategori` — cuma `layanan_id, nama_layanan, satuan, jumlah, harga_satuan, catatan_item, express_tier_nama, biaya_express, qty_minimum, estimasi_jam`. Perlu ditambah `kategori` di 3 titik `items.push(...)` dalam `addLayananItem()` (baris ~1911, ~1916) dan titik push dari quick-create layanan (baris ~1977) — nilai diambil dari `lyn.kategori` (hasil lookup `layananAll.find(l => l.id == id)`, yang sudah membawa kategori krn `get_layanan` pakai `SELECT *`).

Tambah helper JS:
```js
function lmNormKat(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]/g,''); }
function isSelfServiceKat(kat){ return lmNormKat(kat) === 'selfservice'; }
function isAddonKat(kat){ return lmNormKat(kat) === 'tambahanselfservice'; }
function cartHasSelfService(){ return items.some(i => isSelfServiceKat(i.kategori)); }
```

## 3. POS: Tombol Saran Add-on

**File:** `pos.php`, fungsi `renderItems()`

- Saat merender baris item yang `isSelfServiceKat(item.kategori)` true, tampilkan strip tombol kecil di bawah baris itu — satu tombol per layanan aktif berkategori `Tambahan Self-Service` (dari `layananAll`, di-filter `isAddonKat`), format `+ {nama} Rp{harga}`.
- Tombol addon yang `nama_layanan`-nya SAMA dan SUDAH ada sbg item lain di keranjang (match by `layanan_id`) → disabled/dim, biar gak dobel-tap.
- Tap tombol → panggil `addLayananItem(id, nama, satuan, harga)` yang sudah ada (reuse, tidak bikin fungsi baru) — otomatis masuk sbg baris item baru qty 1.
- Kalau tidak ada layanan berkategori `Tambahan Self-Service` terdaftar (outlet belum setup), strip tombol tidak muncul sama sekali (bukan pesan error) — silent, karena ini opsional.

## 4. POS: Nomor HP Opsional untuk Order Self-Service

**File:** `pos.php`

- **Client-side** (`saveTransaksi()` baris ~2580-2581, `doSaveTransaksi()` guard baris ~2616): validasi `!telp` → error HANYA dijalankan kalau `!cartHasSelfService()`. Kalau `cartHasSelfService()` true, lewati cek telepon (nama tetap wajib divalidasi selalu).
- **Server-side** (baris ~259-260, action save transaksi): tentukan self-service dari item-item yang dikirim di payload (`$data['items']`) — cek apakah ada item dengan `layanan_id` yang, saat di-lookup ke `hl_layanan.kategori` (tenant+outlet scoped), ternyata `Self-Service` (normalized match, sama seperti di JS). Kalau ya, lewati validasi `if (!$telepon)`. **Validasi server WAJIB ada, tidak boleh cuma andalkan client-side** (klien bisa dimanipulasi) — ini defense-in-depth standar buat semua validasi input POS di file ini.
- UI: label field No. HP di form order (baris ~1298 area) tambah indikator visual "(opsional)" ketika `cartHasSelfService()` true — update live tiap kali `renderItems()` jalan (item ditambah/dihapus dari keranjang bisa mengubah status ini).

## 5. Testing Manual (tidak ada automated test suite utk pos.php)

- Tambah layanan baru kategori `Self-Service` dan `Tambahan Self-Service` lewat halaman Layanan → cek muncul di POS grid layanan dengan kategori benar.
- Tambah item `Self-Service` ke keranjang → cek tombol add-on muncul, tap salah satu → item add-on masuk keranjang, tombolnya disabled.
- Coba simpan order dengan item self-service TANPA isi No. HP → harus berhasil (nama tetap wajib).
- Coba simpan order TANPA item self-service dan TANPA No. HP → harus tetap gagal (pesan error nomor HP wajib), memastikan validasi lama utk order biasa tidak rusak.
- Cek order self-service tanpa telepon yang tersimpan tetap muncul normal di Orders/riwayat, tidak error di halaman lain yang baca `telepon`.
