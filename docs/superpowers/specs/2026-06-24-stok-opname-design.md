# Stok Opname — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Stok opname (stock-take): petugas outlet hitung stok fisik bahan, sistem bandingkan dengan stok tercatat, generate penyesuaian (mutasi adjust) untuk selisih. Riwayat sesi opname tersimpan untuk audit. Gap vs Smartlink ("Stok Opname"). Penting untuk akurasi inventori laundry (deterjen/parfum/plastik).

**Scope:**
- Sesi opname per-outlet (header + detail item), riwayat tersimpan
- Snapshot stok sistem saat sesi dibuat
- Input stok fisik per bahan → selisih auto
- Finalize → generate mutasi `adjust` per selisih (pola existing) → stok_terkini auto-update
- Tab "Stok Opname" di `/inventori.php` (outlet-level)

**Out of scope (Phase 1):**
- Jurnal gain/loss opname ke keuangan (nilai_selisih cuma info)
- HQ konsolidasi opname multi-outlet
- Scan barcode, opname terjadwal/reminder, export PDF berita acara

---

## 2. Background

**Inventori existing:**
- `hl_bahan` (master: nama, kategori, satuan, stok_awal, harga_beli, supplier, per outlet)
- `hl_bahan_mutasi` (tipe ENUM `masuk`/`keluar`/`adjust`/`transfer`, jumlah, stok_sebelum, stok_sesudah, catatan, input_by)
- `hl_bahan_stok` (VIEW: stok_terkini computed dari mutasi)
- `inventori.php` (outlet): tab stok + mutasi, action mutasi termasuk single-item `adjust`
- Permission `inventori.manage`

**Pola `adjust` existing (inventori.php):**
```php
// adjust pakai stok_aktual sebagai target
$stokAktual  = intval($d['stok_aktual']);   // target fisik
$jumlah      = abs($stokAktual - $stokSebelum);
$stokSesudah = $stokAktual;
// INSERT hl_bahan_mutasi (tipe='adjust', jumlah, stok_sebelum, stok_sesudah, ...)
```
View `hl_bahan_stok` baca `stok_sesudah` untuk adjust → stok_terkini = target. **Opname finalize reuse pola ini persis** per item dgn selisih.

**Greenfield:** tidak ada opname existing (verified). 2 tabel baru.

---

## 3. Arsitektur

### 3.1 Komponen

**New:**
```
db/migrations/2026-06-24-stok-opname.sql   2 tabel (hl_opname, hl_opname_item)
```

**Modified:**
```
inventori.php   tab "Stok Opname" + 5 AJAX action + finalize transaksional + JS
```

Permission: reuse `inventori.manage`. No view change, no schema change ke tabel existing.

### 3.2 Schema

```sql
CREATE TABLE hl_opname (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  tanggal DATE NOT NULL,
  status ENUM('draft','selesai') NOT NULL DEFAULT 'draft',
  total_item INT NOT NULL DEFAULT 0,
  total_selisih_item INT NOT NULL DEFAULT 0,
  nilai_selisih BIGINT NOT NULL DEFAULT 0,
  catatan TEXT NULL,
  input_by INT NULL,
  finalized_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_outlet (tenant_id, outlet_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hl_opname_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opname_id INT NOT NULL,
  tenant_id INT NOT NULL,
  bahan_id INT NOT NULL,
  stok_sistem INT NOT NULL,
  stok_fisik INT NULL,
  selisih INT NOT NULL DEFAULT 0,
  mutasi_id INT NULL,
  INDEX idx_opname (opname_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.3 Data Flow

```
1. BUAT SESI (opname_create) — status=draft
   - snapshot semua bahan aktif outlet dari hl_bahan_stok
   - INSERT hl_opname + INSERT hl_opname_item per bahan (stok_sistem=stok_terkini, stok_fisik=NULL, selisih=0)

2. INPUT FISIK (opname_save_fisik) — draft
   - petugas isi stok_fisik per bahan → UPDATE item, selisih = fisik - sistem
   - save bertahap (tetap draft)

3. FINALIZE (opname_finalize) — draft → selesai, transaksional
   - untuk tiap item dgn stok_fisik NOT NULL dan selisih ≠ 0:
       INSERT hl_bahan_mutasi (tipe='adjust', jumlah=abs(selisih),
         stok_sebelum=stok_sistem, stok_sesudah=stok_fisik,
         catatan="Opname #{id} {tanggal}", input_by)
       UPDATE hl_opname_item SET mutasi_id=?
   - hitung ringkasan: total_item, total_selisih_item (count selisih≠0),
     nilai_selisih = Σ(selisih × harga_beli bahan)
   - UPDATE hl_opname status='selesai', finalized_at, ringkasan
   - stok_terkini otomatis update (view baca mutasi adjust)
```

**stok_fisik NULL = tidak dihitung → skip** (tidak adjust). Hanya item yang di-input + selisih≠0 yang generate mutasi.

**Snapshot stok_sistem fixed** saat sesi dibuat. Opname idealnya saat outlet tidak transaksi (dokumentasikan).

---

## 4. UI Spec

### 4.1 Tab "Stok Opname" di /inventori.php

Riwayat:
```
[Stok] [Mutasi] [📋 Stok Opname]
Riwayat Opname                       [+ Mulai Opname Baru]
Tanggal      Status     Item  Selisih  Nilai
24 Jun 2026  ✓ Selesai   12     3     −Rp 45.000  [👁]
20 Jun 2026  Draft       12     -       -         [Lanjut]
```

Form input (draft):
```
Opname 24 Jun 2026 — Outlet Jakarta   [Draft]
Bahan            Satuan  Sistem  Fisik   Selisih
Detergen Cair    liter    25     [24]     −1
Parfum Lavender  ml      500     [520]   +20
Plastik          pcs     100     [100]     0
[💾 Simpan Draft]        [✅ Finalize & Adjust]
```
- Selisih auto-hitung saat input (merah kurang, hijau lebih)
- Finalize → konfirmasi → generate adjust → status Selesai (read-only)
- Detail (👁) sesi selesai: rincian item + link mutasi

### 4.2 Backend Actions (inventori.php)

| Action | Fungsi |
|--------|--------|
| `opname_list` | riwayat sesi outlet (tenant+outlet scope) |
| `opname_create` | buat draft + snapshot item dari hl_bahan_stok |
| `opname_get` | detail sesi + items |
| `opname_save_fisik` | simpan stok_fisik + recompute selisih (draft only) |
| `opname_finalize` | transaksional: adjust per selisih + ringkasan + selesai |

CSRF + permission `inventori.manage` semua. Tenant + outlet scope.

---

## 5. Edge Cases

| Skenario | Handler |
|----------|---------|
| Sesi draft sudah ada | Boleh lanjut draft atau buat baru (warning) |
| Bahan baru setelah snapshot | Tidak masuk sesi ini (snapshot fixed) |
| stok_fisik kosong saat finalize | Skip (tidak dihitung, no adjust, selisih 0) |
| Finalize 2× | status check draft→selesai sekali; selesai → tolak |
| Selisih 0 semua | Finalize sukses, no adjust, status selesai |
| stok_fisik negatif | Validasi ≥ 0 |
| Bahan dihapus saat draft | Saat finalize cek bahan masih ada (skip kalau hilang) |
| Cross-outlet/tenant | Query scoped outlet_id + tenant_id |
| Edit fisik setelah finalize | Read-only, tidak bisa |

---

## 6. Security

- Permission `inventori.manage` (existing) semua action
- Tenant + outlet scope semua query (opname, item, bahan, mutasi)
- Finalize transaksional (rollback on error) — adjust + ringkasan atomic
- stok_fisik validasi integer ≥ 0
- CSRF semua POST
- XSS: htmlspecialchars/esc render (nama bahan, catatan)

---

## 7. Testing Plan

### 7.1 Smoke Test
1. Migration → 2 tabel
2. /inventori → tab Stok Opname → Mulai Opname → sesi draft + snapshot semua bahan (stok_sistem = stok_terkini)
3. Input fisik beberapa bahan (1 kurang, 1 lebih, 1 sama) → selisih auto benar
4. Simpan draft → reload → fisik tersimpan
5. Finalize → konfirmasi → status Selesai
6. Verify hl_bahan_mutasi: 2 mutasi adjust (bahan selisih≠0), stok_sebelum=sistem, stok_sesudah=fisik, catatan "Opname #"
7. Verify hl_bahan_stok: stok_terkini = fisik untuk bahan yang di-adjust
8. Tab Mutasi → adjust opname muncul
9. Riwayat opname: ringkasan total_item/selisih/nilai benar
10. Detail sesi selesai (read-only)

### 7.2 Edge Cases
| # | Test | Expected |
|---|------|----------|
| 1 | Finalize tanpa input fisik (semua NULL) | Selesai, no adjust |
| 2 | Selisih 0 semua | Selesai, no adjust |
| 3 | Finalize 2× | Tolak (sudah selesai) |
| 4 | stok_fisik negatif | Reject validasi |
| 5 | Cross-tenant opname_id | 404/blocked |
| 6 | Nilai selisih (gain/loss) | Σ(selisih × harga_beli) benar |

---

## 8. Implementation Phasing

5 commits, ~3 jam:
1. Migration 2 tabel
2. Backend read actions (opname_list/create/get/save_fisik)
3. opname_finalize transaksional (adjust generation + ringkasan)
4. Tab UI + JS (riwayat + form input + finalize + detail)
5. E2E + deploy

---

## 9. Files Inventory

### New
- `db/migrations/2026-06-24-stok-opname.sql`

### Modified
- `inventori.php` — tab Opname + 5 action + finalize + JS

### Schema
- 2 tabel baru (hl_opname, hl_opname_item). No change tabel/view existing.

---

## 10. Success Criteria

- ✅ Buat sesi opname → snapshot stok sistem per bahan outlet
- ✅ Input fisik → selisih auto, save draft bertahap
- ✅ Finalize → mutasi adjust per selisih (pola existing), stok_terkini akurat
- ✅ Riwayat sesi + ringkasan (item, selisih, nilai gain/loss)
- ✅ Idempotent finalize (sekali), transaksional
- ✅ Zero impact inventori existing (reuse adjust + view)

---

## 11. References
- `inventori.php` — pola adjust existing (stok_aktual target, jumlah=abs, stok_sebelum/sesudah)
- `hl_bahan_mutasi` (tipe adjust), `hl_bahan_stok` (view stok_terkini), `hl_bahan` (harga_beli untuk nilai_selisih)
- Permission `inventori.manage`
