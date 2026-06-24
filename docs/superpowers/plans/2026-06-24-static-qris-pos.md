# Static QRIS POS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tenant upload static QRIS image per outlet via HQ; kasir display QR di POS saat customer pilih bayar QRIS, customer scan + bayar, kasir confirm manual.

**Architecture:** Additive feature — extend existing POS `qris` payment method (sudah ada di pos.php) dengan modal QR display. Add upload form `/hq/outlet-qris.php` untuk owner. Schema: 3 kolom ALTER outlets (qris_image, qris_uploaded_at, qris_label). Zero impact ke flow existing (cash/transfer/deposit/qris) — backward compat 100%.

**Tech Stack:** PHP 8 vanilla, MariaDB (Hostinger), pos.php inline JS, hq/outlet.php existing CRUD pattern, banners.php upload pattern reference.

## Global Constraints

- **Column name:** `metode_bayar` (varchar 30) — NOT `payment_method`. Existing POS already uses `qris` as value (lihat pos.php:1238).
- **Path absolut master config:** require master/config/db.php → Database::get()
- **Timezone:** `Asia/Jakarta` (set via date_default_timezone_set)
- **CSRF:** Pakai existing `csrfToken()` / `csrfCheck()` di core/Csrf.php (HQ pages)
- **Tenant guard:** `/hq/*` pages MUST require_once 'middleware/tenant_guard.php' + 'middleware/hq_guard.php'
- **Cross-tenant write protection:** ALL UPDATE outlets harus include `WHERE id=? AND tenant_id=?`
- **Upload spec:** JPG/PNG/WebP, max 500KB, min 400×400 px (square)
- **File naming:** `outlet_{id}_{ts}_{rand6}.{ext}` (random component anti-guess)
- **Asset dir:** `/assets/outlet-qris/` (web-accessible)
- **Test-by-smoke:** PHP codebase tanpa unit test framework. Verify via DB query + browser MCP + curl.

---

## File Structure

**New files:**
- `db/migrations/2026-06-24-outlet-qris.sql` — 3 ALTER outlets
- `hq/outlet-qris.php` — upload form + POST handler (~120 LOC)
- `assets/outlet-qris/.gitkeep` — directory placeholder

**Modified files:**
- `hq/outlet.php` — tambah button "💳 Setup QRIS" di outlet card (line ~441)
- `pos.php` — tambah modal QR display + JS trigger pada selectchange metode (lines ~1235, ~1321, ~2022)
- `core/StrukGenerator.php` — explicit label mapping cash/transfer/qris (lines 547, 867)

**No change required:**
- POS save handler — sudah handle `metode_bayar='qris'`
- Reports/laporan — sudah aggregate by metode_bayar
- Audit log — POS sudah catat catatan dengan metode label (pos.php:540-544)

---

## Task 1: DB Migration + Manual Smoke

**Files:**
- Create: `db/migrations/2026-06-24-outlet-qris.sql`

**Interfaces:**
- Consumes: (none — schema-only task)
- Produces: 3 new nullable columns di tabel `outlets`:
  - `qris_image VARCHAR(255) NULL`
  - `qris_uploaded_at DATETIME NULL`
  - `qris_label VARCHAR(100) NULL`

- [ ] **Step 1: Create migration file**

Write to `db/migrations/2026-06-24-outlet-qris.sql`:

```sql
-- Static QRIS POS — Phase 1
-- Tambah 3 kolom ke outlets untuk store QRIS image per outlet.

ALTER TABLE outlets
  ADD COLUMN qris_image VARCHAR(255) NULL AFTER status,
  ADD COLUMN qris_uploaded_at DATETIME NULL AFTER qris_image,
  ADD COLUMN qris_label VARCHAR(100) NULL COMMENT 'Display label, mis. "BCA - PT Rizky Laundry"' AFTER qris_uploaded_at;
```

- [ ] **Step 2: Apply migration to local DB**

Run:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-outlet-qris.sql
```

Expected: no output (success). On error: ALTER table already has columns → safe to re-run idempotent (kalau perlu, jadikan `IF NOT EXISTS` via separate ADD COLUMN — MariaDB 10.0.2+ support).

- [ ] **Step 3: Verify schema applied**

Run:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC outlets" | grep -E "qris_image|qris_uploaded_at|qris_label"
```

Expected output (3 rows):
```
qris_image       varchar(255)  YES    NULL
qris_uploaded_at datetime      YES    NULL
qris_label       varchar(100)  YES    NULL
```

- [ ] **Step 4: Create asset directory**

Run:
```bash
mkdir -p assets/outlet-qris && touch assets/outlet-qris/.gitkeep
```

- [ ] **Step 5: Commit**

```bash
git add db/migrations/2026-06-24-outlet-qris.sql assets/outlet-qris/.gitkeep
git commit -m "feat(qris-static): schema ALTER outlets + assets dir

Tambah 3 kolom nullable ke outlets:
- qris_image: path ke uploaded QRIS image
- qris_uploaded_at: timestamp upload
- qris_label: display label (mis. 'BCA - PT Rizky Laundry')

Plus directory /assets/outlet-qris/ untuk file storage.

Schema bersifat additive — existing outlets gak terdampak."
```

---

## Task 2: Upload Page `/hq/outlet-qris.php`

**Files:**
- Create: `hq/outlet-qris.php`

**Interfaces:**
- Consumes: 3 kolom outlets dari Task 1 (qris_image, qris_uploaded_at, qris_label)
- Produces: Web endpoint untuk upload/replace/delete QRIS image per outlet. Hooked dari hq/outlet.php (Task 3) via URL `/hq/outlet-qris.php?outlet_id=X`.

- [ ] **Step 1: Create page skeleton dengan guards**

Write to `hq/outlet-qris.php`:

```php
<?php
// ══════════════════════════════════════════════════════
// hq/outlet-qris.php — Upload QRIS image per outlet
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/../master/config/db.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../middleware/tenant_guard.php';
require_once __DIR__ . '/../middleware/hq_guard.php';

date_default_timezone_set('Asia/Jakarta');

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$outletId = (int)($_GET['outlet_id'] ?? 0);

if (!$outletId) { http_response_code(400); exit('outlet_id required'); }

$db = Database::get();

// Validasi outlet milik tenant ini
$stmt = $db->prepare("SELECT id, nama_outlet, qris_image, qris_label, qris_uploaded_at
                      FROM outlets WHERE id=? AND tenant_id=?");
$stmt->execute([$outletId, $tenantId]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$outlet) { http_response_code(404); exit('Outlet tidak ditemukan'); }

$msg = '';
$err = '';
```

- [ ] **Step 2: Add POST handler — delete action**

Append to `hq/outlet-qris.php`:

```php
// ─── POST: Delete QRIS ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();

    try {
        if ($outlet['qris_image']) {
            $absPath = __DIR__ . '/..' . $outlet['qris_image'];
            if (is_file($absPath)) @unlink($absPath);
        }
        $up = $db->prepare("UPDATE outlets SET qris_image=NULL, qris_label=NULL, qris_uploaded_at=NULL
                            WHERE id=? AND tenant_id=?");
        $up->execute([$outletId, $tenantId]);
        $msg = 'QRIS berhasil dihapus.';
        // Refresh outlet data
        $stmt->execute([$outletId, $tenantId]);
        $outlet = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $err = 'Gagal hapus: ' . $e->getMessage();
    }
}
```

- [ ] **Step 3: Add POST handler — upload action**

Append to `hq/outlet-qris.php`:

```php
// ─── POST: Upload QRIS ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    csrfCheck();

    try {
        $label = trim($_POST['label'] ?? '');
        if ($label === '') throw new RuntimeException('Label QRIS wajib diisi');
        if (mb_strlen($label) > 100) throw new RuntimeException('Label max 100 karakter');

        $f = $_FILES['qris_image'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload gagal atau tidak ada file');
        }

        // 1. Type check (MIME, bukan extension)
        $mime = mime_content_type($f['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Format harus JPG, PNG, atau WebP. Detected: ' . $mime);
        }

        // 2. Size check (max 500 KB)
        if ($f['size'] > 500 * 1024) {
            throw new RuntimeException('Ukuran max 500 KB. Anda: ' . round($f['size']/1024) . ' KB');
        }

        // 3. Dimension check (min 400×400)
        $info = @getimagesize($f['tmp_name']);
        if (!$info || $info[0] < 400 || $info[1] < 400) {
            $w = $info[0] ?? 0; $h = $info[1] ?? 0;
            throw new RuntimeException("Min 400×400 px. Anda: {$w}×{$h}");
        }

        // 4. Save dengan random filename component
        $ext = $allowed[$mime];
        $filename = sprintf('outlet_%d_%d_%s.%s', $outletId, time(), bin2hex(random_bytes(3)), $ext);
        $dir = __DIR__ . '/../assets/outlet-qris';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $absPath = "$dir/$filename";
        if (!move_uploaded_file($f['tmp_name'], $absPath)) {
            throw new RuntimeException('Gagal save file. Cek permission /assets/outlet-qris/');
        }

        // 5. Delete old image (kalau replace)
        if ($outlet['qris_image']) {
            $oldAbs = __DIR__ . '/..' . $outlet['qris_image'];
            if (is_file($oldAbs)) @unlink($oldAbs);
        }

        // 6. UPDATE DB (tenant_id scope check)
        $relPath = "/assets/outlet-qris/$filename";
        $up = $db->prepare("UPDATE outlets SET qris_image=?, qris_label=?, qris_uploaded_at=NOW()
                            WHERE id=? AND tenant_id=?");
        $up->execute([$relPath, $label, $outletId, $tenantId]);

        $msg = 'QRIS berhasil di-upload.';
        // Refresh outlet
        $stmt->execute([$outletId, $tenantId]);
        $outlet = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
```

- [ ] **Step 4: Add HTML render (layout + form)**

Append to `hq/outlet-qris.php`:

```php
<?php require_once __DIR__ . '/_layout_open.php'; ?>

<div class="hq-page" style="max-width:720px;margin:0 auto;padding:20px">
  <div style="margin-bottom:16px">
    <a href="/hq/outlet" style="color:#6b7280;font-size:13px;text-decoration:none">← Kembali ke daftar outlet</a>
  </div>

  <h1 style="font-size:24px;margin:0 0 8px 0">🏷️ Setup QRIS</h1>
  <p style="color:#6b7280;margin:0 0 24px 0">
    Outlet: <strong><?= htmlspecialchars($outlet['nama_outlet']) ?></strong>
  </p>

  <?php if ($msg): ?>
    <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:12px;border-radius:8px;margin-bottom:16px">
      ✓ <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:16px">
      ✕ <?= htmlspecialchars($err) ?>
    </div>
  <?php endif; ?>

  <?php if ($outlet['qris_image']): ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px">
      <h3 style="margin:0 0 12px 0;font-size:16px">QRIS Saat Ini</h3>
      <img src="<?= htmlspecialchars($outlet['qris_image']) ?>"
           alt="QRIS"
           style="max-width:280px;width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;margin-bottom:12px">
      <div style="color:#374151;font-weight:600;margin-bottom:4px">
        <?= htmlspecialchars($outlet['qris_label']) ?>
      </div>
      <div style="color:#9ca3af;font-size:12px;margin-bottom:16px">
        Di-upload: <?= htmlspecialchars($outlet['qris_uploaded_at']) ?>
      </div>
      <form method="POST" onsubmit="return confirm('Yakin hapus QRIS ini? Customer tidak bisa bayar via QRIS setelah dihapus.')">
        <input type="hidden" name="_csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" style="background:#ef4444;color:#fff;border:0;padding:10px 16px;border-radius:8px;cursor:pointer;font-weight:600">
          🗑️ Hapus QRIS
        </button>
      </form>
    </div>
  <?php endif; ?>

  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px">
    <h3 style="margin:0 0 16px 0;font-size:16px">
      <?= $outlet['qris_image'] ? 'Ganti QRIS' : 'Upload QRIS Baru' ?>
    </h3>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="upload">

      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Label QRIS *</label>
        <input type="text" name="label" required maxlength="100"
               value="<?= htmlspecialchars($outlet['qris_label'] ?? '') ?>"
               placeholder="BCA - PT Rizky Laundry"
               style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
        <div style="font-size:12px;color:#9ca3af;margin-top:4px">
          Tampil di POS sebagai info ke customer
        </div>
      </div>

      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Upload Gambar QRIS *</label>
        <input type="file" name="qris_image" accept="image/jpeg,image/png,image/webp" required
               style="width:100%;padding:10px;border:1px dashed #d1d5db;border-radius:8px;background:#f9fafb">
      </div>

      <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#0c4a6e">
        <strong>📐 Spesifikasi:</strong>
        <ul style="margin:6px 0 0 18px;padding:0">
          <li>Format: JPG, PNG, WebP</li>
          <li>Min 400 × 400 px (square)</li>
          <li>Maks 500 KB</li>
          <li>Gambar QRIS dari banking app harus jelas + fokus</li>
        </ul>
      </div>

      <button type="submit" style="background:#0d9488;color:#fff;border:0;padding:12px 20px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px">
        💾 Simpan QRIS
      </button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_close.php'; ?>
```

- [ ] **Step 5: Smoke test — render kosong (no QRIS yet)**

Run:
```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://lamasy.harpy.id/hq/outlet-qris?outlet_id=1"
```

Expected: `302` (redirect to login if not authenticated) atau `200` jika login session masih hidup.

- [ ] **Step 6: Manual browser test — upload flow**

Login HQ owner di browser → buka `/hq/outlet-qris.php?outlet_id={your_outlet_id}` → upload sample QR image (download dari [https://simulator.sandbox.midtrans.com](https://simulator.sandbox.midtrans.com) atau buat dari [https://qrserver.com/v1/create-qr-code/?size=500x500&data=test](https://qrserver.com/v1/create-qr-code/?size=500x500&data=test)) → input label "TEST QRIS" → submit.

Expected: success banner "QRIS berhasil di-upload.", thumbnail muncul di "QRIS Saat Ini" card.

Verify DB:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT id, nama_outlet, qris_image, qris_label, qris_uploaded_at
FROM outlets WHERE qris_image IS NOT NULL LIMIT 5;
"
```

Expected: row dengan qris_image path + label + recent timestamp.

- [ ] **Step 7: Edge case — upload too-small image**

Browser → upload 200×200 image. Expected error banner: "Min 400×400 px. Anda: 200×200".

- [ ] **Step 8: Edge case — upload non-image**

Browser → upload .pdf file. Expected error banner: "Format harus JPG, PNG, atau WebP."

- [ ] **Step 9: Edge case — delete flow**

Browser → klik tombol "🗑️ Hapus QRIS" → confirm. Expected: file dihapus dari `/assets/outlet-qris/`, DB columns kembali NULL, success banner.

Verify file deleted:
```bash
ls -la assets/outlet-qris/ | grep outlet_
```

Expected: file lama hilang.

- [ ] **Step 10: Commit**

```bash
git add hq/outlet-qris.php
git commit -m "feat(qris-static): upload page hq/outlet-qris.php

Owner upload QRIS image per outlet via HQ. Validation:
- Type: JPG/PNG/WebP (MIME check, bukan extension)
- Size: max 500 KB
- Dimension: min 400×400 (square QR scan-able)
- Filename: outlet_{id}_{ts}_{rand6}.{ext} (random anti-guess)

Actions: upload, replace, delete. Cross-tenant write protection via
UPDATE WHERE tenant_id=?. Old file auto-deleted on replace/delete."
```

---

## Task 3: Link "Setup QRIS" di hq/outlet.php

**Files:**
- Modify: `hq/outlet.php` (around line 441 — outlet card action buttons)

**Interfaces:**
- Consumes: `/hq/outlet-qris.php?outlet_id=X` dari Task 2
- Produces: Visible entry point di outlet card list

- [ ] **Step 1: Locate outlet card action buttons**

Read file `hq/outlet.php` around lines 430-450 to identify where buttons (Edit, dll.) are rendered di template literal JS.

- [ ] **Step 2: Add button — find this existing line**

Around line 441 di hq/outlet.php:
```javascript
${!isClosed ? `<button class="btn btn-light btn-sm" onclick="openEdit(${o.id})">✏️ Edit</button>` : ''}
```

- [ ] **Step 3: Add Setup QRIS button right after Edit button**

Replace the line above with:
```javascript
${!isClosed ? `<button class="btn btn-light btn-sm" onclick="openEdit(${o.id})">✏️ Edit</button>
<a href="/hq/outlet-qris?outlet_id=${o.id}" class="btn btn-light btn-sm" style="text-decoration:none;display:inline-flex;align-items:center;gap:4px">
  ${o.qris_image ? '💳 QRIS ✓' : '💳 Setup QRIS'}
</a>` : ''}
```

- [ ] **Step 4: Update list query — include qris_image flag**

In hq/outlet.php around line 60-70 (the list action SQL), find the SELECT and ensure `qris_image` is selected. Look for:

```php
SELECT id, nama_outlet, alamat, kota, telepon, status, is_main, ...
```

Add `qris_image` to SELECT list:
```php
SELECT id, nama_outlet, alamat, kota, telepon, status, is_main, qris_image, ...
```

(Exact existing column list akan beda — read file dulu lalu add `qris_image` di list)

- [ ] **Step 5: Smoke test — outlet card shows QRIS button**

Browser → login HQ owner → buka `/hq/outlet` → outlet card sekarang ada tombol "💳 Setup QRIS" (atau "💳 QRIS ✓" kalau sudah upload).

- [ ] **Step 6: Click button → reaches upload page**

Click "💳 Setup QRIS" → navigate ke `/hq/outlet-qris?outlet_id=X` → page render. ✓

- [ ] **Step 7: Commit**

```bash
git add hq/outlet.php
git commit -m "feat(qris-static): link 'Setup QRIS' di outlet card

Tampilkan tombol '💳 Setup QRIS' atau '💳 QRIS ✓' (kalau sudah upload)
per outlet card di hq/outlet. Link ke /hq/outlet-qris?outlet_id=X."
```

---

## Task 4: POS QR Modal — JS Trigger + HTML

**Files:**
- Modify: `pos.php` (around lines 1235, 1321, 2022)

**Interfaces:**
- Consumes: outlet.qris_image + qris_label dari DB (perlu loaded saat page render POS)
- Produces: Modal popup saat QRIS dipilih → kasir konfirmasi pembayaran → form submit normal

- [ ] **Step 1: Load outlet QRIS data di PHP header pos.php**

Around line 30-60 of pos.php (top section dengan tenant/outlet init), find where outlet info loaded. Add:

```php
// Load QRIS data untuk modal display di payment method
$outletQrisStmt = $db->prepare("SELECT qris_image, qris_label FROM outlets WHERE id=? AND tenant_id=?");
$outletQrisStmt->execute([$oid, $tid]);
$outletQrisData = $outletQrisStmt->fetch(PDO::FETCH_ASSOC) ?: ['qris_image'=>null, 'qris_label'=>null];
```

(Variables `$tid` dan `$oid` should exist di pos.php — verify via grep before adding)

- [ ] **Step 2: Render QRIS option dengan disabled state**

Around line 1235 di pos.php, find:
```html
<select id="f_metode">
  <option value="cash">💵 Cash</option>
  <option value="transfer">🏦 Transfer</option>
  <option value="qris">📱 QRIS</option>
</select>
```

Replace `<option value="qris">` line dengan:

```php
<option value="qris" <?= !$outletQrisData['qris_image'] ? 'disabled' : '' ?>>
  📱 QRIS<?= !$outletQrisData['qris_image'] ? ' (belum di-setup)' : '' ?>
</option>
```

- [ ] **Step 3: Inject QRIS data ke JS scope**

Around line 1245 (right after `</select>` closing), add:

```php
<script>
window.outletQris = <?= json_encode([
    'image' => $outletQrisData['qris_image'],
    'label' => $outletQrisData['qris_label'],
]) ?>;
</script>
```

- [ ] **Step 4: Add QR modal HTML**

Around line 1321 (where modal `modalStruk` is defined), insert new modal AFTER existing modalStruk closing div:

```html
<!-- QR Display Modal — Static QRIS Payment -->
<div class="modal-overlay" id="modalQris" style="display:none">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <span class="modal-title">💳 Pembayaran QRIS</span>
      <button class="modal-close" onclick="closeQrisModal()">✕</button>
    </div>
    <div class="modal-body" style="padding:20px;text-align:center">
      <div style="font-size:13px;color:#6b7280;margin-bottom:4px">Total Pembayaran</div>
      <div style="font-size:28px;font-weight:700;color:#0d9488;margin-bottom:16px">
        Rp <span id="qrisAmount">0</span>
      </div>

      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;display:inline-block;margin-bottom:12px">
        <img id="qrisImageEl" src="" alt="QRIS"
             style="display:block;width:280px;height:280px;object-fit:contain">
      </div>

      <div id="qrisLabelEl" style="font-weight:600;color:#374151;margin-bottom:16px"></div>

      <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px;text-align:left;font-size:13px;color:#78350f;margin-bottom:16px">
        <strong>Cara bayar:</strong>
        <ol style="margin:6px 0 0 18px;padding:0">
          <li>Customer scan QR pakai banking app</li>
          <li>Cek banking app outlet untuk notif masuk</li>
          <li>Pastikan nominal masuk sesuai total</li>
          <li>Klik tombol di bawah</li>
        </ol>
      </div>
    </div>
    <div class="modal-footer" style="gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" style="flex:1;padding:12px" onclick="confirmQrisPayment()">
        ✓ Pembayaran Diterima
      </button>
      <button class="btn btn-light" style="flex:1;padding:12px" onclick="cancelQrisPayment()">
        Batal
      </button>
    </div>
  </div>
</div>
```

- [ ] **Step 5: Add JS handlers — modal open on QRIS select**

Around line 2022 (just before `function saveTransaksi()`), add:

```javascript
// ─── QRIS Modal (Static QRIS POS) ──────────────────────
let _qrisConfirmed = false;

function onMetodeChange() {
  const metode = document.getElementById('f_metode').value;
  if (metode === 'qris') {
    if (!window.outletQris || !window.outletQris.image) {
      alert('QRIS belum di-setup oleh owner. Pilih metode lain.');
      document.getElementById('f_metode').value = 'cash';
      return;
    }
    openQrisModal();
  }
}

function openQrisModal() {
  const total = parseFloat(document.getElementById('f_dp').value) || 0;
  document.getElementById('qrisAmount').textContent = total.toLocaleString('id-ID');
  document.getElementById('qrisImageEl').src = window.outletQris.image;
  document.getElementById('qrisLabelEl').textContent = window.outletQris.label || '';
  _qrisConfirmed = false;
  document.getElementById('modalQris').style.display = 'flex';
}

function confirmQrisPayment() {
  _qrisConfirmed = true;
  document.getElementById('modalQris').style.display = 'none';
}

function cancelQrisPayment() {
  _qrisConfirmed = false;
  document.getElementById('modalQris').style.display = 'none';
  document.getElementById('f_metode').value = 'cash'; // reset to default
}

function closeQrisModal() {
  cancelQrisPayment();
}
```

- [ ] **Step 6: Hook onchange listener ke select**

Around line 1235 (the `<select id="f_metode">`), add `onchange` attribute:

Current:
```html
<select id="f_metode">
```

Change to:
```html
<select id="f_metode" onchange="onMetodeChange()">
```

- [ ] **Step 7: Block saveTransaksi kalau QRIS belum dikonfirmasi**

Around line 2022 (inside `function saveTransaksi()`), add early check after metode read:

Find existing line:
```javascript
const metode = document.getElementById('f_metode')?.options[document.getElementById('f_metode').selectedIndex]?.text || '-';
```

Add right after it:
```javascript
const metodeVal = document.getElementById('f_metode').value;
if (metodeVal === 'qris' && !_qrisConfirmed) {
  alert('Konfirmasi pembayaran QRIS dulu sebelum simpan.');
  openQrisModal();
  return;
}
```

- [ ] **Step 8: Smoke test — outlet tanpa QRIS, opsi disabled**

1. Login HQ owner → hapus QRIS image (kalau sudah upload dari Task 2) via `/hq/outlet-qris`
2. Login kasir di outlet yg sama → `/pos.php`
3. Buka payment method dropdown → opsi "📱 QRIS (belum di-setup)" muncul tapi disabled

Expected: opsi greyed out, gak bisa dipilih.

- [ ] **Step 9: Smoke test — outlet dengan QRIS, modal muncul**

1. Login HQ owner → upload QRIS via `/hq/outlet-qris`
2. Refresh tab kasir POS
3. Add item ke transaksi → set DP/Total ada nilainya (mis. Rp 75.000)
4. Buka payment method dropdown → pilih "📱 QRIS"

Expected: modal popup dengan QR image, label, total Rp 75.000, 4 instruksi cara bayar.

- [ ] **Step 10: Smoke test — confirm flow**

1. Lanjut dari step 9 → klik "✓ Pembayaran Diterima"

Expected: modal close. Form masih dalam state QRIS selected.

2. Klik tombol simpan/checkout di POS.

Expected: transaksi tersimpan dengan `metode_bayar='qris'`. Verify:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT id, no_transaksi, metode_bayar, total, created_at
FROM hl_transaksi
WHERE metode_bayar='qris'
ORDER BY id DESC LIMIT 3;
"
```

- [ ] **Step 11: Smoke test — cancel flow**

1. Add item ke transaksi baru
2. Pilih QRIS → modal popup → klik "Batal"

Expected: modal close, dropdown reset ke "Cash", `_qrisConfirmed` jadi false.

- [ ] **Step 12: Smoke test — save tanpa confirm (defensive)**

1. Add item, pilih QRIS, modal popup, close pakai tombol "✕" close button
2. Force re-select QRIS via dropdown
3. Sebelum klik confirm di modal, langsung click save transaksi

Expected: alert "Konfirmasi pembayaran QRIS dulu sebelum simpan." + modal re-open.

- [ ] **Step 13: Commit**

```bash
git add pos.php
git commit -m "feat(qris-static): POS modal QR display + confirm flow

Kasir pilih metode QRIS di POS → modal popup dengan:
- QR image (uploaded by owner di /hq/outlet-qris)
- Label (mis. 'BCA - PT Rizky Laundry')
- Total amount (sync dari f_dp)
- 4 instruksi cara bayar
- Tombol 'Pembayaran Diterima' / 'Batal'

QRIS option disabled di outlet yang belum upload QRIS image.
Defensive: save di-block kalau metode QRIS tapi belum confirm modal.

No schema change. Existing metode_bayar='qris' value reused."
```

---

## Task 5: StrukGenerator Label Polish

**Files:**
- Modify: `core/StrukGenerator.php` (lines 547, 867)

**Interfaces:**
- Consumes: `metode_bayar` field dari hl_transaksi
- Produces: Struk struk yang tampilkan label proper (Tunai/Transfer/QRIS) bukan ucfirst raw

- [ ] **Step 1: Read existing label rendering**

Lines 547 dan 867 di core/StrukGenerator.php. Find:

```php
// Line ~547 (thermal/escpos)
$h .= self::tRow('Bayar', ucfirst($trx['metode_bayar']), $maxChar);
```

```php
// Line ~867 (HTML render)
$h .= "  <tr><td>Metode Bayar</td><td class='r'>" . self::esc(ucfirst($trx['metode_bayar'])) . "</td></tr>\n";
```

- [ ] **Step 2: Add label mapping helper di StrukGenerator class**

Around top of class (after `class StrukGenerator {`), add private static method:

```php
private static function metodeBayarLabel(?string $method): string {
    return match($method) {
        'cash'     => 'Tunai',
        'transfer' => 'Transfer Bank',
        'qris'     => 'QRIS',
        'deposit'  => 'Saldo Deposit',
        default    => ucfirst((string)$method),
    };
}
```

- [ ] **Step 3: Replace line 547 — thermal render**

Change:
```php
$h .= self::tRow('Bayar', ucfirst($trx['metode_bayar']), $maxChar);
```

To:
```php
$h .= self::tRow('Bayar', self::metodeBayarLabel($trx['metode_bayar']), $maxChar);
```

- [ ] **Step 4: Replace line 867 — HTML render**

Change:
```php
$h .= "  <tr><td>Metode Bayar</td><td class='r'>" . self::esc(ucfirst($trx['metode_bayar'])) . "</td></tr>\n";
```

To:
```php
$h .= "  <tr><td>Metode Bayar</td><td class='r'>" . self::esc(self::metodeBayarLabel($trx['metode_bayar'])) . "</td></tr>\n";
```

- [ ] **Step 5: Smoke test — cetak struk QRIS**

1. Lanjut dari Task 4 — transaksi yang sudah di-save dengan metode_bayar='qris'
2. Buka URL struk: cari endpoint cetak struk (umumnya `/api/struk.php?no=NO_TRX` atau via POS struk modal)
3. Verify label "QRIS" muncul di struk (bukan "Qris" kapital ucfirst)

Alternatif quick test via curl:
```bash
curl -s "https://lamasy.harpy.id/api/struk.php?no=NO_TRX&format=html" | grep -i "qris\|metode"
```

Expected: `<td>Metode Bayar</td><td class='r'>QRIS</td>`

- [ ] **Step 6: Commit**

```bash
git add core/StrukGenerator.php
git commit -m "feat(qris-static): label mapping di StrukGenerator

Replace ucfirst() generic dengan explicit mapping:
- cash → Tunai
- transfer → Transfer Bank
- qris → QRIS
- deposit → Saldo Deposit
- default → ucfirst (fallback)

Apply di thermal (line 547) dan HTML (line 867) render."
```

---

## Task 6: E2E Smoke Test + Final Commit (Sign-off)

**Files:**
- None (verification only)

**Interfaces:**
- Consumes: All previous tasks
- Produces: E2E confidence

- [ ] **Step 1: Reset test data**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
-- Cleanup any test QRIS data
UPDATE outlets SET qris_image=NULL, qris_label=NULL, qris_uploaded_at=NULL WHERE qris_image LIKE '%test%';
"
ls assets/outlet-qris/ | grep -v .gitkeep | xargs -I{} rm -f assets/outlet-qris/{} 2>/dev/null || true
```

- [ ] **Step 2: Manual E2E — happy path full flow**

Run through all steps as real owner + kasir:

| # | Action | Expected |
|---|--------|----------|
| 1 | Login HQ owner → /hq/outlet | Outlet card menampilkan tombol "💳 Setup QRIS" |
| 2 | Click "💳 Setup QRIS" | Navigate ke /hq/outlet-qris?outlet_id=X |
| 3 | Upload QRIS sample image (≥400×400, ≤500KB) + label | Success banner, thumbnail muncul |
| 4 | Back ke /hq/outlet | Tombol berubah jadi "💳 QRIS ✓" |
| 5 | Logout, login kasir di outlet sama | Masuk ke /pos.php |
| 6 | Add item ke transaksi | Total kalkulasi |
| 7 | Pilih metode "📱 QRIS" | Modal popup dengan QR image |
| 8 | Verify modal isinya correct | QR clear, label sesuai upload, total sesuai DP field |
| 9 | Click "Pembayaran Diterima" | Modal close |
| 10 | Click simpan transaksi | Success, struk modal popup |
| 11 | Verify struk | Label "QRIS" muncul di "Metode Bayar" row |
| 12 | Buka /laporan | Row "qris" muncul di breakdown payment_method |
| 13 | Login SA → /superadmin/payments (atau /audit) | Tidak relevan (SA scope) — skip |

- [ ] **Step 3: Manual E2E — edge case path**

| # | Action | Expected |
|---|--------|----------|
| 1 | Owner: hapus QRIS via /hq/outlet-qris | Success, file deleted dari assets/ |
| 2 | Kasir refresh POS | QRIS option jadi disabled "(belum di-setup)" |
| 3 | Try to select disabled option | Cannot select (greyed) |
| 4 | Owner re-upload | QRIS option re-enabled di POS |
| 5 | Owner upload too-small image (200x200) | Reject "Min 400×400 px" |
| 6 | Owner upload non-image (PDF) | Reject "Format harus JPG..." |
| 7 | Cross-tenant scope test — kasir tenant A buka /hq/outlet-qris?outlet_id={tenant_B_outlet} | "Outlet tidak ditemukan" (404) |

- [ ] **Step 4: Production deploy verification**

Push semua commits:
```bash
git push origin main
```

Tunggu Hostinger auto-deploy (~15 detik). Verify live:
```bash
curl -s -o /dev/null -w "HTTP %{http_code}\n" "https://lamasy.harpy.id/hq/outlet-qris"
```

Expected: HTTP 302 (redirect login — guard works).

- [ ] **Step 5: Apply DB migration ke production**

Connect to production DB (master/config/db.php credentials) atau apply via Hostinger phpMyAdmin atau via `mysql` client with prod creds:

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -h SRV.MARIADB.HOSTINGER.COM -u USER -p DB_NAME < db/migrations/2026-06-24-outlet-qris.sql
```

(User akan run manual via ~/.my.cnf yang sudah configured)

Verify production:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC outlets" | grep qris
```

Expected: 3 qris_* columns visible di production.

- [ ] **Step 6: Final smoke di production**

Login HQ owner di production → repeat E2E happy path Steps 1-12 dari Step 2 above.

Kalau semua OK → mark feature live.

- [ ] **Step 7: Cleanup test artifacts**

```bash
# Optional: hapus QRIS test image dari production kalau pakai non-production data
# (skip kalau test pakai data real)
```

- [ ] **Step 8: Commit sign-off doc (optional)**

```bash
# Tidak perlu commit kalau hanya verification.
# Plan execution complete.
```

---

## Self-Review Checklist

### Spec Coverage

- ✅ §3.2 Schema delta → Task 1
- ✅ §4.1 Upload form → Task 2
- ✅ §3.3 Data flow upload → Task 2 (steps 2-3 handlers)
- ✅ §5.1 Upload validation → Task 2 step 3
- ✅ §4.2 POS payment method extend → Task 4 step 2
- ✅ §4.3 POS QR modal → Task 4 steps 4-6
- ✅ §5.2 POS modal trigger JS → Task 4 step 5
- ✅ §5.3 POS save handler → no change (existing handles `qris`)
- ✅ §6.2 StrukGenerator label → Task 5
- ✅ §6.1 Reports auto-aggregate → no change (existing GROUP BY metode_bayar)
- ✅ §6.3 Audit log → no change (existing POS catatan logging adequate)
- ✅ §7.1-7.5 Security → distributed across Task 2 (validation), Task 2 step 3 (cross-tenant)
- ✅ §8 Edge cases → Task 2 steps 7-9, Task 4 steps 8/11/12
- ✅ §9 Testing plan → Task 6

### Placeholder Scan

✓ No "TBD", "TODO", or "implement later" terms.
✓ All code blocks contain actual code, not pseudocode.
✓ All commands include expected output.
✓ "Similar to Task N" not used — each task self-contained.

### Type/Name Consistency

- ✅ Column names consistent: `qris_image`, `qris_label`, `qris_uploaded_at` (3 places in tasks)
- ✅ `metode_bayar` (NOT `payment_method`) used throughout — matches existing DB
- ✅ `qris` value (NOT `qris_static`) used for metode_bayar — matches existing POS option
- ✅ JS function names consistent: `onMetodeChange`, `openQrisModal`, `confirmQrisPayment`, `cancelQrisPayment`, `closeQrisModal`
- ✅ HTML element IDs consistent: `modalQris`, `qrisAmount`, `qrisImageEl`, `qrisLabelEl`
- ✅ window.outletQris.image / window.outletQris.label — same shape in PHP json_encode + JS access
- ✅ File path `/assets/outlet-qris/` consistent in Task 1 (mkdir) + Task 2 (save) + Task 6 (cleanup)

### Critical Spec Deviation from Implementation

⚠️ **Spec used `qris_static`, plan uses `qris`** — POS sudah punya `<option value="qris">` (pos.php:1238) dan existing `metode_bayar='qris'` di hl_transaksi. Plan reuse existing untuk backward compat 100%. Spec writer didn't grep existing code; plan reflects reality.

⚠️ **Spec used `payment_method` column name, plan uses `metode_bayar`** — schema is Indonesian per existing convention. Plan corrected.

⚠️ **Spec proposed separate `hl_audit_log` INSERT (§5.3), plan skips** — pos.php sudah catat catatan dengan metode label (pos.php:540-544). Adding duplicate log = redundant. Existing transaksi record + catatan adequate audit trail.
