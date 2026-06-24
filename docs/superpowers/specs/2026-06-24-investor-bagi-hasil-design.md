# Investor & Bagi Hasil — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Owner laundry bisa kelola **investor (pemodal)** + hitung & distribusi **bagi hasil** dari laba periode. Gap vs Smartlink (mereka punya "Data Investor" + "Bagi Hasil"). Diferensiasi untuk laundry yang dimodali partner/investor.

**Scope:**
- CRUD investor: nama, kontak, scope (seluruh bisnis / 1 outlet), modal disetor, % kepemilikan
- Setoran modal investor → tercatat di ekuitas (jurnal `modal_disetor` existing)
- Hitung bagi hasil per investor per periode = laba bersih scope × % kepemilikan
- Distribusi: catat pembayaran → Kas Keluar + jurnal `prive` → kurangi kas + ekuitas
- History distribusi (snapshot laba/% saat dibayar)
- Integrasi penuh dgn modul Keuangan existing (Neraca, Perubahan Modal, Arus Kas, Buku Besar)

**Out of scope (Phase 1):**
- Portal investor (login lihat bagi hasil sendiri)
- Statement PDF per investor
- Cap table history (riwayat perubahan %)
- Multi-currency, pajak dividen
- Notifikasi WA ke investor

---

## 2. Background

**Existing yang relevan:**
- Modul Keuangan SAK EMKM: `hl_jurnal_manual` (tipe `modal_disetor`, `prive` dengan `coa_id`, `arah` debit/kredit), `FinancialCalculator::labaRugi()` (laba bersih per periode, konsolidasi kalau outlet_id=null), `neraca()` ekuitas, Perubahan Modal + Buku Besar (baru dibangun).
- `hl_kas`: tipe enum(masuk/keluar), kategori varchar(50), keterangan, jumlah decimal(12,2), outlet_id — untuk catat kas keluar distribusi.
- `modal_disetor` saat ini lump equity (tidak per-investor).

**Gap:** Belum ada entitas investor, belum ada % kepemilikan per pemodal, belum ada perhitungan + distribusi bagi hasil.

**Why integrasi via jurnal+kas existing:** Bagi hasil = distribusi laba ke pemilik modal = pengurangan ekuitas, identik perlakuan `prive`. Setoran investor = `modal_disetor`. Dengan mengalir lewat jurnal+kas yang sudah jadi single source of truth, Neraca/Perubahan Modal/Arus Kas/Buku Besar otomatis konsisten — tanpa double-counting.

---

## 3. Arsitektur

### 3.1 Komponen

**New:**
```
db/migrations/2026-06-24-investor-bagi-hasil.sql   ← 2 tabel + permission seed
core/BagiHasilCalculator.php                       ← hitung() + distribusi() transaksional
hq/investor.php                                    ← UI 2 tab + AJAX CRUD + distribusi
```

**Modified:**
```
hq/_layout_open.php        ← sidebar link "👥 Investor" (group Keuangan)
.htaccess                  ← route /hq/investor (kedua arah: 301 + internal rewrite)
core/TenantProvisioner.php ← seed permission investor.manage ke role owner
core/TenantQuery.php       ← register hl_investor + hl_bagi_hasil (kalau perlu scope/outletTables)
```

### 3.2 Schema

```sql
CREATE TABLE hl_investor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(100) NOT NULL,
  telepon VARCHAR(20) NULL,
  catatan TEXT NULL,
  scope ENUM('tenant','outlet') NOT NULL DEFAULT 'tenant',
  outlet_id INT NULL,                          -- diisi kalau scope='outlet'
  modal_disetor BIGINT NOT NULL DEFAULT 0,     -- total setoran (informasional)
  persentase DECIMAL(5,2) NOT NULL DEFAULT 0,  -- % kepemilikan dalam scope-nya
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  joined_at DATE NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_scope (tenant_id, scope, outlet_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hl_bagi_hasil (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  investor_id INT NOT NULL,
  periode VARCHAR(7) NOT NULL,               -- YYYY-MM
  laba_basis BIGINT NOT NULL,                -- snapshot laba bersih scope periode
  persentase DECIMAL(5,2) NOT NULL,          -- snapshot % saat distribusi
  jumlah BIGINT NOT NULL,                    -- round(laba_basis × persentase / 100)
  status ENUM('pending','dibayar') NOT NULL DEFAULT 'pending',
  kas_id INT NULL,                           -- link hl_kas saat dibayar
  jurnal_id INT NULL,                        -- link hl_jurnal_manual (prive)
  dibayar_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_inv_periode (tenant_id, investor_id, periode),
  INDEX idx_periode (tenant_id, periode, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Permission seed: `investor.manage` (owner-only) ke role owner tenant.

### 3.3 Konsep Scope

- `scope='tenant'` → laba basis = `labaRugi(tid, null, periode)['laba_bersih']` (konsolidasi)
- `scope='outlet'` → laba basis = `labaRugi(tid, outlet_id, periode)['laba_bersih']`
- Pool % independen per scope: investor tenant-wide saling jumlah; investor per-outlet dijumlah per outlet-nya.
- Validasi: total % dalam 1 pool > 100% → **warning** (tidak hard-block; sisa = jatah owner).

### 3.4 Snapshot

`hl_bagi_hasil` simpan `laba_basis` + `persentase` + `jumlah` saat distribusi. History tidak berubah meski % investor diedit kemudian (audit trail).

---

## 4. Data Flow

### 4.1 CRUD Investor + Setoran Modal

```
[Owner /hq/investor tab "Daftar Investor" → Tambah]
   ↓
Input: nama, telepon, scope (tenant/outlet → dropdown outlet), modal_disetor, persentase, joined_at, catatan
   ↓ (transaksional)
INSERT hl_investor
   ↓
Kalau modal_disetor > 0:
   INSERT hl_jurnal_manual (tenant_id, outlet_id[scope], coa_id=COA modal 3-1001,
     tanggal=joined_at/now, periode, tipe='modal_disetor', arah='kredit',
     jumlah=modal_disetor, keterangan="Setoran modal investor: {nama}")
   → ekuitas Neraca naik otomatis
   ↓
Warning kalau SUM(persentase) dalam scope-pool > 100%
```

**Edit modal:** kalau owner ubah `modal_disetor`, catat selisih sebagai jurnal baru (delta), bukan overwrite — jaga konsistensi ekuitas. (Atau: modal edit = tambah entry baru "penyesuaian modal". MVP: catat delta.)

### 4.2 Hitung + Distribusi Bagi Hasil

```
[Tab "Bagi Hasil" → pilih periode YYYY-MM]
   ↓
BagiHasilCalculator::hitung(tid, periode):
   untuk tiap investor aktif:
     labaBasis = labaRugi(tid, scope=='tenant'?null:outlet_id, periode)['laba_bersih']
     jumlah    = (int) round(labaBasis × persentase / 100)
     status    = cek hl_bagi_hasil existing (pending/dibayar) periode ini
   return list {investor, scope, persentase, laba_basis, jumlah, status}
   ↓
[Owner klik "Bayar" (1 investor) / "Distribusi Semua"]
   ↓ (transaksional per investor — laba > 0 required)
   1. UPSERT hl_bagi_hasil (snapshot laba_basis, persentase, jumlah, status='dibayar', dibayar_at=NOW())
   2. INSERT hl_kas (tipe='keluar', kategori='bagi_hasil', jumlah,
        outlet_id[scope/0], keterangan="Bagi hasil investor {nama} {periode}")
   3. INSERT hl_jurnal_manual (tipe='prive', arah='debit', coa_id=COA prive 3-1003,
        jumlah, periode, keterangan="Bagi hasil investor: {nama} {periode}")
   4. UPDATE hl_bagi_hasil SET kas_id, jurnal_id
   ↓
Badge "✓ Dibayar"; kas berkurang; ekuitas berkurang (lewat prive)
```

### 4.3 Integrasi Keuangan (otomatis, no double-count)

| Modul | Interaksi |
|-------|-----------|
| Laba Rugi | Source `laba_basis` (read-only) |
| Neraca ekuitas | Setoran → `modal_disetor`; distribusi → `prive` (via getSaldoManual) |
| Perubahan Modal | Setoran investor di "Setoran Modal"; distribusi di "Prive" |
| Arus Kas | Distribusi = kas keluar (via hl_kas) |
| Buku Besar | Akun 3-1001 Modal + 3-1003 Prive tampilkan entry investor |

---

## 5. UI Spec

### 5.1 Tab "Daftar Investor"

```
┌────────────────────────────────────────────────────────────┐
│ 👥 Investor                              [+ Tambah Investor] │
│ Nama       Scope           Modal        %     Status         │
│ Budi S.    Seluruh Bisnis  50.000.000  40%   ● Aktif  [✏️]   │
│ Ani W.     Outlet Bandung  30.000.000  50%   ● Aktif  [✏️]   │
│ ⚠️ Total kepemilikan "Seluruh Bisnis": 40% (sisa 60% owner)  │
└────────────────────────────────────────────────────────────┘
```
Modal tambah/edit: nama, telepon, scope (radio → dropdown outlet kalau outlet), modal disetor, %, joined_at, catatan. Hapus = soft delete (is_active=0).

### 5.2 Tab "Bagi Hasil"

```
┌────────────────────────────────────────────────────────────┐
│ 💰 Bagi Hasil    Periode: [Juni 2026 ▼]  [Distribusi Semua] │
│ Investor   Scope      Laba Basis    %     Bagi Hasil  Status │
│ Budi S.    Bisnis     8.000.000    40%   3.200.000   [Bayar] │
│ Ani W.     Bandung    3.000.000    50%   1.500.000   ✓ Dibayar│
│ Total didistribusi periode ini: Rp 4.700.000                 │
└────────────────────────────────────────────────────────────┘
```
- Periode rugi (laba_basis < 0) → baris merah, tombol Bayar **disabled** (tidak distribusi rugi sebagai kas keluar)
- Sudah dibayar → badge ✓, tombol jadi non-aktif

### 5.3 Akses
- Permission `investor.manage` (owner-only), seed ke role owner
- Sidebar group **Keuangan** → "👥 Investor"
- HQ-level page `/hq/investor` (data konsolidasi, konsisten keuangan/laporan)

---

## 6. Backend Logic

### 6.1 BagiHasilCalculator

```php
class BagiHasilCalculator {
    // Hitung bagi hasil semua investor aktif untuk periode (read-only)
    public static function hitung(int $tenantId, string $periode): array;
    // Distribusi 1 investor: UPSERT bagi_hasil + INSERT kas + jurnal prive (transaksional)
    // Return ['ok'=>bool, 'jumlah'=>int, 'error'=>?string]. Tolak kalau laba_basis <= 0.
    public static function distribusi(int $tenantId, int $investorId, string $periode): array;
}
```

`hitung()`: loop investor aktif, panggil `FinancialCalculator::labaRugi()` per scope (cache laba per scope-key supaya tidak hitung ulang konsolidasi berkali-kali), hitung jumlah, join status dari hl_bagi_hasil.

`distribusi()`: DB transaction — UPSERT hl_bagi_hasil → INSERT hl_kas → INSERT hl_jurnal_manual → UPDATE link id. Rollback on error. Idempotent: kalau sudah status='dibayar' periode itu → return error "sudah didistribusi".

### 6.2 hq/investor.php Actions
- `list_investor` (GET) — daftar + total % per pool + warning
- `save_investor` (POST) — insert/update + jurnal modal (delta)
- `delete_investor` (POST) — soft delete
- `bagi_hasil_list` (GET) — BagiHasilCalculator::hitung(periode)
- `distribusi` (POST) — 1 investor; `distribusi_semua` (POST) — loop semua pending laba>0
- CSRF + permission `investor.manage` + tenant scope semua.

---

## 7. Security

- Permission `investor.manage` (owner only)
- Tenant scope: semua query WHERE tenant_id=?; outlet validation (outlet milik tenant)
- CSRF di semua POST
- Distribusi transaksional (rollback on partial failure)
- Snapshot mencegah tampering history
- XSS: htmlspecialchars nama/catatan; numeric via fmtRp

---

## 8. Edge Cases

| Skenario | Handler |
|----------|---------|
| Laba periode negatif | Bagi hasil tampil negatif, distribusi disabled |
| Total % > 100% per pool | Warning, tetap simpan (sisa jatah owner) |
| Investor outlet, outlet closed/dihapus | Laba basis 0, badge "outlet nonaktif", skip distribusi |
| Distribusi 2× periode sama | UNIQUE (investor, periode); sudah 'dibayar' → tolak "sudah didistribusi" |
| Edit % setelah distribusi | History pakai snapshot; periode baru pakai % baru |
| Hapus investor punya history | Soft delete (is_active=0), history hl_bagi_hasil tetap |
| Edit modal_disetor | Catat delta sebagai jurnal baru (jaga ekuitas konsisten) |
| Modal disetor = 0 saat create | Skip jurnal, investor tetap tercatat (% bisa 0 → bagi hasil 0) |
| Distribusi semua, sebagian laba ≤ 0 | Skip yang rugi, proses yang laba > 0 |

---

## 9. Testing Plan

### 9.1 Smoke Test
1. Migration apply → 2 tabel + permission `investor.manage` ada
2. /hq/investor → tab Daftar Investor render
3. Tambah investor scope=tenant, modal 50jt, 40% → tersimpan + jurnal modal_disetor masuk (cek Neraca ekuitas naik 50jt)
4. Tambah investor scope=outlet (Bandung), 30jt, 50%
5. Warning total % per pool tampil
6. Tab Bagi Hasil periode berjalan → laba basis tenant (konsolidasi) + outlet (Bandung) benar, jumlah = laba × %
7. Klik "Bayar" investor tenant → kas keluar 'bagi_hasil' masuk + jurnal prive + badge Dibayar
8. Cross-check: Neraca prive naik, Arus Kas keluar naik, Buku Besar akun Prive (3-1003) ada entry, Perubahan Modal prive naik
9. Distribusi 2× periode sama → ditolak "sudah didistribusi"
10. Periode rugi → tombol bayar disabled

### 9.2 Edge Cases
| # | Test | Expected |
|---|------|----------|
| 1 | Laba rugi → distribusi | Disabled |
| 2 | Total % > 100 | Warning, tetap simpan |
| 3 | Soft delete investor | is_active=0, history tetap |
| 4 | Cross-tenant id manipulation | WHERE tenant_id → 404/blocked |
| 5 | Modal 0 | Investor tersimpan tanpa jurnal |
| 6 | Edit % setelah bayar | Snapshot history tidak berubah |

---

## 10. Implementation Phasing

5 commits, ~3.5-4 jam:

1. **Schema + permission** (~20 menit): migration 2 tabel + seed investor.manage + TenantQuery register
2. **BagiHasilCalculator** (~60 menit): hitung() + distribusi() transaksional
3. **investor.php CRUD** (~75 menit): tab Daftar Investor + save (jurnal modal) + soft delete + warning %
4. **investor.php Bagi Hasil** (~60 menit): tab bagi hasil + distribusi + kas/jurnal + distribusi semua
5. **Wiring + E2E** (~40 menit): sidebar + .htaccess route + permission wiring + smoke test + cross-check keuangan

---

## 11. Files Inventory

### New
- `db/migrations/2026-06-24-investor-bagi-hasil.sql`
- `core/BagiHasilCalculator.php`
- `hq/investor.php`

### Modified
- `hq/_layout_open.php` (sidebar)
- `.htaccess` (route)
- `core/TenantProvisioner.php` (permission seed)
- `core/TenantQuery.php` (register tabel, kalau perlu)

### Schema
- 2 tabel baru (hl_investor, hl_bagi_hasil) + 1 permission

---

## 12. Success Criteria

- ✅ Owner CRUD investor dgn scope tenant/outlet + % kepemilikan
- ✅ Setoran modal investor → ekuitas Neraca naik (jurnal modal_disetor)
- ✅ Bagi hasil dihitung benar = laba bersih scope × %
- ✅ Distribusi → kas keluar + prive, badge Dibayar, history snapshot
- ✅ Konsisten dgn Neraca / Perubahan Modal / Arus Kas / Buku Besar (no double-count)
- ✅ Periode rugi: distribusi disabled
- ✅ Zero regression modul keuangan existing

---

## 13. References

- `core/FinancialCalculator.php` — `labaRugi()`, `getSaldoManual()`, jurnal modal_disetor/prive
- `hl_jurnal_manual` (coa_id, arah, tipe modal_disetor/prive), `hl_kas` (tipe/kategori/jumlah/outlet_id), `hl_coa` (3-1001 Modal Disetor, 3-1003 Prive)
- Buku Besar + Perubahan Modal (sibling baru): `docs/superpowers/specs/2026-06-24-keuangan-buku-besar-perubahan-modal-design.md`
- Permission seed pattern: `core/TenantProvisioner.php::seedPermissions()`
