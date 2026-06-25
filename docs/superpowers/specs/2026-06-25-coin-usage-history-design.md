# Riwayat & Analitik Pemakaian Coin — Design Spec

**Tanggal:** 25 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Tenant bisa lihat riwayat & analitik pemakaian coin mereka: transaksi coin (topup + deduct), breakdown pemakaian per fitur/kategori, filter periode, ringkasan saldo. Transparansi "coin saya habis ke mana" → tenant sadar pemakaian → mendorong top-up. Fitur LAMASY↔tenant (sisi coin usage).

**Scope:**
- Tab baru "Riwayat & Pemakaian" di hq/coin-info.php (existing pricing jadi tab 1)
- Ringkasan: saldo coin skrg, Σ terpakai (periode), Σ top-up (periode)
- Breakdown pemakaian per kategori + per fitur (periode)
- Riwayat transaksi paginated (filter periode + type)
- Tenant-wide (semua outlet), kolom outlet ditampilkan

**Out of scope:**
- Export PDF/Excel riwayat coin
- Grafik tren multi-bulan (cukup periode tunggal)
- Proyeksi/forecast habis coin
- Method baru di CoinLedger (query langsung di page)

---

## 2. Background

**Existing:**
- `coin_ledger`: id, tenant_id, outlet_id, type ENUM(topup/deduct), amount, feature_used VARCHAR(50), description TEXT, balance_after, ref_id, payment_id, created_at
- `saas_coin_pricing`: feature_key, nama_fitur, kategori (dokumen/whatsapp/ai/export), harga_coin, deskripsi
- `tenants.coin_balance` (saldo skrg). coin_mode ENUM(shared/per_outlet)
- `hq/coin-info.php`: VIEW-ONLY pricing list. define ROOT + hq_guard + Database::get() + `require _layout_open.php`. Permission: hq_guard (owner-level).
- Low-balance nudge SUDAH ADA (components.php:612, dashboard.php:526/529) — bukan bagian fitur ini.
- `core/CoinLedger.php`: class write (insert topup/deduct). Tidak diubah.

**Gap:** coin-info.php cuma tampilkan HARGA, bukan riwayat/breakdown pemakaian tenant sendiri. coin_ledger punya data lengkap (feature_used, type, amount, balance_after) tapi tak di-surface ke tenant.

---

## 3. Arsitektur

### 3.1 Komponen

**Modified only:**
```
hq/coin-info.php   tabs (Harga existing → tab 1) + tab Riwayat + 3 AJAX action + JS
```

No schema change, no new file, no view. Permission: hq_guard existing (owner). Query langsung di page (konsisten coin-info existing).

### 3.2 AJAX Actions (coin-info.php)

Deteksi AJAX (HTTP_X_REQUESTED_WITH / $_GET['action']), output JSON. Semua `WHERE tenant_id=?`.

| Action | Query | Return |
|--------|-------|--------|
| `coin_summary` | saldo dari tenants.coin_balance; Σ amount WHERE type='topup' & periode; Σ amount WHERE type='deduct' & periode; COUNT transaksi periode | `{saldo, topup, deduct, count}` |
| `coin_breakdown` | `SELECT cl.feature_used, COALESCE(p.nama_fitur, cl.feature_used) nama, COALESCE(p.kategori,'lainnya') kategori, SUM(cl.amount) total FROM coin_ledger cl LEFT JOIN saas_coin_pricing p ON p.feature_key=cl.feature_used WHERE cl.tenant_id=? AND cl.type='deduct' AND periode GROUP BY cl.feature_used ORDER BY total DESC` + agregasi per kategori di PHP | `{per_fitur:[...], per_kategori:[...], total_deduct}` |
| `coin_ledger` | `SELECT cl.*, COALESCE(p.nama_fitur, cl.feature_used) nama_fitur, o.nama_outlet FROM coin_ledger cl LEFT JOIN saas_coin_pricing p ON p.feature_key=cl.feature_used LEFT JOIN outlets o ON o.id=cl.outlet_id WHERE cl.tenant_id=? AND periode [+type filter] ORDER BY cl.created_at DESC LIMIT ? OFFSET ?` + COUNT total | `{rows:[...], total, page, pages}` |

**Periode:** param `bulan` format `YYYY-MM` (default bulan ini Asia/Jakarta). Filter: `DATE_FORMAT(cl.created_at,'%Y-%m')=?` atau range `created_at >= first AND < next_month`.

**Filter type:** `semua` (no filter) / `topup` / `deduct`.
**Pagination:** 30/halaman (LIMIT/OFFSET). page param.

### 3.3 Data Flow
```
Buka tab Riwayat → loadCoinUsage(bulan):
  paralel: coin_summary + coin_breakdown + coin_ledger(page 1)
  render: 3 card + bar kategori + tabel
Ganti periode → reload semua dgn bulan baru
Ganti type filter / page → reload coin_ledger saja
```

---

## 4. UI Spec

### 4.1 Tabs di coin-info.php
```
[💰 Harga Fitur]  [📊 Riwayat & Pemakaian]
```
Tab 1 = konten existing (pricing). Tab 2 = baru.

### 4.2 Tab Riwayat
```
📊 Riwayat & Pemakaian Coin            Periode: [Juni 2026 ▼]

┌─────────────┬──────────────┬───────────────┐
│ Saldo Coin  │ Terpakai     │ Top-up        │
│ 48.250      │ −12.500      │ +60.000       │
└─────────────┴──────────────┴───────────────┘

Pemakaian per Kategori (periode)
🤖 AI          ████████████ 8.200 (66%)
💬 WhatsApp    ████ 3.100 (25%)
📄 Dokumen     █ 1.200 (9%)

Rincian Transaksi          [Semua ▼ topup/deduct]
Tanggal       Fitur               Outlet    Coin      Saldo
25 Jun 14:20  AI Briefing HQ      (semua)   −150      48.250
24 Jun 16:00  Top-up Popular      —         +60.000   48.420
                                       [‹ Prev]  [Next ›]
```
- 3 summary card. Bar per kategori (% dari total_deduct).
- Tabel: deduct merah `−`, topup hijau `+`. esc semua data.
- Empty state per komponen kalau periode kosong.
- Kategori label+icon: 🤖 ai, 💬 whatsapp, 📄 dokumen, 📤 export, 📋 lainnya.

---

## 5. Edge Cases

| Skenario | Handler |
|----------|---------|
| feature_used NULL / tak ada di pricing | LEFT JOIN → COALESCE fallback raw key; kategori 'lainnya' |
| Periode tanpa transaksi | Empty state, card 0, breakdown kosong |
| total_deduct = 0 (breakdown %) | Skip /0, tampil "Belum ada pemakaian" |
| Ledger banyak | Pagination 30/halaman |
| coin_mode per_outlet | Tenant-wide aggregate, kolom outlet (filter opsional defer) |
| XSS description/feature/outlet | esc() semua render |
| Cross-tenant | WHERE tenant_id=? semua query |
| Bulan invalid (param) | Validasi regex YYYY-MM, fallback bulan ini |

---

## 6. Security
- hq_guard (owner-level) — sama coin-info existing
- Tenant scope: WHERE tenant_id=? SEMUA query (summary/breakdown/ledger)
- AJAX read-only (GET) → no CSRF needed (no state change)
- XSS htmlspecialchars/esc semua data DB
- Periode param validasi regex `^\d{4}-\d{2}$`

---

## 7. Testing Plan

### 7.1 Smoke Test
1. /hq/coin-info → 2 tab muncul, tab Harga = konten existing (no regression)
2. Tab Riwayat → 3 card (saldo match tenants.coin_balance), breakdown, tabel
3. Ganti periode → data reload sesuai bulan
4. Filter type topup/deduct → tabel ter-filter
5. Pagination → next/prev jalan
6. Breakdown % = amount/total_deduct benar
7. Empty bulan → empty state

### 7.2 Edge
| # | Test | Expected |
|---|------|----------|
| 1 | feature_used legacy/null | Fallback label, kategori lainnya |
| 2 | Bulan kosong transaksi | Empty state, card 0 |
| 3 | total_deduct 0 | No /0, "Belum ada pemakaian" |
| 4 | Cross-tenant (manipulasi) | WHERE tenant_id scope, no leak |
| 5 | Periode param invalid | Fallback bulan ini |
| 6 | Saldo vs tenants.coin_balance | Match |

---

## 8. Implementation Phasing

3 commits, ~2 jam:
1. Backend 3 actions (coin_summary/breakdown/ledger) + tab skeleton di coin-info.php
2. UI tab Riwayat (cards + bar kategori + tabel + filter periode/type + pagination + JS)
3. E2E + deploy

---

## 9. Files Inventory

### Modified
- `hq/coin-info.php` — tabs + 3 AJAX action + tab Riwayat UI + JS

### Schema
- None (baca coin_ledger + saas_coin_pricing + tenants existing)

---

## 10. Success Criteria
- ✅ Tab Riwayat di coin-info (tab Harga existing utuh)
- ✅ Ringkasan saldo + terpakai + top-up periode
- ✅ Breakdown per kategori & fitur (periode)
- ✅ Riwayat paginated + filter periode/type
- ✅ Tenant scope semua query, XSS-safe
- ✅ Zero regression coin-info pricing existing

---

## 11. References
- `hq/coin-info.php` — pola halaman (ROOT/hq_guard/_layout_open/Database::get), pricing tab existing
- `coin_ledger` (type/amount/feature_used/balance_after/created_at), `saas_coin_pricing` (feature_key→nama_fitur/kategori)
- `tenants.coin_balance`, `coin_mode`
- `core/CoinLedger.php` — write class (tidak diubah)
- Low-balance nudge: components.php:612, dashboard.php:526 (sudah ada, bukan scope)
