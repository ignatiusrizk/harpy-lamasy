# Alamat Outlet Bertingkat (Wilayah Indonesia) — Design Spec

> LAMASY. Tanggal: 2026-07-01.

## Goal

Ganti input alamat outlet di **add-outlet.php** (step "Tambah Outlet") dari teks bebas menjadi **dropdown wilayah bertingkat**: Provinsi → Kota/Kabupaten → Kecamatan → Kelurahan, dengan **Kode Pos terisi otomatis** dari kelurahan terpilih (tetap bisa diedit). Alamat jalan (No/RT/RW) tetap teks bebas.

## Keputusan (terkunci)
- **Sumber data:** tabel DB lokal, di-seed sekali dari dataset resmi Permendagri.
  - `cahyadsn/wilayah` → hierarki `kode` + `nama` (prov/kota/kec/kel).
  - `cahyadsn/wilayah_kodepos` → kode pos per kelurahan.
- **Kedalaman:** 4 level (Provinsi→Kota→Kecamatan→Kelurahan) + auto Kode Pos (editable).
- **Scope:** hanya `add-outlet.php`. Registration form & outlet-settings edit = out of scope (menyusul).
- Tanpa ketergantungan API pihak ketiga saat runtime.

## Arsitektur

### Data & Schema

**Tabel baru `ref_wilayah`** (referensi global, tak ter-tenant-scope):
```
kode         VARCHAR(13)  PRIMARY KEY   -- mis. "32", "32.01", "32.01.01", "32.01.01.2001"
nama         VARCHAR(120) NOT NULL
level        TINYINT      NOT NULL      -- 1=provinsi, 2=kota/kab, 3=kecamatan, 4=kelurahan
parent_kode  VARCHAR(13)  NULL          -- kode induk (NULL untuk provinsi)
kodepos      VARCHAR(5)   NULL          -- hanya terisi di level 4
INDEX idx_parent (parent_kode),
INDEX idx_level_parent (level, parent_kode)
```
- `level` & `parent_kode` diturunkan dari struktur `kode` saat seeding (jumlah segmen titik).
- `kodepos` di-join dari `wilayah_kodepos` pada baris kelurahan.
- ~91.000 baris (34 prov + ~514 kota/kab + ~7.277 kec + ~83.000 kel). Ukuran < 15MB.

**Kolom baru di `outlets`** (semua nullable → outlet lama tak terpengaruh):
```
provinsi     VARCHAR(100) NULL
kecamatan    VARCHAR(100) NULL
kelurahan    VARCHAR(100) NULL
wilayah_kode VARCHAR(13)  NULL   -- kode kelurahan terpilih (presisi/future)
```
Kolom lama tetap dipakai: `kota` (nama kota/kab), `kode_pos`, `alamat` (jalan/No/RT-RW).

### Backend: endpoint cascade

**File baru `api/wilayah.php`** (reusable):
- Guard: login required (pakai guard yang sama dengan halaman app; endpoint hanya baca referensi publik, tak ada data tenant).
- Request: `GET /api/wilayah?parent=<kode>` — kembalikan anak langsung dari `parent`.
  - `parent` kosong/absen → kembalikan daftar **provinsi** (level 1).
  - `parent` diisi → kembalikan anak (`WHERE parent_kode = ?`).
- Response: `{ ok:true, data:[ {kode, nama, kodepos} ] }` (`kodepos` hanya relevan di level kelurahan; null di level lain).
- Query indexed by `parent_kode`. Tak ada tulis.

### Frontend: form add-outlet step 1

Ganti field teks "Kota/Kabupaten" dengan 4 `<select>` bertingkat + pertahankan textarea alamat & field kode pos:
- `#w_prov` (Provinsi) — dimuat saat halaman load (fetch parent kosong).
- `#w_kota` (Kota/Kabupaten) — disabled sampai provinsi dipilih; onchange provinsi → fetch anak.
- `#w_kec` (Kecamatan) — disabled sampai kota dipilih.
- `#w_kel` (Kelurahan) — disabled sampai kecamatan dipilih; onchange → **isi `#kode_pos`** dari `kodepos` anak terpilih (kalau ada), tetap editable.
- Textarea **"Alamat jalan (No, RT/RW)"** (rename dari "Alamat Lengkap") — wajib.
- Tiap select tampil status "⏳ memuat…" saat fetch; error tampil pesan singkat.
- Nilai yang disubmit: nama provinsi/kota/kecamatan/kelurahan (untuk disimpan sebagai teks) + `wilayah_kode` (kode kelurahan) + kode_pos + alamat.
- Hidden field menyimpan nama tiap level (karena `<option>` value = kode, tapi kita simpan nama untuk display) — atau kirim kode dan resolve nama di server. **Keputusan:** kirim **kode** tiap level; server resolve nama dari `ref_wilayah` saat simpan (satu sumber kebenaran, hindari spoof nama).

### Alur simpan (step 1 → 2 → create)

1. Submit step 1: server terima `w_prov, w_kota, w_kec, w_kel` (kode), `kode_pos`, `alamat`, dll.
2. Server validasi: tiap kode ada di `ref_wilayah` dengan level & parent yang benar (mencegah kombinasi asal). Resolve `nama` tiap level dari DB.
3. Simpan ke `$d` (session wizard): `provinsi, kota, kecamatan, kelurahan, wilayah_kode, kode_pos, alamat`.
4. Step 2 (konfirmasi) menampilkan: Provinsi, Kota, Kecamatan, Kelurahan, Kode Pos, Alamat jalan.
5. Create: INSERT `outlets` termasuk kolom baru.

## Seeding / Migrasi

Skrip `migrations/2026-07-01-ref-wilayah.sql` + langkah import:
1. `CREATE TABLE ref_wilayah (...)`.
2. Import `cahyadsn/wilayah` → sementara ke tabel staging `(kode,nama)`.
3. Import `cahyadsn/wilayah_kodepos` → staging `(kode, kodepos)`.
4. INSERT ke `ref_wilayah` dari staging wilayah, hitung `level` = (jumlah segmen setelah split '.'), `parent_kode` = kode tanpa segmen terakhir.
5. UPDATE `kodepos` dari staging kodepos (join `kode`).
6. Drop staging.
- Idempotent: `DROP TABLE IF EXISTS` staging; `ref_wilayah` di-`TRUNCATE`/`CREATE IF NOT EXISTS` sebelum reload.
- Dijalankan sekali via mysql client ke PROD. Disimpan di `migrations/` untuk reproducibility.

## Error Handling
- Endpoint: parent tak valid / tak ada anak → `{ok:true, data:[]}` (select kosong, bukan error).
- Fetch gagal (jaringan): select menampilkan "gagal memuat, coba lagi"; tombol lanjut tetap bisa (validasi server yang jaga).
- Server create: kalau kode wilayah tak lolos validasi → error "Pilih wilayah dengan lengkap".
- Kelurahan tanpa kodepos di dataset → field kode pos dibiarkan kosong untuk diisi manual.

## Keamanan
- `api/wilayah.php` hanya SELECT referensi publik; tetap di belakang login guard app. Parameter `parent` di-prepared-statement.
- Nama wilayah TIDAK dipercaya dari klien; server resolve nama dari `ref_wilayah` berdasarkan kode → cegah injeksi nama palsu.
- Kode pos manual: strip non-digit, maks 5.

## Testing
- `php -l` semua file.
- Manual: buka Tambah Outlet → Provinsi termuat → pilih berjenjang → Kelurahan mengisi Kode Pos → step 2 menampilkan lengkap → outlet tersimpan dengan kolom baru terisi.
- Cek endpoint: `/api/wilayah` (provinsi), `/api/wilayah?parent=32` (kota Jabar), dst.
- Regressi: outlet lama (tanpa kolom baru) tetap tampil normal di daftar/HQ.
- Edge: kelurahan tanpa kodepos → kode pos kosong, bisa diisi manual & tersimpan.

## Out of Scope (v1)
- Cascading address di **registration form** & **outlet-settings (edit outlet)** — struktur sama, spec terpisah menyusul.
- Migrasi data alamat outlet lama ke struktur baru (dibiarkan apa adanya).
- Multi kode-pos per kelurahan (ambil satu; user bisa edit).
- Peta/geocoding lat-long.

## Files
**Baru:** `api/wilayah.php`, `migrations/2026-07-01-ref-wilayah.sql`.
**Ubah:** `add-outlet.php` (form step 1 + handler simpan step 1 + tampilan step 2 + INSERT create), tabel `outlets` (ALTER tambah 4 kolom).

## References
- add-outlet.php: form step 1 (~562), handler POST (~111), INSERT outlets (~155), step 2 review (~648).
- Dataset: `cahyadsn/wilayah` (db/wilayah.sql), `cahyadsn/wilayah_kodepos` (db/wilayah_kodepos.sql).
