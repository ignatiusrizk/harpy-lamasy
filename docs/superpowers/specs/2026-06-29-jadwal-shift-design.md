# Jadwal Shift Karyawan — Design Spec

> LaMaSy. Tanggal: 2026-06-29. Sub-proyek B dari enhancement Absensi (A = clock-in hardening sudah LIVE).

## Goal

Owner mendefinisikan shift (jam + toleransi telat + ambang lembur, configurable, di-seed beberapa template) dan menyusun **pola jadwal mingguan** (karyawan × hari → shift). Saat karyawan clock-in/out, sistem membandingkan dengan shift terjadwal → hitung **telat** & **lembur** (di-snapshot ke `hl_absensi`) → tampil di rekap absensi.

## Konteks Existing

- `absensi.php`: clock_in/out (sudah diperkuat geofence+selfie di Spec A), rekap_personal/rekap_all, izin, shift handover (tab). `hl_absensi` (jam_masuk/keluar/durasi/status + selfie_masuk/shift_id baru).
- Belum ada tabel shift/jadwal (hl_shift_handover = serah-terima, beda).
- Telat kasar sudah ada di `laporan.php` via `outlets.jam_buka` (1 ambang untuk semua) — **dibiarkan apa adanya**; rekap absensi pakai telat berbasis-shift yang presisi.
- `hl_karyawan_outlet` (karyawan per outlet), `hl_users`, BonusEvaluator (baca hl_absensi kolom eksplisit). Multi-tenant + outlet.

## Arsitektur

- Owner kelola shift (`hl_shift`) + pola mingguan (`hl_jadwal_shift`) lewat tab baru "Jadwal" di `absensi.php`.
- Saat clock_in: tentukan hari (date('N') 1–7) → cari jadwal (user, hari) → shift → hitung telat → snapshot `shift_id`+`telat_menit` ke `hl_absensi`. clock_out: hitung lembur → snapshot `lembur_menit`.
- Perhitungan murni di `core/ShiftCalc.php` (testable). Snapshot saat clock (bukan recompute) → histori adil walau config berubah.

## Tech Stack

- PHP 8 / MariaDB. `absensi.php`, `core/ShiftCalc.php` (baru), tabel `hl_shift` + `hl_jadwal_shift` (baru) + kolom `hl_absensi`. Test: CLI + `tests/_assert.php`.

## Global Constraints

- Multi-tenant: semua tabel & query scope `tenant_id` (+ `outlet_id`).
- Kelola shift/jadwal: perm `absensi.view` (owner/manajer) + `verifyCsrf()` di action mutasi.
- Telat/lembur **di-snapshot saat clock-in/out** ke `hl_absensi` (bukan dihitung ulang dari config yang bisa berubah).
- Karyawan tanpa shift terjadwal hari itu → `shift_id=null, telat_menit=0, lembur_menit=0` (libur/tanpa penalti).
- Backward-compat: tanpa jadwal sama sekali → clock-in seperti sekarang (telat/lembur 0). Tak merusak laporan.php / BonusEvaluator.
- Parameter (jam, toleransi_telat_menit, lembur_after_menit) **configurable owner per shift**.
- Best-effort + ErrorLogger; tak crash. PHP CLI `/opt/homebrew/bin/php`; mysql `/opt/homebrew/opt/mysql-client/bin/mysql`; deploy `git push origin main`.

## Data Model

```sql
CREATE TABLE IF NOT EXISTS hl_shift (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  nama VARCHAR(50) NOT NULL,
  jam_mulai TIME NOT NULL,
  jam_selesai TIME NOT NULL,
  toleransi_telat_menit INT NOT NULL DEFAULT 15,
  lembur_after_menit INT NOT NULL DEFAULT 30,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  urutan INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_outlet (tenant_id, outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_jadwal_shift (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  user_id INT NOT NULL,
  hari TINYINT NOT NULL,           -- 1=Senin … 7=Minggu (date('N'))
  shift_id INT NOT NULL,
  UNIQUE KEY uq_user_hari (tenant_id, outlet_id, user_id, hari),
  KEY idx_outlet (tenant_id, outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE hl_absensi
  ADD COLUMN IF NOT EXISTS shift_id INT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS telat_menit INT NOT NULL DEFAULT 0 AFTER shift_id,
  ADD COLUMN IF NOT EXISTS lembur_menit INT NOT NULL DEFAULT 0 AFTER telat_menit;
```
Template seed (saat owner buka tab Jadwal & outlet belum punya shift, tawarkan tombol "buat template"): Pagi 08:00–16:00, Sore 14:00–22:00, Full 08:00–20:00 (toleransi 15, lembur_after 30). Tidak auto-insert global; di-insert saat owner klik (hindari nyampah tenant yang tak pakai).

## Komponen & File

- `migrations/2026-06-29-jadwal-shift.sql` (NEW): 2 tabel + 3 kolom hl_absensi.
- `core/ShiftCalc.php` (NEW): `hitungTelat(string $jamMasuk, string $jamMulai, int $toleransiMenit): int` + `hitungLembur(string $jamKeluar, string $jamSelesai, int $lemburAfterMenit): int` (pure, menit).
- `tests/absensi/test_shiftcalc.php` (NEW): unit.
- `absensi.php` (MODIFY): actions `shift_list/shift_save/shift_delete/shift_seed_template`, `jadwal_get/jadwal_save`; tab "Jadwal" UI (kelola shift + grid mingguan); clock_in/clock_out snapshot telat/lembur; rekap tampilkan telat/lembur.

## Alur

**Owner (tab Jadwal):**
- Kelola Shift: list/tambah/edit/hapus `hl_shift`; tombol "Buat template" (seed 3 shift) kalau belum ada.
- Jadwal Mingguan: grid karyawan (baris, dari hl_karyawan_outlet) × Senin–Minggu (kolom); tiap sel dropdown shift atau "Libur"; Simpan → `jadwal_save` upsert per (user,hari); libur = hapus baris.

**clock_in (extend handler Spec A, setelah enforce geofence/selfie, sebelum/saat INSERT):**
- `$hari = (int)date('N')`. Cari `hl_jadwal_shift (tenant,outlet,user,hari)` → `shift_id` → `hl_shift`.
- Kalau ada: `telat_menit = ShiftCalc::hitungTelat($jam, $shift['jam_mulai'], $shift['toleransi_telat_menit'])`. Set `shift_id`+`telat_menit` di INSERT.
- Kalau tak ada: shift_id null, telat 0.

**clock_out (extend handler existing):**
- Ambil `shift_id` dari row absensi hari ini. Kalau ada → join hl_shift → `lembur_menit = ShiftCalc::hitungLembur($jam, $shift['jam_selesai'], $shift['lembur_after_menit'])`. Update bareng jam_keluar/durasi.

**Rekap:** rekap_personal & rekap_all join shift → tampilkan kolom Telat (badge menit) + Lembur (menit) per hari + ringkasan (total telat menit/count, total lembur menit).

## Logika Perhitungan (ShiftCalc)

- `hitungTelat(jamMasuk, jamMulai, toleransi)`: `selisih = detik(jamMasuk) - detik(jamMulai) - toleransi*60`; return `max(0, ceil(selisih/60))` menit. (clock-in sebelum/dalam toleransi → 0.)
- `hitungLembur(jamKeluar, jamSelesai, lemburAfter)`: `overshoot = detik(jamKeluar) - detik(jamSelesai)`; kalau `overshoot >= lemburAfter*60` → return `floor(overshoot/60)` menit, else 0. (Pulang lebih awal / dalam ambang → 0.)
- Jam disimpan/diproses sebagai TIME (HH:MM:SS) dalam satu hari (shift tak lintas tengah malam di MVP).

## Error Handling

| Kondisi | Perilaku |
|---|---|
| Karyawan tak dijadwalkan hari itu | shift_id null, telat/lembur 0 (tanpa penalti) |
| Hapus shift yang masih dipakai jadwal | tolak / set jadwal terkait ke libur (pilih: tolak dengan pesan) |
| jam_selesai < jam_mulai (shift lintas malam) | OUT OF SCOPE — validasi tolak saat simpan shift (jam_selesai harus > jam_mulai) |
| Clock-in tanpa jadwal & tanpa config | perilaku lama (telat 0) — backward-compatible |

## Testing

- **Unit (`ShiftCalc`):** telat: clock-in tepat jam mulai → 0; dalam toleransi (mis. +10 dari tol 15) → 0; lewat toleransi (+20, tol 15) → 5 menit; sebelum jam mulai → 0. lembur: pulang tepat jam selesai → 0; dalam ambang (+20, after 30) → 0; lewat ambang (+45, after 30) → 45 menit; pulang awal → 0.
- **Manual E2E:** owner buat shift + jadwal mingguan; karyawan clock-in telat → rekap tampil telat menit; clock-out lembur → rekap tampil lembur; karyawan libur (tak dijadwalkan) → telat/lembur 0; edit shift tak ubah histori absensi yang sudah ke-snapshot.

## Out of Scope (Spec B)

- Shift lintas tengah malam (jam_selesai < jam_mulai) — ditolak saat simpan.
- Override jadwal per-tanggal (tukar shift, hanya pola mingguan).
- Integrasi telat/lembur ke perhitungan gaji otomatis (BonusEvaluator) — rekap dulu; payroll integration spec terpisah kalau perlu.
- Notifikasi reminder shift.
- Export.
