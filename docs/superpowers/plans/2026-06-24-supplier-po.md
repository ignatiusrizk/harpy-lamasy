# Supplier DB + Purchase Order Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Master supplier (HQ shared) + Purchase Order per outlet (draft→dipesan→diterima), terima barang generate mutasi masuk per item, riwayat pembelian per supplier.

**Architecture:** 3 tabel (hl_supplier tenant-level, hl_po+hl_po_item per outlet) + ALTER hl_bahan supplier_id. hq/supplier.php master CRUD. pembelian.php (outlet) PO lifecycle. Terima reuse mutasi 'masuk' pattern existing + FOR UPDATE idempotent.

**Tech Stack:** PHP 8 vanilla, MariaDB, HQ page (hq_guard) + outlet page (tenant_guard) AJAX pattern, hl_bahan_mutasi.

## Global Constraints

- Master supplier: tenant-level shared (hl_supplier). PO: per outlet (hl_po + hl_po_item).
- No PO format: `PO/YYYY/MM/000N` counter per tenant per bulan (pola invoice/affiliate).
- Lifecycle: draft → dipesan → diterima (full). Terima HARUS dari status 'dipesan'.
- Terima transaksional + `SELECT ... FOR UPDATE` lock hl_po + re-check status='dipesan' (anti double-receive, pola opname commit 44bde84).
- Mutasi masuk pattern (inventori.php existing): tipe='masuk', jumlah=qty, stok_sebelum=stok_terkini, stok_sesudah=stok_sebelum+qty, harga_beli=harga_satuan, supplier=nama, catatan="PO #{no_po}", input_by. INSERT hl_bahan_mutasi kolom (verify 11 kolom: tenant_id, outlet_id, bahan_id, tipe, jumlah, stok_sebelum, stok_sesudah, harga_beli, supplier, catatan, input_by).
- hl_bahan.supplier text DIPERTAHANKAN (backward compat); supplier_id additive NULL (auto-fill defer).
- Permission `inventori.manage` (reuse) semua action. Tenant scope (supplier); tenant+outlet scope (PO).
- CSRF semua POST. XSS htmlspecialchars/esc render. Validasi qty/harga ≥ 1.
- HQ page: define ROOT, require hq_guard.php, requirePermission, AJAX X-Requested-With + verifyCsrf, getCsrfToken.
- Outlet page (pembelian.php): pola inventori.php — tenant_guard, `$action=$_GET['action']; if($action){header json; $tid=TenantResolver::id(); $oid=TenantResolver::outletId();}`, verifyCsrf POST, $canManage (hasPermission inventori.manage).
- mysql client: /opt/homebrew/opt/mysql-client/bin/mysql. No php CLI → smoke deploy/browser.

---

## File Structure

**New:**
- `db/migrations/2026-06-24-supplier-po.sql` — 3 tabel + ALTER
- `hq/supplier.php` — master supplier CRUD + riwayat
- `pembelian.php` — PO outlet (list/create/items/dipesan/terima)

**Modified:**
- `hq/_layout_open.php` — sidebar HQ Supplier
- `components.php` — sidebar outlet Pembelian + icon
- `.htaccess` — route /hq/supplier + /pembelian

---

## Task 1: Schema Migration

**Files:**
- Create: `db/migrations/2026-06-24-supplier-po.sql`

**Interfaces:**
- Produces: hl_supplier, hl_po, hl_po_item, hl_bahan.supplier_id

- [ ] **Step 1: Create migration**

Write `db/migrations/2026-06-24-supplier-po.sql`:

```sql
-- Supplier DB + Purchase Order
CREATE TABLE IF NOT EXISTS hl_supplier (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(100) NOT NULL,
  kontak_nama VARCHAR(100) NULL,
  telepon VARCHAR(20) NULL,
  alamat TEXT NULL,
  term_pembayaran VARCHAR(50) NULL,
  catatan TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_po (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  supplier_id INT NOT NULL,
  no_po VARCHAR(40) NOT NULL,
  tanggal DATE NOT NULL,
  status ENUM('draft','dipesan','diterima','batal') NOT NULL DEFAULT 'draft',
  total BIGINT NOT NULL DEFAULT 0,
  catatan TEXT NULL,
  input_by INT NULL,
  dipesan_at DATETIME NULL,
  diterima_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_no_po (tenant_id, no_po),
  INDEX idx_outlet_status (tenant_id, outlet_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_po_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_id INT NOT NULL,
  tenant_id INT NOT NULL,
  bahan_id INT NOT NULL,
  qty INT NOT NULL,
  harga_satuan INT NOT NULL,
  subtotal BIGINT NOT NULL,
  mutasi_id INT NULL,
  INDEX idx_po (po_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- supplier_id link opsional (backward compat: supplier text tetap)
ALTER TABLE hl_bahan ADD COLUMN supplier_id INT NULL AFTER supplier;
```

NOTE: ALTER ADD COLUMN bisa error "duplicate column" kalau re-run — acceptable (one-shot). Kalau mau idempotent, cek dulu: `SHOW COLUMNS FROM hl_bahan LIKE 'supplier_id'`.

- [ ] **Step 2: Apply + verify**
```bash
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-supplier-po.sql
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC hl_supplier; DESC hl_po; DESC hl_po_item; SHOW COLUMNS FROM hl_bahan LIKE 'supplier_id'" 2>&1 | head -40
```
Expected: 3 tabel + supplier_id kolom.

- [ ] **Step 3: Commit**
```bash
git add db/migrations/2026-06-24-supplier-po.sql
git commit -m "feat(supplier-po): schema hl_supplier + hl_po + hl_po_item + ALTER hl_bahan

Master supplier (tenant-level), PO header (per outlet) + item, link
supplier_id opsional di hl_bahan (text dipertahankan, additive)."
```

---

## Task 2: Master Supplier (hq/supplier.php)

**Files:**
- Create: `hq/supplier.php`

**Interfaces:**
- Consumes: hl_supplier (Task 1), hl_po (riwayat — Task 3 isi data, read aman walau kosong)
- Produces: page `/hq/supplier` + AJAX `list_supplier`, `save_supplier`, `delete_supplier`, `supplier_options`

- [ ] **Step 1: Page skeleton + guard**

Write `hq/supplier.php` (pola hq/keuangan.php):
```php
<?php
// hq/supplier.php — Master Supplier (HQ, shared tenant)
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
requirePermission('inventori.manage');

$db   = Database::get();
$tid  = (int) TenantResolver::id();
$user = currentUser();
$uid  = (int) ($user['id'] ?? 0);
$csrf = getCsrfToken();

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    try {
        // actions di step berikut
        echo json_encode(['ok'=>false, 'error'=>'Unknown action']);
    } catch (Throwable $e) { echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); }
    exit;
}
$activePage = 'hq-supplier';
?>
```
(Verify hq_guard provides requirePermission/getCsrfToken/verifyCsrf/currentUser/Database/TenantResolver — sama hq/keuangan.php. Ikuti.)

- [ ] **Step 2: list_supplier + supplier_options**

Dalam try AJAX:
```php
        if ($action === 'list_supplier') {
            $rows = $db->prepare("
                SELECT s.*,
                  (SELECT COUNT(*) FROM hl_po p WHERE p.supplier_id=s.id AND p.status='diterima') AS total_po,
                  (SELECT COALESCE(SUM(p.total),0) FROM hl_po p WHERE p.supplier_id=s.id AND p.status='diterima') AS nilai_po
                FROM hl_supplier s WHERE s.tenant_id=? AND s.is_active=1 ORDER BY s.nama");
            $rows->execute([$tid]);
            echo json_encode(['ok'=>true, 'data'=>$rows->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }
        if ($action === 'supplier_options') {
            $rows = $db->prepare("SELECT id, nama FROM hl_supplier WHERE tenant_id=? AND is_active=1 ORDER BY nama");
            $rows->execute([$tid]);
            echo json_encode(['ok'=>true, 'data'=>$rows->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }
```

- [ ] **Step 3: save_supplier + delete_supplier**
```php
        if ($action === 'save_supplier') {
            verifyCsrf();
            $id    = (int)($_POST['id'] ?? 0);
            $nama  = substr(trim(strip_tags($_POST['nama'] ?? '')), 0, 100);
            if ($nama==='') throw new RuntimeException('Nama supplier wajib');
            $kontak= substr(trim(strip_tags($_POST['kontak_nama'] ?? '')), 0, 100);
            $telp  = substr(preg_replace('/[^0-9+\-\s]/','', $_POST['telepon'] ?? ''), 0, 20);
            $alamat= substr(trim(strip_tags($_POST['alamat'] ?? '')), 0, 500);
            $term  = substr(trim(strip_tags($_POST['term_pembayaran'] ?? '')), 0, 50);
            $cat   = substr(trim(strip_tags($_POST['catatan'] ?? '')), 0, 500);
            if ($id) {
                $db->prepare("UPDATE hl_supplier SET nama=?, kontak_nama=?, telepon=?, alamat=?, term_pembayaran=?, catatan=?
                              WHERE id=? AND tenant_id=?")
                   ->execute([$nama,$kontak,$telp,$alamat,$term,$cat,$id,$tid]);
            } else {
                $db->prepare("INSERT INTO hl_supplier (tenant_id, nama, kontak_nama, telepon, alamat, term_pembayaran, catatan)
                              VALUES (?,?,?,?,?,?,?)")
                   ->execute([$tid,$nama,$kontak,$telp,$alamat,$term,$cat]);
                $id = (int)$db->lastInsertId();
            }
            echo json_encode(['ok'=>true, 'id'=>$id]); exit;
        }
        if ($action === 'delete_supplier') {
            verifyCsrf();
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE hl_supplier SET is_active=0 WHERE id=? AND tenant_id=?")->execute([$id,$tid]);
            echo json_encode(['ok'=>true]); exit;
        }
```

- [ ] **Step 4: HTML render + JS**

Setelah blok AJAX, render layout HQ (pola hq/keuangan.php: `require _layout_open.php` / `_layout_close.php`). Tabel supplier (loaded via JS) + tombol Tambah + modal form (nama/kontak/telepon/alamat/term/catatan). JS: loadSupplier(), saveSupplier(), deleteSupplier(). Kolom Total PO / Nilai dari list_supplier. esc/CSRF konsisten pola HQ existing.

NOTE: implementer baca hq/keuangan.php atau hq sibling untuk pola layout (renderHead/renderTopbar vs _layout_open) + helper JS + CSRF transport. Ikuti.

- [ ] **Step 5: Verify + commit**
```bash
grep -nE "list_supplier|save_supplier|delete_supplier|supplier_options" hq/supplier.php
git add hq/supplier.php
git commit -m "feat(supplier-po): hq/supplier.php master CRUD

Master supplier HQ shared: list (+ total PO/nilai diterima), save (insert/
update), soft delete, supplier_options (dropdown PO). Guard inventori.manage,
CSRF, tenant scope."
```

---

## Task 3: pembelian.php — PO List + Create + Items

**Files:**
- Create: `pembelian.php`

**Interfaces:**
- Consumes: hl_po/hl_po_item (Task 1), hl_supplier (Task 2 supplier_options), hl_bahan_stok
- Produces: page `/pembelian` + AJAX `po_list`, `po_create`, `po_get`, `po_save_items`, `supplier_opts`, `bahan_opts`. No-PO generator `generatePoNo()`.

- [ ] **Step 1: Page skeleton (pola inventori.php)**

Write `pembelian.php`:
```php
<?php
// pembelian.php — Purchase Order per outlet
$activePage = 'pembelian';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
if (!hasPermission('inventori.manage') && !hasPermission('kas.create')) {
    requirePermission('inventori.manage');
}
$canManage = hasPermission('inventori.manage') || hasPermission('kas.create');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();
    // actions di step berikut
    echo json_encode(['error'=>'Unknown action']); exit;
}
?>
```

- [ ] **Step 2: generatePoNo + po_list + supplier_opts + bahan_opts**

Helper + actions (dalam if($action), ganti placeholder):
```php
    function generatePoNo(PDO $db, int $tid): string {
        $ym = date('Y/m'); $prefix = "PO/$ym/";
        $s = $db->prepare("SELECT COUNT(*) FROM hl_po WHERE tenant_id=? AND no_po LIKE ?");
        $s->execute([$tid, $prefix.'%']);
        return $prefix . str_pad((string)((int)$s->fetchColumn()+1), 4, '0', STR_PAD_LEFT);
    }
    if ($action === 'po_list') {
        $rows = TenantQuery::raw(
            "SELECT p.id, p.no_po, p.tanggal, p.status, p.total, s.nama AS supplier_nama
             FROM hl_po p LEFT JOIN hl_supplier s ON s.id=p.supplier_id AND s.tenant_id=p.tenant_id
             WHERE p.tenant_id=? AND p.outlet_id=? ORDER BY p.created_at DESC LIMIT 50",
            [$tid, $oid]);
        echo json_encode(['ok'=>true, 'rows'=>$rows]); exit;
    }
    if ($action === 'supplier_opts') {
        $rows = TenantQuery::raw("SELECT id, nama FROM hl_supplier WHERE tenant_id=? AND is_active=1 ORDER BY nama", [$tid]);
        echo json_encode(['ok'=>true, 'data'=>$rows]); exit;
    }
    if ($action === 'bahan_opts') {
        $rows = TenantQuery::raw("SELECT id, nama, satuan, harga_beli FROM hl_bahan
                                  WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY nama", [$tid, $oid]);
        echo json_encode(['ok'=>true, 'data'=>$rows]); exit;
    }
```

- [ ] **Step 3: po_create (draft + no_po)**
```php
    if ($action === 'po_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $supplierId = (int)($d['supplier_id'] ?? 0);
        $tgl = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['tanggal'] ?? '') ? $d['tanggal'] : date('Y-m-d');
        if (!$supplierId) { echo json_encode(['error'=>'Pilih supplier']); exit; }
        $db = Database::get();
        // validasi supplier milik tenant
        $sc = $db->prepare("SELECT 1 FROM hl_supplier WHERE id=? AND tenant_id=? AND is_active=1");
        $sc->execute([$supplierId, $tid]);
        if (!$sc->fetchColumn()) { echo json_encode(['error'=>'Supplier tidak valid']); exit; }
        $noPo = generatePoNo($db, (int)$tid);
        $db->prepare("INSERT INTO hl_po (tenant_id, outlet_id, supplier_id, no_po, tanggal, status, input_by)
                      VALUES (?,?,?,?,?, 'draft', ?)")
           ->execute([$tid, $oid, $supplierId, $noPo, $tgl, (int)($user['id'] ?? 0)]);
        echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId(), 'no_po'=>$noPo]); exit;
    }
```

- [ ] **Step 4: po_get + po_save_items (draft only, recompute total)**
```php
    if ($action === 'po_get') {
        $id = (int)($_GET['id'] ?? 0);
        $hdr = TenantQuery::rawOne(
            "SELECT p.*, s.nama AS supplier_nama FROM hl_po p
             LEFT JOIN hl_supplier s ON s.id=p.supplier_id AND s.tenant_id=p.tenant_id
             WHERE p.id=? AND p.tenant_id=? AND p.outlet_id=?", [$id, $tid, $oid]);
        if (!$hdr) { echo json_encode(['error'=>'PO tidak ditemukan']); exit; }
        $items = TenantQuery::raw(
            "SELECT i.id, i.bahan_id, i.qty, i.harga_satuan, i.subtotal, b.nama, b.satuan
             FROM hl_po_item i JOIN hl_bahan b ON b.id=i.bahan_id AND b.tenant_id=i.tenant_id
             WHERE i.po_id=? AND i.tenant_id=? ORDER BY i.id", [$id, $tid]);
        echo json_encode(['ok'=>true, 'header'=>$hdr, 'items'=>$items]); exit;
    }
    if ($action === 'po_save_items' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $poId = (int)($d['po_id'] ?? 0);
        $items = $d['items'] ?? []; // [{bahan_id, qty, harga_satuan}]
        $db = Database::get();
        $chk = $db->prepare("SELECT status FROM hl_po WHERE id=? AND tenant_id=? AND outlet_id=?");
        $chk->execute([$poId, $tid, $oid]);
        $st = $chk->fetchColumn();
        if ($st === false) { echo json_encode(['error'=>'PO tidak ditemukan']); exit; }
        if ($st !== 'draft') { echo json_encode(['error'=>'PO sudah dipesan/diterima']); exit; }
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM hl_po_item WHERE po_id=? AND tenant_id=?")->execute([$poId, $tid]);
            $ins = $db->prepare("INSERT INTO hl_po_item (po_id, tenant_id, bahan_id, qty, harga_satuan, subtotal)
                                 VALUES (?,?,?,?,?,?)");
            // validasi bahan milik outlet
            $bahanChk = $db->prepare("SELECT 1 FROM hl_bahan WHERE id=? AND tenant_id=? AND outlet_id=? AND is_active=1");
            $total = 0;
            foreach ($items as $it) {
                $bahanId = (int)($it['bahan_id'] ?? 0);
                $qty = max(1, (int)($it['qty'] ?? 0));
                $harga = max(0, (int)($it['harga_satuan'] ?? 0));
                if (!$bahanId || $qty < 1) continue;
                $bahanChk->execute([$bahanId, $tid, $oid]);
                if (!$bahanChk->fetchColumn()) continue;
                $sub = $qty * $harga;
                $ins->execute([$poId, $tid, $bahanId, $qty, $harga, $sub]);
                $total += $sub;
            }
            $db->prepare("UPDATE hl_po SET total=? WHERE id=? AND tenant_id=?")->execute([$total, $poId, $tid]);
            $db->commit();
            echo json_encode(['ok'=>true, 'total'=>$total]); exit;
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); exit; }
    }
```

- [ ] **Step 5: Verify + commit**
```bash
grep -nE "generatePoNo|po_list|po_create|po_get|po_save_items|supplier_opts|bahan_opts" pembelian.php
git add pembelian.php
git commit -m "feat(supplier-po): pembelian.php PO list + create + items

PO per outlet: po_list, po_create (draft + no_po PO/YYYY/MM/000N),
po_get (header+items), po_save_items (draft only, replace+recompute total,
validasi bahan outlet), supplier_opts + bahan_opts dropdown. Permission
inventori.manage, CSRF, tenant+outlet scope."
```

---

## Task 4: PO Dipesan + Terima (transaksional)

**Files:**
- Modify: `pembelian.php` (po_dipesan + po_terima actions)

**Interfaces:**
- Consumes: hl_po/item, hl_bahan_stok, hl_bahan_mutasi, hl_supplier
- Produces: AJAX `po_dipesan`, `po_terima` (FOR UPDATE + mutasi masuk)

- [ ] **Step 1: po_dipesan**
```php
    if ($action === 'po_dipesan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $poId = (int)($d['po_id'] ?? 0);
        $db = Database::get();
        // validasi: draft + punya item
        $hdr = $db->prepare("SELECT status FROM hl_po WHERE id=? AND tenant_id=? AND outlet_id=?");
        $hdr->execute([$poId, $tid, $oid]);
        $st = $hdr->fetchColumn();
        if ($st === false) { echo json_encode(['error'=>'PO tidak ditemukan']); exit; }
        if ($st !== 'draft') { echo json_encode(['error'=>'PO bukan draft']); exit; }
        $cnt = $db->prepare("SELECT COUNT(*) FROM hl_po_item WHERE po_id=? AND tenant_id=?");
        $cnt->execute([$poId, $tid]);
        if ((int)$cnt->fetchColumn() < 1) { echo json_encode(['error'=>'PO belum punya item']); exit; }
        $db->prepare("UPDATE hl_po SET status='dipesan', dipesan_at=NOW() WHERE id=? AND tenant_id=? AND status='draft'")
           ->execute([$poId, $tid]);
        echo json_encode(['ok'=>true]); exit;
    }
```

- [ ] **Step 2: po_terima (FOR UPDATE + mutasi masuk)**
```php
    if ($action === 'po_terima' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $poId = (int)($d['po_id'] ?? 0);
        $db = Database::get();
        $db->beginTransaction();
        try {
            // Lock header + re-check status (anti double-receive)
            $lock = $db->prepare("SELECT p.status, p.no_po, s.nama AS supplier_nama
                                  FROM hl_po p LEFT JOIN hl_supplier s ON s.id=p.supplier_id AND s.tenant_id=p.tenant_id
                                  WHERE p.id=? AND p.tenant_id=? AND p.outlet_id=? FOR UPDATE");
            $lock->execute([$poId, $tid, $oid]);
            $po = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$po) { $db->rollBack(); echo json_encode(['error'=>'PO tidak ditemukan']); exit; }
            if ($po['status'] !== 'dipesan') { $db->rollBack(); echo json_encode(['error'=>'PO harus berstatus dipesan']); exit; }

            $items = $db->prepare("SELECT id, bahan_id, qty, harga_satuan FROM hl_po_item WHERE po_id=? AND tenant_id=?");
            $items->execute([$poId, $tid]);
            $rows = $items->fetchAll(PDO::FETCH_ASSOC);

            $stokQ = $db->prepare("SELECT stok_terkini FROM hl_bahan_stok WHERE id=? AND tenant_id=? AND outlet_id=?");
            $insMut = $db->prepare(
                "INSERT INTO hl_bahan_mutasi
                   (tenant_id, outlet_id, bahan_id, tipe, jumlah, stok_sebelum, stok_sesudah, harga_beli, supplier, catatan, input_by)
                 VALUES (?,?,?, 'masuk', ?, ?, ?, ?, ?, ?, ?)");
            $linkItem = $db->prepare("UPDATE hl_po_item SET mutasi_id=? WHERE id=?");
            foreach ($rows as $it) {
                $stokQ->execute([(int)$it['bahan_id'], $tid, $oid]);
                $sebelum = (int)$stokQ->fetchColumn();
                $qty = (int)$it['qty'];
                $insMut->execute([
                    $tid, $oid, (int)$it['bahan_id'], $qty, $sebelum, $sebelum + $qty,
                    (int)$it['harga_satuan'], $po['supplier_nama'] ?: null,
                    "PO #{$po['no_po']}", (int)($user['id'] ?? 0)
                ]);
                $linkItem->execute([(int)$db->lastInsertId(), (int)$it['id']]);
            }
            $db->prepare("UPDATE hl_po SET status='diterima', diterima_at=NOW() WHERE id=? AND tenant_id=? AND status='dipesan'")
               ->execute([$poId, $tid]);
            $db->commit();
            echo json_encode(['ok'=>true, 'count'=>count($rows)]); exit;
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); exit; }
    }
```
NOTE: verify kolom INSERT hl_bahan_mutasi (11 kolom) match existing adjust/masuk di inventori.php — sesuaikan urutan kalau beda.

- [ ] **Step 3: Verify + commit**
```bash
grep -nE "po_dipesan|po_terima|FOR UPDATE|'masuk'" pembelian.php | head
git add pembelian.php
git commit -m "feat(supplier-po): PO dipesan + terima transaksional

po_dipesan (draft→dipesan, validasi ≥1 item). po_terima: FOR UPDATE lock
+ re-check status='dipesan' (anti double-receive), generate mutasi masuk
per item (stok_sebelum+qty, harga_beli=harga_satuan, catatan PO#), link
mutasi_id, status→diterima. Stok naik auto via view."
```

---

## Task 5: UI (supplier + pembelian) + Sidebar + Route

**Files:**
- Modify: `hq/supplier.php` (kalau UI belum lengkap di Task 2 — pastikan render), `pembelian.php` (UI), `hq/_layout_open.php`, `components.php`, `.htaccess`

**Interfaces:**
- Consumes: semua action Task 2-4
- Produces: UI lengkap + akses (sidebar + route)

- [ ] **Step 1: pembelian.php HTML + JS**

Tambah render HTML pembelian.php (pola inventori.php / outlet page): list PO + tombol Buat PO + form (supplier dropdown + tanggal + item rows: bahan dropdown + qty + harga → subtotal; total auto) + tombol Simpan Draft / Tandai Dipesan / Terima Barang (per status). Detail diterima read-only.

JS: loadPoList, poCreate, poOpen (detail/form), poAddItem, poRecalc, collectPoItems, poSaveItems, poDipesan, poTerima. Pakai esc/fmtNum + CSRF transport sama inventori.php. Verify nama helper dari inventori.php.

NOTE: implementer baca inventori.php untuk pola layout + CSRF + esc/fmt; ikuti. supplier dropdown dari supplier_opts, bahan dropdown dari bahan_opts (harga default harga_beli).

- [ ] **Step 2: hq/supplier.php UI**

Pastikan Task 2 sudah render UI (tabel + modal). Kalau belum, lengkapi di sini (pola hq page).

- [ ] **Step 3: Sidebar HQ (supplier) + outlet (pembelian)**

`hq/_layout_open.php` — group Master/Inventori, tambah:
```php
      <a href="/hq/supplier" class="hq-side-link <?= $_aPage === 'hq-supplier' ? 'active' : '' ?>">
        <span class="ico">👥</span> Supplier
      </a>
```
`components.php` — outlet sidebar, group Master (dekat layanan/inventori), tambah item:
```php
                'pembelian' => ['label'=>'Pembelian', 'url'=>'/pembelian', 'perm'=>'inventori.manage'],
```
+ icon map `'pembelian'=>'🛒'`. (Verify grup + struktur navItem di components.php — ikuti pola inventori existing.)

- [ ] **Step 4: .htaccess route**

Tambah `supplier` ke HQ RewriteRule (2 baris), `pembelian` ke app RewriteRule (2 baris). Pola:
```bash
grep -n "hq/(dashboard|outlet" .htaccess
grep -n "^RewriteRule \^(dashboard|pos" .htaccess
```
Sisip `|supplier` (HQ group) + `|pembelian` (app group) di kedua arah (canonical 301 + internal rewrite).

- [ ] **Step 5: Verify + commit**
```bash
grep -n "supplier\|pembelian" .htaccess
grep -n "hq-supplier\|pembelian" hq/_layout_open.php components.php
git add hq/supplier.php pembelian.php hq/_layout_open.php components.php .htaccess
git commit -m "feat(supplier-po): UI pembelian + supplier + sidebar + route

pembelian.php UI (PO list/form/lifecycle), hq/supplier.php UI final.
Sidebar HQ 👥 Supplier + outlet 🛒 Pembelian. .htaccess route
/hq/supplier + /pembelian."
```

---

## Task 6: E2E + Deploy

**Files:** None

- [ ] **Step 1: Push + prod migration**
```bash
git push origin main
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-supplier-po.sql
/opt/homebrew/opt/mysql-client/bin/mysql -e "SHOW TABLES LIKE 'hl_supplier'; SHOW TABLES LIKE 'hl_po%'; SHOW COLUMNS FROM hl_bahan LIKE 'supplier_id'" 2>&1
```

- [ ] **Step 2: HTTP smoke**
```bash
curl -s -o /dev/null -w "GET /hq/supplier %{http_code}\n" "https://lamasy.harpy.id/hq/supplier"
curl -s -o /dev/null -w "GET /pembelian %{http_code}\n" "https://lamasy.harpy.id/pembelian"
```
Expected: 302 (auth gate).

- [ ] **Step 3: Browser E2E (login owner)**

| # | Action | Expected |
|---|--------|----------|
| 1 | /hq/supplier → tambah supplier | List muncul |
| 2 | /pembelian → Buat PO → pilih supplier + 2 item + qty/harga | Total auto, no_po PO/2026/06/0001 |
| 3 | Simpan Draft → Tandai Dipesan | status dipesan |
| 4 | Terima Barang | konfirmasi → status diterima |
| 5 | Verify mutasi masuk | hl_bahan_mutasi 2 row masuk (qty, harga, catatan PO#) |
| 6 | Tab Stok inventori | stok naik sesuai qty |
| 7 | /hq/supplier | total PO/nilai supplier naik |
| 8 | Terima 2× (double) | sekali (FOR UPDATE) |
| 9 | Terima PO draft (belum dipesan) | tolak |

- [ ] **Step 4: DB cross-check**
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT p.no_po, p.status, p.total, s.nama,
  (SELECT COUNT(*) FROM hl_po_item i WHERE i.po_id=p.id) items
FROM hl_po p LEFT JOIN hl_supplier s ON s.id=p.supplier_id ORDER BY p.id DESC LIMIT 5;" 2>&1
```

- [ ] **Step 5: Update ledger**
```bash
cat >> .superpowers/sdd/progress.md <<'EOF'

Supplier DB + PO COMPLETE 2026-06-24 WIB.
Final state: <base>..<head>
EOF
```

---

## Self-Review Checklist

### Spec Coverage
- ✅ §3.2 schema → Task 1
- ✅ §4.1 master supplier → Task 2 + Task 5
- ✅ §4.2 PO create/items → Task 3; dipesan/terima → Task 4
- ✅ §3.4 terima mutasi masuk → Task 4
- ✅ §4.3 actions → Task 2/3/4
- ✅ §5 edge cases → Task 3 (draft validasi), Task 4 (FOR UPDATE, dipesan-dulu)
- ✅ §6 security → permission + scope + CSRF + FOR UPDATE
- ✅ §7 testing → Task 6

### Placeholder Scan
✓ Code lengkap. UI HTML (Task 2/5) mengarahkan ikut pola existing (layout HQ/outlet bervariasi) — flagged, implementer baca sibling. AJAX + transaksi bodies penuh.

### Type/Name Consistency
- ✅ Action names konsisten: supplier (list/save/delete/options), PO (po_list/create/get/save_items/dipesan/terima/supplier_opts/bahan_opts)
- ✅ Tabel kolom konsisten T1 → T2/3/4
- ✅ no_po format PO/YYYY/MM/000N (generatePoNo)
- ✅ mutasi masuk: stok_sebelum+qty=stok_sesudah, harga_beli=harga_satuan (pola existing)
- ✅ FOR UPDATE idempotent (po_terima) — pola opname
- ✅ subtotal=qty×harga, total=Σsubtotal konsisten (save_items + render)

### Notes (verify saat implementasi)
- Task 2/5: layout HQ pattern (renderHead vs _layout_open), CSRF transport, helper JS — baca hq/keuangan.php / hq sibling.
- Task 3/5: pembelian.php pola dari inventori.php (CSRF, esc/fmtNum, $db acquisition).
- Task 4: INSERT hl_bahan_mutasi 11 kolom — verify match existing masuk/adjust di inventori.php (Task Stok Opname pakai 11 kolom: +harga_beli+supplier).
- Task 5: components.php navItem structure + group + icon map — verify pola existing (mis. inventori item).
