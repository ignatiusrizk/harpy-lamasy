# Supplier DB + Purchase Order — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Master supplier (terstruktur, kontak + term) + Purchase Order (PO) terstruktur: pilih supplier + item + qty + harga → status draft/dipesan/diterima → terima barang auto generate mutasi masuk → riwayat pembelian per supplier. Upgrade dari supplier free-text + daftar belanja PDF. Gap vs Smartlink. Melengkapi inventori + opname.

**Scope:**
- Master supplier HQ-level (shared tenant): nama, kontak, telepon, alamat, term, catatan
- PO per outlet: supplier (master) + item bahan + qty + harga, no PO formal
- Lifecycle: draft → dipesan → diterima (full)
- Terima → mutasi `masuk` per item (pola existing) → stok naik
- Riwayat pembelian per supplier
- hl_bahan tetap field text supplier (backward compat) + supplier_id link opsional

**Out of scope (Phase 1):**
- Partial receive (full receive saja)
- "Buat PO dari daftar belanja" auto
- Auto-update hl_bahan.supplier_id saat terima
- PO approval workflow, cetak PO PDF formal, AP/hutang supplier ke keuangan, retur pembelian

---

## 2. Background

**Existing:**
- `hl_bahan.supplier` = VARCHAR free-text (per bahan)
- `api/inventori_po.php` = "Daftar Belanja PDF" — shopping list dari stok rendah, grouped by supplier text. Precursor (bukan PO terstruktur).
- Stock-in via `hl_bahan_mutasi` tipe='masuk' (stok_sebelum + qty = stok_sesudah, harga_beli, supplier text). Pola di `inventori.php:173,193`.
- `hl_bahan_stok` view (stok_terkini auto dari mutasi)
- Permission `inventori.manage`. inventori.php outlet-level; hq/inventori.php HQ.
- No-generator pattern (invoice/affiliate): `XXX/YYYY/MM/000N` counter per tenant per bulan.
- FOR UPDATE idempotency pattern (dari opname finalize).

**Gap:** no master supplier table, no structured PO, no receive→stock-in flow, no per-supplier purchase history.

---

## 3. Arsitektur

### 3.1 Komponen

**New:**
```
db/migrations/2026-06-24-supplier-po.sql   3 tabel + ALTER hl_bahan
hq/supplier.php                            master supplier CRUD + riwayat (HQ)
pembelian.php                              PO per outlet (list/create/dipesan/terima)
```

**Modified:**
```
hq/_layout_open.php       sidebar HQ: 👥 Supplier
components.php            sidebar outlet: 🛒 Pembelian + icon
.htaccess                 route /hq/supplier + /pembelian
```

Permission: reuse `inventori.manage` (no new permission). No view change.

### 3.2 Schema

```sql
CREATE TABLE hl_supplier (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(100) NOT NULL,
  kontak_nama VARCHAR(100) NULL,
  telepon VARCHAR(20) NULL,
  alamat TEXT NULL,
  term_pembayaran VARCHAR(50) NULL,
  catatan TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hl_po (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  supplier_id INT NOT NULL,
  no_po VARCHAR(40) NOT NULL,
  tanggal DATE NOT NULL,
  status ENUM('draft','dipesan','diterima','batal') NOT NULL DEFAULT 'draft',
  total BIGINT NOT NULL DEFAULT 0,
  catatan TEXT NULL,
  input_by INT NULL,
  dipesan_at DATETIME NULL,
  diterima_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_no_po (tenant_id, no_po),
  INDEX idx_outlet_status (tenant_id, outlet_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hl_po_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_id INT NOT NULL,
  tenant_id INT NOT NULL,
  bahan_id INT NOT NULL,
  qty INT NOT NULL,
  harga_satuan INT NOT NULL,
  subtotal BIGINT NOT NULL,
  mutasi_id INT NULL,
  INDEX idx_po (po_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE hl_bahan ADD COLUMN supplier_id INT NULL AFTER supplier;
```

### 3.3 Model
- Master supplier: tenant-level (shared semua outlet). 1 daftar per tenant.
- PO: per outlet. Supplier dari master shared, bahan dari hl_bahan outlet.
- No PO: `PO/YYYY/MM/000N` counter per tenant per bulan.
- hl_bahan.supplier text dipertahankan (backward compat); supplier_id link opsional (defer auto-fill).

### 3.4 Data Flow (PO Lifecycle)

```
1. BUAT PO (draft)
   - pilih supplier (master) + tanggal → INSERT hl_po (no_po generate, status draft)
   - tambah item: bahan outlet + qty + harga_satuan → subtotal; INSERT hl_po_item
   - edit item selama draft; UPDATE hl_po.total = Σ subtotal

2. DIPESAN (draft → dipesan)
   - validasi ≥1 item → UPDATE status='dipesan', dipesan_at=NOW()

3. TERIMA (dipesan → diterima) — transaksional, FOR UPDATE
   - lock hl_po FOR UPDATE + re-check status='dipesan' (anti double-receive)
   - untuk tiap hl_po_item:
       stok_sebelum = hl_bahan_stok.stok_terkini (bahan, outlet)
       INSERT hl_bahan_mutasi (tipe='masuk', jumlah=qty, stok_sebelum,
         stok_sesudah=stok_sebelum+qty, harga_beli=harga_satuan, supplier=nama_supplier,
         catatan="PO #{no_po}", input_by)
       UPDATE hl_po_item.mutasi_id
   - UPDATE hl_po status='diterima', diterima_at=NOW()
   - stok naik auto (view)
```

---

## 4. UI Spec

### 4.1 Master Supplier (hq/supplier.php)
```
👥 Master Supplier                    [+ Tambah Supplier]
Nama            Kontak   Telepon   Total PO   Nilai
PT Kimia Jaya   Budi     0812xxx   8          Rp 12jt   [✏️]
```
Modal: nama, kontak_nama, telepon, alamat, term_pembayaran, catatan. Soft delete. Total PO/Nilai = Σ PO diterima supplier itu.

### 4.2 Purchase Order (pembelian.php, outlet)
```
🛒 Purchase Order — Outlet Jakarta    [+ Buat PO]
No PO            Supplier      Tgl     Status      Total
PO/2026/06/0002  Toko Plastik  24 Jun  Dipesan     800rb  [👁][📥 Terima]
```
Form create/detail:
```
PO/2026/06/0002 [Draft]
Supplier: [PT Kimia Jaya ▼]   Tanggal: [24 Jun 2026]
Bahan            Qty   Harga Satuan  Subtotal  [x]
Detergen Cair    [20]  [15.000]      300.000   🗑️
[+ Tambah Item: bahan ▼]
Total: Rp 550.000
[💾 Simpan Draft] [📤 Tandai Dipesan]
(dipesan → [📥 Terima Barang]; diterima → read-only + link mutasi)
```
- Item dropdown bahan outlet, harga default harga_beli bahan, subtotal+total auto
- Edit item draft only; dipesan/diterima read-only

### 4.3 Backend Actions
**hq/supplier.php:** list_supplier (+ total PO/nilai), save_supplier, delete_supplier (soft)
**pembelian.php:** po_list, po_create (draft + no_po), po_get, po_save_items (draft), po_dipesan, po_terima (transaksional FOR UPDATE), supplier_options (dropdown), bahan_options (dropdown outlet)

CSRF semua POST. Permission inventori.manage. Tenant+outlet scope (PO); tenant scope (supplier master).

---

## 5. Edge Cases

| Skenario | Handler |
|----------|---------|
| Supplier soft-deleted tapi ada PO | PO tetap (supplier_id ref); hide dari pilihan baru |
| Terima 2× (double-click) | FOR UPDATE lock + re-check status='dipesan' → request ke-2 rollback |
| PO draft tanpa item | Tidak bisa dipesan (validasi ≥1 item) |
| Edit PO setelah dipesan | Read-only (draft only edit) |
| Bahan beda outlet di PO | Validasi bahan milik outlet PO |
| Batal PO | status='batal' (draft/dipesan saja; diterima tidak bisa) |
| Qty/harga ≤ 0 | Validasi |
| Terima PO draft (belum dipesan) | Tolak — harus dipesan dulu |
| Cross-tenant/outlet manipulation | WHERE tenant_id + outlet_id |

---

## 6. Security
- Permission `inventori.manage` semua action
- Tenant scope (supplier), tenant+outlet scope (PO/item/mutasi)
- Terima transaksional + FOR UPDATE lock (anti double mutasi masuk)
- CSRF semua POST. XSS htmlspecialchars semua render.
- Validasi qty/harga ≥ 1, supplier/bahan milik tenant/outlet

---

## 7. Testing Plan

### 7.1 Smoke Test
1. Migration → 3 tabel + hl_bahan.supplier_id
2. /hq/supplier → tambah supplier → list muncul
3. /pembelian (outlet) → Buat PO → pilih supplier + tambah 2 item (qty/harga) → total auto → simpan draft, no_po = PO/2026/06/0001
4. Tandai Dipesan → status dipesan
5. Terima Barang → konfirmasi → status diterima
6. Verify hl_bahan_mutasi: 2 mutasi masuk (qty, harga_satuan, catatan "PO #"), stok_sesudah=sebelum+qty
7. Verify hl_bahan_stok: stok naik sesuai qty
8. /hq/supplier → total PO/nilai supplier terupdate
9. Detail PO diterima: read-only + link mutasi

### 7.2 Edge Cases
| # | Test | Expected |
|---|------|----------|
| 1 | Terima 2× | Sekali (FOR UPDATE) |
| 2 | PO draft tanpa item → dipesan | Tolak |
| 3 | Terima PO draft | Tolak (harus dipesan) |
| 4 | Qty 0 | Reject |
| 5 | Edit PO diterima | Read-only |
| 6 | Cross-tenant po_id | 404 |
| 7 | Supplier soft-delete | Hide dari pilihan, PO lama tetap |

---

## 8. Implementation Phasing

6 commits, ~4 jam:
1. Migration 3 tabel + ALTER
2. hq/supplier.php (master CRUD + riwayat)
3. pembelian.php — PO list + create/draft + items + supplier/bahan options
4. po_dipesan + po_terima transaksional (FOR UPDATE + mutasi masuk)
5. Sidebar + route (HQ supplier + outlet pembelian)
6. E2E + deploy

---

## 9. Files Inventory

### New
- db/migrations/2026-06-24-supplier-po.sql
- hq/supplier.php
- pembelian.php

### Modified
- hq/_layout_open.php (sidebar)
- components.php (sidebar outlet + icon)
- .htaccess (route)

### Schema
- 3 tabel (hl_supplier, hl_po, hl_po_item) + ALTER hl_bahan add supplier_id. No change view/tabel lain.

---

## 10. Success Criteria
- ✅ Master supplier CRUD (HQ shared) + riwayat pembelian
- ✅ PO per outlet: supplier + item + qty + harga, no PO formal
- ✅ Lifecycle draft → dipesan → diterima
- ✅ Terima → mutasi masuk per item (pola existing), stok akurat
- ✅ Idempotent terima (FOR UPDATE, sekali)
- ✅ Backward compat: hl_bahan.supplier text utuh, supplier_id additive
- ✅ Zero impact inventori/opname/daftar-belanja existing

---

## 11. References
- `inventori.php` — mutasi masuk pattern (stok_sebelum+qty, harga_beli, supplier)
- `api/inventori_po.php` — daftar belanja (precursor)
- `hl_bahan_mutasi` (tipe masuk), `hl_bahan_stok` view, `hl_bahan` (harga_beli/supplier)
- No-generator pattern: invoice/affiliate `XXX/YYYY/MM/000N`
- FOR UPDATE idempotency: opname finalize (commit 44bde84)
- Permission `inventori.manage`
