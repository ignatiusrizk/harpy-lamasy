# Konsolidasi Revenue Midtrans ke Dashboard SA — Design Spec

**Tanggal:** 25 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Dashboard revenue SuperAdmin (`superadmin/billing.php`) saat ini cuma menghitung pembayaran manual (`saas_manual_payments`). Pembayaran Midtrans otomatis (`saas_payments`, status='paid') TIDAK terhitung → revenue LAMASY understated. **Midtrans sudah LIVE** (per 2026-06-25), jadi ini bug aktif: begitu ada pembayaran QRIS/VA sukses, angka revenue salah. Fix: konsolidasi `saas_payments` ke query revenue billing.php via UNION.

**Scope:**
- 3 action di billing.php (stats, chart, top_tenants) baca revenue gabungan manual + Midtrans
- Helper SQL terpusat (DRY) untuk derived-table revenue gabungan
- Mapping tipe + kolom saas_payments → format dashboard
- Coin dari coin_ledger (akurat termasuk bonus)

**Out of scope:**
- Ubah skema / merge tabel jadi satu
- Breakdown payment_type (QRIS vs VA)
- Halaman rekonsiliasi pembayaran
- Perubahan coin_metrics action (sudah baca coin_ledger langsung — benar)

---

## 2. Background

**Dua jalur pembayaran (terpisah, tak tumpang tindih):**

| Tabel | Sumber | Kolom kunci | Status sukses |
|-------|--------|-------------|---------------|
| `saas_manual_payments` | Konfirmasi manual SA (registration_wizard, payments.php, client_detail) | type(setup_fee/coin_topup/adjustment/custom), nominal_dibayar, coin_dikreditkan, tanggal_bayar | `confirmed` |
| `saas_payments` | Midtrans otomatis (api/midtrans-webhook → PaymentSettler) | type(topup_coin/setup_fee/outlet_activation), amount, paid_at | `paid` |

**Verifikasi (terkonfirmasi):**
- billing.php stats/chart/top_tenants → query `saas_manual_payments WHERE status='confirmed'` SAJA (billing.php:34,77,112)
- `PaymentSettler::settleTopupCoin` + `settleSetupFee` → UPDATE tenants.coin_balance + INSERT coin_ledger; TIDAK INSERT saas_manual_payments
- Semua INSERT saas_manual_payments dari flow manual SA (grep: registration_wizard, payments, client_detail, registrations, migrations) — bukan webhook
- → UNION dua tabel TIDAK double-count
- Data saat ini: saas_payments 2 attempt (expired/failed), belum ada `paid`; saas_manual_payments kosong. Wiring harus benar sebelum transaksi sukses pertama.

---

## 3. Arsitektur

### 3.1 Komponen

**Modified only:** `superadmin/billing.php` — 1 helper baru + refactor 3 action.

No schema, no file baru, no perubahan PaymentSettler/webhook.

### 3.2 Helper terpusat (DRY)

```php
// Derived-table SQL: revenue gabungan manual + Midtrans, kolom dinormalisasi
// (nominal, coin, bayar, type, tenant_id). Dipakai stats/chart/top_tenants.
function smpRevenueSource(): string {
    return "(
        SELECT tenant_id,
               CASE WHEN type='custom' THEN 'adjustment' ELSE type END AS type,
               nominal_dibayar AS nominal,
               coin_dikreditkan AS coin,
               tanggal_bayar AS bayar
        FROM saas_manual_payments
        WHERE status='confirmed'
        UNION ALL
        SELECT sp.tenant_id,
               CASE WHEN sp.type='topup_coin' THEN 'coin_topup' ELSE 'setup_fee' END AS type,
               sp.amount AS nominal,
               COALESCE((SELECT cl.amount FROM coin_ledger cl
                         WHERE cl.payment_id=sp.id AND cl.type='topup' LIMIT 1),0) AS coin,
               DATE(COALESCE(sp.paid_at, sp.created_at)) AS bayar
        FROM saas_payments sp
        WHERE sp.status='paid'
    ) rev";
}
```

**Mapping tipe saas_payments → dashboard:**
| saas_payments.type | dashboard type |
|--------------------|----------------|
| topup_coin | coin_topup |
| setup_fee | setup_fee |
| outlet_activation | setup_fee |

**Kolom alias seragam (derived `rev`):** `tenant_id`, `type`, `nominal`, `coin`, `bayar`.

### 3.3 Refactor 3 Action

| Action | Sebelum | Sesudah |
|--------|---------|---------|
| `stats` (smpStats + YTD + pending) | `FROM saas_manual_payments WHERE status='confirmed' AND MONTH(tanggal_bayar)=? AND YEAR(...)` | `FROM <rev> WHERE MONTH(bayar)=? AND YEAR(bayar)=?` ; kolom `nominal`/`coin`. **pending_count tetap dari saas_manual_payments** (pending = antrian konfirmasi manual, bukan revenue) |
| `chart` (6 bulan stacked) | `FROM saas_manual_payments WHERE status='confirmed' AND tanggal_bayar >= ...` GROUP BY mon,type | `FROM <rev> WHERE bayar >= ...` GROUP BY mon,type (kolom `bayar`,`nominal`) |
| `top_tenants` | `FROM saas_manual_payments p JOIN tenants` | `FROM <rev> p JOIN tenants t ON t.id=p.tenant_id` (kolom `nominal`) |

`coin_metrics` action TIDAK diubah (sudah baca coin_ledger + tenants langsung — benar).

---

## 4. UI Spec

Tidak ada perubahan UI/markup. Kartu revenue, chart, top_tenants tampil sama — hanya **angka** sekarang termasuk Midtrans. Label tetap.

---

## 5. Edge Cases

| Skenario | Handler |
|----------|---------|
| Midtrans topup coin + bonus | coin dari coin_ledger.amount (aktual base+bonus), akurat |
| saas_payments setup_fee (no coin_ledger topup) | COALESCE subquery → 0 coin (benar) |
| outlet_activation | map → setup_fee |
| paid_at NULL (status paid edge) | COALESCE(paid_at, created_at) untuk tanggal |
| Double-count manual vs Midtrans | Tak ada — 2 sumber terpisah (verified) |
| type='custom' (manual) | map → adjustment (sesuai chart existing yg gabung custom ke adj) |
| Performa correlated subquery coin_ledger | SA-scale volume kecil, acceptable |
| saas_payments status selain paid (pending/expired/failed) | Di-exclude (WHERE status='paid') |

---

## 6. Security
- SA-only (billing.php sudah di bawah superadmin guard) — no perubahan akses
- Read-only agregasi, no user input ke SQL (action fixed string, periode dari date() server)
- Tidak ada perubahan tenant data / pembayaran — murni baca

---

## 7. Testing Plan

### 7.1 Smoke
1. /superadmin/billing → dashboard load, kartu/chart/top_tenants tampil (no error)
2. Angka revenue = Σ manual confirmed + Σ Midtrans paid (saat ini: manual kosong + paid kosong → 0, no error)
3. Insert dummy saas_payments status='paid' (test) → muncul di stats/chart/top_tenants; hapus dummy
4. pending_count tetap dari saas_manual_payments

### 7.2 Edge / Verifikasi
| # | Test | Expected |
|---|------|----------|
| 1 | Midtrans paid topup_coin | nominal masuk coin_topup card + coin dari ledger |
| 2 | Midtrans paid setup_fee/outlet_activation | masuk setup_fee card, coin 0 |
| 3 | Manual confirmed + Midtrans paid sama bulan | dijumlah, tak double |
| 4 | saas_payments expired/failed | tak terhitung |
| 5 | chart 6 bulan | manual+Midtrans per bulan benar |
| 6 | top_tenants | total_spent gabungan |

DB cross-check: bandingkan SUM manual + SUM Midtrans paid vs angka dashboard.

---

## 8. Implementation Phasing

2 commits, ~1.5 jam:
1. Helper smpRevenueSource() + refactor 3 action (stats/chart/top_tenants) ke derived-table; pending_count tetap manual
2. E2E (dummy paid verify + cleanup) + deploy

---

## 9. Files Inventory

### Modified
- `superadmin/billing.php` — helper smpRevenueSource() + 3 action refactor

### Schema
- None

---

## 10. Success Criteria
- ✅ Revenue dashboard = manual confirmed + Midtrans paid (akurat)
- ✅ Coin dari coin_ledger (termasuk bonus)
- ✅ Tipe mapping benar (topup_coin→coin_topup, setup_fee/outlet_activation→setup_fee)
- ✅ Tidak double-count
- ✅ pending_count tetap antrian manual
- ✅ coin_metrics tak berubah
- ✅ Zero regression UI

---

## 11. References
- `superadmin/billing.php` — stats(25-62)/chart(65-103)/top_tenants(106-122)/coin_metrics(125+)
- `core/PaymentSettler.php` — settleTopupCoin(39)/settleSetupFee(97): tulis coin_ledger, bukan saas_manual_payments
- `saas_payments` (Midtrans: type/amount/paid_at/status), `saas_manual_payments` (manual: type/nominal_dibayar/coin_dikreditkan/tanggal_bayar/status), `coin_ledger` (payment_id link)
- api/midtrans-webhook.php → PaymentSettler (jalur otomatis)
- [[project_monetization]]
