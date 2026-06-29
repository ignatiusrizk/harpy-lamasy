# Absensi Clock-in Hardening (Selfie + Geofence) — Design Spec

> LaMaSy. Tanggal: 2026-06-26. Sub-proyek A dari enhancement Absensi (B = Jadwal Shift menyusul, spec terpisah).

## Goal

Perkuat clock-in absensi karyawan dengan dua aturan **opsional per-outlet** yang diatur owner/manajemen:
1. **Selfie wajib** saat clock-in (anti titip-absen).
2. **Geofence** — clock-in hanya boleh dalam radius titik outlet (anti absen dari jauh), mode **strict** (blokir kalau GPS tak ada / di luar radius).

Berlaku **clock-in saja** (clock-out tetap cukup tap). Membangun di atas fitur absensi yang SUDAH ada (`absensi.php`, `hl_absensi`, integrasi penggajian).

## Konteks Existing (jangan dibangun ulang)

- `absensi.php` sudah punya `clock_in`/`clock_out` (hitung durasi, simpan `lokasi_masuk/keluar`), rekap, izin, shift handover.
- `hl_absensi`: id, tenant_id, user_id, tanggal, jam_masuk, jam_keluar, durasi_menit, lokasi_masuk, lokasi_keluar, catatan, status, outlet_id.
- Config outlet = kolom di tabel `outlets`, diatur via `outlet-settings.php` (action save → UPDATE outlets). Perm owner.
- `FileUpload::uploadImage($file,$folder,$prefix): {path,error}` (2MB, image, path relatif).
- Permission `absensi.clock` (karyawan) + `absensi.view` (manajer) sudah ada & ter-assign.

## Arsitektur

- Owner set config (toggle selfie, toggle geofence + titik lat/lng via peta Leaflet + radius) di Outlet Settings → kolom `outlets`.
- Karyawan clock-in: frontend baca config outlet → kalau geofence aktif minta GPS, kalau selfie wajib buka kamera → kirim ke `absensi.php?action=clock_in` dengan `lat/lng/selfie_path`.
- Server menegakkan aturan dari kolom `outlets` (bukan dari client): haversine distance ≤ radius; selfie ada & path valid. Lolos → INSERT `hl_absensi`.

## Tech Stack

- PHP 8 / MariaDB. `absensi.php`, `outlet-settings.php`, `FileUpload`, kolom `outlets` + `hl_absensi`.
- Frontend: **Leaflet** (vendored lokal `assets/vendor/leaflet.js`+`leaflet.css`) + tile OpenStreetMap (gratis, tanpa API key) untuk map picker. `navigator.geolocation` + `<input type=file accept="image/*" capture="user">` (kamera depan) untuk clock-in.
- Test: skrip CLI + `tests/_assert.php` untuk fungsi haversine.

## Global Constraints

- Multi-tenant: config & query scope `tenant_id` (+ `outlet_id`). Upload selfie prefix `t{tid}_o{oid}`.
- **Server-side enforcement**: aturan dibaca dari kolom `outlets` milik outlet karyawan; jarak dihitung server (haversine) — tak percaya klaim client "saya di dalam radius".
- Geofence **strict**: GPS kosong/ditolak ATAU jarak > radius → tolak clock-in (error jelas). Hanya saat `absensi_geofence_aktif=1`.
- Selfie wajib hanya saat `absensi_selfie_wajib=1`; tak ada foto → tolak.
- `selfie_path` divalidasi: prefix `uploads/absensi_selfie/t{tid}_o{oid}` + no `..` (anti traversal/XSS, pola kas-struk). Foto di-`esc()` saat render rekap.
- Config save: perm owner (`outlet-settings` perm yang dipakai existing), `verifyCsrf()`.
- `clock_in`/`upload_selfie`: perm `absensi.clock`, `verifyCsrf()`.
- Best-effort + ErrorLogger; tak crash.
- PHP CLI `/opt/homebrew/bin/php`; mysql `/opt/homebrew/opt/mysql-client/bin/mysql`; deploy `git push origin main`.

## Scope (Spec A)

IN: toggle selfie + enforce; toggle geofence + map picker + radius + strict enforce (clock-in only); simpan selfie + tampil ikon di rekap.
OUT (sub-proyek B / nanti): jadwal shift, telat/lembur vs jadwal, geofence di clock-out, selfie clock-out, mode geofence longgar, export.

## Data Model

`outlets` (ALTER, idempotent `ADD COLUMN IF NOT EXISTS`):
```sql
ALTER TABLE outlets
  ADD COLUMN IF NOT EXISTS absensi_selfie_wajib   TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS absensi_geofence_aktif TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS absensi_lat            DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS absensi_lng            DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS absensi_radius_m       INT NOT NULL DEFAULT 100;
```
`hl_absensi`:
```sql
ALTER TABLE hl_absensi ADD COLUMN IF NOT EXISTS selfie_masuk VARCHAR(255) NULL AFTER lokasi_masuk;
```

## Komponen & File

- `migrations/2026-06-26-absensi-hardening.sql` (NEW): ALTER outlets + hl_absensi.
- `assets/vendor/leaflet.js` + `assets/vendor/leaflet.css` (NEW, vendored).
- `core/Geo.php` (NEW): `Geo::haversineMeters(lat1,lng1,lat2,lng2): float` (pure, testable).
- `outlet-settings.php` (MODIFY): section "Absensi & Geofence" (toggles + Leaflet map picker + radius), action `save_absensi`.
- `absensi.php` (MODIFY): action `upload_selfie`; extend `clock_in` (terima lat/lng/selfie_path + enforce); frontend clock-in (GPS + kamera per config); rekap tampilkan ikon selfie.
- `tests/absensi/test_geo.php` (NEW): unit haversine.

## Alur

**Setting (owner):** Outlet Settings → section Absensi → set toggle + (kalau geofence) geser pin di peta + radius (lingkaran tergambar) + tombol "Pakai lokasi saya" → Simpan → `save_absensi` UPDATE outlets.

**Clock-in (karyawan):**
1. Halaman absensi load → ambil config outlet (selfie_wajib, geofence_aktif, radius — lat/lng TIDAK perlu dikirim ke client, cukup flag).
2. Tap Clock In:
   - geofence_aktif → `navigator.geolocation.getCurrentPosition({enableHighAccuracy:true, timeout:10s})`. Gagal/ditolak → STOP toast "Aktifkan izin lokasi untuk clock-in".
   - selfie_wajib → buka kamera (input file capture=user). Belum foto → STOP toast "Ambil selfie dulu".
3. Upload selfie (jika ada) → `absensi.php?action=upload_selfie` → path.
4. POST `clock_in { lat, lng, selfie_path }`.
5. Server: baca config outlet karyawan. Jika geofence_aktif: butuh lat/lng valid + `Geo::haversineMeters` ke (absensi_lat,lng) ≤ absensi_radius_m, else `{error:'Di luar area outlet'}` / `{error:'Lokasi tak terdeteksi'}`. Jika selfie_wajib: selfie_path non-empty + prefix valid, else `{error:'Selfie wajib'}`. Lolos → INSERT hl_absensi (jam_masuk, lokasi_masuk="lat,lng", selfie_masuk=path, status hadir).
6. Sukses → widget "Clocked In".

## Error Handling

| Kondisi | Perilaku |
|---|---|
| geofence aktif, GPS ditolak/timeout | tolak clock-in, toast "Aktifkan izin lokasi" |
| geofence aktif, di luar radius | tolak, toast "Kamu di luar area outlet (>{radius}m)" |
| selfie wajib, tak ada foto | tolak, toast "Ambil selfie dulu" |
| upload selfie gagal/bukan image | toast error dari FileUpload |
| selfie_path invalid prefix | tolak (`bad_path`) |
| sudah clock-in hari ini | pesan existing "sudah clock in jam ..." |
| config off (default) | clock-in seperti sekarang (tanpa selfie/geofence) — backward compatible |

Best-effort + ErrorLogger.

## Testing

- **Unit (`Geo::haversineMeters`):** jarak 0 untuk titik sama; ~111km untuk selisih 1° lintang; titik dekat (mis. 50m) akurat ±beberapa meter; urutan argumen simetris.
- **Manual E2E:** owner set geofence (geser pin + radius) & toggle selfie → simpan. Karyawan: clock-in di dalam radius + selfie → sukses; di luar radius → ditolak; GPS off → ditolak; selfie wajib tanpa foto → ditolak; semua toggle off → clock-in normal. Cek `hl_absensi.selfie_masuk` + `lokasi_masuk` terisi; ikon selfie tampil di rekap.

## Out of Scope (Spec A)

- Jadwal shift + telat/lembur (Sub-proyek B).
- Geofence/selfie di clock-out.
- Mode geofence longgar (saat ini strict saja).
- Export rekap.
