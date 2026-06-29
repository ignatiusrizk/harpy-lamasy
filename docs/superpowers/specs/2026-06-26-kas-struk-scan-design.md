# Tambah Kas via Foto Struk (AI Vision) — Design Spec

> LaMaSy. Tanggal: 2026-06-26.

## Goal

Admin foto/upload struk belanja → AI vision (Claude) baca → ekstrak total, tanggal, keterangan (toko+ringkasan), kategori → modal konfirmasi (editable) → isi form Tambah Kas (pengeluaran) → admin Simpan manual. Foto struk disimpan sebagai **bukti** yang menempel di entry kas. Mempercepat pencatatan pengeluaran + jejak audit.

## Arsitektur

- Capture pakai `<input type=file accept="image/*" capture="environment">` — jalan di **app & browser** (kamera/galeri). **Tak butuh plugin native / build APK** (beda dari voice order).
- Upload via `FileUpload::uploadImage` → simpan ke `uploads/` (bukti, path tenant-prefixed) → dapat path.
- `POST /api/kas_struk_scan.php { foto_path }` → server baca file → base64 → `AnthropicClient` vision → JSON → `ReceiptScanner::validate` (terhadap aturan) → potong coin hanya jika sukses → balas parsed.
- Modal konfirmasi (editable) → isi form Tambah Kas → admin Simpan (`kas.php?action=save`, diperluas terima `bukti_foto`).

Pola mirip voice order (capture → AI → modal konfirmasi → isi form, coin on success, tak auto-submit).

## Tech Stack

- PHP 8 / MariaDB. `core/AnthropicClient.php` (tambah dukungan image), `core/ReceiptScanner.php` (baru), `core/FileUpload.php` (uploadImage, existing), `core/CoinLedger.php` (deduct), `core/AIRateLimiter.php`. Tabel `hl_kas`. Frontend `kas.php`.
- Anthropic vision (image base64 + media_type). Model default sudah vision-capable.
- Test: skrip CLI + `tests/_assert.php` (existing), array fixture + mock AI closure.

## Global Constraints

- Multi-tenant: `hl_kas` & query scoping `tenant_id` (+ `outlet_id`). File upload tenant-prefixed.
- `jumlah` selalu divalidasi numeric > 0; `tipe` selalu `keluar` (tak dari AI). Harga/total dari hasil baca tapi admin WAJIB review sebelum Simpan.
- **Tak pernah auto-submit** — form diisi, admin tekan Simpan.
- **Potong coin hanya jika sukses** (struk valid + jumlah terbaca > 0). Gagal upload/AI/JSON/not_receipt → coin TIDAK dipotong.
- Rate limit via `AIRateLimiter::canCall('ai_kas_struk')` sebelum panggil AI.
- Endpoint POST WAJIB `verifyCsrf()` (token auto via interceptor global).
- File divalidasi sebagai image oleh `FileUpload::uploadImage` (tipe/ukuran). Bukti disimpan di `uploads/`.
- Semua kegagalan best-effort + `ErrorLogger`; tak crash halaman Kas.
- PHP CLI: `/opt/homebrew/bin/php`. Deploy: `git push origin main`. mysql: `/opt/homebrew/opt/mysql-client/bin/mysql`.

## Scope (MVP)

AI mengisi: **jumlah (total), tanggal, keterangan (toko+ringkasan item), kategori (tebakan)**. `tipe`=keluar tetap. Foto disimpan sebagai bukti.
Di luar scope: multi-item line-by-line ke beberapa entry (1 struk = 1 entry kas), kas masuk via foto, edit OCR per-baris, iOS-specific.

## Data Model

`ALTER TABLE hl_kas ADD COLUMN bukti_foto VARCHAR(255) NULL AFTER keterangan;` (idempotent: `ADD COLUMN IF NOT EXISTS`).

## Komponen & File

- `migrations/2026-06-26-kas-bukti-foto.sql` (NEW): ALTER hl_kas + apply.
- `core/CoinLedger.php` (MODIFY): tambah `'ai_kas_struk'` ke `const COSTS` + seed `saas_coin_pricing` (kolom `feature_key, harga_coin, daily_limit, is_active`).
- `core/AnthropicClient.php` (MODIFY): tambah `askJsonWithImage(string $prompt, string $base64, string $mediaType, array $opts = []): array` — content array `[{type:image, source:{type:base64,media_type,data}},{type:text,text}]`, reuse jalur curl/error existing.
- `core/ReceiptScanner.php` (NEW): `buildPrompt(): string`, `validate(array $raw): array` (pure), `scan(string $base64, string $mediaType, ?callable $aiFn = null): array`.
- `api/kas_struk_scan.php` (NEW): guard + CSRF + rate-limit + baca foto_path → base64 → scan → coin on success.
- `kas.php` (MODIFY): tombol "📸 Scan Struk", modal konfirmasi editable, isi form, perluas `save` terima `bukti_foto`, tampilkan bukti di list/detail.
- `tests/kas/test_receipt_validate.php` (NEW): unit validate.

## Alur Detail

1. Admin di Kas tap "📸 Scan Struk" → input file image (kamera/galeri).
2. Upload ke `kas.php?action=upload_bukti` (atau reuse pola upload) via `FileUpload::uploadImage($f,'uploads/kas_bukti','t{tid}_o{oid}')` → path.
3. `POST /api/kas_struk_scan.php { foto_path }`.
4. Server: `tenant_guard` → `verifyCsrf()` → `AIRateLimiter::canCall('ai_kas_struk')` → validasi `foto_path` ada di `uploads/` & milik tenant (prefix) → baca file → base64 + deteksi media_type → `ReceiptScanner::scan()` → AI → `validate` → jika valid (is_receipt && jumlah>0): `CoinLedger::deduct('ai_kas_struk')` (false → `insufficient_coin`) → balas `{ok:true, parsed:{jumlah,tanggal,keterangan,kategori}, foto_path}`. Gagal → `{ok:false, reason}` (no coin).
5. Modal konfirmasi: thumbnail + field editable → Terapkan.
6. Terapkan → isi form Tambah Kas (tipe=keluar, jumlah, tanggal, keterangan, kategori, hidden bukti_foto=foto_path) → admin Simpan.
7. `kas.php?action=save` simpan ke `hl_kas` termasuk `bukti_foto`. List/detail tampilkan link/ikon bukti.

## Kontrak JSON (AI ↔ server)

Prompt minta JSON saja. Skema:
```json
{ "is_receipt": true, "jumlah": 85000, "tanggal": "2026-06-26", "keterangan": "Toko Makmur — deterjen, pewangi", "kategori": "Bahan" }
```

`ReceiptScanner::validate(array $raw): array` (pure):
- `is_receipt===false` OR jumlah tak valid → `['ok'=>false]` (endpoint → reason `not_receipt`).
- `jumlah` → buang non-digit pemisah → (int) > 0 else gagal.
- `tanggal` → cocokkan `/^\d{4}-\d{2}-\d{2}$/` & `strtotime` valid & ≤ besok; else null.
- `keterangan` → trim+strip_tags, max 500; kosong → 'Belanja (scan struk)'.
- `kategori` → trim, max 50.
- Sukses → `['ok'=>true, 'jumlah'=>int, 'tanggal'=>?string, 'keterangan'=>string, 'kategori'=>string]`.

Endpoint reason codes: `not_image | rate_limited | insufficient_coin | ai_error | not_receipt | bad_path`.

## Error Handling

| Kondisi | Perilaku | Coin |
|---|---|---|
| Upload/file bukan image | Toast "Foto tidak valid" | — |
| foto_path invalid / bukan milik tenant | `{ok:false,bad_path}` | — |
| Rate limit habis | Toast "Limit AI harian tercapai" | tak dipotong |
| Coin tak cukup | Toast "Coin tak cukup" | tak dipotong |
| AI gagal / JSON invalid | Toast "Gagal membaca struk, coba foto lebih jelas" | tak dipotong |
| not_receipt / jumlah tak terbaca | Toast "Bukan struk / total tak terbaca" | tak dipotong |
| Sukses | Modal konfirmasi editable | dipotong (`ai_kas_struk`) |

## Testing

- **Unit (`ReceiptScanner::validate`):** (a) is_receipt=false → ok=false; (b) jumlah "85.000" / "Rp85000" → 85000; (c) jumlah 0/negatif/non-numeric → ok=false; (d) tanggal invalid/masa depan → null, valid → kept; (e) keterangan kosong → fallback; (f) kategori trim/cap. Pakai mock aiFn untuk `scan`.
- **Manual E2E:** foto struk asli → modal muncul jumlah/tanggal/keterangan benar → edit kalau perlu → Terapkan → form terisi tipe=keluar → Simpan → entry kas + bukti foto tersimpan & bisa dilihat. Tes: foto buram (ai_error), foto non-struk (not_receipt), coin habis.

## Out of Scope (v1)

- Kas masuk via foto (hanya pengeluaran).
- 1 struk → multi entry per item.
- Edit per-baris item OCR.
- Auto-kategori belajar dari histori.

## Prasyarat

- Konfigurasi AI aktif (`ANTHROPIC_API_KEY` — sudah ada untuk fitur AI lain). Tak butuh build APK.
