# Static QRIS POS Integration — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Customer-facing QRIS payment di POS — tenant upload static QRIS image (dari banking app mereka sendiri), kasir display QR saat customer bayar, customer scan + transfer, kasir confirm manual.

**Scope:**
- Per-outlet QRIS image upload via HQ settings
- POS payment method baru: `qris_static`
- Modal QR display + manual confirmation kasir
- Reports auto-aggregate (existing payment_method breakdown)

**Out of scope:**
- Per-tenant Midtrans MID integration (terlalu kompleks untuk B2C, sudah covered untuk B2B billing platform)
- Auto-reconciliation banking app (gak ada integrasi bank API)
- QRIS string text input (image-only — YAGNI)
- Customer-side portal payment (in-person POS only)

---

## 2. Background

**Current state (existing POS flow):**

POS punya payment method picker dengan 3 options: `cash`, `transfer`, `deposit`. Kasir pilih method → input amount → submit order. No QR display, no QRIS option.

**Pain point:**
- Customer ingin bayar cashless tanpa transfer manual (yang ribet input nominal + verify bukti)
- Tenant punya QRIS dari bank/wallet tapi gak ada cara display di POS
- Print QR jadi sticker di kasir = ribet update kalau ganti rekening

**Why this is the right approach:**
- LAMASY tidak perlu jadi merchant facilitator (no per-tenant MID hassle)
- Settlement langsung ke rekening tenant — instant
- Zero fee LAMASY untuk transaksi ini (tenant pakai infra QRIS bank sendiri)
- Manual confirm by kasir cocok untuk UMKM trust model (same as cash payment trust)

**Comparison vs Midtrans (yang baru kelar):**

| Aspect | Static QRIS (spec ini) | Midtrans QRIS (existing) |
|--------|------------------------|--------------------------|
| Use case | Customer → Tenant (B2C) | Tenant → LAMASY (B2B billing) |
| Integration | Image upload only | API + webhook |
| Setup tenant | 1 menit | KYC + per-tenant MID |
| Verification | Manual (kasir) | Auto webhook |
| Settlement | Instant ke tenant bank | T+1/T+2 LAMASY |
| Fee | 0 (tenant pakai infra bank) | 0.7% per tx |

Kedua approach co-exist — Midtrans untuk B2B platform billing, Static QRIS untuk B2C customer payments.

---

## 3. Arsitektur

### 3.1 Komponen

**New:**
```
hq/outlet-qris.php                ← Upload form per outlet
assets/outlet-qris/               ← Upload directory (file storage)
db/migrations/2026-06-24-outlet-qris.sql
```

**Modified:**
```
hq/outlet.php                     ← Link "Setup QRIS" per outlet row
pos.php                           ← Radio QRIS + modal QR display
core/StrukGenerator.php           ← Label "QRIS" di struk
```

**Existing (no change):**
- Reports/laporan auto-aggregate via `payment_method` GROUP BY
- Audit log via existing hl_audit_log INSERT pattern
- POS save handler treats `qris_static` like any other payment method

### 3.2 Schema Delta

```sql
ALTER TABLE outlets
  ADD COLUMN qris_image VARCHAR(255) NULL AFTER status,
  ADD COLUMN qris_uploaded_at DATETIME NULL,
  ADD COLUMN qris_label VARCHAR(100) NULL COMMENT 'Display label, mis. "BCA - PT Rizky Laundry"';
```

3 kolom additive ke `outlets`. NULL by default — existing outlets gak terdampak.

### 3.3 Data Flow

```
┌──────────────────────────────────────────────────┐
│ A. UPLOAD (HQ)                                    │
└──────────────────────────────────────────────────┘
[Owner login HQ] → /hq/outlet-qris.php?outlet_id=X
       ↓
Form: label + file upload
       ↓
Validate: mime, size ≤500KB, dim ≥400×400
       ↓
Save: /assets/outlet-qris/outlet_{id}_{ts}.{ext}
       ↓
UPDATE outlets SET qris_image, qris_label, qris_uploaded_at
       ↓
Old image deleted (kalau replace)

┌──────────────────────────────────────────────────┐
│ B. POS PAYMENT (Outlet)                          │
└──────────────────────────────────────────────────┘
[Kasir pilih method = QRIS]
       ↓
Frontend cek outlet.qris_image
  ├─ NULL → option disabled "QRIS belum di-setup"
  └─ ada → modal popup dengan QR image
       ↓
[Customer scan + bayar di banking app]
       ↓
[Kasir verify di banking app tenant (notif masuk)]
       ↓
[Kasir klik "Pembayaran Diterima"]
       ↓
Modal close, submit order normal
       ↓
INSERT hl_order + hl_pembayaran (method='qris_static')
       ↓
INSERT hl_audit_log (aksi='pembayaran_qris')
       ↓
Cetak struk (label "QRIS")
```

---

## 4. UI Spec

### 4.1 Upload Form (`/hq/outlet-qris.php`)

```
┌─────────────────────────────────────────────────────┐
│ 🏷️  Setup QRIS — Outlet Jakarta                    │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Label QRIS *                                        │
│  ┌─────────────────────────────────────────────┐   │
│  │ BCA - PT Rizky Laundry                       │   │
│  └─────────────────────────────────────────────┘   │
│  Tampil di POS sebagai info ke customer              │
│                                                      │
│  Upload Gambar QRIS *                                │
│  ┌─────────────────────────────────────────────┐   │
│  │  [Preview thumbnail kalau sudah upload]      │   │
│  │  📷 [Pilih File]  🗑️ [Hapus]                 │   │
│  └─────────────────────────────────────────────┘   │
│                                                      │
│  📐 Spesifikasi:                                     │
│  • Format: JPG, PNG, WebP                            │
│  • Min 400 × 400 px (square)                         │
│  • Maks 500 KB                                        │
│                                                      │
│  [ Simpan ]                                          │
└─────────────────────────────────────────────────────┘
```

**Access control:** Owner-only (`hq.access` permission already enforced via HQ guard).

### 4.2 POS Payment Method (extend existing)

```
Metode Pembayaran:
  ◯ Cash
  ◯ Transfer Bank
  ◯ Deposit Wallet
  ◉ QRIS                  ← NEW (disabled kalau outlet belum upload)
```

**Disabled state:** Show option dengan badge "Belum aktif" + tooltip "Owner harus upload QRIS dulu di HQ → Outlet Settings".

### 4.3 POS QR Modal (saat QRIS dipilih)

```
┌─────────────────────────────────────────────────────┐
│ 💳 Pembayaran QRIS                          [×]     │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Total: Rp 75.000                                    │
│                                                      │
│  ┌──────────────────────────────┐                   │
│  │     [QRIS IMAGE display]     │                   │
│  │     (max 320×320 px)         │                   │
│  └──────────────────────────────┘                   │
│                                                      │
│  BCA - PT Rizky Laundry                              │
│                                                      │
│  Cara bayar:                                          │
│  1. Customer scan QR pakai banking app               │
│  2. Cek banking app outlet untuk notif masuk         │
│  3. Pastikan nominal masuk = Rp 75.000               │
│  4. Klik tombol di bawah                             │
│                                                      │
│  [ ✓ Pembayaran Diterima — Lanjutkan ]              │
│  [ Batal — Pilih Metode Lain ]                      │
└─────────────────────────────────────────────────────┘
```

**Confirm flow:** Klik "Pembayaran Diterima" → modal close → form submit dengan `payment_method='qris_static'` → order tersimpan + struk cetak.

**Cancel flow:** Klik "Batal" → modal close → radio button QRIS un-selected → kasir bebas pilih method lain.

---

## 5. Backend Logic

### 5.1 Upload Validation (`hq/outlet-qris.php`)

```php
// 1. Type check (mime, bukan extension)
$mime = mime_content_type($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) throw new RuntimeException('Hanya JPG/PNG/WebP');

// 2. Size check
if ($f['size'] > 500 * 1024) throw new RuntimeException('Max 500 KB');

// 3. Dimension check (QR harus square + scan-able)
$info = getimagesize($f['tmp_name']);
if (!$info || $info[0] < 400 || $info[1] < 400) {
    throw new RuntimeException("Min 400×400 px. Anda: {$info[0]}×{$info[1]}");
}

// 4. Save with random filename component (anti-guess)
$ext = $allowed[$mime];
$filename = sprintf('outlet_%d_%d_%s.%s', $outletId, time(), bin2hex(random_bytes(3)), $ext);
$dir = __DIR__ . '/../assets/outlet-qris';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
move_uploaded_file($f['tmp_name'], "$dir/$filename");

// 5. Delete old (kalau replace)
// ... (lookup old qris_image, unlink kalau ada)

// 6. UPDATE outlets dengan tenant_id check (anti cross-tenant write)
```

### 5.2 POS Modal Trigger (`pos.php`)

```javascript
// Load outlet QRIS data dari PHP-rendered JSON
const outletQris = <?= json_encode([
    'image' => $outletQrisData['qris_image'] ?? null,
    'label' => $outletQrisData['qris_label'] ?? null,
]) ?>;

document.querySelector('input[value="qris_static"]')?.addEventListener('change', function() {
    if (!this.checked) return;
    if (!outletQris.image) {
        alert('QRIS belum di-setup. Hubungi owner.');
        this.checked = false;
        return;
    }
    document.getElementById('qrisAmount').textContent =
        currentTotal.toLocaleString('id-ID');
    document.getElementById('qrisImage').src = outletQris.image;
    document.getElementById('qrisLabel').textContent = outletQris.label || '';
    document.getElementById('qrisModal').style.display = 'flex';
});

function confirmQrisPayment() {
    document.getElementById('qrisModal').style.display = 'none';
    // Submit form normal — payment_method='qris_static'
}

function closeQrisModal() {
    document.getElementById('qrisModal').style.display = 'none';
    document.querySelector('input[value="qris_static"]').checked = false;
}
```

### 5.3 POS Save Handler

No special handling. `payment_method='qris_static'` treated identically to `cash`:

```sql
INSERT INTO hl_pembayaran
    (tenant_id, outlet_id, order_id, jumlah, payment_method, kasir_id, created_at)
VALUES (?, ?, ?, ?, 'qris_static', ?, NOW());
```

Audit log entry:

```sql
INSERT INTO hl_audit_log
    (tenant_id, outlet_id, user_id, user_nama, user_role, modul, aksi, keterangan, ref_id, ip_address)
VALUES (?, ?, ?, ?, 'kasir', 'pos', 'pembayaran_qris',
        CONCAT('Order #', ?, ' — Rp ', ?, ' via QRIS'), ?, ?);
```

---

## 6. Existing System Integration

### 6.1 Reports Auto-Aggregate

Existing SQL di `/laporan.php` dan `/dashboard.php`:

```sql
SELECT payment_method, COUNT(*) AS qty, SUM(jumlah) AS total
FROM hl_pembayaran
WHERE tenant_id=? AND DATE(created_at) = CURDATE()
GROUP BY payment_method;
```

Output sudah auto-include `qris_static`:
```
cash         | 12 | 1.500.000
transfer     |  3 |   450.000
deposit      |  2 |   200.000
qris_static  |  5 |   750.000  ← appears otomatis
```

**Display label di UI:** mapping `qris_static` → "QRIS" di view layer (existing pattern untuk method labels).

### 6.2 Struk Generator

`core/StrukGenerator.php` baca `payment_method` field. Add mapping:

```php
$paymentMethodLabel = match($method) {
    'cash'        => 'Tunai',
    'transfer'    => 'Transfer Bank',
    'deposit'     => 'Deposit Wallet',
    'qris_static' => 'QRIS',
    default       => ucfirst($method),
};
```

### 6.3 Audit Log

Existing SA audit log viewer (`/superadmin/audit.php` tab "Tenant Audit") akan auto-show entries dengan `aksi='pembayaran_qris'`. No SA-side code change.

---

## 7. Security

### 7.1 Upload Validation
- MIME-type check (bukan extension)
- Size limit 500 KB
- Dimension validation (min 400×400, square QR scan-able)
- Filename randomization (anti-guessing): `outlet_{id}_{timestamp}_{rand6}.{ext}`

### 7.2 Access Control
- Upload page: HQ guard (`hq.access` permission) — owner only
- POS access: tenant_guard + outlet scope
- Cross-tenant write prevention: UPDATE includes `WHERE outlet_id=? AND tenant_id=?`

### 7.3 Public Asset
QRIS image accessible via direct URL (`/assets/outlet-qris/outlet_X_...png`). Acceptable karena:
- QRIS itself static, tenant share ke customer
- Random filename component → URL not enumerable
- No sensitive data dalam QRIS (cuma merchant ID + verifier hash)

### 7.4 XSS
- `htmlspecialchars()` di semua label display
- `json_encode()` untuk JS data injection

### 7.5 Path Traversal
- Filename construction server-side dari ID + time + random
- No user input dalam filename

---

## 8. Edge Cases

| Skenario | Handler |
|----------|---------|
| Outlet baru, belum upload QRIS | Option QRIS disabled di POS dengan badge "Belum aktif" |
| Owner upload pertama kali | Save + update DB, available di POS langsung |
| Owner replace QRIS | Old file deleted, new uploaded, qris_uploaded_at refresh |
| Owner hapus QRIS | qris_image=NULL, file deleted, POS option disabled |
| Kasir scan tapi customer cancel | Klik "Batal" → modal close, pilih method lain |
| Customer scan + bank app lambat | Kasir tunggu di modal sampai notif masuk |
| Kasir klaim "sudah bayar" tanpa verify | Same trust model as cash. Audit log timestamps. Owner reconcile via banking app history. |
| Multi-kasir login bersamaan | Each kasir punya session sendiri, no conflict |
| Upload file rusak/non-image | Validation reject (mime + getimagesize fail) |
| File too large | Validation reject (size > 500KB) |
| Outlet pindah pakai rekening beda | Owner upload baru, lama auto-deleted |

---

## 9. Testing Plan

### 9.1 Manual Smoke Test

1. Login HQ owner → `/hq/outlet-qris?outlet_id=X` → upload sample QR image (download dari Midtrans sandbox simulator atau buat QR sample online) → save → verify file ada di `/assets/outlet-qris/` + DB updated
2. Logout, login as kasir → POS → ada order item → pilih payment method "QRIS" → modal popup dengan QR image yang di-upload + label + total → click "Pembayaran Diterima" → modal close → submit → order tersimpan dengan `payment_method='qris_static'`
3. Cetak struk → label "QRIS" muncul
4. Buka laporan harian → row baru `qris_static` muncul di aggregation
5. SA login → `/superadmin/audit.php` tab Tenant Audit → cari entry `aksi='pembayaran_qris'` → verifikasi metadata

### 9.2 Edge Cases Manual Test

| # | Test | Expected |
|---|------|----------|
| 1 | Upload non-image (PDF) | Reject "Hanya JPG/PNG/WebP" |
| 2 | Upload >500KB | Reject "Max 500 KB" |
| 3 | Upload 200×200 | Reject "Min 400×400" |
| 4 | Outlet baru POS pilih QRIS | Option disabled + tooltip |
| 5 | Replace QRIS image | Old file deleted, new saved |
| 6 | Hapus QRIS | File deleted, DB NULL, POS option disabled |
| 7 | Cross-tenant try update | UPDATE WHERE tenant_id check blocks |

---

## 10. Implementation Phasing

Single deliverable, ~1.5-2 jam total. Inline execution (no subagent needed) atau split jadi 2 commits:

**Commit 1 — Schema + Upload Page (~45 menit):**
- DB migration
- `/hq/outlet-qris.php` form + handler
- Validation logic
- Link di `/hq/outlet` row

**Commit 2 — POS Integration (~45 menit):**
- pos.php radio QRIS + modal
- POS save handler accept `qris_static`
- StrukGenerator label
- Manual smoke test E2E

---

## 11. Files Inventory

### New
- `db/migrations/2026-06-24-outlet-qris.sql`
- `hq/outlet-qris.php`
- `assets/outlet-qris/.gitkeep`

### Modified
- `hq/outlet.php` — link "Setup QRIS" per outlet
- `pos.php` — radio + modal
- `core/StrukGenerator.php` — label mapping

---

## 12. Out of Scope (Phase 1)

- Bank API integration (auto-confirm)
- QRIS string text input
- Customer-side portal QRIS payment
- Per-tenant QRIS (HQ-level shared) — kita per-outlet semua
- Auto-generate dynamic QRIS dengan amount embedded (butuh per-tenant MID)
- Refund flow QRIS (manual reconciliation by owner)
- Multi-rekening per outlet
- QRIS expiry tracking (static QR tidak expire by design)

---

## 13. Success Criteria

- ✅ Owner upload QRIS image via HQ dalam <1 menit
- ✅ Kasir pilih QRIS di POS, modal popup dengan QR yang jelas + scan-able
- ✅ Customer scan + bayar, kasir confirm manual, order completed
- ✅ Cetak struk include label "QRIS"
- ✅ Laporan harian auto-aggregate QRIS revenue
- ✅ Audit log capture kasir + timestamp + amount
- ✅ Zero impact ke flow existing (cash/transfer/deposit) — backward compat 100%

---

## 14. References

- Existing POS payment method picker di `pos.php`
- Banner upload pattern di `/superadmin/banners.php` (similar image upload validation)
- Audit log pattern di outlet operations
