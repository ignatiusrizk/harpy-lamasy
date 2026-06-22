# Production App Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bangun `/produksi.php` — mobile-first worker app dengan 6 stage forms (terima, cuci, kering, setrika, siap, diambil) + QR scanner untuk shortcut load order.

**Architecture:** Single PHP file mengikuti pola pos.php/orders.php — action handlers di top, UI inline, JS inline. 1 tabel baru `hl_proses_input` simpan data per-stage. Scanner pakai html5-qrcode (host lokal di `/assets/`). Concurrency guard via `FOR UPDATE` lock + transition validation.

**Tech Stack:** PHP 8.1 + PDO + vanilla JS. html5-qrcode 2.3.8 (lokal). Reuse `core/TenantQuery`, `core/FileUpload`, `middleware/tenant_guard`.

## Global Constraints

- All endpoints pakai `tenant_guard.php` + `requirePermission('produksi.work')`.
- POST endpoints pakai `verifyCsrf()`; GET pakai tenant guard.
- All DB queries scoped via `tenant_id` + `outlet_id` (TenantQuery wrapper).
- Migration via SQL file di `superadmin/sql/`; juga append ke `tenant/migrations/tenant_schema.sql` untuk tenant baru.
- Foto upload via existing `core/FileUpload::uploadImage()` ke folder `uploads/foto_proses/`.
- html5-qrcode di-host lokal (`/assets/html5-qrcode.min.js`), bukan CDN — hindari SRI overhead + CDN compromise.
- Stage mapping (verbatim dari spec):
  - `STAGE_FROM`: terima→null, cuci→masuk, kering→cuci, setrika→kering, siap→setrika, diambil→siap
  - `STAGE_TO`: terima→null, cuci→cuci, kering→kering, setrika→setrika, siap→siap, diambil→diambil
- Spec: [docs/superpowers/specs/2026-06-22-produksi-app-design.md](../specs/2026-06-22-produksi-app-design.md)

## File Structure

- **Create** `superadmin/sql/produksi_input_migration.sql` — CREATE TABLE hl_proses_input
- **Modify** `tenant/migrations/tenant_schema.sql` — append same CREATE TABLE untuk tenant baru
- **Modify** `core/TenantProvisioner.php` — tambah `produksi.work` di seedPermissions array
- **Modify** `components.php` — tambah item `produksi` di sidebar group `operasional` + JS helper `escAttr` (kalau belum ada)
- **Create** `assets/html5-qrcode.min.js` — host lokal lib (download dari npm)
- **Create** `produksi.php` — single file (skeleton + 5 actions + UI + JS)
- **Create** `uploads/foto_proses/.gitkeep` — placeholder folder

---

### Task 1: Migration `hl_proses_input` + apply ke prod

**Files:**
- Create: `superadmin/sql/produksi_input_migration.sql`
- Modify: `tenant/migrations/tenant_schema.sql` (append)

**Interfaces:**
- Produces: tabel `hl_proses_input` dengan kolom yang persis sama di kedua tempat.

- [ ] **Step 1: Buat file migration**

```sql
-- superadmin/sql/produksi_input_migration.sql
-- Tabel input form per-stage untuk /produksi.php

CREATE TABLE IF NOT EXISTS hl_proses_input (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  transaksi_id INT NOT NULL,
  stage VARCHAR(20) NOT NULL,
  karyawan_id INT NOT NULL,
  data_json JSON,
  foto_paths TEXT,
  catatan TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outlet_order (tenant_id, outlet_id, transaksi_id),
  INDEX idx_stage_time (stage, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Append same CREATE TABLE ke tenant_schema.sql**

Open `tenant/migrations/tenant_schema.sql`, append di akhir file (sebelum baris terakhir kalau ada terminator):

```sql

-- ── Production app input data (per-stage form submissions) ──
CREATE TABLE IF NOT EXISTS hl_proses_input (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  transaksi_id INT NOT NULL,
  stage VARCHAR(20) NOT NULL,
  karyawan_id INT NOT NULL,
  data_json JSON,
  foto_paths TEXT,
  catatan TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outlet_order (tenant_id, outlet_id, transaksi_id),
  INDEX idx_stage_time (stage, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 3: Apply ke remote DB**

```bash
mysql u269895997_harpy_master < superadmin/sql/produksi_input_migration.sql
mysql u269895997_harpy_master -e "DESCRIBE hl_proses_input;"
```

Expected output: 10 columns listed (id, tenant_id, outlet_id, transaksi_id, stage, karyawan_id, data_json, foto_paths, catatan, created_at).

- [ ] **Step 4: Commit**

```bash
git add superadmin/sql/produksi_input_migration.sql tenant/migrations/tenant_schema.sql
git commit -m "feat(produksi): migration hl_proses_input"
```

---

### Task 2: Permission seed + sidebar link

**Files:**
- Modify: `core/TenantProvisioner.php` line ~190 (in seedPermissions array)
- Modify: `components.php` line ~157 (di group 'operasional' items)

**Interfaces:**
- Produces:
  - Permission key `produksi.work` tersedia untuk role assignment
  - Sidebar item key `produksi` dengan URL `/produksi`

- [ ] **Step 1: Tambah permission di seedPermissions**

Locate `core/TenantProvisioner.php:156` array `$permissions`. Tambah baris baru di group operasional (cari grup `mesin.*` lalu sisipkan setelahnya):

```php
['mesin.manage',         'mesin',     'manage',        'Tambah/edit/hapus mesin & atur cycle'],
['produksi.work',        'produksi',  'work',          'Akses /produksi & update stage order'],
['laporan.view',         'laporan',   'view',          'Lihat laporan'],
```

- [ ] **Step 2: Backfill permission ke tenant existing**

```bash
mysql u269895997_harpy_master <<'SQL'
INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi, created_at)
SELECT DISTINCT tenant_id, 'produksi.work', 'produksi', 'work',
       'Akses /produksi & update stage order', NOW()
FROM hl_permissions
WHERE kode = 'orders.view_all';
SQL
mysql u269895997_harpy_master -e "SELECT COUNT(*) AS cnt FROM hl_permissions WHERE kode='produksi.work';"
```

Expected: cnt > 0 (jumlah tenant existing).

- [ ] **Step 3: Tambah sidebar item**

Open `components.php:148`. Cari array `'operasional'` items. Tambah `produksi` setelah `mesin`:

```php
'operasional' => [
    'label' => 'Operasional',
    'items' => [
        'pos'       => ['label'=>'POS',       'url'=>'/pos',       'perm'=>'pos.view'],
        'orders'    => ['label'=>'Order',     'url'=>'/orders',    'perms'=>['orders.view_all','orders.view_own']],
        'kanban'    => ['label'=>'Kanban',    'url'=>'/kanban',    'perms'=>['orders.view_all','orders.view_own']],
        'kas'       => ['label'=>'Kas',       'url'=>'/kas',       'perm'=>'kas.view'],
        'inventori' => ['label'=>'Inventori', 'url'=>'/inventori', 'perms'=>['inventori.view','kas.view']],
        'mesin'     => ['label'=>'Mesin Koin', 'url'=>'/mesin',     'perms'=>['mesin.view','pos.view']],
        'produksi'  => ['label'=>'🧺 Produksi','url'=>'/produksi',  'perm'=>'produksi.work'],
        'checklist' => ['label'=>'Checklist', 'url'=>'/checklist', 'perm'=>null],
    ],
],
```

- [ ] **Step 4: Verify backend permission via curl after deploy**

```bash
# Tidak login → harus redirect/403
curl -sI https://lamasy.harpy.id/produksi | head -5
```

Expected: 302 ke /login (atau 404 belum ada, OK karena page belum dibuat di Task 3).

- [ ] **Step 5: Commit**

```bash
git add core/TenantProvisioner.php components.php
git commit -m "feat(produksi): permission produksi.work + sidebar link"
```

---

### Task 3: produksi.php skeleton + page guard

**Files:**
- Create: `produksi.php`

**Interfaces:**
- Produces:
  - HTTP GET `/produksi.php` (no action) → render full HTML page
  - `$activePage = 'produksi'` set sebelum render header

- [ ] **Step 1: Buat file skeleton**

```php
<?php
// ══════════════════════════════════════════════════════
// produksi.php — Mobile-first worker app
//
// Stage forms: terima, cuci, kering, setrika, siap, diambil
// Actions: ?action=list|get_by_kode|mesin_list|upload_foto|save_stage
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';

requirePermission('produksi.work');

$tid    = TenantResolver::id();
$oid    = TenantResolver::outletId();
$userId = (int)(currentUser()['id'] ?? 0);
$db     = Database::get();

// Stage mapping
const STAGE_FROM = [
  'terima'  => null,
  'cuci'    => 'masuk',
  'kering'  => 'cuci',
  'setrika' => 'kering',
  'siap'    => 'setrika',
  'diambil' => 'siap',
];
const STAGE_TO = [
  'terima'  => null,
  'cuci'    => 'cuci',
  'kering'  => 'kering',
  'setrika' => 'setrika',
  'siap'    => 'siap',
  'diambil' => 'diambil',
];

$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    // Action handlers ditambahkan di Task 4-5
    echo json_encode(['error' => 'Action belum diimplementasi: ' . $action]);
    exit;
}

$activePage = 'produksi';
$pageTitle  = '🧺 Produksi';
require __DIR__ . '/components.php';
renderHead($pageTitle);
renderSidebar($activePage);
?>
<main class="ol-main">
  <div class="ol-content">
    <h1 style="margin:0 0 16px">🧺 Produksi</h1>
    <div id="produksiRoot">
      <p style="color:var(--gray)">Stub — UI ditambahkan di Task 6.</p>
    </div>
  </div>
</main>
<?php
renderFooter();
```

- [ ] **Step 2: Verify load di browser**

Push + deploy + login lalu buka `https://lamasy.harpy.id/produksi`. Harus muncul page dengan judul "🧺 Produksi" + stub text.

Manual verification — implementer document di report.

- [ ] **Step 3: Commit**

```bash
git add produksi.php
git commit -m "feat(produksi): skeleton page + permission guard"
```

---

### Task 4: Backend actions read-only (list, get_by_kode, mesin_list)

**Files:**
- Modify: `produksi.php` — ganti action stub di Step 1 Task 3 dengan handler nyata

**Interfaces:**
- Consumes: `STAGE_FROM`, `STAGE_TO` dari Task 3; `TenantQuery::raw`, `TenantResolver` existing.
- Produces:
  - `GET ?action=list&stage=X` → JSON array of orders matching stage filter
  - `GET ?action=get_by_kode&kode=X` → JSON order detail
  - `GET ?action=mesin_list&jenis=cuci|kering` → JSON array of available mesin

- [ ] **Step 1: Ganti stub di produksi.php**

Replace block:
```php
if ($action) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Action belum diimplementasi: ' . $action]);
    exit;
}
```

dengan:

```php
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $stage = $_GET['stage'] ?? 'masuk';
        // Map stage tab to status_proses filter
        $statusMap = [
            'terima'  => 'masuk',       // sama dengan masuk; differ by foto_paths existence
            'cuci'    => 'cuci',
            'kering'  => 'kering',
            'setrika' => 'setrika',
            'siap'    => 'siap',
            'diambil' => 'diambil',
        ];
        $statusFilter = $statusMap[$stage] ?? 'masuk';
        $rows = TenantQuery::raw(
            "SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon, t.total,
                    t.status_proses, t.tanggal, t.estimasi_selesai,
                    (SELECT COUNT(*) FROM hl_transaksi_item WHERE transaksi_id=t.id) AS jml_item
               FROM hl_transaksi t
              WHERE t.tenant_id=? AND t.outlet_id=? AND t.status_proses=?
              ORDER BY t.tanggal DESC LIMIT 100",
            [$tid, $oid, $statusFilter]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'get_by_kode') {
        $kode = trim($_GET['kode'] ?? '');
        if (!$kode) { echo json_encode(['error' => 'Kode kosong']); exit; }
        $order = TenantQuery::rawOne(
            "SELECT id, no_order, nama_pelanggan, telepon, total, status_proses, estimasi_selesai
               FROM hl_transaksi
              WHERE tenant_id=? AND outlet_id=? AND no_order=? LIMIT 1",
            [$tid, $oid, $kode]
        );
        if (!$order) { echo json_encode(['error' => 'Order tidak ditemukan']); exit; }
        echo json_encode($order);
        exit;
    }

    if ($action === 'mesin_list') {
        $jenis = $_GET['jenis'] ?? '';
        if (!in_array($jenis, ['cuci','kering'], true)) {
            echo json_encode(['error' => 'Jenis invalid']); exit;
        }
        $rows = TenantQuery::raw(
            "SELECT id, nama, kode FROM hl_mesin
              WHERE tenant_id=? AND outlet_id=? AND jenis=? AND status!='maintenance'
              ORDER BY nama",
            [$tid, $oid, $jenis]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action: ' . $action]);
    exit;
}
```

- [ ] **Step 2: Test via DevTools fetch (after deploy)**

Dari browser tab yang sudah login, di DevTools:
```js
fetch('/produksi.php?action=list&stage=cuci').then(r=>r.json()).then(console.log);
fetch('/produksi.php?action=mesin_list&jenis=cuci').then(r=>r.json()).then(console.log);
fetch('/produksi.php?action=get_by_kode&kode=NONEXIST').then(r=>r.json()).then(console.log);
```

Expected:
- list: `{rows: [...]}` array (possibly empty kalau belum ada order status cuci)
- mesin_list: `{rows: [...]}` (atau empty kalau belum input mesin)
- get_by_kode invalid: `{error: "Order tidak ditemukan"}`

- [ ] **Step 3: Verify column `hl_mesin.jenis` exists**

```bash
mysql u269895997_harpy_master -e "DESCRIBE hl_mesin;" | grep -E "jenis|status"
```

Kalau kolom `jenis` tidak ada dengan value 'cuci'/'kering' (mungkin nama beda atau enum beda), adjust query di Step 1. **NEEDS_CONTEXT kalau struktur jauh berbeda — escalate ke controller.**

- [ ] **Step 4: Commit**

```bash
git add produksi.php
git commit -m "feat(produksi): backend actions list/get_by_kode/mesin_list"
```

---

### Task 5: Backend save_stage + upload_foto (transactional)

**Files:**
- Modify: `produksi.php` — tambah 2 action handlers
- Create: `uploads/foto_proses/.gitkeep`

**Interfaces:**
- Consumes: STAGE_FROM, STAGE_TO, FileUpload class existing.
- Produces:
  - `POST ?action=upload_foto` (multipart) → `{ok:true, path:"uploads/foto_proses/..."}` atau `{error}`
  - `POST ?action=save_stage` (JSON body) → `{ok:true}` atau `{error}` dengan transaction + race guard

- [ ] **Step 1: Buat folder upload**

```bash
mkdir -p uploads/foto_proses
touch uploads/foto_proses/.gitkeep
```

- [ ] **Step 2: Tambah action upload_foto sebelum block `echo json_encode(['error'=>'Unknown action'])`**

```php
if ($action === 'upload_foto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    require_once ROOT . '/core/FileUpload.php';
    $f = $_FILES['foto'] ?? null;
    if (!$f) { echo json_encode(['error'=>'File foto tidak ditemukan']); exit; }
    $res = FileUpload::uploadImage($f, 'uploads/foto_proses', 't' . $tid . '_o' . $oid);
    if (!empty($res['error'])) { echo json_encode(['error' => $res['error']]); exit; }
    echo json_encode(['ok' => true, 'path' => $res['path']]);
    exit;
}
```

- [ ] **Step 3: Tambah action save_stage**

```php
if ($action === 'save_stage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $transaksiId = (int)($d['transaksi_id'] ?? 0);
    $stage       = $d['stage'] ?? '';
    $dataFields  = $d['data'] ?? [];
    $fotoPaths   = $d['foto'] ?? [];          // array of paths
    $catatan     = trim($d['catatan'] ?? '');
    $signature   = $d['signature'] ?? '';     // data URL untuk stage diambil

    if ($transaksiId <= 0 || !array_key_exists($stage, STAGE_FROM)) {
        echo json_encode(['error' => 'Input tidak valid']); exit;
    }

    // Decode signature dataURL → save as PNG → append to foto_paths
    if ($signature && preg_match('/^data:image\/png;base64,(.+)$/', $signature, $m)) {
        $bin = base64_decode($m[1]);
        if ($bin !== false && strlen($bin) < 1000000) { // 1MB cap
            $fn = 'uploads/foto_proses/sig_t' . $tid . '_o' . $oid . '_' . bin2hex(random_bytes(8)) . '.png';
            if (file_put_contents(ROOT . '/' . $fn, $bin) !== false) {
                $fotoPaths[] = $fn;
            }
        }
    }

    try {
        $db->beginTransaction();
        $st = $db->prepare(
            "SELECT status_proses FROM hl_transaksi
              WHERE id=? AND tenant_id=? AND outlet_id=? FOR UPDATE"
        );
        $st->execute([$transaksiId, $tid, $oid]);
        $current = $st->fetchColumn();
        if ($current === false) { throw new Exception('Order tidak ditemukan'); }

        $expectedFrom = STAGE_FROM[$stage];
        if ($expectedFrom !== null && $current !== $expectedFrom) {
            throw new Exception('Order sudah diupdate worker lain. Refresh halaman.');
        }

        // Insert input record
        TenantQuery::insert('hl_proses_input', [
            'transaksi_id' => $transaksiId,
            'stage'        => $stage,
            'karyawan_id'  => $userId,
            'data_json'    => json_encode($dataFields),
            'foto_paths'   => implode(',', $fotoPaths),
            'catatan'      => $catatan,
        ]);

        // Update status (kecuali stage 'terima')
        $newStatus = STAGE_TO[$stage];
        if ($newStatus !== null) {
            $upd = $db->prepare(
                "UPDATE hl_transaksi SET status_proses=?, updated_at=NOW()
                  WHERE id=? AND tenant_id=? AND outlet_id=?"
            );
            $upd->execute([$newStatus, $transaksiId, $tid, $oid]);

            // Log status change
            $logSt = $db->prepare(
                "INSERT INTO hl_proses_log (transaksi_id, status_lama, status_baru, by_user, by_user_id, catatan, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $byName = currentUser()['nama'] ?? '';
            $logSt->execute([
                $transaksiId, $current, $newStatus, $byName, $userId,
                'Stage ' . $stage . ' via /produksi'
            ]);
        }

        logAudit('proses_stage', 'transaksi', "id={$transaksiId} stage={$stage}");
        $db->commit();
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[produksi save_stage] ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
```

- [ ] **Step 4: Verify hl_proses_log columns match**

```bash
mysql u269895997_harpy_master -e "DESCRIBE hl_proses_log;"
```

Cek kolom `by_user`, `by_user_id`, `catatan` ada. **Kalau kolom beda nama (mis. `user_id` instead of `by_user_id`), adjust INSERT statement di Step 3. NEEDS_CONTEXT escalate kalau struktur jauh beda.**

- [ ] **Step 5: Test via curl after deploy**

Test happy path (butuh CSRF token + session):
```js
// di DevTools tab yang sudah login
const csrf = document.querySelector('meta[name=csrf-token]').content;
fetch('/produksi.php?action=save_stage', {
  method:'POST',
  headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
  body: JSON.stringify({transaksi_id: 1, stage: 'terima', data:{}, foto:[], catatan:'test'})
}).then(r=>r.json()).then(console.log);
```

Expected: `{ok: true}` jika order id 1 ada. Cek DB:
```bash
mysql u269895997_harpy_master -e "SELECT * FROM hl_proses_input ORDER BY id DESC LIMIT 1;"
```

- [ ] **Step 6: Commit**

```bash
git add produksi.php uploads/foto_proses/.gitkeep
git commit -m "feat(produksi): backend save_stage + upload_foto (transactional)"
```

---

### Task 6: Frontend UI shell (stage tabs + card list)

**Files:**
- Modify: `produksi.php` — ganti stub UI dengan stage tabs + card list + JS loader

**Interfaces:**
- Consumes: action=list, action=mesin_list (Task 4)
- Produces: variabel JS global `currentStage`, `loadCards()`, `openStageModal(orderId)` (modal handler defined in Task 7).

- [ ] **Step 1: Replace UI block di produksi.php**

Find:
```html
<main class="ol-main">
  <div class="ol-content">
    <h1 style="margin:0 0 16px">🧺 Produksi</h1>
    <div id="produksiRoot">
      <p style="color:var(--gray)">Stub — UI ditambahkan di Task 6.</p>
    </div>
  </div>
</main>
```

Replace dengan:

```html
<main class="ol-main">
  <div class="ol-content">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h1 style="margin:0">🧺 Produksi</h1>
      <button class="btn btn-primary" onclick="startScan()">📷 Scan QR</button>
    </div>

    <!-- Stage tabs -->
    <div id="stageTabs" style="display:flex;gap:6px;overflow-x:auto;padding-bottom:8px;margin-bottom:12px;-webkit-overflow-scrolling:touch">
      <button class="stage-tab active" data-stage="terima"  onclick="switchStage('terima')">📥 Terima <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="cuci"    onclick="switchStage('cuci')">🫧 Cuci <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="kering"  onclick="switchStage('kering')">💨 Kering <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="setrika" onclick="switchStage('setrika')">👔 Setrika <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="siap"    onclick="switchStage('siap')">✅ Siap <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="diambil" onclick="switchStage('diambil')">📦 Diambil <span class="cnt"></span></button>
    </div>

    <!-- Card list -->
    <div id="cardList" style="display:grid;gap:10px;grid-template-columns:1fr">
      <div style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat...</div>
    </div>

    <!-- Modal stage form (filled di Task 7) -->
    <div id="stageModal" class="modal-overlay" style="display:none">
      <div class="modal-box" style="max-width:480px;max-height:90vh;overflow-y:auto">
        <div id="stageModalBody"></div>
      </div>
    </div>

    <!-- Modal scanner (filled di Task 8) -->
    <div id="scanModal" class="modal-overlay" style="display:none">
      <div class="modal-box" style="max-width:480px">
        <h3 style="margin:0 0 12px">📷 Scan QR Order</h3>
        <div id="scanArea" style="width:100%;min-height:300px"></div>
        <button class="btn" onclick="stopScan()" style="margin-top:12px;width:100%">Batal</button>
      </div>
    </div>
  </div>
</main>

<style>
.stage-tab { padding:8px 14px;border:1px solid var(--off);background:#fff;border-radius:100px;font-size:13px;font-weight:600;white-space:nowrap;cursor:pointer; }
.stage-tab.active { background:var(--teal);color:#fff;border-color:var(--teal); }
.stage-tab .cnt { display:inline-block;margin-left:4px;background:rgba(0,0,0,.08);padding:1px 7px;border-radius:100px;font-size:11px; }
.stage-tab.active .cnt { background:rgba(255,255,255,.25); }
.order-card { background:#fff;border:1px solid var(--off);border-radius:12px;padding:12px 14px;cursor:pointer;transition:border .2s; }
.order-card:active { border-color:var(--teal); }
</style>

<script>
let currentStage = 'terima';
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';

function switchStage(stage) {
  currentStage = stage;
  document.querySelectorAll('.stage-tab').forEach(b => b.classList.toggle('active', b.dataset.stage === stage));
  loadCards();
}

async function loadCards() {
  const list = document.getElementById('cardList');
  list.innerHTML = '<div style="text-align:center;padding:30px;color:var(--gray)">⏳ Memuat...</div>';
  try {
    const r = await fetch('/produksi.php?action=list&stage=' + currentStage);
    const d = await r.json();
    if (d.error) { list.innerHTML = '<div style="padding:20px;color:var(--red)">❌ ' + d.error + '</div>'; return; }
    if (!d.rows.length) {
      list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray)">Tidak ada order di stage ini</div>';
      return;
    }
    list.innerHTML = d.rows.map(r => `
      <div class="order-card" onclick="openStageModal(${r.id})">
        <div style="font-weight:700;font-size:15px">#${r.no_order} · ${esc(r.nama_pelanggan||'(no name)')}</div>
        <div style="color:var(--gray);font-size:13px;margin-top:3px">${r.jml_item} item · Rp ${Number(r.total||0).toLocaleString('id-ID')}</div>
        <div style="margin-top:8px"><span class="badge b-${r.status_proses}">${r.status_proses}</span></div>
      </div>`).join('');
  } catch (e) {
    list.innerHTML = '<div style="padding:20px;color:var(--red)">❌ Network error: ' + e.message + '</div>';
  }
}

function openStageModal(orderId) {
  // Implementation di Task 7
  alert('Stage modal akan diisi di Task 7. Order id: ' + orderId);
}

function startScan() {
  // Implementation di Task 8
  alert('Scanner akan diisi di Task 8');
}
function stopScan() {
  document.getElementById('scanModal').style.display = 'none';
}

// Initial load
loadCards();
</script>
```

- [ ] **Step 2: Verify di browser**

Push + deploy. Buka /produksi. Harus muncul:
- Header dengan tombol Scan QR
- 6 stage tab (Terima aktif default)
- Card list memuat order dengan status_proses='masuk'
- Klik card → alert "Stage modal akan diisi di Task 7"

- [ ] **Step 3: Commit**

```bash
git add produksi.php
git commit -m "feat(produksi): UI shell stage tabs + card list"
```

---

### Task 7: Stage forms (6 modal templates + submit handlers)

**Files:**
- Modify: `produksi.php` — replace `openStageModal()` stub dengan full implementation; tambah HTML template functions

**Interfaces:**
- Consumes: `loadCards()`, `CSRF` dari Task 6; `upload_foto`, `save_stage` dari Task 5.
- Produces: function `openStageModal(orderId)` open modal sesuai status_proses order.

- [ ] **Step 1: Replace openStageModal stub**

Replace block:
```js
function openStageModal(orderId) {
  // Implementation di Task 7
  alert('Stage modal akan diisi di Task 7. Order id: ' + orderId);
}
```

dengan:

```js
let mesinCache = { cuci: null, kering: null };

async function getMesinList(jenis) {
  if (mesinCache[jenis]) return mesinCache[jenis];
  const r = await fetch('/produksi.php?action=mesin_list&jenis=' + jenis);
  const d = await r.json();
  mesinCache[jenis] = d.rows || [];
  return mesinCache[jenis];
}

function openStageModal(orderId) {
  // Stage diambil dari tab aktif (currentStage). Tidak perlu fetch ulang —
  // form input tidak butuh detail order; submit-nya hanya kirim orderId + stage.
  const body = document.getElementById('stageModalBody');
  body.innerHTML = renderStageForm(currentStage, orderId);
  document.getElementById('stageModal').style.display = 'flex';
}

function closeStageModal() {
  document.getElementById('stageModal').style.display = 'none';
}

function renderStageForm(stage, orderId) {
  const head = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h3 style="margin:0">${stageTitle(stage)}</h3>
    <button onclick="closeStageModal()" style="background:none;border:none;font-size:24px;cursor:pointer">×</button>
  </div>
  <input type="hidden" id="f_orderId" value="${orderId}">
  <input type="hidden" id="f_stage" value="${stage}">`;

  if (stage === 'terima') {
    return head + `
      <label>Foto Kondisi (max 3)</label>
      <input type="file" accept="image/*" capture="environment" multiple onchange="onFotoPick(this)" id="f_foto">
      <div id="fotoPreview" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"></div>
      <label>Catatan Kondisi</label>
      <textarea id="f_catatan" rows="3" placeholder="Noda, robek, atau hal khusus..."></textarea>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">💾 Simpan Dokumentasi</button>`;
  }

  if (stage === 'cuci') {
    return head + `
      <label>Mesin Cuci</label>
      <select id="f_mesin"><option value="">-- Pilih --</option></select>
      <label style="margin-top:10px">Berat Masuk (kg)</label>
      <input type="number" step="0.1" min="0" id="f_berat" placeholder="5.0">
      <label style="margin-top:10px">Program</label>
      <select id="f_program">
        <option value="putih">Putih</option>
        <option value="berwarna">Berwarna</option>
        <option value="halus">Halus</option>
        <option value="jeans">Jeans</option>
      </select>
      <label style="margin-top:10px">Catatan</label>
      <textarea id="f_catatan" rows="2"></textarea>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">▶ Mulai Cuci</button>`;
  }

  if (stage === 'kering') {
    return head + `
      <label>Mesin Pengering</label>
      <select id="f_mesin"><option value="">-- Pilih --</option></select>
      <label style="margin-top:10px">Durasi Target (menit)</label>
      <input type="number" min="1" id="f_durasi" placeholder="45">
      <label style="margin-top:10px">Suhu</label>
      <select id="f_suhu">
        <option value="rendah">Rendah</option>
        <option value="sedang" selected>Sedang</option>
        <option value="tinggi">Tinggi</option>
      </select>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">▶ Mulai Kering</button>`;
  }

  if (stage === 'setrika') {
    return head + `
      <label>Foto Hasil (opsional)</label>
      <input type="file" accept="image/*" capture="environment" multiple onchange="onFotoPick(this)" id="f_foto">
      <div id="fotoPreview" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"></div>
      <label>Catatan</label>
      <textarea id="f_catatan" rows="2"></textarea>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">▶ Mulai Setrika</button>`;
  }

  if (stage === 'siap') {
    return head + `
      <label>Lokasi Rak / Nomor Plastik</label>
      <input type="text" id="f_lokasi" maxlength="50" placeholder="Rak A-12 / Plastik #5">
      <label style="margin-top:10px">Foto Packing (opsional)</label>
      <input type="file" accept="image/*" capture="environment" multiple onchange="onFotoPick(this)" id="f_foto">
      <div id="fotoPreview" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"></div>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">✅ Tandai Siap</button>`;
  }

  if (stage === 'diambil') {
    return head + `
      <label>Foto Serah Terima (wajib)</label>
      <input type="file" accept="image/*" capture="environment" onchange="onFotoPick(this)" id="f_foto" required>
      <div id="fotoPreview" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"></div>
      <label style="margin-top:10px">Tanda Tangan</label>
      <canvas id="sigCanvas" width="400" height="120" style="border:1px solid var(--off);border-radius:8px;width:100%;touch-action:none"></canvas>
      <button onclick="clearSig()" style="margin-top:4px;font-size:12px">Bersihkan TTD</button>
      <label style="margin-top:10px">Catatan</label>
      <textarea id="f_catatan" rows="2"></textarea>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">📦 Tandai Diambil</button>`;
  }

  return head + '<p style="color:var(--red)">Stage tidak dikenali</p>';
}

function stageTitle(s) {
  return {
    'terima': '📥 Terima Cucian',
    'cuci': '🫧 Mulai Cuci',
    'kering': '💨 Mulai Kering',
    'setrika': '👔 Mulai Setrika',
    'siap': '✅ Tandai Siap',
    'diambil': '📦 Tandai Diambil',
  }[s] || s;
}

// ── Foto upload state
let uploadedFoto = [];

async function onFotoPick(input) {
  uploadedFoto = [];
  document.getElementById('fotoPreview').innerHTML = '⏳ Upload...';
  for (const f of input.files) {
    const fd = new FormData();
    fd.append('foto', f);
    fd.append('_csrf', CSRF);
    const r = await fetch('/produksi.php?action=upload_foto', {
      method:'POST', headers:{'X-CSRF-Token':CSRF}, body: fd
    });
    const d = await r.json();
    if (d.ok) uploadedFoto.push(d.path);
  }
  document.getElementById('fotoPreview').innerHTML = uploadedFoto.map(p =>
    `<img src="/${p}" style="width:64px;height:64px;object-fit:cover;border-radius:6px">`
  ).join('');
}

// ── Signature canvas (stage 5)
function setupSig() {
  const c = document.getElementById('sigCanvas');
  if (!c) return;
  const ctx = c.getContext('2d');
  ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round';
  let drawing = false;
  const pos = (e) => {
    const rect = c.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    return {x: (t.clientX - rect.left) * c.width / rect.width,
            y: (t.clientY - rect.top) * c.height / rect.height};
  };
  const start = (e) => { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); };
  const move  = (e) => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); };
  const end   = () => { drawing = false; };
  c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); c.addEventListener('mouseup', end);
  c.addEventListener('touchstart', start, {passive:false}); c.addEventListener('touchmove', move, {passive:false}); c.addEventListener('touchend', end);
}
function clearSig() {
  const c = document.getElementById('sigCanvas');
  if (c) c.getContext('2d').clearRect(0,0,c.width,c.height);
}

// Wrap openStageModal: render dulu (sync), lalu populate mesin dropdown + setup signature (async)
const origOpen = openStageModal;
openStageModal = async function(orderId) {
  origOpen(orderId);
  uploadedFoto = [];

  // Populate mesin dropdown kalau form punya field mesin
  const mesinEl = document.getElementById('f_mesin');
  if (mesinEl) {
    const jenis = (currentStage === 'cuci') ? 'cuci' : 'kering';
    const mesins = await getMesinList(jenis);
    mesinEl.innerHTML = '<option value="">-- Pilih --</option>' +
      mesins.map(m => `<option value="${m.id}">${esc(m.nama)} (${esc(m.kode||'')})</option>`).join('');
  }

  // Setup signature canvas kalau stage = diambil
  setupSig();
};

async function submitStage() {
  const orderId = parseInt(document.getElementById('f_orderId').value);
  const stage   = document.getElementById('f_stage').value;
  const catatan = document.getElementById('f_catatan')?.value || '';
  const data = {};
  if (document.getElementById('f_mesin'))   data.mesin_id = document.getElementById('f_mesin').value;
  if (document.getElementById('f_berat'))   data.berat    = document.getElementById('f_berat').value;
  if (document.getElementById('f_program')) data.program  = document.getElementById('f_program').value;
  if (document.getElementById('f_durasi'))  data.durasi   = document.getElementById('f_durasi').value;
  if (document.getElementById('f_suhu'))    data.suhu     = document.getElementById('f_suhu').value;
  if (document.getElementById('f_lokasi'))  data.lokasi   = document.getElementById('f_lokasi').value;

  let signature = '';
  const sig = document.getElementById('sigCanvas');
  if (sig) signature = sig.toDataURL('image/png');

  if (stage === 'diambil' && uploadedFoto.length === 0) {
    alert('Foto serah terima wajib diisi.'); return;
  }

  const r = await fetch('/produksi.php?action=save_stage', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({transaksi_id: orderId, stage, data, foto: uploadedFoto, catatan, signature})
  });
  const d = await r.json();
  if (d.ok) {
    closeStageModal();
    loadCards();
  } else {
    alert('❌ ' + (d.error || 'Gagal simpan'));
  }
}

function esc(s){return String(s||'').replace(/[<>&"]/g,c=>({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));}
```

- [ ] **Step 2: Manual smoke test (after deploy)**

1. Create order baru via /pos → status 'masuk'
2. Buka /produksi → tab Terima → klik card → modal Terima muncul
3. Upload foto + catatan → Simpan → modal close, card refresh
4. Verify hl_proses_input row baru di DB
5. Klik tab Cuci → klik card yang sama → modal Cuci → pilih mesin + program → submit → status berubah 'cuci', card pindah ke tab Cuci
6. Repeat sampai 'diambil' — tes signature canvas drawing + foto wajib
7. Test race: 2 browser submit stage cuci sama → 1 ok, 1 error

- [ ] **Step 3: Commit**

```bash
git add produksi.php
git commit -m "feat(produksi): 6 stage forms + foto upload + signature canvas"
```

---

### Task 8: Scanner integration (host lokal lib + decode flow)

**Files:**
- Create: `assets/html5-qrcode.min.js` (downloaded)
- Modify: `produksi.php` — replace `startScan()` stub dengan implementation + tambah `<script>` tag local

**Interfaces:**
- Consumes: `openStageModal(orderId)` dari Task 7; action=get_by_kode dari Task 4.
- Produces: working camera scanner.

- [ ] **Step 1: Download html5-qrcode lokal**

```bash
mkdir -p assets
curl -sL https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js -o assets/html5-qrcode.min.js
wc -c assets/html5-qrcode.min.js
```

Expected: file size ~250KB.

- [ ] **Step 2: Tambah script tag di produksi.php `<head>` area**

Karena pakai renderHead() yang sudah lock head, sisipkan script di body sebelum existing `<script>` block:

```html
<script src="/assets/html5-qrcode.min.js?v=<?= @filemtime(__DIR__ . '/assets/html5-qrcode.min.js') ?: '1' ?>"></script>
```

- [ ] **Step 3: Replace startScan stub di JS**

Replace:
```js
function startScan() {
  alert('Scanner akan diisi di Task 8');
}
```

dengan:

```js
let qrInstance = null;

async function startScan() {
  document.getElementById('scanModal').style.display = 'flex';
  try {
    qrInstance = new Html5Qrcode("scanArea");
    await qrInstance.start(
      {facingMode: "environment"},
      {fps: 10, qrbox: 250},
      async (decoded) => {
        await stopScan();
        // Extract no_order: URL param atau bare kode
        const m = decoded.match(/order=([A-Z0-9-]+)/i) || decoded.match(/^([A-Z0-9-]{3,})$/i);
        if (!m) { alert('QR tidak dikenali: ' + decoded.slice(0, 60)); return; }
        const r = await fetch('/produksi.php?action=get_by_kode&kode=' + encodeURIComponent(m[1]));
        const order = await r.json();
        if (order.error) { alert('❌ ' + order.error); return; }
        // Set currentStage berdasarkan status_proses order, lalu open modal
        const stageMap = {'masuk':'terima','cuci':'kering','kering':'setrika','setrika':'siap','siap':'diambil'};
        const nextStage = stageMap[order.status_proses] || order.status_proses;
        currentStage = nextStage;
        // Sync tab UI
        document.querySelectorAll('.stage-tab').forEach(b => b.classList.toggle('active', b.dataset.stage === nextStage));
        await openStageModal(order.id);
      },
      () => {} // silent scan errors
    );
  } catch (e) {
    alert('Tidak bisa akses kamera: ' + e.message + '\n\nGunakan input manual no_order.');
    stopScan();
    const kode = prompt('Input no_order manual:');
    if (kode) {
      const r = await fetch('/produksi.php?action=get_by_kode&kode=' + encodeURIComponent(kode));
      const order = await r.json();
      if (order.error) { alert(order.error); return; }
      const stageMap = {'masuk':'terima','cuci':'kering','kering':'setrika','setrika':'siap','siap':'diambil'};
      currentStage = stageMap[order.status_proses] || order.status_proses;
      document.querySelectorAll('.stage-tab').forEach(b => b.classList.toggle('active', b.dataset.stage === currentStage));
      await openStageModal(order.id);
    }
  }
}

async function stopScan() {
  if (qrInstance) {
    try { await qrInstance.stop(); } catch {}
    qrInstance = null;
  }
  document.getElementById('scanModal').style.display = 'none';
}
```

- [ ] **Step 4: Manual smoke test**

1. /produksi → klik Scan QR → modal kamera muncul, prompt permission
2. Allow camera → kamera belakang aktif
3. Arahkan ke QR di struk yang baru di-print → decoded → modal stage muncul sesuai status order
4. Test fallback: tolak camera permission → prompt manual input muncul
5. Test invalid QR (text random) → alert "QR tidak dikenali"

- [ ] **Step 5: Commit**

```bash
git add assets/html5-qrcode.min.js produksi.php
git commit -m "feat(produksi): QR scanner via html5-qrcode (host lokal)"
```

---

## Self-Review Checklist (untuk implementer)

- [ ] Migration applied di production + tenant_schema.sql updated.
- [ ] Permission `produksi.work` muncul di /hq/roles atau /settings setelah seed.
- [ ] Sidebar item `🧺 Produksi` muncul untuk role dengan permission, hidden tanpa permission.
- [ ] `requirePermission('produksi.work')` di top produksi.php memblokir akses tanpa permission.
- [ ] CSRF token validated di POST endpoints.
- [ ] FOR UPDATE lock + transition validation di save_stage berfungsi (test race).
- [ ] Signature canvas decode → save PNG file → path masuk ke foto_paths.
- [ ] Scanner kerja di Chrome mobile (camera permission required + HTTPS).
- [ ] Scanner kerja di Safari iOS (Safari paling strict).
- [ ] Foto folder writable: `chmod 755 uploads/foto_proses` di prod.

## Out of scope (jangan dikerjakan di plan ini)

- Push notification ke worker
- PWA install banner / offline mode
- Assign mode (admin pilih worker)
- Field schema configurable per tenant
- Label sticker print dedicated (Plan B terpisah)
- Worker performance dashboard
- Bulk action multi-order
