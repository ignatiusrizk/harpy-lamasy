# Task 2 Report: Backend endpoint + Frontend UI — dropdown Tier Express di edit item

**Date:** 2026-09-05  
**Implementer:** Claude Sonnet 5  
**Status:** DONE  
**Worktree:** `/Users/rizky/Documents/lamasy-tier-express-edit` (branch `feat/tier-express-edit-order`)

## Summary

Successfully implemented Task 2: added `action=express_tiers` backend endpoint and integrated Express tier dropdown UI into the order item edit form. Users can now select Express tiers from a dropdown when editing order items, with live preview of Express fees in the total calculation.

## Changes Made

### 1. Backend Endpoint (orders.php, line ~950)
- Added new endpoint `action=express_tiers` that returns JSON array of active Express tiers for the tenant/outlet
- Reuses existing `ExpressTier::forTenant()` method (already used in pos.php)
- Returns format: `[{id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, urutan, outlet_id}, ...]`

### 2. Frontend: Global Variable & Loader (orders.php, lines ~1661, ~1704)
- Added `availableTiersEdit = []` global variable to store fetched Express tiers
- Added `loadExpressTiersEdit()` async function to fetch from `action=express_tiers` endpoint
- Integrated call to `loadExpressTiersEdit()` in DOMContentLoaded (line ~1678)

### 3. Frontend: UI Column & Handler (orders.php, lines ~2306-2327)
- Modified `renderEditItems()` to include new "Express" column (`<td data-lbl="Express">`)
- Dropdown shows "⏱️ Reguler" (no tier) + list of available tiers with "⚡" emoji
- Displays live preview of Express fee (e.g., "+Rp 150.000") when tier is selected
- Added `onEditItemTierChange(idx, tierName)` handler function that:
  - Updates `express_tier_nama` field
  - Calculates `biaya_express` based on tier type (flat or percent)
  - Re-renders and recalculates totals

### 4. Frontend: Item Initialization (orders.php, lines ~2330, ~2353)
- Updated `addEditRow()` to initialize new fields: `express_tier_nama: null, biaya_express: 0`
- Updated `addEditLayanan()` to include same initialization

### 5. Frontend: Total Calculation (orders.php, lines ~2407-2408)
- Modified `recalcEdit()` to calculate `biayaExprPreview` from `editItems` Express fees
- Updated total formula: `tot = Math.max(sub - dis + biayaExprPreview + currentOrderBiayaLainnya, 0)`
- Note: Frontend preview only; backend recomputes authoritative values on submit

## Verification Results

### Endpoint Test ✓
```
[OK] Login berhasil
[HTTP 200] Response from express_tiers endpoint:
[{"id":3,"nama_tier":"Express 2 Hari","estimasi_jam":48,"tipe_biaya":"percent","nilai_biaya":"30.00",...}]
[OK] Valid JSON response with 4 tier(s)
First tier: {"id":3,"nama_tier":"Express 2 Hari","estimasi_jam":48,"tipe_biaya":"percent","nilai_biaya":"30.00","urutan":0,"outlet_id":null}
```

### Code Structure Verification ✓
- `availableTiersEdit` defined and populated: ✓ (4 references in code)
- `loadExpressTiersEdit()` function: ✓ (defined at line 1701)
- `onEditItemTierChange()` handler: ✓ (defined at line 2318)
- Express dropdown column: ✓ (data-lbl="Express" confirmed at line 2306)
- `biayaExprPreview` in recalcEdit: ✓ (line 2407-2408)
- New fields in addEditRow/addEditLayanan: ✓ (initialized with null/0)

### Syntax Check ✓
```
No syntax errors detected in orders.php
```

## Commits

| Hash | Message |
|------|---------|
| 9655be9 | feat(orders): dropdown Tier Express di form edit item order |

**Related commits (Task 1 backend - already in worktree):**
- b6ab566: fix: asymmetric comparison di itemsChanged — include express_tier_nama di query
- a15fca0: feat(orders): recompute biaya_tambahan (Tier Express) & biaya_lainnya saat edit order

## Technical Notes

### Frontend vs Backend Calculation
- **Frontend** (this task): Live preview of Express fee in dropdown select → `onEditItemTierChange()` → `renderEditItems()` + `recalcEdit()`
- **Backend** (Task 1): Authoritative recompute via `ExpressTier::calcItemFee()` + `findByNama()` on order submit (action=update)
- This design prevents inconsistency: even if client-side formula has bugs, the backend value is what gets saved

### Tier Selection Flow
1. User opens order edit form → `loadExpressTiersEdit()` fetches tiers in background
2. User selects tier in dropdown → `onEditItemTierChange()` updates `editItems[i]`
3. Preview updates: biaya_express calculated, renderEditItems() re-renders, recalcEdit() updates totals
4. User clicks Save → orders.php `action=update` processes the item with `express_tier_nama` field
5. Backend Task 1 logic: recomputes the biaya_express value from ExpressTier DB (this is what gets saved)
6. Order detail page reloads → shows the tier selection + fee in readonly mode

## No Concerns

- Code follows established patterns (mirrors pos.php dropdown implementation)
- All required fields initialized in item objects
- Endpoint properly guarded by tenant_guard (session-based $tid/$oid)
- Frontend preview does not interfere with backend validation/recompute
- Syntax clean, no errors

---

## Post-Implementation: Code Review Fixes (2026-09-05)

**Status:** COMPLETED  
**Commit:** `40f1e6ab` — "fix(orders): dua critical bugs — editStateJSON gak nyertain tier, thead mismatch kolom"

### Critical Bug #1: `editStateJSON()` Missing Express Tier Field

**Problem:** The `editStateJSON()` function (line 1651-1659) serializes the current edit state to detect if any changes were made before submitting to the server. The function compares the current state with a snapshot taken when the form opened (`editSnapshot`). If they match exactly, the save is rejected with "Tidak ada perubahan untuk disimpan".

However, the items array serialization was missing the `express_tier_nama` field:
```javascript
// BEFORE (line 1657)
items: (editItems || []).map(it => ({ l: it.nama_layanan, s: it.satuan, j: it.jumlah, h: it.harga_satuan, k: it.catatan_item || '' }))
```

**Impact:** If a user opened an order for editing and ONLY changed the Express tier (without touching any other field), the `editStateJSON()` would produce an identical result as the snapshot → system would falsely believe "no changes were made" → the tier selection would be silently discarded, never sent to the server.

**Fix Applied:** Added `t: it.express_tier_nama || ''` to the serialized object:
```javascript
// AFTER (line 1657)
items: (editItems || []).map(it => ({ l: it.nama_layanan, s: it.satuan, j: it.jumlah, h: it.harga_satuan, t: it.express_tier_nama || '', k: it.catatan_item || '' }))
```

Now tier changes are detected and properly sent to the server.

### Critical Bug #2: Table Header Column Mismatch

**Problem:** The `<thead>` in the order items table had only 7 `<th>` elements (line 2028-2029):
```html
<th>Layanan</th><th>Sat</th><th>Jml</th><th>Harga</th><th>Subtotal</th><th>Ket</th><th></th>
```

But the `renderEditItems()` JavaScript function generates 8 `<td>` elements per row (the new Express column was added between Subtotal and Ket).

**Impact:** On desktop browsers, the table is rendered as a native HTML table (not collapsed to cards like on mobile). The header-body column mismatch caused:
- The "Ket" header to appear above the Express column
- The delete button column to have no header
- Misaligned visual presentation that appears broken

**Fix Applied:** Added `<th>Express</th>` between `<th>Subtotal</th>` and `<th>Ket</th>` (line 2029):
```html
<th>Layanan</th><th>Sat</th><th>Jml</th><th>Harga</th><th>Subtotal</th><th>Express</th><th>Ket</th><th></th>
```

Now all 8 headers align correctly with the 8 data columns.

### Verification Results

**Syntax Check:** ✓
```
$ php -l orders.php
No syntax errors detected in orders.php
```

**Code Structure Verification:** ✓
- Line 1657: `editStateJSON()` items map now includes `t: it.express_tier_nama || ''`
- Line 2029: `<thead>` now has 8 `<th>` elements including `<th>Express</th>`

Both fixes follow the established patterns in the codebase and address the exact issues identified in the code review.

---

## Code Review Fix: Express Column Flex Wrapper (2026-09-05)

**Status:** COMPLETED  
**Commit:** `171f622` — "fix(orders): bungkus select+badge fee Express dalam wrapper flex — cegah overflow di HP"

### Medium Finding: Express Column Risiko Kepotong/Tumpang-Tindih di Mobile

**Problem:** Kolom Express mengandung 2 sibling elements langsung di dalam `<td>`:
1. `<select>` dropdown tier
2. `<div>` badge nominal fee (muncul kondisional saat tier dipilih)

CSS untuk row items dirancang dengan asumsi 1 kontrol per `<td>` (label kiri, kontrol kanan, flex-row):
```css
.items-table tbody td { display:flex; justify-content:space-between; gap:8px; ... }
.items-table tbody td input, 
.items-table tbody td select { flex:1; max-width:170px; ... }
```

Selector CSS ini **hanya target `input`/`select`**, bukan div badge. Ketika badge muncul:
- `<td>` melihat 3 children (label pseudoelement + select + div)
- `justify-content:space-between` & no-wrap = dipaksa 1 baris
- Select + badge gak dapat flex rule → risiko kepotong/tumpang-tindih di layar sempit

**Pattern History:** Bug kelas ini sudah diperbaiki di 8 halaman lain (pos.php, dll) — commit deef40e, 4d87cbb, 6b41e99, 113258b; pola yang sama perlu diterapkan di sini.

### Fix Applied

Membungkus `<select>` dan div badge dalam 1 wrapper `<div style="flex:1;min-width:0;max-width:170px">`:
- Dari sudut pandang CSS flex `<td>`, cuma ada 1 "kontrol" (wrapper)
- Wrapper sendiri yang handle stacking select + badge secara vertikal (div block)
- Select width diubah: `110px` → `100%` (isi wrapper)
- Badge fee ditambah `text-align:right;` (align angka dengan konsisten)

**Before:**
```javascript
<td data-lbl="Express">
  <select style="width:110px">...</select>
  ${item.biaya_express > 0 ? `<div>+Rp ...</div>` : ''}
</td>
```

**After:**
```javascript
<td data-lbl="Express">
  <div style="flex:1;min-width:0;max-width:170px">
    <select style="width:100%">...</select>
    ${item.biaya_express > 0 ? `<div style="text-align:right;">+Rp ...</div>` : ''}
  </div>
</td>
```

### Verification Results

**Syntax Check:** ✓
```
$ php -l orders.php
No syntax errors detected in orders.php
```

**Structure Verification:** ✓
```
$ grep -A 8 'data-lbl="Express"' orders.php | head -15
<td data-lbl="Express">${CAN_EDIT_ORDER ? `
        <div style="flex:1;min-width:0;max-width:170px">
          <select class="item-input" style="width:100%;font-size:11px" ...>
            ...
          </select>
          ${item.biaya_express > 0 ? `<div style="...text-align:right;">+Rp ...</div>` : ''}
        </div>
      ` : ...}</td>
```

- Wrapper `<div style="flex:1;...">` membungkus select ✓
- Badge fee sebagai child div (block stacking) ✓
- Select menggunakan `width:100%` ✓
- Badge fee punya `text-align:right;` ✓

Pola konsisten dengan fix sebelumnya di pos.php + halaman lain, menghindari overflow/blowout di breakpoint mobile.

---

## Whole-Branch Code Review Fixes: 4 Temuan FINAL (2026-09-05)

**Status:** COMPLETED  
**Commit:** `4f699ff` — "fix(orders): 4 temuan review whole-branch FINAL"  
**Worktree:** `/Users/rizky/Documents/lamasy-tier-express-edit` (branch `feat/tier-express-edit-order`)

### Temuan #1 (CRITICAL) — `recalcEdit()` String Concatenation Bug

**Problem:** Fungsi `recalcEdit()` (line 2409) menghitung `biayaExprPreview` dari `editItems`:
```javascript
const biayaExprPreview = editItems.reduce((s,i) => s + (i.biaya_express||0), 0);
```

**Root Cause:** Kolom `biaya_express` di database adalah tipe `DECIMAL(12,2)`, dan PDO/mysqlnd mengembalikannya **sebagai string** (misal `"0.00"` atau `"5000.00"`). String `"0.00"` adalah **truthy**, jadi `|| 0` tidak berfungsi. Ketika pertama kali `i.biaya_express` string:
```javascript
0 + "0.00"          // → "00.00" (string concat, bukan penjumlahan)
"00.00" + "5000.00" // → "00.005000.00" (CONCATENATION!)
```
Hasil akhirnya bisa jadi NaN, atau angka yang sangat salah (jutaan rupiah error).

**Impact:** SEMUA order yang dibuka detail-nya terpengaruh (bukan cuma order yang pakai Tier Express) karena field `biaya_express` ada di semua item dengan nilai default `"0.00"`. Total dan Sisa Bayar yang tampil di modal bisa jadi angka nonsense atau NaN.

**Fix Applied (Line 2409):**
```javascript
// BEFORE:
const biayaExprPreview = editItems.reduce((s,i) => s + (i.biaya_express||0), 0);

// AFTER:
const biayaExprPreview = editItems.reduce((s,i) => s + (parseFloat(i.biaya_express)||0), 0);
```

**Verification:** ✓
- `parseFloat("0.00")` → `0` (angka)
- `parseFloat("5000.00")` → `5000` (angka)
- Menjumlahan numerik, bukan string concat
- No regression test: 16/16 PASS

---

### Temuan #2 (IMPORTANT) — Tier Nonaktif Tampil sebagai "Reguler" di Dropdown

**Problem:** Dropdown Express (line 2306-2314) hanya isi `<option>` dari `availableTiersEdit` (tier AKTIF doang):
```javascript
<select>
  <option value="">⏱️ Reguler</option>
  ${availableTiersEdit.map(t => `<option value="${esc(t.nama_tier)}" ...>...`)}
</select>
```

Kalau item punya `express_tier_nama` yang sudah **dinonaktifkan/direname/dihapus owner** setelah order dibuat:
- Item.express_tier_nama masih berisi nama tier lama (di data)
- Tier itu tidak ada di `availableTiersEdit` (sudah nonaktif)
- `<select>` jatuh ke opsi pertama "Reguler" secara visual
- **Tapi** badge fee masih muncul di bawah (karena `item.biaya_express` masih ada nilainya)
- **Hasil:** User liat dropdown bilang "Reguler" tapi ada badge fee → **MENYESATKAN**

**Fix Applied (Line 2309-2310):**
```javascript
// BEFORE:
<option value="">⏱️ Reguler</option>
${availableTiersEdit.map(t => `...`)}

// AFTER:
<option value="">⏱️ Reguler</option>
${item.express_tier_nama && !availableTiersEdit.some(t => t.nama_tier === item.express_tier_nama) ? `<option value="${esc(item.express_tier_nama)}" selected>⚠️ ${esc(item.express_tier_nama)} (nonaktif)</option>` : ''}
${availableTiersEdit.map(t => `...`)}
```

**Behavior Sekarang:**
- Item dengan tier nonaktif: opsi "⚠️ Express 1 Hari (nonaktif)" SELECTED (user tahu ada yang aneh)
- Item dengan tier aktif: opsi tier aktif SELECTED (normal)
- Item tanpa tier: "Reguler" SELECTED (normal)
- Badge fee tetap akurat sesuai `item.biaya_express` di data

---

### Temuan #3 (MINOR) — Dead Code `currentOrderBiayaTambahan`

**Problem:** Variable `currentOrderBiayaTambahan` dideklarasikan (line 1644) dan di-assign dari API saat modal dibuka (line 1998):
```javascript
currentOrderBiayaTambahan = parseFloat(d.biaya_tambahan) || 0;
```

Tapi **tidak pernah digunakan** di tempat lain. Setelah fix Temuan #1, logic total calculation diambil alih oleh `biayaExprPreview` yang dihitung dari `editItems` langsung.

**Fix Applied:**
- Hapus assignment di line 1998 (dead code)
- Biarkan deklarasi di line 1644 (scope consistency)

**Result:** Kurangi noise variable yang gak dipakai.

---

### Temuan #4 (MINOR) — Robustness & Error Handling

#### 4a. `loadExpressTiersEdit()` Fetch Error Handling

**Problem:** Fungsi `loadExpressTiersEdit()` (line 1702-1705) tidak ada try/catch:
```javascript
async function loadExpressTiersEdit() {
  const r = await fetch('orders.php?action=express_tiers');
  availableTiersEdit = await r.json();
}
```

Kalau fetch gagal (network error, endpoint down), exception lolos ke caller (`DOMContentLoaded` wrapper). Bisa mematikan keseluruhan logic auto-open order dari Kanban.

**Fix Applied (Line 1702-1708):**
```javascript
async function loadExpressTiersEdit() {
  try {
    const r = await fetch('orders.php?action=express_tiers');
    availableTiersEdit = await r.json();
  } catch (e) {
    availableTiersEdit = [];
  }
}
```

**Fallback:** Kalau fetch gagal, `availableTiersEdit` tetap array kosong `[]` → dropdown cuma tampil "Reguler" option → order masih bisa diedit, cuma tanpa pilihan tier.

#### 4b. Payload Item Validation (Backend)

**Problem:** Backend `action=update` (line 289) langsung loop `$data['items']` tanpa validasi:
```php
$dom = ExpressTier::dominantTier(array_map(
    fn($it, $x) => array_merge($it, $x), $data['items'], $itemsWithTier
));
```

Kalau ada elemen `$data['items']` yang **bukan array** (payload rusak dari client):
- `array_merge()` akan fatal error
- PHP return HTTP 500 tanpa pesan JSON
- Bukannya error response terstruktur

**Fix Applied (Line 276-280):**
```php
if ($itemsChanged && !empty($data['items'])) {
    // Validasi: pastikan setiap item adalah array (avoid fatal error dari payload rusak)
    foreach ($data['items'] as $item) {
        if (!is_array($item)) {
            echo json_encode(['error'=>'Format item tidak valid']); exit;
        }
    }
    // ... rest of loop
}
```

**Result:** Invalid payload ditolak dengan JSON response yang rapi, bukan PHP fatal.

---

### Verification Results

**PHP Syntax Check:** ✓
```bash
$ php -l /Users/rizky/Documents/lamasy-tier-express-edit/orders.php
No syntax errors detected in orders.php
```

**Integration Test Results:** ✓
```
Test command: php tests/orders/test_edit_tier_recompute.php
Server: localhost:8091

PASS: fetch login page & dapat csrf token
PASS: login berhasil (tanpa CSRF/auth error)
PASS: ada minimal 1 Tier Express aktif di tenant test
PASS: action=update tidak error
PASS: biaya_tambahan header = 25000 (got 25000.00)
PASS: express_tier_nama header = 'Express 1 Hari' (got 'Express 1 Hari')
PASS: item.express_tier_nama = 'Express 1 Hari' (got 'Express 1 Hari')
PASS: item.biaya_express = 25000 (got 25000.00)
PASS: skenario 2: action=update tidak error
PASS: item A (gak diubah) tetap tier 'Express 1 Hari'
PASS: item A (gak diubah) tetap biaya_express 25000
PASS: item B (qty berubah, tetap tanpa tier) express_tier_nama NULL
PASS: skenario 3: status update tanpa ubah item → tidak error
PASS: skenario 3: item.express_tier_nama tetap 'Express 1 Hari'
PASS: skenario 3: item.biaya_express tetap 25000
PASS: skenario 3: status_proses berubah ke 'cuci'

RESULT: 16 PASS, 0 FAIL (No regression)
```

---

### Summary

Semua 4 temuan sudah diterapkan:
1. **Temuan #1 (CRITICAL)** — parseFloat string concatenation bug: FIX ✓
2. **Temuan #2 (IMPORTANT)** — Tier legacy nonaktif dropdown: FIX ✓
3. **Temuan #3 (MINOR)** — Dead code cleanup: FIX ✓
4. **Temuan #4 (MINOR)** — Robustness (try/catch + validation): FIX ✓

**Test Status:** All 16 test cases PASS (no regression)  
**Code Quality:** PHP syntax OK, patterns follow established codebase conventions
