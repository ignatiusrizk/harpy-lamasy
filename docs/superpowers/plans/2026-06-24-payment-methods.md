# Custom Payment Methods per Outlet Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owner outlet bisa tambah/edit/hapus/toggle metode pembayaran custom (selain cash/transfer/qris yang sekarang hardcoded) untuk muncul di POS dropdown. Per-outlet scope, free-form label + emoji, built-in tidak bisa delete/rename.

**Architecture:** New `hl_payment_methods` table per outlet. Migration backfill 3 default rows untuk existing outlets. TenantProvisioner seed untuk outlet baru. POS dropdown render dinamis. StrukGenerator DB lookup dengan fallback ucfirst() untuk historical orphan codes. Backward compat 100% — existing flow cash/transfer/qris/deposit tetap jalan.

**Tech Stack:** PHP 8 vanilla, MariaDB (Hostinger), PDO prepared statements, AJAX inline pattern di payment-settings.php (consistent dengan existing QRIS upload).

## Global Constraints

- **Column types:** `code VARCHAR(30)`, `label VARCHAR(50)`, `emoji VARCHAR(8) DEFAULT '💳'`
- **Built-in seed values (verbatim):** `cash`/`Tunai`/`💵`, `transfer`/`Transfer Bank`/`🏦`, `qris`/`QRIS`/`📱`
- **Storage:** hl_transaksi.metode_bayar (VARCHAR 30) — existing — stores `code` value
- **Tenant scope:** ALL writes WHERE outlet_id=? AND tenant_id=? (defense in depth)
- **CSRF:** verifyCsrf() di setiap POST
- **Permission:** `settings.roles` via existing requirePermission()
- **Filename pattern slug:** `[a-z0-9_]` only, max 30 char
- **UNIQUE constraint:** (tenant_id, outlet_id, code) — collision → auto-suffix `_2`, `_3`
- **Built-in rules:** is_builtin=1 → cannot delete, cannot rename (label/code/emoji locked). CAN toggle is_active.
- **Custom rules:** is_builtin=0 → full CRUD. Code locked after creation (preserve historical reference).
- **Existing payment methods preserved:** Migration is purely additive. POS flow keeps working for in-flight transactions.
- **Smoke testing:** PHP codebase tanpa unit test framework. Verify via DB query + browser UI + curl.

---

## File Structure

**New files:**
- `db/migrations/2026-06-24-payment-methods.sql` — Schema + backfill seed

**Modified files:**
- `core/TenantProvisioner.php` — Add static helper `seedPaymentMethods(PDO $db, int $tenantId, int $outletId): void`
- `add-outlet.php` — Call seed helper after outlet INSERT (line ~144)
- `payment-settings.php` — Add "Metode Pembayaran POS" section above existing QRIS section + AJAX CRUD handlers
- `pos.php` — Replace hardcoded select (lines ~1235-1239) with dynamic loop + add server-side validation in save handler (around line 460)
- `core/StrukGenerator.php` — Replace `metodeBayarLabel()` (line 63) with DB-aware version + update 2 call sites (lines 560, 880)

**Files NOT touched:**
- Reports/laporan SQL (auto-aggregates via existing GROUP BY)
- hl_transaksi schema (metode_bayar VARCHAR 30 already flexible)
- outlet-settings.php / struk.php tab nav (label "Pembayaran QRIS" remains — rename cosmetic, defer)

---

## Task 1: Schema Migration + Backfill

**Files:**
- Create: `db/migrations/2026-06-24-payment-methods.sql`

**Interfaces:**
- Consumes: existing `outlets` table (id, tenant_id)
- Produces: `hl_payment_methods` table dengan 3 seeded rows per existing outlet

- [ ] **Step 1: Create migration file**

Write to `db/migrations/2026-06-24-payment-methods.sql`:

```sql
-- Custom Payment Methods per Outlet — Phase 1
-- Tambah table hl_payment_methods + backfill 3 default rows untuk semua outlet existing.

CREATE TABLE IF NOT EXISTS hl_payment_methods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  code VARCHAR(30) NOT NULL,
  label VARCHAR(50) NOT NULL,
  emoji VARCHAR(8) DEFAULT '💳',
  is_builtin TINYINT(1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  sort_order INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_outlet_code (tenant_id, outlet_id, code),
  INDEX idx_outlet_active (outlet_id, is_active, sort_order)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: seed 3 built-in methods untuk semua outlet existing.
-- INSERT IGNORE skip outlets yang sudah punya (idempotent re-run safe via UNIQUE constraint).
INSERT IGNORE INTO hl_payment_methods (tenant_id, outlet_id, code, label, emoji, is_builtin, sort_order)
SELECT o.tenant_id, o.id, 'cash',     'Tunai',         '💵', 1, 1 FROM outlets o
UNION ALL
SELECT o.tenant_id, o.id, 'transfer', 'Transfer Bank', '🏦', 1, 2 FROM outlets o
UNION ALL
SELECT o.tenant_id, o.id, 'qris',     'QRIS',          '📱', 1, 3 FROM outlets o;
```

- [ ] **Step 2: Apply migration**

Run:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-payment-methods.sql
```

Expected: no output (success). If "Table already exists" — safe (CREATE TABLE IF NOT EXISTS). If duplicate row warnings — safe (INSERT IGNORE).

- [ ] **Step 3: Verify table created**

Run:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC hl_payment_methods"
```

Expected: 10 columns including id, tenant_id, outlet_id, code, label, emoji, is_builtin, is_active, sort_order, created_at.

- [ ] **Step 4: Verify backfill — count seeded rows**

Run:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT COUNT(*) AS total_methods,
       COUNT(DISTINCT outlet_id) AS outlets_seeded,
       SUM(is_builtin) AS builtin_count
FROM hl_payment_methods;
"
```

Expected: total_methods = 3 × number-of-outlets, outlets_seeded = total outlet count, builtin_count = total_methods (all seeded are builtin).

- [ ] **Step 5: Spot-check seed values**

Run:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT outlet_id, code, label, emoji, is_builtin, is_active, sort_order
FROM hl_payment_methods
ORDER BY outlet_id, sort_order
LIMIT 9;
"
```

Expected: per outlet ada 3 rows berurut: (cash/Tunai/💵), (transfer/Transfer Bank/🏦), (qris/QRIS/📱), semua is_builtin=1, is_active=1.

- [ ] **Step 6: Commit**

```bash
git add db/migrations/2026-06-24-payment-methods.sql
git commit -m "feat(payment-methods): schema hl_payment_methods + backfill seed

CREATE TABLE hl_payment_methods (per-outlet config storage):
- code (varchar 30, saved as hl_transaksi.metode_bayar)
- label (varchar 50, display)
- emoji (varchar 8, default 💳)
- is_builtin (cash/transfer/qris seed: no delete/rename allowed)
- is_active (toggle hide/show di POS dropdown)
- sort_order (display order)
- UNIQUE (tenant_id, outlet_id, code) — anti-duplicate per outlet
- INDEX (outlet_id, is_active, sort_order) — POS read path

Backfill 3 built-in rows per outlet via INSERT IGNORE × outlets cross-join.
Idempotent re-run safe."
```

---

## Task 2: TenantProvisioner Seed Helper + Outlet Create Hook

**Files:**
- Modify: `core/TenantProvisioner.php` — add public static method `seedPaymentMethods()` (location: after existing `seedLayanan()` method or grouped with other seeders)
- Modify: `add-outlet.php` around line 144 (after `INSERT INTO outlets` + `$outletId = (int)$db->lastInsertId();`)

**Interfaces:**
- Consumes: hl_payment_methods table from Task 1
- Produces:
  - `TenantProvisioner::seedPaymentMethods(PDO $db, int $tenantId, int $outletId): void`
  - Called from add-outlet.php for outlet ke-2+ AND outlet pertama (verify via grep)

- [ ] **Step 1: Locate insertion point in TenantProvisioner.php**

Run:
```bash
grep -n "seedLayanan\|seedBahan\|public static function seed" core/TenantProvisioner.php
```

Note line of last seed method to position the new method after it.

- [ ] **Step 2: Add seedPaymentMethods helper to TenantProvisioner**

Add new method to `core/TenantProvisioner.php` after the last existing `seed*()` method. Place near siblings (`seedLayanan`, `seedBahan` if exists). Use this exact code:

```php
    /**
     * Seed 3 default built-in payment methods untuk outlet baru.
     * Cash, Transfer, QRIS — all is_builtin=1, is_active=1.
     *
     * Idempotent: INSERT IGNORE skip kalau rows sudah ada (UNIQUE constraint).
     */
    public static function seedPaymentMethods(PDO $db, int $tenantId, int $outletId): void
    {
        $db->prepare("
            INSERT IGNORE INTO hl_payment_methods
                (tenant_id, outlet_id, code, label, emoji, is_builtin, is_active, sort_order)
            VALUES
                (?, ?, 'cash',     'Tunai',         '💵', 1, 1, 1),
                (?, ?, 'transfer', 'Transfer Bank', '🏦', 1, 1, 2),
                (?, ?, 'qris',     'QRIS',          '📱', 1, 1, 3)
        ")->execute([
            $tenantId, $outletId,
            $tenantId, $outletId,
            $tenantId, $outletId,
        ]);
    }
```

- [ ] **Step 3: Locate add-outlet.php INSERT location**

Run:
```bash
grep -n "INSERT INTO outlets\|outletId = (int)" add-outlet.php
```

Expected: shows `INSERT INTO outlets` around line 144 and `$outletId = (int)$db->lastInsertId();` shortly after.

- [ ] **Step 4: Add seedPaymentMethods call in add-outlet.php**

Find this existing block in add-outlet.php (around line 165 — right after the outlet INSERT and before/around the nota_prefix auto-set try block):

```php
$outletId = (int)$db->lastInsertId();

// Auto-set nota_prefix dari nama outlet (kalau kolom sudah ada)
try {
    require_once ROOT . '/core/NotaFormatter.php';
```

Insert immediately after `$outletId = (int)$db->lastInsertId();` line, before the comment for nota_prefix:

```php
$outletId = (int)$db->lastInsertId();

// Seed default payment methods (cash/transfer/qris) untuk outlet baru
try {
    require_once ROOT . '/core/TenantProvisioner.php';
    TenantProvisioner::seedPaymentMethods($db, $tid, $outletId);
} catch (Throwable $e) {
    error_log('seedPaymentMethods failed for outlet ' . $outletId . ': ' . $e->getMessage());
    // Non-fatal: migration backfill or first POS access will compensate
}

// Auto-set nota_prefix dari nama outlet (kalau kolom sudah ada)
try {
    require_once ROOT . '/core/NotaFormatter.php';
```

- [ ] **Step 5: Verify changes via grep**

Run:
```bash
grep -n "seedPaymentMethods" core/TenantProvisioner.php add-outlet.php
```

Expected: 1 hit in TenantProvisioner.php (definition), 1 hit in add-outlet.php (call).

- [ ] **Step 6: Smoke test — simulate creating a fake outlet**

Run via CLI to test seed runs cleanly (without actually creating outlet):

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
-- Pick an existing tenant_id + outlet_id to simulate the seed.
-- Delete its payment methods first, then we'll re-seed via SQL.
DELETE FROM hl_payment_methods WHERE outlet_id = (SELECT id FROM outlets LIMIT 1);
SELECT COUNT(*) AS before_seed FROM hl_payment_methods
WHERE outlet_id = (SELECT id FROM outlets LIMIT 1);
"
```

Expected: before_seed = 0.

Now manually replicate what seedPaymentMethods would do (sanity check the SQL works):

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SET @oid = (SELECT id FROM outlets LIMIT 1);
SET @tid = (SELECT tenant_id FROM outlets WHERE id = @oid);
INSERT IGNORE INTO hl_payment_methods
    (tenant_id, outlet_id, code, label, emoji, is_builtin, is_active, sort_order)
VALUES
    (@tid, @oid, 'cash',     'Tunai',         '💵', 1, 1, 1),
    (@tid, @oid, 'transfer', 'Transfer Bank', '🏦', 1, 1, 2),
    (@tid, @oid, 'qris',     'QRIS',          '📱', 1, 1, 3);
SELECT code, label, emoji, is_builtin FROM hl_payment_methods
WHERE outlet_id = @oid ORDER BY sort_order;
"
```

Expected: 3 rows returned (cash/transfer/qris), all is_builtin=1.

- [ ] **Step 7: Commit**

```bash
git add core/TenantProvisioner.php add-outlet.php
git commit -m "feat(payment-methods): TenantProvisioner seed + outlet create hook

Add public static TenantProvisioner::seedPaymentMethods() — INSERT IGNORE
3 built-in rows (cash/transfer/qris) untuk outlet baru.

Call dari add-outlet.php right after outlet INSERT (line ~165). Try/catch
defensive logging — non-fatal kalau gagal karena migration backfill atau
first POS access akan kompensasi (build-in baseline tetap available).

Idempotent via INSERT IGNORE + UNIQUE constraint (tenant_id, outlet_id, code)."
```

---

## Task 3: payment-settings.php — Methods Section + CRUD

**Files:**
- Modify: `payment-settings.php` — add Metode section + AJAX POST handlers + modal HTML + JS

**Interfaces:**
- Consumes: hl_payment_methods table (Task 1) + TenantResolver::id(), TenantResolver::outletId(), verifyCsrf(), getCsrfToken()
- Produces:
  - AJAX endpoints: `?action=method_list`, POST action=`method_add` / `method_edit` / `method_delete` / `method_toggle`
  - JS functions: `loadMethods()`, `openMethodModal(id=null)`, `closeMethodModal()`, `saveMethod()`, `deleteMethod(id, label)`, `toggleMethod(id)`
  - HTML section: "Metode Pembayaran POS" above existing QRIS section

- [ ] **Step 1: Read existing payment-settings.php structure**

Verify current file state to anchor insertion points:

```bash
grep -n "^<?php\|^?>\|POST: Delete QRIS\|POST: Upload QRIS\|class=\"hl-main\"\|renderHead\|renderTopbar" payment-settings.php
```

Note line of opening `?>` (end of PHP block) and the start of HTML body.

- [ ] **Step 2: Add method_list GET handler (top of file, before existing POST handlers)**

Find this existing block in payment-settings.php (around line 26-28):

```php
$msg = '';
$err = '';

// ─── POST: Delete QRIS ─────────────────────────────────
```

Replace with:

```php
$msg = '';
$err = '';

// ─── AJAX: Method list (JSON) ──────────────────────────
if (($_GET['action'] ?? '') === 'method_list') {
    header('Content-Type: application/json');
    $stmt = $db->prepare("
        SELECT id, code, label, emoji, is_builtin, is_active, sort_order
        FROM hl_payment_methods
        WHERE outlet_id=? AND tenant_id=?
        ORDER BY sort_order, id
    ");
    $stmt->execute([$oid, $tid]);
    echo json_encode(['ok' => true, 'methods' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ─── Helper: slugify code dari label ───────────────────
function slugifyMethodCode(string $label): string {
    $s = strtolower(trim($label));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    return substr($s, 0, 30) ?: 'method_' . dechex(random_int(0, 0xFFFFFF));
}

// ─── POST: Delete QRIS ─────────────────────────────────
```

- [ ] **Step 3: Add method_add POST handler**

Append before the "Delete QRIS" POST handler block:

```php
// ─── POST: Method add ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'method_add') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $label = substr(trim(strip_tags($_POST['label'] ?? '')), 0, 50);
        $emoji = substr(trim($_POST['emoji'] ?? '💳'), 0, 8);
        if ($label === '') throw new RuntimeException('Label wajib diisi');

        // Slug + collision resolve
        $base = slugifyMethodCode($label);
        $code = $base;
        $i = 2;
        $check = $db->prepare("SELECT 1 FROM hl_payment_methods
                               WHERE tenant_id=? AND outlet_id=? AND code=?");
        while (true) {
            $check->execute([$tid, $oid, $code]);
            if (!$check->fetchColumn()) break;
            $code = $base . '_' . $i++;
            if ($i > 50) throw new RuntimeException('Tidak bisa generate code unik');
        }

        // Compute next sort_order
        $maxSort = (int)$db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM hl_payment_methods
                                      WHERE tenant_id=? AND outlet_id=?")
                           ->execute([$tid, $oid]) ? null : 0;
        $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM hl_payment_methods
                              WHERE tenant_id=? AND outlet_id=?");
        $stmt->execute([$tid, $oid]);
        $nextSort = (int)$stmt->fetchColumn();

        $ins = $db->prepare("INSERT INTO hl_payment_methods
            (tenant_id, outlet_id, code, label, emoji, is_builtin, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, 0, 1, ?)");
        $ins->execute([$tid, $oid, $code, $label, $emoji, $nextSort]);

        echo json_encode(['ok' => true, 'id' => $db->lastInsertId(), 'code' => $code]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
```

- [ ] **Step 4: Add method_edit POST handler**

Append right after method_add:

```php
// ─── POST: Method edit (label + emoji only) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'method_edit') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $id    = (int)($_POST['id'] ?? 0);
        $label = substr(trim(strip_tags($_POST['label'] ?? '')), 0, 50);
        $emoji = substr(trim($_POST['emoji'] ?? '💳'), 0, 8);
        if (!$id) throw new RuntimeException('ID method invalid');
        if ($label === '') throw new RuntimeException('Label wajib diisi');

        // Built-in row tidak boleh edit (server-side enforce)
        $check = $db->prepare("SELECT is_builtin FROM hl_payment_methods
                               WHERE id=? AND outlet_id=? AND tenant_id=?");
        $check->execute([$id, $oid, $tid]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Method tidak ditemukan');
        if ((int)$row['is_builtin'] === 1) {
            throw new RuntimeException('Metode bawaan tidak bisa di-edit');
        }

        $up = $db->prepare("UPDATE hl_payment_methods
                            SET label=?, emoji=?
                            WHERE id=? AND outlet_id=? AND tenant_id=? AND is_builtin=0");
        $up->execute([$label, $emoji, $id, $oid, $tid]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
```

- [ ] **Step 5: Add method_delete + method_toggle POST handlers**

Append right after method_edit:

```php
// ─── POST: Method delete (custom only) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'method_delete') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new RuntimeException('ID method invalid');

        $check = $db->prepare("SELECT is_builtin FROM hl_payment_methods
                               WHERE id=? AND outlet_id=? AND tenant_id=?");
        $check->execute([$id, $oid, $tid]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Method tidak ditemukan');
        if ((int)$row['is_builtin'] === 1) {
            throw new RuntimeException('Metode bawaan tidak bisa di-hapus, hanya di-nonaktifkan');
        }

        $del = $db->prepare("DELETE FROM hl_payment_methods
                             WHERE id=? AND outlet_id=? AND tenant_id=? AND is_builtin=0");
        $del->execute([$id, $oid, $tid]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── POST: Method toggle is_active ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'method_toggle') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new RuntimeException('ID method invalid');

        $up = $db->prepare("UPDATE hl_payment_methods
                            SET is_active = 1 - is_active
                            WHERE id=? AND outlet_id=? AND tenant_id=?");
        $up->execute([$id, $oid, $tid]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
```

- [ ] **Step 6: Add Metode section HTML (above existing QRIS section)**

Find this existing block in payment-settings.php (around line 115-118):

```php
  <div style="max-width:680px">
    <p style="color:#6b7280;margin:0 0 24px 0">
      Outlet: <strong><?= htmlspecialchars($outlet['nama_outlet']) ?></strong>
    </p>
```

Insert this BETWEEN the closing `</p>` of "Outlet: ..." and the existing `<?php if ($msg): ?>` line. Adjust the structural divider so QRIS section gets its own header. New section starts:

```php
  <div style="max-width:680px">
    <p style="color:#6b7280;margin:0 0 24px 0">
      Outlet: <strong><?= htmlspecialchars($outlet['nama_outlet']) ?></strong>
    </p>

    <!-- ═══ Section: Metode Pembayaran POS ═══ -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:24px">
      <h3 style="margin:0 0 6px 0;font-size:16px">💳 Metode Pembayaran POS</h3>
      <p style="color:#6b7280;font-size:13px;margin:0 0 16px 0">
        Kelola metode yang muncul di POS saat input pembayaran. Centang untuk aktifkan, uncheck untuk sembunyikan.
      </p>

      <div id="methodsList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
        <div style="color:#9ca3af;font-size:13px;padding:8px">Memuat…</div>
      </div>

      <button type="button" onclick="openMethodModal()"
              style="background:#0d9488;color:#fff;border:0;padding:10px 16px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px">
        + Tambah Metode
      </button>
    </div>

    <!-- ═══ Section: QRIS Image (existing — header polish) ═══ -->
    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">📱 Setup Gambar QRIS</h3>
```

- [ ] **Step 7: Add Method modal HTML (before closing </div></body>)**

Find the closing `</div>` of `class="hl-main"` and the closing `</body>` tag, around the bottom of payment-settings.php. Insert this modal HTML right BEFORE `</body>`:

```php
<!-- Method Add/Edit Modal -->
<div id="methodModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center"
     onclick="if (event.target===this) closeMethodModal()">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3)">
    <h3 id="methodModalTitle" style="margin:0 0 16px 0;font-size:18px">Tambah Metode Pembayaran</h3>
    <input type="hidden" id="methodEditId" value="">

    <div style="margin-bottom:14px">
      <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Label *</label>
      <input id="methodLabel" type="text" maxlength="50" placeholder="Transfer BCA"
             style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box">
      <div style="font-size:12px;color:#9ca3af;margin-top:4px">Max 50 karakter</div>
    </div>

    <div style="margin-bottom:14px">
      <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Emoji Icon</label>
      <input id="methodEmoji" type="text" maxlength="4" placeholder="💳"
             style="width:80px;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:18px;text-align:center;box-sizing:border-box">
      <div style="font-size:12px;color:#9ca3af;margin-top:4px">Default: 💳</div>
    </div>

    <div id="methodModalError" style="display:none;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:10px;border-radius:8px;font-size:13px;margin-bottom:12px"></div>

    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button type="button" onclick="closeMethodModal()"
              style="background:#fff;color:#374151;border:1px solid #d1d5db;padding:10px 16px;border-radius:8px;cursor:pointer;font-weight:600">
        Batal
      </button>
      <button type="button" onclick="saveMethod()"
              style="background:#0d9488;color:#fff;border:0;padding:10px 16px;border-radius:8px;cursor:pointer;font-weight:600">
        💾 Simpan
      </button>
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode(getCsrfToken()) ?>;

async function loadMethods() {
  const r = await fetch('/payment-settings?action=method_list');
  const d = await r.json();
  const listEl = document.getElementById('methodsList');
  if (!d.ok) { listEl.innerHTML = '<div style="color:#dc2626;padding:8px">Gagal load: ' + (d.error || 'unknown') + '</div>'; return; }
  if (!d.methods.length) { listEl.innerHTML = '<div style="color:#9ca3af;padding:8px">Belum ada metode.</div>'; return; }

  listEl.innerHTML = d.methods.map(m => {
    const isBuiltin = parseInt(m.is_builtin) === 1;
    const isActive = parseInt(m.is_active) === 1;
    const safeLabel = String(m.label).replace(/[<>&"']/g, c => ({ '<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;' })[c]);
    const safeEmoji = String(m.emoji || '💳').replace(/[<>&"']/g, c => ({ '<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;' })[c]);

    return `
      <div style="display:flex;align-items:center;gap:12px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;${isActive ? '' : 'opacity:0.5'}">
        <input type="checkbox" ${isActive ? 'checked' : ''}
               onchange="toggleMethod(${m.id})"
               style="width:18px;height:18px;cursor:pointer">
        <span style="font-size:18px">${safeEmoji}</span>
        <span style="flex:1;font-weight:600;color:#374151">${safeLabel}</span>
        ${isBuiltin
          ? '<span style="font-size:11px;color:#9ca3af;background:#e5e7eb;padding:2px 8px;border-radius:4px">built-in</span>'
          : `<button type="button" onclick="openMethodModal(${m.id})"
                     style="background:#fff;border:1px solid #d1d5db;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px">✏️ Edit</button>
             <button type="button" onclick="deleteMethod(${m.id}, ${JSON.stringify(safeLabel)})"
                     style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px">🗑️ Hapus</button>`
        }
      </div>
    `;
  }).join('');
}

function openMethodModal(id = null) {
  document.getElementById('methodModalError').style.display = 'none';
  document.getElementById('methodEditId').value = id || '';
  if (id) {
    // Find row data dari list (re-query loadMethods response indirectly via fetch)
    fetch('/payment-settings?action=method_list').then(r => r.json()).then(d => {
      const row = d.methods.find(m => parseInt(m.id) === id);
      if (!row) return;
      document.getElementById('methodModalTitle').textContent = 'Edit Metode Pembayaran';
      document.getElementById('methodLabel').value = row.label;
      document.getElementById('methodEmoji').value = row.emoji || '💳';
    });
  } else {
    document.getElementById('methodModalTitle').textContent = 'Tambah Metode Pembayaran';
    document.getElementById('methodLabel').value = '';
    document.getElementById('methodEmoji').value = '💳';
  }
  document.getElementById('methodModal').style.display = 'flex';
  setTimeout(() => document.getElementById('methodLabel').focus(), 50);
}

function closeMethodModal() {
  document.getElementById('methodModal').style.display = 'none';
}

async function saveMethod() {
  const errEl = document.getElementById('methodModalError');
  errEl.style.display = 'none';

  const id = document.getElementById('methodEditId').value;
  const label = document.getElementById('methodLabel').value.trim();
  const emoji = document.getElementById('methodEmoji').value.trim() || '💳';

  if (!label) { errEl.textContent = 'Label wajib diisi'; errEl.style.display = 'block'; return; }

  const fd = new FormData();
  fd.append('_csrf', CSRF_TOKEN);
  fd.append('action', id ? 'method_edit' : 'method_add');
  fd.append('label', label);
  fd.append('emoji', emoji);
  if (id) fd.append('id', id);

  const r = await fetch('/payment-settings', { method: 'POST', body: fd });
  const d = await r.json();
  if (!d.ok) { errEl.textContent = d.error || 'Gagal'; errEl.style.display = 'block'; return; }
  closeMethodModal();
  loadMethods();
}

async function deleteMethod(id, label) {
  if (!confirm('Hapus metode "' + label + '"? Transaksi historis tidak terpengaruh.')) return;

  const fd = new FormData();
  fd.append('_csrf', CSRF_TOKEN);
  fd.append('action', 'method_delete');
  fd.append('id', id);

  const r = await fetch('/payment-settings', { method: 'POST', body: fd });
  const d = await r.json();
  if (!d.ok) { alert('Gagal: ' + (d.error || 'unknown')); return; }
  loadMethods();
}

async function toggleMethod(id) {
  const fd = new FormData();
  fd.append('_csrf', CSRF_TOKEN);
  fd.append('action', 'method_toggle');
  fd.append('id', id);

  const r = await fetch('/payment-settings', { method: 'POST', body: fd });
  const d = await r.json();
  if (!d.ok) { alert('Gagal: ' + (d.error || 'unknown')); loadMethods(); return; }
  loadMethods();
}

// Initial load
loadMethods();
</script>

</body>
```

- [ ] **Step 8: Smoke test — verify page loads + method_list endpoint**

Push tidak perlu — bisa test via curl untuk endpoint status, dan langsung visual via browser.

Run:
```bash
curl -s -o /dev/null -w "GET /payment-settings %{http_code}\n" "https://lamasy.harpy.id/payment-settings"
```

Expected: HTTP 302 (auth redirect — guards work). Actual page render happens after browser login.

- [ ] **Step 9: Browser smoke test — visual + interactive**

Login as HQ owner di browser → buka `/payment-settings`. Verify:

1. Section "💳 Metode Pembayaran POS" muncul di atas QRIS section
2. List menampilkan 3 built-in: Tunai, Transfer Bank, QRIS (semua checkbox checked, semua punya badge "built-in", tidak ada tombol Edit/Hapus)
3. Tombol "[+ Tambah Metode]" terlihat
4. Klik tombol "+ Tambah Metode" → modal popup dengan field Label + Emoji
5. Input label "Transfer BCA" + emoji 🏦 → klik 💾 Simpan → modal close, row baru "Transfer BCA" muncul di list dengan tombol Edit + Hapus
6. Verify DB:

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT id, code, label, emoji, is_builtin, is_active FROM hl_payment_methods
ORDER BY id DESC LIMIT 3;
"
```

Expected: row dengan code='transfer_bca', label='Transfer BCA', is_builtin=0.

7. Klik checkbox "Tunai" → uncheck → list refresh (Tunai opacity 50%)
8. Klik checkbox "Tunai" → check kembali → list refresh (Tunai opacity normal)
9. Klik Edit pada "Transfer BCA" → modal popup pre-filled → ganti label "Transfer BCA Tabungan" → save → row update di list
10. Klik Hapus pada "Transfer BCA Tabungan" → confirm → row hilang dari list

- [ ] **Step 10: Edge case browser test**

| Test | Expected |
|------|----------|
| Add empty label | Modal error "Label wajib diisi", tidak close |
| Add label > 50 char | Auto-truncated (substr di server) |
| Add duplicate label "Transfer BCA" 2× | 2nd insert sukses dengan code auto-suffix `_2` |
| Click Edit pada built-in (shouldn't exist — verify no button) | Tombol Edit tidak ada di built-in row |
| Click Hapus pada built-in (shouldn't exist) | Tombol Hapus tidak ada di built-in row |
| Toggle built-in via checkbox | Allowed — checkbox bekerja seperti custom |

- [ ] **Step 11: Commit**

```bash
git add payment-settings.php
git commit -m "feat(payment-methods): payment-settings CRUD section + modal

Tambah section 'Metode Pembayaran POS' di atas section QRIS existing.

Endpoints:
- GET ?action=method_list — JSON list of methods for current outlet
- POST action=method_add — INSERT custom (label + emoji), slugify code
- POST action=method_edit — UPDATE custom label/emoji (built-in blocked)
- POST action=method_delete — DELETE custom (built-in blocked)
- POST action=method_toggle — flip is_active

UI: list view dengan checkbox toggle inline + edit/hapus per custom row.
Modal popup untuk add/edit, validasi inline + server-side.

Built-in (cash/transfer/qris): badge label, toggle aktif/nonaktif yes,
edit/delete blocked dengan error message kalau di-attempt.

CSRF + tenant_id scope check di semua mutations."
```

---

## Task 4: POS Dropdown Dinamis + Save Validation

**Files:**
- Modify: `pos.php` — replace hardcoded `<select>` (around line 1235-1239) + add validation di save handler (around line 460)

**Interfaces:**
- Consumes: hl_payment_methods table from Task 1
- Produces: POS dropdown render dinamis. Server-side save handler reject tampered metode_bayar.

- [ ] **Step 1: Locate variable scope di pos.php**

Verify variable names di page-render scope (top of pos.php):

```bash
grep -nE "\\\$tid\s*=|\\\$oid\s*=|TenantResolver" pos.php | head -8
```

Note the exact variable names — should be `$tid` and `$oid` based on existing QRIS query.

- [ ] **Step 2: Load active methods di PHP header (alongside existing $outletQrisData)**

Find this existing block (around line 706-711, the QRIS data load):

```php
// Load QRIS data untuk modal display di payment method
$outletQrisStmt = $db->prepare("SELECT qris_image, qris_label FROM outlets WHERE id=? AND tenant_id=?");
$outletQrisStmt->execute([...]);
$outletQrisData = $outletQrisStmt->fetch(PDO::FETCH_ASSOC) ?: ['qris_image'=>null, 'qris_label'=>null];
```

Right AFTER that block, append:

```php
// Load active payment methods untuk dropdown render
$methodsStmt = $db->prepare("
    SELECT code, label, emoji
    FROM hl_payment_methods
    WHERE outlet_id=? AND tenant_id=? AND is_active=1
    ORDER BY sort_order, id
");
$methodsStmt->execute([$_pageOid, $_pageTid]);
$activeMethods = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);
// Fallback ke 3 default kalau outlet belum punya rows (shouldn't happen post-migration, defensive)
if (!$activeMethods) {
    $activeMethods = [
        ['code'=>'cash',     'label'=>'Tunai',         'emoji'=>'💵'],
        ['code'=>'transfer', 'label'=>'Transfer Bank', 'emoji'=>'🏦'],
        ['code'=>'qris',     'label'=>'QRIS',          'emoji'=>'📱'],
    ];
}
```

Note: variable names `$_pageOid` and `$_pageTid` should match what's used in existing QRIS query. If existing uses `$tid`/`$oid`, use those instead. Verify via Step 1's grep.

- [ ] **Step 3: Replace hardcoded dropdown**

Find this existing block (around line 1235-1239):

```html
<select id="f_metode" onchange="onMetodeChange()">
  <option value="cash">💵 Cash</option>
  <option value="transfer">🏦 Transfer</option>
  <option value="qris">📱 QRIS</option>
</select>
```

Replace with:

```php
<select id="f_metode" onchange="onMetodeChange()">
  <?php foreach ($activeMethods as $m): ?>
    <?php
      $isQrisDisabled = ($m['code'] === 'qris' && empty($outletQrisData['qris_image']));
    ?>
    <option value="<?= htmlspecialchars($m['code']) ?>" <?= $isQrisDisabled ? 'disabled' : '' ?>>
      <?= htmlspecialchars($m['emoji']) ?> <?= htmlspecialchars($m['label']) ?><?= $isQrisDisabled ? ' (belum di-setup)' : '' ?>
    </option>
  <?php endforeach; ?>
</select>
```

- [ ] **Step 4: Add server-side validation di save handler**

Locate the save handler that processes the transaction (around line 460 where `metode_bayar` is read from POST):

```bash
grep -nE "metode_bayar.*\\\$data|metode_bayar.*\\\$_POST" pos.php | head -5
```

Find the line `$data['metode_bayar'] ?? 'cash'` (likely around line 460 in the save action handler).

Right BEFORE the existing INSERT INTO hl_transaksi statement, add this defensive check:

```php
// Defense: validate metode_bayar against active methods config (anti-tamper)
$_metodeIn = $data['metode_bayar'] ?? 'cash';
$_validate = $db->prepare("
    SELECT 1 FROM hl_payment_methods
    WHERE outlet_id=? AND tenant_id=? AND code=? AND is_active=1
");
$_validate->execute([$oid, $tid, $_metodeIn]);
if (!$_validate->fetchColumn()) {
    throw new RuntimeException('Metode pembayaran tidak valid atau dinonaktifkan.');
}
```

(Replace `$oid`/`$tid` with actual variable names di save handler scope — usually same as page-render scope.)

- [ ] **Step 5: Verify changes via grep**

Run:
```bash
grep -nE "hl_payment_methods|activeMethods|foreach \(\\\$activeMethods" pos.php | head -10
```

Expected: at least 3 hits — load query, dropdown loop, save validation.

- [ ] **Step 6: Browser smoke test — happy path**

Login as kasir di outlet that has QRIS image setup → buka /pos.php. Verify:

1. Dropdown "Metode" menampilkan semua active methods (built-in + custom)
2. Custom method dari Task 3 (Transfer BCA) muncul kalau is_active=1
3. Pilih "💵 Tunai" → submit transaksi → success, struk tampil dengan label normal
4. Pilih "🏦 Transfer BCA" → submit transaksi → success, struk tampil dengan label "Transfer BCA" (atau "Transfer_bca" sementara — akan di-fix di Task 5)
5. Pilih "📱 QRIS" (kalau image ada) → modal QR muncul, confirm, submit → success

Verify DB:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT id, no_transaksi, metode_bayar, total, created_at FROM hl_transaksi
ORDER BY id DESC LIMIT 5;
"
```

Expected: row terbaru pakai metode_bayar='transfer_bca' (atau code yang dipilih).

- [ ] **Step 7: Browser smoke test — deactivated method tidak muncul**

Login as owner → /payment-settings → uncheck "Tunai" (deactivate built-in). Refresh tab kasir POS:

Expected: dropdown tidak punya option "💵 Tunai".

Login owner kembali → centang Tunai → check kembali POS → option Tunai muncul lagi.

- [ ] **Step 8: Tamper test — POST validation**

Manual POST via curl (akan gagal karena CSRF, jadi ini just demo intent — actual test via browser console manipulating fetch payload). Lewati jika sulit; in-app validation should be sufficient.

Atau: di payment-settings, hapus method "Transfer BCA". Kemudian via browser dev console di POS, modify dropdown value to 'transfer_bca' before submit (HTML inspector → change option value):

```js
// In browser console di /pos.php
document.querySelector('#f_metode').innerHTML += '<option value="transfer_bca">Hacked</option>';
document.querySelector('#f_metode').value = 'transfer_bca';
```

Submit transaksi.

Expected: server reject — error "Metode pembayaran tidak valid atau dinonaktifkan."

- [ ] **Step 9: Commit**

```bash
git add pos.php
git commit -m "feat(payment-methods): POS dropdown dinamis + save validation

Replace hardcoded <option> cash/transfer/qris dengan PHP loop dari
hl_payment_methods WHERE outlet_id=? AND is_active=1.

Server-side validation di save handler reject metode_bayar yang tidak
exist atau is_active=0 (anti-tamper).

Defensive fallback ke 3 default built-in kalau query return empty
(shouldn't happen post-migration, but safe).

QRIS modal trigger tetap jalan (existing) — kalau code='qris' dan
qris_image NULL, option disabled dengan suffix '(belum di-setup)'."
```

---

## Task 5: StrukGenerator DB Lookup + Call Sites

**Files:**
- Modify: `core/StrukGenerator.php` — replace `metodeBayarLabel()` (line 63) + update 2 call sites (lines 560, 880)

**Interfaces:**
- Consumes: hl_payment_methods table from Task 1, hl_transaksi.outlet_id
- Produces: `private static metodeBayarLabel(?string $code, ?int $outletId = null): string` — DB lookup with cache + fallback `ucfirst($code)` untuk orphan codes

- [ ] **Step 1: Read existing metodeBayarLabel + call sites**

Run:
```bash
sed -n '60,75p' core/StrukGenerator.php
echo "---"
sed -n '555,565p' core/StrukGenerator.php
echo "---"
sed -n '875,885p' core/StrukGenerator.php
```

Note exact existing signatures + call patterns.

- [ ] **Step 2: Replace metodeBayarLabel method**

Find existing method (lines 63-73 approximately):

```php
    private static function metodeBayarLabel(?string $method): string
    {
        return match($method) {
            'cash'        => 'Tunai',
            'transfer'    => 'Transfer Bank',
            'qris'        => 'QRIS',
            'deposit'     => 'Saldo Deposit',
            default       => ucfirst((string)$method),
        };
    }
```

Replace with:

```php
    /**
     * Resolve display label untuk metode pembayaran.
     *
     * Lookup ke hl_payment_methods (per-outlet config). Cache per-request.
     * Fallback graceful ke ucfirst($code) untuk orphan codes (historical
     * transactions where method was deleted, or pre-migration data).
     */
    private static function metodeBayarLabel(?string $code, ?int $outletId = null): string
    {
        if (!$code) return '';

        static $cache = [];
        $key = ($outletId ?? 0) . ':' . $code;
        if (isset($cache[$key])) return $cache[$key];

        // DB lookup jika outlet_id tersedia
        if ($outletId) {
            try {
                $db = Database::get();
                $stmt = $db->prepare(
                    "SELECT emoji, label FROM hl_payment_methods
                     WHERE outlet_id=? AND code=? LIMIT 1"
                );
                $stmt->execute([$outletId, $code]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $cache[$key] = trim(($row['emoji'] ?? '') . ' ' . $row['label']);
                }
            } catch (Throwable $e) {
                // Fall through to default mapping
            }
        }

        // Fallback for orphan codes (deleted methods, pre-migration data)
        $defaults = [
            'cash'     => 'Tunai',
            'transfer' => 'Transfer Bank',
            'qris'     => 'QRIS',
            'deposit'  => 'Saldo Deposit',
        ];
        return $cache[$key] = $defaults[$code] ?? ucfirst($code);
    }
```

- [ ] **Step 3: Update call site #1 (thermal render, line ~560)**

Find existing line:

```php
$h .= self::tRow('Bayar', self::metodeBayarLabel($trx['metode_bayar']), $maxChar);
```

Replace with:

```php
$h .= self::tRow('Bayar', self::metodeBayarLabel($trx['metode_bayar'], $trx['outlet_id'] ?? null), $maxChar);
```

- [ ] **Step 4: Update call site #2 (HTML render, line ~880)**

Find existing line:

```php
$h .= "  <tr><td>Metode Bayar</td><td class='r'>" . self::esc(self::metodeBayarLabel($trx['metode_bayar'])) . "</td></tr>\n";
```

Replace with:

```php
$h .= "  <tr><td>Metode Bayar</td><td class='r'>" . self::esc(self::metodeBayarLabel($trx['metode_bayar'], $trx['outlet_id'] ?? null)) . "</td></tr>\n";
```

- [ ] **Step 5: Verify both call sites use 2-arg version**

Run:
```bash
grep -n "metodeBayarLabel" core/StrukGenerator.php
```

Expected: 3 hits — method definition (with `?int $outletId`) + 2 call sites (with `$trx['outlet_id']` second arg).

- [ ] **Step 6: Smoke test — struk render with custom method**

Test from Task 4 transaksi (metode_bayar='transfer_bca'). Fetch struk via existing endpoint:

Login kasir → POS → cari order yang barusan di-create dengan metode 'transfer_bca' → cetak struk.

Expected: struk menampilkan label "🏦 Transfer BCA" (dengan emoji dari DB lookup), BUKAN "Transfer_bca" (ucfirst raw).

- [ ] **Step 7: Smoke test — orphan code fallback**

Manually delete custom method via DB direct:

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
DELETE FROM hl_payment_methods WHERE code='transfer_bca' AND is_builtin=0;
"
```

Cetak struk untuk transaksi tagged 'transfer_bca' yang baru di-buat.

Expected: struk menampilkan "Transfer_bca" (ucfirst fallback) — no crash, no empty label.

Re-create the method via /payment-settings → struk render ulang menampilkan label penuh dengan emoji.

- [ ] **Step 8: Commit**

```bash
git add core/StrukGenerator.php
git commit -m "feat(payment-methods): StrukGenerator DB lookup + fallback

Replace match() hardcoded mapping dengan DB lookup ke hl_payment_methods
(per-outlet config). Cache per-request untuk perf.

Signature changed: metodeBayarLabel(\$code, \$outletId = null).
Both call sites updated (thermal render line 560, HTML render line 880)
to pass \$trx['outlet_id'] sebagai 2nd arg.

Fallback graceful untuk orphan codes (method deleted setelah transaksi):
- defaults map (cash/transfer/qris/deposit) sebagai static fallback
- final fallback: ucfirst(\$code) — no crash, render anything

Custom methods with emoji muncul as 'emoji label' (mis. '🏦 Transfer BCA')."
```

---

## Task 6: E2E Sign-off + Production Deploy

**Files:**
- None (verification only)

**Interfaces:**
- Consumes: All previous tasks
- Produces: Production-ready feature

- [ ] **Step 1: Run full git log review**

```bash
git log --oneline fcb0cad..HEAD
```

Expected: 5 commits (Tasks 1-5). Verify clean history, no merge conflicts.

- [ ] **Step 2: Push to remote — trigger Hostinger auto-deploy**

```bash
git push origin main
```

Wait ~15-20s for Hostinger deploy.

- [ ] **Step 3: HTTP smoke test post-deploy**

```bash
curl -s -o /dev/null -w "GET /payment-settings %{http_code}\n" "https://lamasy.harpy.id/payment-settings"
curl -s -o /dev/null -w "GET /pos.php          %{http_code}\n" "https://lamasy.harpy.id/pos.php"
curl -s -o /dev/null -w "GET /payment-settings?action=method_list %{http_code}\n" "https://lamasy.harpy.id/payment-settings?action=method_list"
```

Expected: 302 (login redirect) untuk all three (auth gates work).

- [ ] **Step 4: Manual E2E happy path on production**

| # | Action | Expected |
|---|--------|----------|
| 1 | Login HQ owner → /payment-settings | Section "💳 Metode Pembayaran POS" load 3 built-in |
| 2 | Tambah method "Transfer Mandiri" + 🏦 | Row baru muncul di list |
| 3 | Login kasir di outlet sama → POS | Dropdown menampilkan 4 options (3 built-in + Transfer Mandiri) |
| 4 | Pilih "Transfer Mandiri" → submit transaksi | Order tersimpan dengan metode_bayar='transfer_mandiri' |
| 5 | Cetak struk | Label "🏦 Transfer Mandiri" muncul |
| 6 | Buka /laporan harian | Row metode 'transfer_mandiri' muncul di aggregation |
| 7 | Owner uncheck "Tunai" | Refresh POS kasir — Tunai hilang dari dropdown |
| 8 | Owner edit "Transfer Mandiri" → "Transfer Mandiri Bisnis" | Submit, list refresh dengan label baru |
| 9 | Owner hapus "Transfer Mandiri Bisnis" | Confirm, row hilang. Cetak struk transaksi lama → fallback "Transfer_mandiri" |
| 10 | Owner check Tunai kembali | POS dropdown ada Tunai lagi |

- [ ] **Step 5: Edge case verification**

| Test | Expected |
|------|----------|
| Tampering POST (browser console mutate dropdown then submit) | Server reject "Metode pembayaran tidak valid" |
| Add new outlet via add-outlet flow | 3 built-in auto-seeded via TenantProvisioner |
| Add empty label | Modal error "Label wajib diisi" |
| Add duplicate label | Auto-suffix `_2` di code |
| Try edit built-in via direct POST | Server reject "Metode bawaan tidak bisa di-edit" |
| Try delete built-in via direct POST | Server reject "Metode bawaan tidak bisa di-hapus" |

- [ ] **Step 6: Cleanup test artifacts**

Hapus test methods yang dibuat saat sign-off (kalau pakai data real production):

```bash
# Hanya hapus yang label dimulai "TEST_" atau yang dibuat selama E2E
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT id, code, label FROM hl_payment_methods WHERE label LIKE 'Transfer Mandiri%';
-- Setelah cek, hapus secara selektif
-- DELETE FROM hl_payment_methods WHERE id IN (...);
"
```

- [ ] **Step 7: Update progress ledger**

```bash
cat >> .superpowers/sdd/progress.md <<'EOF'

Payment Methods PLAN COMPLETE 2026-06-24 WIB — 5/6 tasks done. Task 6 (E2E + prod deploy) is verification stage.
Final state: <base>..<head>
EOF
```

(Replace `<base>` and `<head>` with actual commit SHAs.)

---

## Self-Review Checklist

### Spec Coverage

- ✅ §3.2 Schema → Task 1
- ✅ §3.3 Data flow A (config CRUD) → Task 3
- ✅ §3.3 Data flow B (POS dropdown) → Task 4
- ✅ §3.3 Data flow C (struk + reports) → Task 5
- ✅ §4.1 Pembayaran tab layout → Task 3 (Step 6)
- ✅ §4.2 Tambah/Edit modal → Task 3 (Step 7)
- ✅ §4.3 POS dropdown display → Task 4 (Step 3)
- ✅ §5.1 CRUD actions → Task 3 (Steps 2-5)
- ✅ §5.2 POS diff → Task 4
- ✅ §5.3 StrukGenerator diff → Task 5
- ✅ §5.4 TenantProvisioner diff → Task 2
- ✅ §6 Existing system integration → covered di Task 5 (struk + reports auto)
- ✅ §7 Security → CSRF + tenant scope + tamper validation distributed across Tasks 3-4
- ✅ §8 Edge cases → smoke test in Task 6
- ✅ §9 Testing plan → Task 6
- ✅ §13 Success criteria → Task 6 (E2E)

### Placeholder Scan

✓ No "TBD", "TODO", "implement later"
✓ All code blocks contain actual code, not pseudocode
✓ All commands include expected output
✓ "Similar to Task N" not used — each task self-contained

### Type/Name Consistency

- ✅ Method signature: `seedPaymentMethods(PDO $db, int $tenantId, int $outletId): void` (Task 2 def, Task 2 call)
- ✅ Method signature: `metodeBayarLabel(?string $code, ?int $outletId = null): string` (Task 5 def + both call sites with $trx['outlet_id'])
- ✅ JS function names consistent: `loadMethods`, `openMethodModal`, `closeMethodModal`, `saveMethod`, `deleteMethod`, `toggleMethod`
- ✅ HTML element IDs: `methodsList`, `methodModal`, `methodEditId`, `methodLabel`, `methodEmoji`, `methodModalError`, `methodModalTitle`
- ✅ POST action names: `method_list`, `method_add`, `method_edit`, `method_delete`, `method_toggle` (all 5 used consistently)
- ✅ DB columns: code, label, emoji, is_builtin, is_active, sort_order (all consistent across Tasks 1-5)
- ✅ Built-in seed values: cash/Tunai/💵, transfer/Transfer Bank/🏦, qris/QRIS/📱 (Task 1 migration matches Task 2 provisioner exactly)

### Deviations / Notes

- Task 2 Step 4: insertion point line in add-outlet.php is approximate (line ~165). Implementer must grep for `$outletId = (int)$db->lastInsertId();` to find exact line.
- Task 3 Step 6/7: insertion point in payment-settings.php for new section is between "Outlet: ..." paragraph and existing `<?php if ($msg): ?>`. Implementer should adjust if file structure has shifted.
- Task 4 Step 1-2: variable scope `$tid` vs `$_pageTid` should be verified — use whatever name exists in current QRIS data load block.
- Task 5 Step 1: line numbers (63, 560, 880) are approximate post-Task 5 of static-qris-pos plan. Implementer should grep for `metodeBayarLabel` to anchor.
