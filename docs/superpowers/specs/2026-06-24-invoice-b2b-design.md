# Invoice B2B Document — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Tenant laundry bisa generate **dokumen invoice profesional** (HTML print → PDF) untuk klien B2B (hotel, restoran, kos) dari piutang yang sudah ada. Invoice menampilkan kop outlet, nomor invoice formal, rincian per-order, total + PPN opsional, dan info pembayaran — cocok untuk kebutuhan accounting klien B2B.

**Scope:**
- Dokumen invoice HTML print-friendly di `api/invoice.php?id={piutang_id}`
- Nomor invoice formal (`INV/YYYY/MM/0001`) generate saat di-tagih
- Line-item per-order (tanggal, no order, total) dari periode piutang
- PPN opsional (default 0, owner set per invoice)
- Letterhead outlet + logo (kalau ada `tenants.logo_path`)
- Status badge (LUNAS / SEBAGIAN / BELUM LUNAS)
- Info pembayaran dari payment methods outlet
- Tombol cetak di `piutang.php`

**Out of scope (Phase 1):**
- Public invoice link via token (klien akses tanpa login) — owner cetak PDF manual, kirim ke klien
- Recurring auto-generate (cron bulanan)
- Portal B2B client (klien lihat semua invoice sendiri)
- Payment online dari invoice
- Per-item granularity (per layanan dalam order) — pakai per-order saja
- Multi-currency
- Email invoice otomatis (WA link existing sudah cukup)

---

## 2. Background

**Current state — sudah ada `piutang.php` + `hl_piutang`:**

Sistem AR/billing B2B sudah jalan:
- `generate` — buat tagihan single customer per periode dari hl_transaksi
- `generate_bulk` — Faktur Massal: generate semua customer B2B sekaligus per periode
- `mark_invoiced` — tandai terkirim + WA link ringkasan (200 coin via CoinLedger)
- `bayar` — catat pelunasan (partial/full), update status
- `reminder` — kirim reminder
- Status: belum_tagih / sudah_tagih / sebagian / lunas
- Aging: outstanding, due_week, overdue
- `sisa_tagihan` STORED GENERATED column

**Schema existing:**
- `hl_piutang`: pelanggan_id, periode_start, periode_end, jatuh_tempo, total_order, total_tagihan, total_dibayar, sisa_tagihan, status, invoice_sent_at, lunas_at
- `hl_transaksi`: no_order, tanggal, total, dp, pelanggan_id, outlet_id
- `hl_transaksi_item`: nama_layanan, satuan, jumlah, harga_satuan, subtotal (per order — untuk detail kalau perlu)
- `tenants.logo_path`: VARCHAR(255) NULL — logo untuk letterhead
- `hl_payment_methods`: payment methods per outlet (baru dibangun) — untuk info pembayaran

**Pain point / gap vs Smartlink:**
- Smartlink punya "Faktur Massal (Invoice Hotel)" — bulk invoice generation + dokumen invoice formal
- LAMASY: bulk generate sudah ada, tapi `mark_invoiced` cuma kirim WA text ringkasan. **Tidak ada dokumen invoice formal dengan rincian** yang bisa dipakai klien B2B untuk accounting mereka
- Klien hotel/resto butuh: nomor invoice, kop perusahaan, rincian order, PPN, untuk proses pembayaran internal mereka

**Why HTML print (bukan dompdf):**
- Codebase pakai pattern HTML print + `window.print()` di `api/payslip.php`, `api/label.php`, struk
- No dompdf dependency di project
- Browser "Save as PDF" sudah cukup + konsisten dengan existing UX

---

## 3. Arsitektur

### 3.1 Komponen

**New:**
```
api/invoice.php?id={piutang_id}[&auto_print=1]   ← HTML invoice print-friendly
db/migrations/2026-06-24-invoice-b2b.sql          ← 2 ALTER columns
```

**Modified:**
```
piutang.php   ← tombol "Cetak Invoice" + generate invoice_no di mark_invoiced + PPN input
```

**Existing reused (no change):**
- `hl_piutang` data (periode, pelanggan, total)
- `hl_transaksi` (line-item source — re-query live)
- `tenants.logo_path` (letterhead)
- `hl_payment_methods` (info pembayaran)

### 3.2 Schema Delta

```sql
ALTER TABLE hl_piutang
  ADD COLUMN invoice_no VARCHAR(40) NULL AFTER id,
  ADD COLUMN pajak_persen DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER total_tagihan;
```

- `invoice_no`: nomor formal, NULL sampai di-tagih (`mark_invoiced`). Format `INV/2026/06/0001`. Immutable setelah di-set.
- `pajak_persen`: PPN % (default 0). Owner set saat tagih. Invoice hitung pajak dari total_tagihan.

Additive, nullable/default — existing piutang rows tidak terdampak.

### 3.3 Data Flow

```
┌──────────────────────────────────────────────────┐
│ A. GENERATE INVOICE NUMBER (saat tagih)           │
└──────────────────────────────────────────────────┘
[Owner /piutang → klik "Tagih" pada baris piutang]
       ↓
Modal mark_invoiced: input PPN % (default 0)
       ↓
mark_invoiced POST:
  - kalau invoice_no NULL → generateInvoiceNo() → INV/YYYY/MM/000N
  - simpan pajak_persen
  - status = sudah_tagih, invoice_sent_at = NOW()
  - deduct coin (existing)
  - return WA link (ringkasan + sebut nomor invoice)

┌──────────────────────────────────────────────────┐
│ B. CETAK INVOICE                                  │
└──────────────────────────────────────────────────┘
[Owner klik "Cetak Invoice" pada baris piutang]
       ↓
GET /api/invoice.php?id={piutang_id}
       ↓
tenant_guard + permission laporan.export
       ↓
Load hl_piutang (WHERE id + tenant_id + outlet_id) → 404 kalau gak ada
       ↓
Load pelanggan (nama, alamat, telepon)
       ↓
Load letterhead: outlets (nama, alamat, telepon) + tenants.logo_path
       ↓
Re-query LINE ITEMS dari hl_transaksi:
  SELECT no_order, tanggal, total
  WHERE pelanggan_id=? AND outlet_id=? AND tenant_id=?
    AND DATE(tanggal) BETWEEN periode_start AND periode_end
  ORDER BY tanggal, id
       ↓
Hitung: subtotal = SUM(total), pajak = subtotal × pajak_persen/100,
        grand_total = subtotal + pajak, sisa = grand_total - total_dibayar
       ↓
Load info pembayaran: hl_payment_methods aktif (label) untuk outlet
       ↓
Render HTML invoice + (auto_print=1 → window.print())
```

---

## 4. Invoice Document Layout

```
┌────────────────────────────────────────────────────────────┐
│  [Logo]  RIZKY LAUNDRY JAKARTA              INVOICE         │
│          Jl. Sudirman No. 12, Jakarta                       │
│          Telp: 0812-3456-7890        No: INV/2026/06/0001   │
│                                      Tanggal: 24 Jun 2026   │
│                                      Jatuh Tempo: 30 Jun 2026│
├────────────────────────────────────────────────────────────┤
│  Ditagihkan kepada:                                         │
│  Hotel Santika Jakarta                                      │
│  Jl. Thamrin No. 5, Jakarta                                 │
│  Periode: 01 Jun 2026 – 30 Jun 2026                         │
├────────────────────────────────────────────────────────────┤
│  No   Tanggal      No Order        Total                    │
│  1    02 Jun 2026  HTL-0601-1      337.500                  │
│  2    05 Jun 2026  HTL-0605-3      240.000                  │
│  3    08 Jun 2026  HTL-0608-2      180.000                  │
│  ...                                                         │
│                                    ───────────────────────  │
│                          Subtotal:          2.450.000       │
│                          PPN 11%:             269.500       │
│                          ═══════════════════════════        │
│                          TOTAL:             2.719.500       │
│                          Dibayar:             500.000       │
│                          SISA TAGIHAN:      2.219.500       │
├────────────────────────────────────────────────────────────┤
│  Pembayaran ke:                       [Badge: BELUM LUNAS]  │
│  Tunai · Transfer Bank · QRIS (dari payment methods)        │
│                                                              │
│  Terima kasih atas kerjasamanya.                            │
│                                                              │
│  [ 🖨️ Cetak / Simpan PDF ]  (hidden saat @media print)     │
└────────────────────────────────────────────────────────────┘
```

**Detail render:**
- **Logo**: `tenants.logo_path` kalau ada (`<img>`), else nama outlet text saja
- **No invoice**: `invoice_no` kalau ada, else "DRAFT (belum ditagih)"
- **Line items**: per-order — Tanggal, No Order (`no_order`), Total. Order-level granularity (bukan per layanan).
- **PPN row**: muncul hanya kalau `pajak_persen > 0`
- **Status badge**: warna by status — lunas=hijau, sebagian=kuning, belum_tagih/sudah_tagih=merah
- **Info pembayaran**: label payment methods aktif outlet (comma-separated), atau qris_label kalau ada
- **Print button**: `@media print { .no-print { display:none } }`

---

## 5. Backend Logic

### 5.1 Invoice Number Generation (`piutang.php`)

Helper, dipanggil di `mark_invoiced` kalau invoice_no masih NULL:

```php
function generateInvoiceNo(PDO $db, int $tid): string {
    $ym = date('Y/m');
    $prefix = "INV/$ym/";
    $stmt = $db->prepare("SELECT COUNT(*) FROM hl_piutang
                          WHERE tenant_id=? AND invoice_no LIKE ?");
    $stmt->execute([$tid, $prefix . '%']);
    $next = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}
```

Counter per tenant per bulan. Disimpan ke `hl_piutang.invoice_no`, immutable.

### 5.2 mark_invoiced Update (`piutang.php`)

Tambah di existing `mark_invoiced` handler:
1. Baca `pajak_persen` dari POST (validate 0–100, default 0)
2. Kalau `invoice_no` NULL → generate + simpan
3. Simpan `pajak_persen`
4. WA message include nomor invoice + (existing ringkasan)

```php
$pajak = max(0, min(100, (float)($d['pajak_persen'] ?? 0)));
// ... setelah load $row:
$invoiceNo = $row['invoice_no'];
if (!$invoiceNo) {
    $invoiceNo = generateInvoiceNo($db, $tid);
}
$db->prepare("UPDATE hl_piutang
              SET status='sudah_tagih', invoice_sent_at=NOW(),
                  invoice_no=?, pajak_persen=?
              WHERE id=? AND tenant_id=?")
   ->execute([$invoiceNo, $pajak, $id, $tid]);
```

WA text tambah baris: `"No. Invoice: {$invoiceNo}\n"` di awal.

### 5.3 api/invoice.php

```php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
$user = currentUser();
$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
if (!hasPermission('laporan.export')) { http_response_code(403); exit('Akses ditolak'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID invalid'); }

// Load piutang (tenant + outlet scope)
$p = TenantQuery::rawOne(
  "SELECT pi.*, pl.nama AS pel_nama, pl.alamat AS pel_alamat, pl.telepon AS pel_telepon,
          o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_telepon,
          t.logo_path
   FROM hl_piutang pi
   JOIN hl_pelanggan pl ON pl.id=pi.pelanggan_id AND pl.tenant_id=pi.tenant_id
   LEFT JOIN outlets o ON o.id=pi.outlet_id
   LEFT JOIN tenants t ON t.id=pi.tenant_id
   WHERE pi.id=? AND pi.tenant_id=? AND pi.outlet_id=?",
  [$id, $tid, $oid]
);
if (!$p) { http_response_code(404); exit('Invoice tidak ditemukan'); }

// Line items per-order
$items = TenantQuery::rawAll(
  "SELECT no_order, tanggal, total
   FROM hl_transaksi
   WHERE pelanggan_id=? AND outlet_id=? AND tenant_id=?
     AND DATE(tanggal) BETWEEN ? AND ?
   ORDER BY tanggal, id",
  [$p['pelanggan_id'], $oid, $tid, $p['periode_start'], $p['periode_end']]
);

$subtotal = array_sum(array_map(fn($r) => (float)$r['total'], $items));
$pajak    = $subtotal * ((float)$p['pajak_persen'] / 100);
$grand    = $subtotal + $pajak;
$dibayar  = (float)$p['total_dibayar'];
$sisa     = $grand - $dibayar;

// Info pembayaran dari payment methods aktif
$methods = TenantQuery::rawAll(
  "SELECT label FROM hl_payment_methods
   WHERE outlet_id=? AND tenant_id=? AND is_active=1 ORDER BY sort_order, id",
  [$oid, $tid]
);
$methodLabels = implode(' · ', array_map(fn($m) => $m['label'], $methods)) ?: 'Hubungi outlet';

// Render HTML (letterhead, table, totals, badge) + auto_print
```

**Status badge mapping:**
```php
$badge = match($p['status']) {
    'lunas'    => ['LUNAS', '#16a34a'],
    'sebagian' => ['SEBAGIAN', '#ca8a04'],
    default    => ['BELUM LUNAS', '#dc2626'],
};
```

### 5.4 piutang.php — Tombol Cetak

Di list view tiap baris piutang, tambah tombol:
```javascript
<a href="/api/invoice.php?id=${p.id}" target="_blank" class="hl-btn hl-btn-sm hl-btn-outline">🖨️ Invoice</a>
```

Plus PPN input di modal "Tagih" (mark_invoiced):
```html
<label>PPN (%) <small>opsional, kosongkan = 0</small></label>
<input type="number" id="inv_pajak" min="0" max="100" step="0.01" value="0">
```

---

## 6. Existing System Integration

### 6.1 Piutang Flow
Tidak mengubah generate / generate_bulk / bayar / reminder. Hanya extend `mark_invoiced` (tambah invoice_no + pajak) dan tambah action cetak baru.

### 6.2 Payment Methods
Invoice baca `hl_payment_methods` (fitur baru) untuk seksi "Pembayaran ke". Kalau outlet belum setup, fallback "Hubungi outlet".

### 6.3 Coin Ledger
`mark_invoiced` existing sudah deduct `invoice_b2b` (200 coin). Tidak berubah — cetak invoice itu sendiri gratis (cuma render).

---

## 7. Security

- **Access control**: `api/invoice.php` butuh tenant_guard + `laporan.export` permission (sama dengan piutang generate)
- **Tenant + outlet scope**: SELECT WHERE id + tenant_id + outlet_id → cross-tenant akses return 404
- **Invoice link tidak public**: butuh login owner. Klien B2B terima PDF hasil cetak owner (Opsi A). Token-based public invoice = future.
- **XSS**: htmlspecialchars semua data (nama, alamat, label)
- **PPN input**: clamp 0–100 server-side
- **Logo path**: render dari `tenants.logo_path` (tenant-controlled saat upload, sudah tervalidasi di flow logo upload existing)

---

## 8. Edge Cases

| Skenario | Handler |
|----------|---------|
| Piutang belum di-tagih (invoice_no NULL) | Invoice tampil "DRAFT (belum ditagih)" tanpa nomor — tetap bisa cetak preview |
| PPN = 0 | Row PPN hidden, total = subtotal |
| Status lunas | Badge hijau "LUNAS" |
| Partial payment (sebagian) | Badge kuning, tampil dibayar + sisa |
| Order di-edit/hapus setelah piutang dibuat | Re-query live → invoice reflect data terkini (subtotal bisa beda dari total_tagihan tersimpan). Tampilkan subtotal hasil re-query sebagai source of truth untuk dokumen. |
| Tidak ada order di periode (data drift) | Tabel kosong + subtotal 0 — warning "Tidak ada order di periode ini" |
| Outlet belum punya logo | Letterhead text-only (nama outlet) |
| Outlet belum setup payment methods | "Pembayaran ke: Hubungi outlet" |
| Cross-tenant akses (manipulate id) | 404 |
| Pelanggan retail (bukan B2B) | Tetap bisa cetak — tidak ada blocking, tapi use case utama B2B |

---

## 9. Testing Plan

### 9.1 Manual Smoke Test

1. Login owner → /piutang → pastikan ada piutang B2B (generate kalau belum)
2. Klik "Tagih" pada baris → modal muncul dengan input PPN → isi 11 → submit
3. Verify DB: `SELECT invoice_no, pajak_persen, status FROM hl_piutang WHERE id=N` → invoice_no = INV/2026/06/000N, pajak_persen=11, status=sudah_tagih
4. Klik "🖨️ Invoice" pada baris → tab baru buka /api/invoice.php?id=N
5. Verify invoice render: kop outlet + logo, nomor invoice, bill-to klien, tabel order per baris, subtotal + PPN 11% + total, status badge, info pembayaran
6. Klik "Cetak / Simpan PDF" → browser print dialog → save as PDF works
7. Catat pembayaran sebagian via "Bayar" → cetak invoice lagi → badge "SEBAGIAN" + sisa tagihan terupdate

### 9.2 Edge Case Test

| # | Test | Expected |
|---|------|----------|
| 1 | Cetak invoice piutang belum ditagih | "DRAFT (belum ditagih)", tetap render |
| 2 | PPN 0 | Row PPN tidak muncul |
| 3 | Status lunas | Badge hijau LUNAS |
| 4 | Cross-tenant id manipulation | 404 |
| 5 | Outlet tanpa logo | Letterhead text-only |
| 6 | Outlet tanpa payment methods | "Hubungi outlet" |
| 7 | auto_print=1 | window.print() otomatis |

---

## 10. Implementation Phasing

3 commits, ~2.5 jam:

**Commit 1 — Schema + Invoice Numbering (~30 menit):**
- Migration 2 kolom
- generateInvoiceNo() helper
- mark_invoiced integration (invoice_no + pajak)

**Commit 2 — Invoice Document (~75 menit):**
- api/invoice.php HTML render
- Letterhead + line items + totals + badge + payment info

**Commit 3 — Piutang UI Integration (~30 menit):**
- Tombol Cetak Invoice di list
- PPN input di modal Tagih
- Smoke test E2E

---

## 11. Files Inventory

### New
- `db/migrations/2026-06-24-invoice-b2b.sql`
- `api/invoice.php`

### Modified
- `piutang.php` — tombol cetak + PPN input + invoice_no generation di mark_invoiced

---

## 12. Out of Scope (Phase 1)

- Public invoice link via token (klien akses tanpa login)
- Recurring auto-generate (cron bulanan)
- Portal B2B client (klien lihat semua invoice + history)
- Payment online dari invoice
- Per-item granularity (per layanan dalam order)
- Multi-currency
- Email invoice otomatis
- Invoice template customization per tenant
- Kwitansi/receipt terpisah setelah lunas

---

## 13. Success Criteria

- ✅ Owner cetak invoice profesional dari piutang dalam <30 detik
- ✅ Invoice punya nomor formal (INV/YYYY/MM/000N) yang unik per tenant per bulan
- ✅ Line-item per-order tampil dengan tanggal + no order + total
- ✅ PPN opsional dihitung benar
- ✅ Letterhead pakai logo + alamat outlet
- ✅ Status badge akurat (lunas/sebagian/belum)
- ✅ Save as PDF via browser print works
- ✅ Zero impact ke flow piutang existing (generate/bayar/reminder)

---

## 14. References

- Print pattern: `api/payslip.php` (HTML print + auto_print + @media print)
- Piutang existing: `piutang.php` (generate, generate_bulk, mark_invoiced, bayar, reminder)
- Nota numbering pattern: `core/NotaFormatter.php`
- Payment methods (sibling feature): `docs/superpowers/specs/2026-06-24-payment-methods-design.md`
- Line-item source: `hl_transaksi` (no_order, tanggal, total)
