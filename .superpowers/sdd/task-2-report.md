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
