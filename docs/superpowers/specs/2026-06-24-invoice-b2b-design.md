# Invoice B2B Enhancement — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** REVISED — enhancement (fitur dasar sudah ada)

---

## ⚠️ Koreksi Penting

Versi awal spec ini mengasumsikan invoice B2B dibangun dari nol. **Salah.**
`StrukGenerator::generateInvoice()` sudah ada dan fully wired:
- Endpoint `api/struk.php?action=generate_invoice&id={piutang}[&preview=1]`
- Tombol "📄 Invoice" di `piutang.php` (preview + paid variant)
- Render A4: header+logo, title INVOICE, line-item per-order, bill-to klien, jatuh tempo, totals, rekening box, footer + QR
- Coin deduction 200 (`generate_invoice`)

Spec ini di-rewrite jadi **enhancement** atas fitur existing — bukan build baru.

---

## 1. Tujuan

Sempurnakan invoice B2B existing dengan 2 hal yang belum ada:

1. **PPN opsional** — banyak klien hotel butuh invoice + PPN 11% untuk accounting
2. **Nomor invoice formal** — ganti synthetic `{tid}-INV-00001` jadi `INV/YYYY/MM/000N` (counter per tenant per bulan, immutable)

**Scope:**
- Schema: 2 kolom di `hl_piutang` (invoice_no, pajak_persen)
- `mark_invoiced`: generate invoice_no + simpan pajak_persen (input PPN di modal Tagih)
- `StrukGenerator::generateInvoice()`: pakai invoice_no formal + render baris PPN
- Render totals: subtotal → PPN → grand total

**Out of scope:**
- ❌ Payment methods di invoice — DROP. Rekening box existing (bank/no/atas nama dari `hl_struk_template`) lebih tepat untuk transfer B2B daripada label metode. Tidak diubah.
- Public invoice link via token (klien akses tanpa login) — owner cetak manual
- Recurring auto-generate (cron)
- Portal B2B client
- Payment online
- Multi-currency

---

## 2. Background

**Fitur invoice existing (`core/StrukGenerator.php::generateInvoice`):**

```
Input: piutang_id, deductCoin bool
Flow:
  1. Load hl_piutang + pelanggan (nama/telp/alamat)
  2. Load hl_transaksi dalam periode → line items per-order
     ("Order #{no_order} ({tgl})", harga = total)
  3. Synthetic $trx: no_order = "{tid}-INV-{piutang:05d}",
     subtotal/total = total_tagihan, dp = total_dibayar,
     sisa_bayar = sisa_tagihan, jatuh_tempo
  4. loadTemplate(tid, oid, 'b2b') → hl_struk_template
  5. render() → A4 HTML invoice
  6. deductCoin (200)
```

**Render existing (`renderPdf`):**
- Header: logo (`tmpl.logo` / outlet) + nama outlet + alamat
- Title "INVOICE" + No: {no_order} + Jatuh Tempo (kalau show_jatuh_tempo)
- Bill-to: pelanggan nama/telp/alamat
- Tabel line-items: # / Layanan / Qty / Harga Satuan / Subtotal
- Totals: subtotal (kalau breakdown), diskon, biaya tambahan, **TOTAL**, DP, metode, sisa bayar
- Rekening box: bank / no rekening / atas nama (dari `hl_struk_template.rekening_*`)
- Footer ucapan/sosmed/syarat + QR tracking

**Gap nyata:**
- Totals tidak ada baris PPN — total = total_tagihan apa adanya
- Nomor invoice synthetic `{tid}-INV-00001` — tidak formal, tidak sequential per bulan, berubah-ubah kalau piutang_id beda

**Schema existing:**
- `hl_piutang`: id, pelanggan_id, periode_start/end, jatuh_tempo, total_order, total_tagihan, total_dibayar, sisa_tagihan (generated), status, invoice_sent_at
- `hl_struk_template`: tipe='b2b', rekening_bank/nomor/atas_nama, show_* flags, footer_*

---

## 3. Arsitektur

### 3.1 Komponen

**Modified:**
```
core/StrukGenerator.php   ← generateInvoice(): pakai invoice_no formal + PPN dari piutang
                             renderPdf(): baris PPN di totals (kalau pajak > 0)
piutang.php               ← mark_invoiced: generate invoice_no + simpan pajak_persen
                             modal Tagih: input PPN %
db/migrations/2026-06-24-invoice-b2b.sql   ← 2 ALTER columns
```

**No change:** api/struk.php (endpoint sudah ada), rekening box, line-item logic.

### 3.2 Schema Delta

```sql
ALTER TABLE hl_piutang
  ADD COLUMN invoice_no VARCHAR(40) NULL AFTER id,
  ADD COLUMN pajak_persen DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER total_tagihan;
```

Additive nullable/default — existing rows tidak terdampak. Existing piutang yang sudah di-invoice (invoice_no NULL) akan generate nomor saat di-cetak/re-tagih.

### 3.3 Data Flow (Delta)

```
[Owner /piutang → "Tagih" → modal input PPN % (default 0)]
       ↓
mark_invoiced POST:
  - kalau invoice_no NULL → generateInvoiceNo() → INV/YYYY/MM/000N → simpan
  - clamp pajak 0–100, simpan pajak_persen
  - (existing: status=sudah_tagih, invoice_sent_at, deduct coin, WA link)
       ↓
[Owner "📄 Invoice"]  → api/struk.php?action=generate_invoice
       ↓
StrukGenerator::generateInvoice():
  - no_order = piu.invoice_no (kalau ada) else synthetic fallback
  - hitung pajak = total_tagihan × pajak_persen/100
  - pass pajak_persen + pajak_amount ke $trx
       ↓
renderPdf totals:
  Subtotal: total_tagihan
  PPN {persen}%: pajak_amount   ← baru, hanya kalau pajak > 0
  TOTAL: total_tagihan + pajak
  DP / Sisa: recalc dengan grand total
```

---

## 4. Detail Perubahan

### 4.1 Invoice Number Generation

Helper di `piutang.php` (atau StrukGenerator static):

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

Generate sekali saat `mark_invoiced` (kalau NULL), immutable setelahnya.

### 4.2 mark_invoiced (piutang.php)

Tambah:
```php
$pajak = max(0, min(100, (float)($d['pajak_persen'] ?? 0)));
// setelah load $row:
$invoiceNo = $row['invoice_no'] ?: generateInvoiceNo($db, $tid);
$db->prepare("UPDATE hl_piutang
              SET status='sudah_tagih', invoice_sent_at=NOW(),
                  invoice_no=?, pajak_persen=?
              WHERE id=? AND tenant_id=?")
   ->execute([$invoiceNo, $pajak, $id, $tid]);
```
WA text tambah baris `"No. Invoice: {$invoiceNo}\n"` di awal pesan.

Modal Tagih tambah field:
```html
<label>PPN (%) <small>opsional, kosong = 0</small></label>
<input type="number" id="inv_pajak" min="0" max="100" step="0.01" value="0">
```
`markInvoiced()` JS kirim `pajak_persen` di body POST.

### 4.3 StrukGenerator::generateInvoice

```php
// invoice_no formal
$invoiceNo = $piu['invoice_no'] ?: ($tid . '-INV-' . str_pad((string)$piutangId, 5, '0', STR_PAD_LEFT));
$pajakPersen = (float)($piu['pajak_persen'] ?? 0);
$subtotal = (float)$piu['total_tagihan'];
$pajakAmount = $subtotal * $pajakPersen / 100;
$grandTotal = $subtotal + $pajakAmount;

$trx = [
    'no_order'     => $invoiceNo,            // formal number
    // ...
    'subtotal'     => $subtotal,
    'pajak_persen' => $pajakPersen,          // baru
    'pajak_amount' => $pajakAmount,          // baru
    'total'        => $grandTotal,           // subtotal + pajak
    'dp'           => (float)$piu['total_dibayar'],
    'sisa_bayar'   => $grandTotal - (float)$piu['total_dibayar'],
    // ...
];
```

### 4.4 renderPdf — Baris PPN

Di blok totals (sebelum baris TOTAL), tambah:
```php
// PPN (B2B invoice, kalau pajak_persen > 0)
$pjP = (float)($trx['pajak_persen'] ?? 0);
$pjA = (float)($trx['pajak_amount'] ?? 0);
if ($pjP > 0) {
    // subtotal selalu tampil kalau ada pajak
    $h .= "  <tr><td>Subtotal</td><td class='r'>Rp " . self::rpNum($trx['subtotal']) . "</td></tr>\n";
    $h .= "  <tr><td>PPN " . rtrim(rtrim(number_format($pjP,2),'0'),'.') . "%</td><td class='r'>Rp " . self::rpNum($pjA) . "</td></tr>\n";
}
```
Diletakkan sebelum `if (show_total)` TOTAL row. TOTAL pakai `$trx['total']` (sudah grand total). Backward compat: retail nota tidak ada pajak_persen → blok skip.

---

## 5. Security

- Tidak ada surface baru — semua via existing endpoint dengan tenant+outlet scope di generateInvoice query
- PPN input clamp 0–100 server-side di mark_invoiced
- invoice_no server-generated, immutable
- htmlspecialchars existing di render

---

## 6. Edge Cases

| Skenario | Handler |
|----------|---------|
| Piutang lama (invoice_no NULL) di-cetak | Fallback synthetic `{tid}-INV-00001` (existing behavior) sampai di-tagih ulang |
| PPN = 0 | Blok PPN skip, total = total_tagihan (behavior existing tidak berubah) |
| Re-tagih piutang yang sudah punya invoice_no | invoice_no tidak di-regenerate (immutable via `?:`) — pajak boleh update |
| Retail nota (bukan b2b) | pajak_persen tidak ada di $trx → blok PPN skip |
| Partial payment + PPN | sisa = grand total − dibayar (recalc benar) |
| Counter rollover bulan | Prefix INV/YYYY/MM/ reset per bulan via LIKE filter |

---

## 7. Testing Plan

### 7.1 Smoke Test

1. Migration apply → verify 2 kolom di hl_piutang
2. /piutang → "Tagih" piutang B2B → modal muncul input PPN → isi 11 → submit
3. Verify DB: invoice_no = INV/2026/06/000N, pajak_persen = 11
4. "📄 Invoice" → invoice render dengan: nomor formal di header, baris Subtotal + PPN 11% + TOTAL (grand), sisa benar
5. Tagih piutang ke-2 → invoice_no increment (000N+1)
6. PPN 0 (atau kosong) → invoice tanpa baris PPN, total = tagihan

### 7.2 Edge Cases

| # | Test | Expected |
|---|------|----------|
| 1 | Cetak piutang lama (invoice_no NULL, belum re-tagih) | Synthetic fallback, no crash |
| 2 | PPN 0 | No baris PPN |
| 3 | Re-tagih piutang ada invoice_no | Nomor tetap, pajak update |
| 4 | Retail nota cetak | Tidak terpengaruh (no pajak row) |
| 5 | PPN 11 + partial paid | Sisa = grand − dibayar |

---

## 8. Implementation Phasing

2 commits, ~1 jam:

**Commit 1 — Schema + Numbering + mark_invoiced (~30 menit):**
- Migration 2 kolom
- generateInvoiceNo() helper
- mark_invoiced: invoice_no + pajak + PPN input modal + JS

**Commit 2 — Invoice Render PPN (~30 menit):**
- generateInvoice(): invoice_no formal + pajak calc
- renderPdf(): baris PPN
- Smoke test E2E

---

## 9. Files Inventory

### New
- `db/migrations/2026-06-24-invoice-b2b.sql`

### Modified
- `piutang.php` — mark_invoiced (invoice_no + pajak) + modal PPN input + JS
- `core/StrukGenerator.php` — generateInvoice (invoice_no + pajak calc) + renderPdf (PPN row)

---

## 10. Success Criteria

- ✅ Invoice tampil nomor formal INV/YYYY/MM/000N (sequential per tenant per bulan)
- ✅ PPN opsional dihitung + ditampilkan benar (subtotal → PPN → grand total)
- ✅ PPN = 0 → behavior invoice existing tidak berubah
- ✅ Nomor invoice immutable setelah di-generate
- ✅ Zero regression: retail nota + invoice existing tetap render benar
- ✅ Rekening box existing dipertahankan (lebih tepat dari payment-method labels)

---

## 11. References

- Existing: `core/StrukGenerator.php::generateInvoice()` (line ~234) + `renderPdf()` (line ~660)
- Endpoint: `api/struk.php?action=generate_invoice`
- UI: `piutang.php` (mark_invoiced handler + list row "📄 Invoice" button)
- Template: `hl_struk_template` tipe='b2b' (rekening_*, show_* flags)
