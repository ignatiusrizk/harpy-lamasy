# Custom Payment Methods per Outlet — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Owner outlet bisa **tambah/edit/hapus** metode pembayaran custom (selain cash/transfer/qris/deposit yang hardcoded sekarang) untuk muncul di POS dropdown saat input pembayaran. Mis. "Transfer BCA", "Transfer Mandiri", "GoPay", "OVO", "EDC", "Voucher", "Tempo B2B" — sesuai kebutuhan outlet masing-masing.

**Scope:**
- Per-outlet methods (each outlet manage own list)
- Free-form label + emoji icon
- Built-in defaults (Tunai/Transfer/QRIS) yang gak bisa di-delete tapi bisa di-deactivate
- POS dropdown render dinamis dari DB
- StrukGenerator label lookup via DB dengan fallback graceful
- Reports auto-aggregate (existing GROUP BY metode_bayar tidak berubah)

**Out of scope (Phase 1):**
- Drag-drop sort UI (gunakan ID-order saja)
- Image upload per method (cuma QRIS yang special — sudah ada di Pembayaran tab)
- Per-tenant shared methods (per-outlet saja)
- Method-specific transaction fee/discount rules
- Method-specific reporting filters di laporan
- Soft delete (hard delete dengan fallback `ucfirst($code)` di display)

---

## 2. Background

**Current state:**

POS hardcode 3 metode di `pos.php:1235-1239`:
```html
<option value="cash">💵 Cash</option>
<option value="transfer">🏦 Transfer</option>
<option value="qris">📱 QRIS</option>
```

Plus "Deposit Wallet" sebagai checkbox terpisah (deduct dari saldo customer).

`hl_transaksi.metode_bayar` adalah VARCHAR(30) — flexible storage cukup untuk custom values.

Reports/laporan sudah `GROUP BY metode_bayar` — auto-aggregate row baru saat ada code baru.

`StrukGenerator::metodeBayarLabel()` (existing) sudah explicit match() expression untuk cash/transfer/qris/deposit dengan ucfirst fallback.

**Pain point:**
- Multi-outlet tenant punya rekening berbeda per outlet (Outlet Jakarta = BCA, Bandung = Mandiri) — POS gak bisa bedakan
- Sebagian laundry pakai EDC, voucher, GoPay terpisah — tidak ada di metode default
- Reports campur semua "Transfer" jadi 1 row meskipun bank beda — sulit reconcile
- Owner gak bisa atur sendiri tanpa edit code

**Why this approach:**
- Free-form custom = paling flexible, sedikit assumption tentang bisnis owner
- Per-outlet = konsisten dengan QRIS yang sudah per-outlet
- Built-in defaults dipertahankan = backward compat 100% untuk historical data
- Hard delete + fallback `ucfirst()` = simple, no soft-delete column needed

---

## 3. Arsitektur

### 3.1 Komponen

**New file:**
```
db/migrations/2026-06-24-payment-methods.sql   ← Schema + backfill seed
```

**Modified files:**
```
payment-settings.php          ← Tambah section "Metode Pembayaran POS"
pos.php                       ← Dropdown render dinamis + save validation
core/StrukGenerator.php       ← Label lookup via DB + fallback
core/TenantProvisioner.php    ← Seed 3 default rows saat outlet create
```

**No change required:**
- Reports/laporan SQL (existing GROUP BY metode_bayar auto-include custom)
- hl_transaksi schema (metode_bayar VARCHAR(30) sudah cukup)
- StrukGenerator call sites (hanya update label resolution internal)

### 3.2 Schema

```sql
CREATE TABLE hl_payment_methods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  code VARCHAR(30) NOT NULL,         -- value yang masuk hl_transaksi.metode_bayar
  label VARCHAR(50) NOT NULL,        -- display label, mis. "Transfer BCA"
  emoji VARCHAR(8) DEFAULT '💳',
  is_builtin TINYINT(1) DEFAULT 0,   -- 1 untuk cash/transfer/qris (no delete)
  is_active TINYINT(1) DEFAULT 1,    -- 0 = hidden di POS
  sort_order INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_outlet_code (tenant_id, outlet_id, code),
  INDEX idx_outlet_active (outlet_id, is_active, sort_order)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Built-in default seed (3 rows per outlet):**

| code | label | emoji | is_builtin | sort_order |
|------|-------|-------|------------|------------|
| cash | Tunai | 💵 | 1 | 1 |
| transfer | Transfer Bank | 🏦 | 1 | 2 |
| qris | QRIS | 📱 | 1 | 3 |

**Backfill strategy:**

- Migration: `INSERT ... SELECT FROM outlets` — semua outlet existing dapat 3 rows
- TenantProvisioner: tambah seed di outlet create flow (future-proof)

### 3.3 Data Flow

```
┌──────────────────────────────────────────────┐
│ A. CONFIG (Pembayaran tab)                   │
└──────────────────────────────────────────────┘
[Owner /payment-settings]
       ↓
List metode dari hl_payment_methods WHERE outlet_id=? ORDER BY sort_order
       ↓
Built-in rows: checkbox active, no edit/delete
Custom rows:   checkbox active + edit + delete
       ↓
[Klik + Tambah Metode]
       ↓
Modal: input label + emoji
       ↓
Auto-slug code dari label (mis. "Transfer BCA" → "transfer_bca")
       ↓
INSERT hl_payment_methods (code unique per outlet)
       ↓
Refresh list

┌──────────────────────────────────────────────┐
│ B. POS DROPDOWN                              │
└──────────────────────────────────────────────┘
[POS page render]
       ↓
SELECT code, label, emoji FROM hl_payment_methods
WHERE outlet_id=? AND tenant_id=? AND is_active=1
ORDER BY sort_order, id
       ↓
<select id="f_metode">
  <option value="{code}">{emoji} {label}</option>
  ...
</select>
       ↓
[Kasir pilih option]
       ↓
Submit dengan metode_bayar={code}
       ↓
Validate: code exists in hl_payment_methods active for this outlet
       ↓
INSERT hl_transaksi (metode_bayar={code})

┌──────────────────────────────────────────────┐
│ C. STRUK + REPORTS                           │
└──────────────────────────────────────────────┘
[Cetak struk]
       ↓
StrukGenerator::metodeBayarLabel($trx['metode_bayar'], $trx['outlet_id'])
       ↓
Cache check
       ↓
DB lookup hl_payment_methods (outlet_id + code)
       ↓
Found → "🏦 Transfer BCA"
Not found → fallback `ucfirst($code)` (for historical/deleted methods)
       ↓
Render di struk
```

---

## 4. UI Spec

### 4.1 Pembayaran Tab Layout

Restructure existing "Pembayaran QRIS" tab title jadi **"Pembayaran"** (lebih generic). 2 sections di dalam:

```
┌─────────────────────────────────────────────────────────┐
│ 🏢 Outlet & Nota │ 🧾 Struk & Invoice │ 💳 Pembayaran │
├─────────────────────────────────────────────────────────┤
│                                                          │
│ ═══ Metode Pembayaran POS ═══                           │
│                                                          │
│ Kelola metode yang muncul di POS saat input pembayaran. │
│                                                          │
│ ┌─────────────────────────────────────────────────────┐│
│ │ ☑ 💵 Tunai           [built-in]                     ││
│ │ ☑ 🏦 Transfer Bank   [built-in]                     ││
│ │ ☑ 📱 QRIS            [built-in]                     ││
│ │ ☑ 🏦 Transfer BCA          [✏️ Edit]  [🗑️ Hapus]   ││
│ │ ☑ 💸 GoPay                 [✏️ Edit]  [🗑️ Hapus]   ││
│ │ ☐ 🎫 Voucher (nonaktif)    [✏️ Edit]  [🗑️ Hapus]   ││
│ └─────────────────────────────────────────────────────┘│
│                                                          │
│ [+ Tambah Metode Baru]                                  │
│                                                          │
│ ═══ Setup Gambar QRIS ═══                               │
│                                                          │
│ [Upload form QRIS image — existing section]             │
└─────────────────────────────────────────────────────────┘
```

**Behavior:**

- Built-in rows: checkbox aktif/nonaktif, tidak ada tombol edit/delete
- Custom rows: full CRUD
- Checkbox change → AJAX inline (no submit button), update `is_active` immediately
- Drag-drop sort_order: out of scope Phase 1, gunakan urutan ID

### 4.2 Tambah/Edit Metode Modal

Klik **[+ Tambah Metode Baru]** atau **[✏️ Edit]**:

```
┌─────────────────────────────────────────────────────┐
│ Tambah Metode Pembayaran                  [×]       │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Label *                                             │
│  ┌──────────────────────────────────────────────┐  │
│  │ Transfer BCA                                  │  │
│  └──────────────────────────────────────────────┘  │
│  Max 50 karakter                                     │
│                                                      │
│  Emoji Icon                                          │
│  ┌─────────┐  Default: 💳                          │
│  │   🏦    │                                        │
│  └─────────┘                                        │
│                                                      │
│  Code (auto-generated dari label)                    │
│  ┌──────────────────────────────────────────────┐  │
│  │ transfer_bca                                  │  │
│  └──────────────────────────────────────────────┘  │
│  Nilai yang disimpan di database. Saat edit,        │
│  field ini terkunci untuk preserve histori data.    │
│                                                      │
│  [ Batal ]                    [ 💾 Simpan ]         │
└─────────────────────────────────────────────────────┘
```

**Validation:**

- Label: required, trim whitespace, max 50 char
- Emoji: optional (default `💳`), single Unicode emoji char
- Code: auto-slug dari label saat tambah baru, locked saat edit
- Code unique per outlet (UNIQUE constraint catch + show error)

**Slug helper:**

```php
function slugifyMethodCode(string $label): string {
    $s = strtolower(trim($label));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    return substr($s, 0, 30) ?: 'method_' . dechex(random_int(0, 0xFFFFFF));
}
```

**Collision resolution:** Append `_2`, `_3` etc kalau code sudah ada untuk outlet ini.

### 4.3 POS Dropdown Display

```
Metode Pembayaran:
  💵 Tunai             ← built-in active
  🏦 Transfer Bank
  📱 QRIS               (klik → modal QR popup)
  🏦 Transfer BCA       ← custom
  💸 GoPay              ← custom
  (Voucher hidden — is_active=0)
```

**Built-in QRIS behavior tetap:** klik → modal QR popup dengan image scan. Other methods (cash/transfer/custom) tidak buka modal — langsung submit form.

---

## 5. Backend Logic

### 5.1 payment-settings.php — CRUD Actions

POST handlers (in addition to existing upload/delete QRIS):

```php
// action=method_add
$label = trim($_POST['label']);
$emoji = trim($_POST['emoji']) ?: '💳';
$code  = slugifyMethodCode($label);
// resolve collision
$i = 2;
while (codeExists($outletId, $code)) { $code = slugifyMethodCode($label) . "_$i"; $i++; }
INSERT hl_payment_methods (tenant_id, outlet_id, code, label, emoji, is_builtin=0, is_active=1)

// action=method_edit
// Only label + emoji editable; code locked.
// Built-in: still allow label/emoji edit (sedikit longgar) atau lock semua? → pilih: lock semua untuk built-in (preserve consistency)
UPDATE hl_payment_methods SET label=?, emoji=? WHERE id=? AND outlet_id=? AND tenant_id=? AND is_builtin=0

// action=method_delete
DELETE FROM hl_payment_methods WHERE id=? AND outlet_id=? AND tenant_id=? AND is_builtin=0

// action=method_toggle
UPDATE hl_payment_methods SET is_active = 1 - is_active WHERE id=? AND outlet_id=? AND tenant_id=?
// Built-in can be toggled (mis. outlet B2B nonaktifkan Cash)
```

All endpoints:
- CSRF verify
- Tenant + outlet scope on WHERE clause
- Return JSON with refreshed list

### 5.2 pos.php Diff

**1. Load active methods (around the existing $outletQrisData query):**

```php
$methodsStmt = $db->prepare("
  SELECT code, label, emoji
  FROM hl_payment_methods
  WHERE outlet_id=? AND tenant_id=? AND is_active=1
  ORDER BY sort_order, id
");
$methodsStmt->execute([$oid, $tid]);
$activeMethods = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);
```

**2. Replace dropdown HTML (around line 1235):**

```php
<select id="f_metode" onchange="onMetodeChange()">
  <?php foreach ($activeMethods as $m): ?>
    <?php
      $isQrisDisabled = ($m['code']==='qris' && !$outletQrisData['qris_image']);
    ?>
    <option value="<?= htmlspecialchars($m['code']) ?>" <?= $isQrisDisabled ? 'disabled' : '' ?>>
      <?= htmlspecialchars($m['emoji']) ?> <?= htmlspecialchars($m['label']) ?>
      <?= $isQrisDisabled ? '(belum di-setup)' : '' ?>
    </option>
  <?php endforeach; ?>
</select>
```

**3. Save handler validation (around line 460):**

```php
$metode = $data['metode_bayar'] ?? 'cash';
$valid = $db->prepare("
  SELECT 1 FROM hl_payment_methods
  WHERE outlet_id=? AND tenant_id=? AND code=? AND is_active=1
");
$valid->execute([$oid, $tid, $metode]);
if (!$valid->fetchColumn()) {
    throw new RuntimeException('Metode pembayaran tidak valid');
}
```

### 5.3 StrukGenerator Diff

Replace existing `metodeBayarLabel()`:

```php
private static function metodeBayarLabel(?string $code, ?int $outletId = null): string {
    if (!$code) return '';

    static $cache = [];
    $key = ($outletId ?? 0) . ':' . $code;
    if (isset($cache[$key])) return $cache[$key];

    if ($outletId) {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT emoji, label FROM hl_payment_methods
                                  WHERE outlet_id=? AND code=? LIMIT 1");
            $stmt->execute([$outletId, $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $cache[$key] = trim(($row['emoji'] ?? '') . ' ' . $row['label']);
            }
        } catch (Throwable $e) {
            // Fall through to default mapping
        }
    }

    // Fallback for historical/orphan codes
    $defaults = [
        'cash' => 'Tunai', 'transfer' => 'Transfer Bank',
        'qris' => 'QRIS', 'deposit' => 'Saldo Deposit',
    ];
    return $cache[$key] = $defaults[$code] ?? ucfirst($code);
}
```

Call sites updated: pass `$trx['outlet_id']` as 2nd argument.

```php
// Before:
$h .= self::tRow('Bayar', self::metodeBayarLabel($trx['metode_bayar']), $maxChar);

// After:
$h .= self::tRow('Bayar', self::metodeBayarLabel($trx['metode_bayar'], $trx['outlet_id'] ?? null), $maxChar);
```

### 5.4 TenantProvisioner Diff

Saat outlet baru di-create, append seed:

```php
$db->prepare("
  INSERT INTO hl_payment_methods (tenant_id, outlet_id, code, label, emoji, is_builtin, sort_order) VALUES
    (?, ?, 'cash', 'Tunai', '💵', 1, 1),
    (?, ?, 'transfer', 'Transfer Bank', '🏦', 1, 2),
    (?, ?, 'qris', 'QRIS', '📱', 1, 3)
")->execute([
    $tenantId, $outletId, $tenantId, $outletId, $tenantId, $outletId
]);
```

---

## 6. Existing System Integration

### 6.1 Reports (Laporan/Dashboard)

Existing SQL tidak perlu berubah:

```sql
SELECT metode_bayar, COUNT(*) AS qty, SUM(jumlah) AS total
FROM hl_pembayaran
WHERE tenant_id=? AND DATE(created_at) = CURDATE()
GROUP BY metode_bayar;
```

Custom methods otomatis muncul sebagai row baru di breakdown. Display layer pakai StrukGenerator helper untuk label, atau bisa duplicate logic kalau di laporan.

**Display label di UI:** Optional polish — sekarang laporan tampilkan raw code (mis. "transfer_bca"). Bisa improve di follow-up task untuk JOIN hl_payment_methods di laporan query. Defer ke later.

### 6.2 Struk Generator

Sudah di-cover di §5.3 — DB lookup dengan fallback graceful untuk historical orphan codes.

### 6.3 Audit Log

POS save handler sudah catat catatan transaksi termasuk metode label. Pattern tetap, tinggal label-nya jadi dinamis (dari DB).

---

## 7. Security

### 7.1 Access Control

- payment-settings.php: HQ guard + `settings.roles` permission
- Cross-tenant write protection: ALL UPDATE/DELETE WHERE outlet_id=? AND tenant_id=?

### 7.2 Input Validation

- Label: max 50 char, trim, strip_tags
- Emoji: max 8 byte (1-2 grapheme), accept any UTF-8 emoji
- Code: server-side slug from label, never user-controlled raw

### 7.3 POS Tamper Prevention

- Save handler validate `metode_bayar` exists in hl_payment_methods for outlet+active
- Block tampered POST that tries to use deactivated/deleted code

### 7.4 XSS

- `htmlspecialchars()` on label + emoji in all display contexts
- No HTML in label (strip_tags before save)

### 7.5 CSRF

- All POST endpoints verifyCsrf()

---

## 8. Edge Cases

| Skenario | Handler |
|----------|---------|
| Outlet baru via TenantProvisioner | Auto-seed 3 built-in rows |
| Outlet existing (pre-migration) | Migration backfill INSERT seed |
| Owner deactivate Tunai | POS hide option, transaksi baru gak bisa cash. Historical valid. |
| Owner add custom method "Voucher" | INSERT new row, muncul di POS dropdown |
| Owner rename label "Voucher" → "Gift Card" | UPDATE label only, code locked. Old transactions still reference 'voucher' code. |
| Owner delete custom method dipakai di hl_transaksi | DELETE row. StrukGenerator fallback to ucfirst($code) untuk old transactions. |
| Kasir POST tampered metode_bayar (mis. inject 'admin_steal') | Save validation block — RuntimeException |
| QRIS deactivated tapi qris_image masih ada | POS hide QRIS option. Image tidak terdampak (still in DB). |
| Built-in rename attempt | Server-side check `is_builtin=1` reject |
| Built-in delete attempt | Server-side check `is_builtin=1` reject |
| Duplicate code via collision | Auto-suffix `_2`, `_3` saat slugify |
| Multi-tab race (owner edit di 2 tab) | Last write wins, acceptable |

---

## 9. Testing Plan

### 9.1 Manual Smoke Test

1. Login owner → /payment-settings → section "Metode Pembayaran POS" muncul dengan 3 built-in (Tunai/Transfer/QRIS)
2. Klik [+ Tambah Metode] → input "Transfer BCA" + 🏦 emoji → save
3. List refresh: row baru "🏦 Transfer BCA" + Edit/Hapus buttons
4. Klik checkbox "Tunai" → toggle nonaktif → AJAX, list refresh dengan checkbox uncheck
5. Login kasir di outlet sama → /pos.php → dropdown metode pembayaran:
   - Tunai hilang (deactivated)
   - 🏦 Transfer BCA muncul
6. Add item transaksi, pilih "Transfer BCA" → submit → success
7. Verify DB: `SELECT metode_bayar FROM hl_transaksi ORDER BY id DESC LIMIT 1` = 'transfer_bca'
8. Cetak struk → label "🏦 Transfer BCA" muncul (bukan raw code)
9. Login owner → laporan harian → row "transfer_bca" muncul (atau "Transfer BCA" kalau display label improved)
10. Owner → /payment-settings → klik [🗑️ Hapus] "Transfer BCA" → confirm → row deleted
11. Cetak struk transaksi lama (yang tagged transfer_bca) → fallback label "Transfer_bca" (ucfirst), tidak crash

### 9.2 Edge Case Test

| # | Test | Expected |
|---|------|----------|
| 1 | Tambah method label kosong | Reject "Label wajib diisi" |
| 2 | Tambah method label >50 char | Reject "Max 50 karakter" |
| 3 | Tambah method dengan label sama 2x | Auto-suffix code "_2" |
| 4 | Edit built-in label | Reject (server-side block) |
| 5 | Delete built-in | Reject |
| 6 | Toggle built-in nonaktif | Allow (POS hide option) |
| 7 | Cross-tenant try edit (manipulate POST id) | UPDATE WHERE tenant_id=? block |
| 8 | POS POST metode tidak ada di DB | Save handler reject |
| 9 | POS POST metode deactivated | Save handler reject |
| 10 | Struk untuk transaksi orphan code (method deleted) | Display ucfirst fallback, no crash |

---

## 10. Implementation Phasing

Recommend 3 commits, ~2.5 jam total:

**Commit 1 — Schema + Provisioner Seed (~30 menit):**
- DB migration
- TenantProvisioner seed
- Verify backfill applied

**Commit 2 — payment-settings.php CRUD UI (~75 menit):**
- Add Metode section
- AJAX handlers (add/edit/delete/toggle)
- Modal form
- Slugify helper

**Commit 3 — POS + StrukGenerator Integration (~45 menit):**
- pos.php dropdown dinamis
- Save handler validation
- StrukGenerator DB lookup
- Smoke test E2E

---

## 11. Files Inventory

### New
- `db/migrations/2026-06-24-payment-methods.sql`

### Modified
- `payment-settings.php` — Add Metode section + CRUD AJAX
- `pos.php` — Dropdown dinamis + save validation
- `core/StrukGenerator.php` — DB lookup label helper
- `core/TenantProvisioner.php` — Seed default rows
- Possibly `outlet-settings.php` + `struk.php` — rename tab "Pembayaran QRIS" → "Pembayaran" (cosmetic)

---

## 12. Out of Scope (Phase 1)

- Drag-drop sort UI (gunakan ID order)
- Image upload per method (QRIS special, others tidak butuh)
- Per-tenant shared methods (overkill, per-outlet sudah cukup)
- Method category (cash/transfer/ewallet/edc) — flat list cukup
- Method-specific fee/discount rules
- Method-specific reporting filter di laporan
- Soft delete (hard delete + fallback acceptable)
- Allow built-in rename (preserve consistency)
- Allow custom code edit setelah dibuat (preserve historical reference)
- Bulk import/export methods

---

## 13. Success Criteria

- ✅ Owner add/edit/delete custom payment method dalam <1 menit
- ✅ Built-in method gak bisa di-delete atau di-rename
- ✅ Built-in BISA di-toggle aktif/nonaktif
- ✅ POS dropdown render dinamis dari DB
- ✅ Save handler reject tampered metode_bayar
- ✅ Struk render label proper (emoji + label) untuk active + fallback graceful untuk orphan
- ✅ Reports auto-include row baru saat custom method dipakai
- ✅ Zero impact ke flow existing (cash/transfer/qris/deposit) — backward compat 100%

---

## 14. References

- POS payment method picker: `pos.php:1235-1239`
- StrukGenerator label mapping: `core/StrukGenerator.php` (existing metodeBayarLabel)
- TenantProvisioner pattern: similar untuk bahan default seed
- Static QRIS POS spec (sibling feature): `docs/superpowers/specs/2026-06-24-static-qris-pos-design.md`
