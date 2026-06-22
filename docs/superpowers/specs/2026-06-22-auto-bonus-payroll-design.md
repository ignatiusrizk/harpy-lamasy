# Auto-Bonus Absensi → Payroll — Design Spec

**Tanggal:** 2026-06-22
**Status:** Approved, ready for implementation plan
**Scope:** Master rule bonus/penalti yang dievaluasi otomatis saat owner generate gaji bulanan, dengan multi-outlet targeting + breakdown komponen per karyawan.

## Tujuan

Hilangkan beban owner hitung manual bonus karyawan tiap bulan. Owner set rule sekali (hadir penuh, tepat waktu, lembur, zero izin, penalti telat) → sistem otomatis evaluate per karyawan saat generate gaji + simpan breakdown jelas. Mengurangi human error + meningkatkan fairness payroll.

## Non-tujuan

- Tidak mengubah existing absensi flow (jam_masuk/keluar tetap manual via /absensi)
- Tidak menambah jam_kerja_resmi config baru — telat detect via existing `outlets.jam_buka`
- Tidak ada rule custom dengan PHP/SQL expression (semua via 5 enum tipe)
- Tidak ada bonus referral / komisi (di luar scope, sudah ada komisi penjualan terpisah)
- Tidak ada notifikasi karyawan saat bonus dapat (Phase 2, defer)

## Pendekatan

3 tabel baru: `hl_bonus_rule` (master), `hl_bonus_rule_outlet` (junction multi-outlet), `hl_gaji_komponen` (breakdown per gaji). Tidak ALTER `hl_gaji` — kolom existing `bonus`/`potongan`/`total` di-recompute dari komponen. Evaluator class `core/BonusEvaluator.php` query absensi + apply rules. Integrate ke existing generate gaji flow di `/hq/penggajian`. UI master di `/hq/bonus-rule` (NEW, owner-only) dengan multi-outlet checkbox (pattern sama dengan loyalty reward).

## Komponen

### Data Model

**Tabel baru `hl_bonus_rule`** — master rule per tenant:
```sql
CREATE TABLE hl_bonus_rule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(100) NOT NULL,
  tipe ENUM('hadir_penuh','tepat_waktu','lembur','zero_izin','penalti_telat') NOT NULL,
  threshold INT NOT NULL DEFAULT 0,
  amount INT NOT NULL DEFAULT 0,
  amount_per_unit TINYINT(1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Semantik `threshold` per tipe:
- `hadir_penuh`: tidak dipakai (rule pass kalau semua hari kerja status=hadir)
- `tepat_waktu`: minimum hari tepat waktu untuk trigger bonus (e.g., 25 hari)
- `lembur`: menit threshold per hari (e.g., 480 = 8 jam; lembur dihitung dari menit excess di atas 480)
- `zero_izin`: tidak dipakai (rule pass kalau count izin+sakit = 0)
- `penalti_telat`: maksimum telat boleh sebelum penalti aktif (e.g., 5 — telat ke-6 dst trigger penalti)

Semantik `amount`:
- Tipe positif (hadir_penuh, tepat_waktu, lembur, zero_izin) — rupiah positif
- Tipe penalti_telat — rupiah positif (auto-negated saat hitung), atau owner input langsung negative

`amount_per_unit`:
- 0 = flat (e.g., bonus Rp 500.000 sekali kalau rule trigger)
- 1 = per unit excess (lembur: amount per menit; penalti: amount per kelebihan telat)

**Tabel baru `hl_bonus_rule_outlet`** — junction multi-outlet:
```sql
CREATE TABLE hl_bonus_rule_outlet (
  rule_id INT NOT NULL,
  outlet_id INT NOT NULL,
  PRIMARY KEY (rule_id, outlet_id),
  INDEX idx_outlet (outlet_id),
  FOREIGN KEY (rule_id) REFERENCES hl_bonus_rule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Convention: junction empty = berlaku semua outlet (sama pattern dengan `hl_poin_reward_outlet`).

**Tabel baru `hl_gaji_komponen`** — breakdown per gaji:
```sql
CREATE TABLE hl_gaji_komponen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gaji_id INT NOT NULL,
  jenis VARCHAR(40) NOT NULL,
  rule_id INT NULL,
  nama VARCHAR(100) NOT NULL,
  amount INT NOT NULL,
  keterangan TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gaji (gaji_id),
  FOREIGN KEY (gaji_id) REFERENCES hl_gaji(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`jenis` values:
- `pokok` — gaji pokok (1 row per gaji, rule_id NULL)
- `bonus_hadir_penuh`, `bonus_tepat_waktu`, `bonus_lembur`, `bonus_zero_izin` — bonus dari rule (rule_id terisi)
- `penalti_telat` — komponen negatif (rule_id terisi)
- `manual` — owner adjust ad-hoc (rule_id NULL, owner input)

`amount` positive=bonus, negative=potongan.

### Backend: `core/BonusEvaluator.php` (NEW)

```php
class BonusEvaluator {
    /** Evaluate rules untuk karyawan dalam bulan, return array komponen (tidak persist). */
    public static function evaluate(int $tid, int $userId, string $bulan, int $gajiPokok): array;

    /** Evaluate + persist komponen ke hl_gaji_komponen, recompute hl_gaji.bonus/potongan/total. */
    public static function applyToGaji(int $gajiId): void;
}
```

Logic `applyToGaji`:
1. Load `hl_gaji` row + user + outlet
2. DELETE existing komponen untuk gaji_id (idempotent — bisa re-run)
3. INSERT komponen `pokok` = gaji_pokok existing
4. Resolve karyawan outlet_id → query rules yang apply (junction logic: empty junction OR outlet match)
5. Query absensi karyawan dalam bulan (filter by `tanggal LIKE 'YYYY-MM%'`)
6. Per rule, evaluate tipe-specific:
   - `hadir_penuh`: count(hadir) == workdays(bulan); if yes → komponen `bonus_hadir_penuh` amount=amount
   - `tepat_waktu`: count(jam_masuk <= outlet.jam_buka AND status=hadir) >= threshold; if yes → komponen `bonus_tepat_waktu`
   - `lembur`: sum(MAX(0, durasi_menit - threshold)) di hari hadir; if amount_per_unit → komponen `bonus_lembur` amount = sum_excess * amount; else flat kalau total_lembur > 0
   - `zero_izin`: count(status IN (izin, sakit)) == 0; if yes → komponen `bonus_zero_izin`
   - `penalti_telat`: count(jam_masuk > outlet.jam_buka) excess > threshold; if amount_per_unit → komponen amount = -excess * amount; else flat negative
7. SUM positive komponen → `hl_gaji.bonus`; SUM negative komponen → `hl_gaji.potongan` (absolute value)
8. `total` = `gaji_pokok` + `bonus` - `potongan`
9. UPDATE `hl_gaji`
10. `logAudit('gaji_bonus_eval', 'gaji', "id=$gajiId rules=N")`

Helper:
- `workdays(string $bulan): int` — hitung jumlah hari kerja dalam bulan. MVP: Senin-Sabtu (skip Minggu); future: configurable per outlet.

### Halaman 1: `/hq/bonus-rule` (NEW, owner-only)

Pattern sama dengan `/hq/loyalty`.

Akses: `hq_guard.php` + role check `owner|superadmin`. Sidebar item gated.

Layout:
```
🎯 Bonus & Penalti Rule

[+ Tambah Rule]
─────────────────────────────────────────
[Card per rule]
- Nama + tipe + amount/threshold + outlets badge
- Edit/Toggle/Hapus actions
```

Modal create/edit:
- Nama
- Tipe (5 enum)
- Threshold (number) — label berubah per tipe ("hari" / "menit" / "kali telat")
- Amount (number) — bisa negative untuk penalti
- Per-unit checkbox (untuk lembur/penalti)
- Multi-outlet scope (radio Semua / Tertentu + checkbox outlets)
- Aktif toggle

### Halaman 2: `/hq/penggajian` extend (existing)

Tambah checkbox "✓ Evaluate auto-bonus" (default on) di generate flow.

Saat generate gaji bulan:
- Existing: INSERT `hl_gaji` per karyawan dengan gaji_pokok
- NEW: kalau checkbox on, call `BonusEvaluator::applyToGaji($gajiId)` per karyawan

Tampilan list hasil:
- Tombol "▾ Detail" per row → expand komponen breakdown
- Komponen list: jenis, nama, amount, rule_id (link ke rule master)
- Tombol "+ Komponen Manual" untuk owner adjust ad-hoc (insert komponen jenis='manual', re-compute total)
- Tombol "🔄 Re-evaluate" per gaji → re-run applyToGaji (idempotent)

### Halaman 3: `/api/payslip.php` extend (existing print)

Existing payslip print sudah ada. Tambah section "Breakdown Komponen" di output kalau ada `hl_gaji_komponen` rows untuk gaji tersebut. Tabel sederhana: nama komponen | amount.

### Permissions

Permission baru:
- `bonus_rule.manage` — kelola master rule di /hq/bonus-rule (owner+superadmin default)

Reuse:
- `karyawan.gaji` (existing) — generate gaji + applyToGaji

### Sidebar

`hq/_layout_open.php`: tambah item di group HR atau Master:
```php
<?php if ($hqIsOwner): ?>
<a href="/hq/bonus-rule" class="hq-side-link ..."><span class="ico">🎯</span> Bonus Rule</a>
<?php endif; ?>
```

### Concurrency & Edge Cases

- Re-run idempotent: DELETE komponen sebelum re-INSERT
- Karyawan baru join mid-month → workdays count dari tgl_masuk
- Rule deleted → komponen lama tetap (history preserved), nama tetap di komponen, rule_id orphan OK
- Outlet `jam_buka` NULL → skip telat/tepat_waktu rules (no eval, no komponen)
- Karyawan tanpa absensi bulan → rules fail trigger (no komponen except pokok)

### Audit

- `bonus_rule_save` saat create/edit
- `bonus_rule_delete` saat soft-delete (is_active=0)
- `gaji_bonus_eval` saat applyToGaji, log: gaji_id + jumlah rules evaluated + total bonus + total potongan

### Testing

Manual:
1. Owner /hq/bonus-rule → buat rule A: hadir_penuh +Rp500k (semua outlet), rule B: lembur >480 menit, +Rp200/menit excess (outlet Tebet)
2. Owner /hq/karyawan: ada karyawan Budi di outlet Tebet
3. Karyawan Budi: 26 hari kerja bulan, semua hadir; lembur total 600 menit (excess 120m dari 480 base per hari kalkulasi salah, akan dijelaskan di plan)
4. Owner /hq/penggajian: pilih bulan, check "Evaluate auto-bonus", klik Generate
5. Lihat detail Budi: pokok + bonus hadir 500k + bonus lembur 200*120=24k → total gaji_pokok + 524k
6. Klik "+ Komponen Manual" → tambah THR Rp 1jt → total naik
7. Klik "🔄 Re-evaluate" → komponen manual TIDAK ke-hapus (only auto-evaluate rules)
8. Print payslip → breakdown muncul
9. Karyawan beda outlet (Mall): rule B tidak apply → no bonus lembur
10. Test edge: karyawan tanpa absensi → cuma komponen pokok

## Out of scope (Phase 2, defer)

- Notifikasi WA ke karyawan saat bonus dapat
- Bonus referral / komisi performa penjualan
- Custom rule via expression engine
- Per-outlet jam_buka beda per hari (Senin-Jumat vs Sabtu)
- Public holiday calendar (otomatis libur dari list nasional)
- Bonus prorata kalau karyawan join mid-month
- Multi-month rolling rule (e.g., "3 bulan berturut hadir penuh → bonus tambahan")
