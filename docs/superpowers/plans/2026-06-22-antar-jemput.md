# Antar Jemput Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sistem antar jemput laundry — staff input request, owner assign kurir, kurir mobile update status, report harian, dengan akses role-based.

**Architecture:** 3 halaman PHP baru (`/antar-jemput` dispatcher, `/kurir` mobile, `/kurir-master`) + integrasi ke `/pos`, `/produksi`, `/track`. 3 tabel baru + 1 ALTER outlets. Role `kurir` baru. Permissions `antar.view/manage/kurir` di seed TenantProvisioner.

**Tech Stack:** PHP 8.1 + PDO + vanilla JS. Reuse `TenantQuery`, `FileUpload`, signature canvas pattern dari produksi.

## Global Constraints

- All endpoints pakai `tenant_guard.php` + permission check via `requirePermission()`.
- POST endpoints pakai `verifyCsrf()`.
- DB queries scoped via `tenant_id` + `outlet_id`.
- Migration SQL di `superadmin/sql/`; tenant_schema.sql untuk tenant baru.
- Foto upload via `core/FileUpload::uploadImage()` ke `uploads/foto_antar/`.
- Status flow: `pending → assigned → menuju → sampai → done` (+ `cancel` di state apa saja).
- Tipe enum: `jemput | antar` (bukan pickup/delivery).
- Terminologi UI: "Antar Jemput" (page), "Jemput" + "Antar" (tabs), "Kurir" (worker).
- Concurrency: `FOR UPDATE` lock saat assign/status change (race guard).
- Audit log per action: `antar_create`, `antar_assign`, `antar_status`, `antar_done`.
- Spec: [docs/superpowers/specs/2026-06-22-antar-jemput-design.md](../specs/2026-06-22-antar-jemput-design.md)
- mysql binary: `/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master`

## File Structure

- **Create** `superadmin/sql/antar_jemput_migration.sql` — 3 CREATE TABLE + ALTER outlets
- **Modify** `tenant/migrations/tenant_schema.sql` — append same DDL
- **Modify** `core/TenantProvisioner.php` — role `kurir` + 3 permissions + allowlist
- **Modify** `components.php` — 2 sidebar items + iconMap
- **Modify** `login.php` — redirect role=kurir ke /kurir
- **Create** `antar-jemput.php` — dispatcher page (action handlers + UI + JS)
- **Create** `kurir.php` — mobile worker page
- **Create** `kurir-master.php` — CRUD kurir + create user account
- **Modify** `outlet-settings.php` — antar_mode radio + zona CRUD section
- **Modify** `pos.php` — checkbox antar + alamat fields + auto-create row
- **Modify** `produksi.php` — auto-create antar row saat jenis=diantarkan
- **Modify** `track.php` — section status kurir + bukti
- **Create** `uploads/foto_antar/.gitkeep`
- **Modify** `.htaccess` — whitelist /antar-jemput, /kurir, /kurir-master rewrites

---

### Task 1: Migration — 3 tables + ALTER outlets

**Files:**
- Create: `superadmin/sql/antar_jemput_migration.sql`
- Modify: `tenant/migrations/tenant_schema.sql`

**Interfaces:**
- Produces: tables `hl_kurir`, `hl_antar_jemput`, `hl_zona_antar`; column `outlets.antar_mode`

- [ ] **Step 1: Buat file migration**

```sql
-- superadmin/sql/antar_jemput_migration.sql
-- Sistem Antar Jemput laundry

CREATE TABLE IF NOT EXISTS hl_kurir (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  user_id INT NULL,
  nama VARCHAR(100) NOT NULL,
  no_hp VARCHAR(20),
  kendaraan VARCHAR(50),
  aktif TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outlet_aktif (tenant_id, outlet_id, aktif),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_antar_jemput (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  tipe ENUM('jemput','antar') NOT NULL,
  transaksi_id INT NULL,
  pelanggan_id INT NULL,
  nama VARCHAR(100) NOT NULL,
  telepon VARCHAR(20),
  alamat TEXT NULL,
  zona_id INT NULL,
  fee INT DEFAULT 0,
  slot_waktu DATETIME NULL,
  kurir_id INT NULL,
  status ENUM('pending','assigned','menuju','sampai','done','cancel') DEFAULT 'pending',
  catatan TEXT,
  foto_bukti VARCHAR(255),
  signature_path VARCHAR(255),
  created_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  done_at DATETIME NULL,
  INDEX idx_outlet_status (tenant_id, outlet_id, status, created_at),
  INDEX idx_kurir_status (kurir_id, status),
  INDEX idx_transaksi (transaksi_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_zona_antar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  nama VARCHAR(60) NOT NULL,
  fee INT NOT NULL DEFAULT 0,
  aktif TINYINT(1) DEFAULT 1,
  INDEX idx_outlet_aktif (tenant_id, outlet_id, aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE outlets ADD COLUMN IF NOT EXISTS antar_mode ENUM('free','zona') DEFAULT 'free' AFTER label_size;
```

- [ ] **Step 2: Append same DDL ke tenant_schema.sql**

Append di akhir `tenant/migrations/tenant_schema.sql` (3 CREATE TABLE saja, tanpa ALTER outlets karena outlets schema beda).

- [ ] **Step 3: Apply ke prod DB**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master < superadmin/sql/antar_jemput_migration.sql
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master -e "DESCRIBE hl_kurir; DESCRIBE hl_antar_jemput; DESCRIBE hl_zona_antar; SHOW COLUMNS FROM outlets LIKE 'antar_mode';"
```

Expected: 3 tabel ada + kolom antar_mode di outlets.

- [ ] **Step 4: Commit**

```bash
git add superadmin/sql/antar_jemput_migration.sql tenant/migrations/tenant_schema.sql
git commit -m "feat(antar): migration hl_kurir + hl_antar_jemput + hl_zona_antar + antar_mode"
```

---

### Task 2: Role `kurir` + Permissions seed + Sidebar + Login redirect + Route whitelist

**Files:**
- Modify: `core/TenantProvisioner.php` (lines ~138-150 dan ~156-243)
- Modify: `components.php` (sidebar navGroups + iconMap)
- Modify: `login.php` (redirect role kurir)
- Modify: `.htaccess` (whitelist routes)

**Interfaces:**
- Produces:
  - Role `kurir` di hl_roles tenant baru
  - Permission keys: `antar.view`, `antar.manage`, `antar.kurir`
  - Sidebar items `antar-jemput` (group Operasional) + `kurir-master` (group Master)
  - iconMap: `antar-jemput=🚚`, `kurir-master=🛵`
  - Routes `/antar-jemput`, `/kurir`, `/kurir-master` accessible (extensionless)

- [ ] **Step 1: Tambah role kurir di TenantProvisioner**

Locate `seedRoles()` di `core/TenantProvisioner.php:138`. Tambah:

```php
            'kasir'    => ['Kasir',    'Input order & pembayaran saja',        1],
            'karyawan' => ['Karyawan', 'Absensi & update status order',        1],
            'kurir'    => ['Kurir',    'Akses /kurir untuk update antar jemput', 1],
        ];
```

- [ ] **Step 2: Tambah 3 permissions di seedPermissions array**

Cari array `$permissions` di `core/TenantProvisioner.php:156`. Tambah setelah `produksi.work`:

```php
['produksi.work',        'produksi',  'work',          'Akses /produksi & update stage order'],
['antar.view',           'antar',     'view',          'Lihat list antar jemput & report'],
['antar.manage',         'antar',     'manage',        'Create antar jemput, assign kurir, kelola master'],
['antar.kurir',          'antar',     'kurir',         'Akses /kurir mobile (untuk role kurir)'],
['laporan.view',         'laporan',   'view',          'Lihat laporan'],
```

- [ ] **Step 3: Tambah allowlist + role mapping**

Di `seedPermissions()` setelah definisi `$karyawanInclude`:

```php
        $kasirInclude   = [/* existing */, 'antar.view'];
        $karyawanInclude = [/* existing */, 'antar.view'];
        $kurirInclude   = ['antar.kurir'];
```

Update foreach loop tambah:

```php
            // Kurir: hanya antar.kurir
            if (in_array($kode, $kurirInclude)) {
                $stmtMap->execute([$tenantId, $roleIds['kurir'], $permId, 'all']);
            }
```

- [ ] **Step 4: Backfill ke tenant + role existing**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master <<'SQL'
-- Tambah role 'kurir' untuk semua tenant existing
INSERT IGNORE INTO hl_roles (tenant_id, nama, deskripsi, is_system)
SELECT DISTINCT tenant_id, 'Kurir', 'Akses /kurir untuk update antar jemput', 1
FROM hl_roles WHERE nama='Owner';

-- Backfill 3 permissions
INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi, created_at)
SELECT DISTINCT tenant_id, 'antar.view', 'antar', 'view', 'Lihat list antar jemput & report', NOW()
FROM hl_permissions WHERE kode='orders.view_all';

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi, created_at)
SELECT DISTINCT tenant_id, 'antar.manage', 'antar', 'manage', 'Create antar jemput, assign kurir, kelola master', NOW()
FROM hl_permissions WHERE kode='orders.view_all';

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi, created_at)
SELECT DISTINCT tenant_id, 'antar.kurir', 'antar', 'kurir', 'Akses /kurir mobile (untuk role kurir)', NOW()
FROM hl_permissions WHERE kode='orders.view_all';
SQL
```

- [ ] **Step 5: Sidebar items + iconMap di components.php**

Lokasi: `components.php` navGroups operasional & master.

```php
'operasional' => [
    'label' => 'Operasional',
    'items' => [
        // ... existing items
        'produksi'      => ['label'=>'Produksi',     'url'=>'/produksi',      'perm'=>'produksi.work'],
        'antar-jemput'  => ['label'=>'Antar Jemput', 'url'=>'/antar-jemput',  'perm'=>'antar.view'],
        'checklist'     => ['label'=>'Checklist',    'url'=>'/checklist',     'perm'=>null],
    ],
],
```

Group `master` tambah setelah customer:

```php
'kurir-master' => ['label'=>'Kurir', 'url'=>'/kurir-master', 'perm'=>'antar.manage'],
```

Update iconMap di `components.php:253`:

```php
'inventori'=>'🧴','mesin'=>'🪙','produksi'=>'🧺',
'antar-jemput'=>'🚚','kurir-master'=>'🛵',
```

- [ ] **Step 6: Login redirect role kurir**

Locate `login.php` setelah `$_SESSION` set + sebelum redirect to dashboard. Cari pattern `header('Location: /dashboard')` atau similar. Tambah:

```php
// Kurir → mobile page langsung
if (($user['role'] ?? '') === 'kurir') {
    header('Location: /kurir');
    exit;
}
```

Letakkan sebelum redirect dashboard default. Read login.php first untuk lokasi tepat.

- [ ] **Step 7: .htaccess route whitelist**

Tambah ke 2 rewrite rules (sama pattern dgn /produksi sebelumnya):

```
RewriteRule ^(...|produksi|antar-jemput|kurir|kurir-master)\.php$ /$1 [R=301,L]
RewriteRule ^(...|produksi|antar-jemput|kurir|kurir-master)$ $1.php [L,QSA]
```

Edit alfabet list yang sudah ada — append `|antar-jemput|kurir|kurir-master` ke kedua list.

- [ ] **Step 8: Commit**

```bash
git add core/TenantProvisioner.php components.php login.php .htaccess
git commit -m "feat(antar): role kurir + 3 permissions + sidebar + login redirect + routes"
```

---

### Task 3: Master Kurir page `/kurir-master`

**Files:**
- Create: `kurir-master.php`

**Interfaces:**
- Consumes: tabel `hl_kurir`, `hl_users` (existing); `requirePermission('antar.manage')`
- Produces:
  - GET `?action=list` → JSON list kurir + status akun
  - POST `?action=save` → create/update kurir record
  - POST `?action=create_account` → generate `hl_users` row role=kurir + password random
  - POST `?action=toggle_aktif` → flip kurir.aktif
  - UI: page render list + tombol tambah/edit + assign akun

- [ ] **Step 1: Buat skeleton + list action**

```php
<?php
// kurir-master.php — Master kurir untuk Antar Jemput
$activePage = 'kurir-master';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';
require_once __DIR__ . '/components.php';

requirePermission('antar.manage');
$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$db  = Database::get();

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $rows = TenantQuery::raw(
            "SELECT k.*, u.username AS akun_username
               FROM hl_kurir k
          LEFT JOIN hl_users u ON u.id = k.user_id
              WHERE k.tenant_id=? AND k.outlet_id=?
              ORDER BY k.aktif DESC, k.nama ASC",
            [$tid, $oid]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id   = (int)($d['id'] ?? 0);
        $nama = substr(trim($d['nama'] ?? ''), 0, 100);
        $hp   = substr(trim($d['no_hp'] ?? ''), 0, 20);
        $kdr  = substr(trim($d['kendaraan'] ?? ''), 0, 50);

        if (!$nama) { echo json_encode(['error'=>'Nama wajib']); exit; }

        if ($id > 0) {
            $st = $db->prepare("UPDATE hl_kurir SET nama=?, no_hp=?, kendaraan=? WHERE id=? AND tenant_id=? AND outlet_id=?");
            $st->execute([$nama, $hp, $kdr, $id, $tid, $oid]);
            logAudit('update', 'kurir', "id=$id nama=$nama");
        } else {
            TenantQuery::insert('hl_kurir', ['nama'=>$nama, 'no_hp'=>$hp, 'kendaraan'=>$kdr, 'outlet_id'=>$oid]);
            logAudit('create', 'kurir', "nama=$nama");
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'toggle_aktif' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        $st = $db->prepare("UPDATE hl_kurir SET aktif=1-aktif WHERE id=? AND tenant_id=? AND outlet_id=?");
        $st->execute([$id, $tid, $oid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'create_account' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);

        $kurir = TenantQuery::rawOne("SELECT id, nama, no_hp, user_id FROM hl_kurir WHERE id=? AND tenant_id=? AND outlet_id=?", [$id, $tid, $oid]);
        if (!$kurir) { echo json_encode(['error'=>'Kurir tidak ditemukan']); exit; }
        if ($kurir['user_id']) { echo json_encode(['error'=>'Kurir sudah punya akun']); exit; }

        // Generate username dari nama (slugify) + 3 digit random
        $base = preg_replace('/[^a-z0-9]/', '', strtolower($kurir['nama']));
        $username = substr($base, 0, 8) . rand(100,999);
        $password = bin2hex(random_bytes(4)); // 8 char
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        // Insert ke hl_users (cek kolom yang ada)
        try {
            $db->beginTransaction();
            $st = $db->prepare("INSERT INTO hl_users (tenant_id, username, password_hash, nama, role, outlet_id, is_active, created_at) VALUES (?,?,?,?,?,?,1,NOW())");
            $st->execute([$tid, $username, $hash, $kurir['nama'], 'kurir', $oid]);
            $uid = (int)$db->lastInsertId();

            $upd = $db->prepare("UPDATE hl_kurir SET user_id=? WHERE id=? AND tenant_id=? AND outlet_id=?");
            $upd->execute([$uid, $id, $tid, $oid]);

            logAudit('create_account', 'kurir', "kurir_id=$id user_id=$uid username=$username");
            $db->commit();
            echo json_encode(['ok'=>true, 'username'=>$username, 'password'=>$password]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[kurir create_account] ' . $e->getMessage());
            echo json_encode(['error' => 'Gagal buat akun: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

$pageTitle = '🛵 Kurir';
renderHead($pageTitle);
renderTopbar($activePage);
?>
<div class="hl-main">
  <h1 style="margin:0 0 14px">🛵 Master Kurir</h1>
  <button class="hl-btn hl-btn-primary" onclick="openEdit()">+ Tambah Kurir</button>
  <div id="kurirList" style="margin-top:18px;min-height:120px">⏳ Memuat...</div>
</div>

<!-- Modal edit -->
<div class="hl-modal-overlay" id="modalEdit">
  <div class="hl-modal">
    <div class="hl-modal-header"><span class="hl-modal-title">Tambah/Edit Kurir</span></div>
    <div class="hl-modal-body">
      <input type="hidden" id="ed_id" value="0">
      <label class="hl-label">Nama</label>
      <input type="text" id="ed_nama" class="hl-input" maxlength="100">
      <label class="hl-label">No HP</label>
      <input type="text" id="ed_hp" class="hl-input" maxlength="20">
      <label class="hl-label">Kendaraan</label>
      <input type="text" id="ed_kendaraan" class="hl-input" maxlength="50" placeholder="Motor Beat / Mobil Avanza">
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeEdit()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveKurir()">💾 Simpan</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

async function loadList() {
  const list = document.getElementById('kurirList');
  list.innerHTML = '⏳ Memuat...';
  const r = await fetch('?action=list');
  const d = await r.json();
  if (!d.rows.length) { list.innerHTML = '<div style="padding:30px;text-align:center;color:var(--gray)">Belum ada kurir</div>'; return; }
  list.innerHTML = d.rows.map(k => `
    <div class="hl-card" style="margin-bottom:10px;padding:14px 16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <div style="font-weight:700;font-size:15px">🛵 ${esc(k.nama)}
          ${k.aktif==1 ? '' : '<span style="background:#FEE;color:#991B1B;font-size:10px;padding:2px 7px;border-radius:100px;margin-left:6px">NON-AKTIF</span>'}
        </div>
        <div style="color:var(--gray);font-size:13px;margin-top:3px">${esc(k.no_hp||'-')} · ${esc(k.kendaraan||'-')}</div>
        <div style="font-size:12px;margin-top:5px">
          ${k.akun_username
            ? `<span style="background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:6px">✓ Akun: ${esc(k.akun_username)}</span>`
            : `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="createAkun(${k.id})">🔑 Buat Akun</button>`}
        </div>
      </div>
      <div style="display:flex;gap:6px;align-items:center">
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='openEdit(${JSON.stringify(k)})'>✏️</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="toggleAktif(${k.id})">${k.aktif==1?'Nonaktifkan':'Aktifkan'}</button>
      </div>
    </div>
  `).join('');
}

function openEdit(k) {
  document.getElementById('ed_id').value        = k?.id || 0;
  document.getElementById('ed_nama').value      = k?.nama || '';
  document.getElementById('ed_hp').value        = k?.no_hp || '';
  document.getElementById('ed_kendaraan').value = k?.kendaraan || '';
  document.getElementById('modalEdit').classList.add('open');
}
function closeEdit() { document.getElementById('modalEdit').classList.remove('open'); }

async function saveKurir() {
  const payload = {
    id: parseInt(document.getElementById('ed_id').value),
    nama: document.getElementById('ed_nama').value,
    no_hp: document.getElementById('ed_hp').value,
    kendaraan: document.getElementById('ed_kendaraan').value,
  };
  const r = await fetch('?action=save', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(payload)});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('✅ Tersimpan','success'); closeEdit(); loadList();
}

async function createAkun(id) {
  if (!confirm('Buat akun login untuk kurir ini?')) return;
  const r = await fetch('?action=create_account', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id})});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  alert(`✅ Akun dibuat:\n\nUsername: ${d.username}\nPassword: ${d.password}\n\n⚠ CATAT SEKARANG! Tidak ditampilkan lagi.`);
  loadList();
}

async function toggleAktif(id) {
  const r = await fetch('?action=toggle_aktif', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id})});
  await r.json();
  loadList();
}

loadList();
</script>
```

- [ ] **Step 2: Verify schema hl_users**

Sebelum deploy, verify columns hl_users:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master -e "DESCRIBE hl_users;" | head -20
```

Adjust INSERT statement Step 1 (line `INSERT INTO hl_users`) sesuai kolom yang ada — kolom `password_hash`, `is_active`, dll mungkin nama beda. **Adjust before testing.**

- [ ] **Step 3: Manual smoke test (post-deploy)**

1. Login owner → /kurir-master → list kosong
2. Klik "+ Tambah Kurir" → isi nama+hp+kendaraan → simpan → muncul
3. Klik "🔑 Buat Akun" → alert tampil username+password (catat)
4. Logout, login pakai username+password kurir → harus redirect ke /kurir

- [ ] **Step 4: Commit**

```bash
git add kurir-master.php
git commit -m "feat(antar): /kurir-master CRUD + generate user account"
```

---

### Task 4: Outlet Settings — antar_mode + Zona CRUD

**Files:**
- Modify: `outlet-settings.php`

**Interfaces:**
- Consumes: column `outlets.antar_mode`, tabel `hl_zona_antar`
- Produces:
  - GET `?action=list` → tambah `antar_mode` ke response
  - POST `?action=save` → tambah `antar_mode` ke UPDATE outlets
  - GET `?action=zona_list&outlet_id=X` → list zona per outlet
  - POST `?action=zona_save` → create/update zona
  - POST `?action=zona_delete` → toggle aktif zona
  - UI: section di modal edit untuk pilih mode + CRUD zona

- [ ] **Step 1: Update SELECT + UPDATE outlets dengan antar_mode**

Cari di outlet-settings.php list action (sekitar line 28):

```php
if ($hasNotaCols) $cols .= ", nota_prefix, nota_format, label_size, antar_mode";
```

Save action (sekitar line 60):

```php
$antarMode = in_array(($d['antar_mode'] ?? 'free'), ['free','zona'], true) ? $d['antar_mode'] : 'free';

$st = $db->prepare("UPDATE outlets SET nota_prefix=?, nota_format=?, label_size=?, antar_mode=? WHERE id=? AND tenant_id=?");
$st->execute([$prefix, $format, $labelSize, $antarMode, $id, $tid]);
```

- [ ] **Step 2: Tambah action zona_list/save/delete**

Setelah action save existing, tambah:

```php
if ($action === 'zona_list') {
    $outletId = (int)($_GET['outlet_id'] ?? 0);
    $rows = TenantQuery::raw(
        "SELECT id, nama, fee, aktif FROM hl_zona_antar WHERE tenant_id=? AND outlet_id=? AND aktif=1 ORDER BY nama",
        [$tid, $outletId]
    );
    echo json_encode(['rows'=>$rows]);
    exit;
}

if ($action === 'zona_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($d['id'] ?? 0);
    $outletId = (int)($d['outlet_id'] ?? 0);
    $nama = substr(trim($d['nama'] ?? ''), 0, 60);
    $fee  = (int)($d['fee'] ?? 0);
    if (!$nama || $outletId <= 0) { echo json_encode(['error'=>'Nama + outlet wajib']); exit; }

    if ($id > 0) {
        $st = $db->prepare("UPDATE hl_zona_antar SET nama=?, fee=? WHERE id=? AND tenant_id=? AND outlet_id=?");
        $st->execute([$nama, $fee, $id, $tid, $outletId]);
    } else {
        TenantQuery::insert('hl_zona_antar', ['nama'=>$nama, 'fee'=>$fee, 'outlet_id'=>$outletId]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'zona_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($d['id'] ?? 0);
    $st = $db->prepare("UPDATE hl_zona_antar SET aktif=0 WHERE id=? AND tenant_id=?");
    $st->execute([$id, $tid]);
    echo json_encode(['ok'=>true]);
    exit;
}
```

- [ ] **Step 3: Tambah UI section di modal edit**

Locate modal edit di outlet-settings.php (sekitar line 230, setelah section label-size). Tambah section baru:

```html
<!-- Antar Jemput Mode + Zona -->
<div style="margin:8px 0 14px;padding:14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px">
  <label class="hl-label" style="margin-bottom:8px">🚚 Mode Antar Jemput</label>
  <div style="display:flex;gap:10px;margin-bottom:14px">
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:#fff">
      <input type="radio" name="ed_antar_mode" value="free" onchange="toggleZonaSection()"> Free
    </label>
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:#fff">
      <input type="radio" name="ed_antar_mode" value="zona" onchange="toggleZonaSection()"> Zona (fee per zona)
    </label>
  </div>

  <div id="zonaSection" style="display:none">
    <label class="hl-label">Daftar Zona</label>
    <div id="zonaList" style="margin-bottom:10px">⏳</div>
    <div style="display:flex;gap:6px">
      <input type="text" id="zona_nama_new" placeholder="Zona 1 - radius 3km" class="hl-input" style="flex:1">
      <input type="number" id="zona_fee_new" placeholder="Rp" class="hl-input" style="width:120px">
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="addZona()">+ Tambah</button>
    </div>
  </div>
</div>
```

- [ ] **Step 4: Tambah JS handlers untuk zona section**

Di `<script>` block outlet-settings.php, tambah setelah saveFormat():

```js
function toggleZonaSection() {
  const mode = document.querySelector('input[name=ed_antar_mode]:checked')?.value;
  document.getElementById('zonaSection').style.display = mode === 'zona' ? 'block' : 'none';
  if (mode === 'zona') loadZonaList();
}

async function loadZonaList() {
  const outletId = document.getElementById('ed_id').value;
  if (!outletId) return;
  const r = await fetch('?action=zona_list&outlet_id=' + outletId);
  const d = await r.json();
  const list = document.getElementById('zonaList');
  if (!d.rows.length) { list.innerHTML = '<div style="color:var(--gray);font-size:12px;padding:8px 0">Belum ada zona</div>'; return; }
  list.innerHTML = d.rows.map(z => `
    <div style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid #EEF1F8">
      <span style="flex:1;font-size:13px">${esc(z.nama)}</span>
      <span style="font-size:13px;font-weight:600;color:#0F7B6C">Rp ${Number(z.fee).toLocaleString('id-ID')}</span>
      <button onclick="deleteZona(${z.id})" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:14px">×</button>
    </div>
  `).join('');
}

async function addZona() {
  const outletId = document.getElementById('ed_id').value;
  const nama = document.getElementById('zona_nama_new').value.trim();
  const fee  = parseInt(document.getElementById('zona_fee_new').value) || 0;
  if (!nama) { showToast('Nama zona wajib', 'error'); return; }
  const r = await fetch('?action=zona_save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({outlet_id: outletId, nama, fee})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  document.getElementById('zona_nama_new').value = '';
  document.getElementById('zona_fee_new').value = '';
  loadZonaList();
}

async function deleteZona(id) {
  if (!confirm('Hapus zona ini?')) return;
  await fetch('?action=zona_delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  loadZonaList();
}
```

- [ ] **Step 5: Init radio state di openEdit() existing**

Locate openEdit() in outlet-settings.php (sekitar line 312). Tambah init untuk antar_mode:

```js
function openEdit(r) {
  // ... existing fields
  const am = (r.antar_mode === 'zona') ? 'zona' : 'free';
  document.querySelectorAll('input[name=ed_antar_mode]').forEach(el => el.checked = (el.value === am));
  toggleZonaSection();
  // ... existing modal open
}
```

Saat saveFormat(), pass antar_mode:

```js
body: JSON.stringify({id, nota_prefix: prefix, nota_format: format, label_size: labelSize, antar_mode: document.querySelector('input[name=ed_antar_mode]:checked')?.value || 'free'})
```

- [ ] **Step 6: Commit**

```bash
git add outlet-settings.php
git commit -m "feat(antar): outlet settings antar_mode + CRUD zona"
```

---

### Task 5: `/antar-jemput` skeleton + list view + tab UI

**Files:**
- Create: `antar-jemput.php`

**Interfaces:**
- Consumes: `requirePermission('antar.view')`, tabel hl_antar_jemput + hl_kurir
- Produces:
  - GET `?action=list&tipe=jemput|antar` → JSON rows + counts
  - GET `?action=kurir_list` → JSON kurir aktif
  - UI: stage tabs + cards + tombol assign

- [ ] **Step 1: Buat skeleton + actions**

```php
<?php
$activePage = 'antar-jemput';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';
require_once __DIR__ . '/components.php';

requirePermission('antar.view');
$canManage = hasPermission('antar.manage');
$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$db  = Database::get();

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $tipe = in_array(($_GET['tipe'] ?? 'jemput'), ['jemput','antar'], true) ? $_GET['tipe'] : 'jemput';
        $today = date('Y-m-d');
        $rows = TenantQuery::raw(
            "SELECT aj.*, k.nama AS kurir_nama, k.no_hp AS kurir_hp, z.nama AS zona_nama
               FROM hl_antar_jemput aj
          LEFT JOIN hl_kurir k ON k.id = aj.kurir_id
          LEFT JOIN hl_zona_antar z ON z.id = aj.zona_id
              WHERE aj.tenant_id=? AND aj.outlet_id=? AND aj.tipe=?
                AND (aj.status != 'done' OR DATE(aj.done_at) = ?)
              ORDER BY FIELD(aj.status,'pending','assigned','menuju','sampai','done','cancel'), aj.created_at DESC
              LIMIT 200",
            [$tid, $oid, $tipe, $today]
        );

        // Counts per status (semua tipe)
        $counts = TenantQuery::raw(
            "SELECT tipe, status, COUNT(*) AS cnt
               FROM hl_antar_jemput
              WHERE tenant_id=? AND outlet_id=?
                AND (status != 'done' OR DATE(done_at) = ?)
              GROUP BY tipe, status",
            [$tid, $oid, $today]
        );
        echo json_encode(['rows' => $rows, 'counts' => $counts]);
        exit;
    }

    if ($action === 'kurir_list') {
        $rows = TenantQuery::raw(
            "SELECT id, nama FROM hl_kurir WHERE tenant_id=? AND outlet_id=? AND aktif=1 ORDER BY nama",
            [$tid, $oid]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

$pageTitle = '🚚 Antar Jemput';
renderHead($pageTitle);
renderTopbar($activePage);
?>
<div class="hl-main">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h1 style="margin:0">🚚 Antar Jemput</h1>
    <div style="display:flex;gap:8px">
      <?php if ($canManage): ?>
        <button class="hl-btn hl-btn-primary" onclick="openCreate()">+ Jemput Baru</button>
      <?php endif; ?>
      <a class="hl-btn hl-btn-outline" href="?view=report">📊 Report</a>
    </div>
  </div>

  <div style="display:flex;gap:4px;margin-bottom:14px;border-bottom:1px solid var(--off)">
    <button class="aj-tab active" data-tipe="jemput" onclick="switchTipe('jemput')" style="padding:10px 18px;border:none;background:none;font-weight:700;border-bottom:3px solid var(--teal);cursor:pointer">📥 Jemput <span class="cnt"></span></button>
    <button class="aj-tab" data-tipe="antar" onclick="switchTipe('antar')" style="padding:10px 18px;border:none;background:none;font-weight:600;color:var(--gray);border-bottom:3px solid transparent;cursor:pointer">📤 Antar <span class="cnt"></span></button>
  </div>

  <div id="ajList" style="display:grid;gap:10px;grid-template-columns:1fr">⏳ Memuat...</div>
</div>

<?php renderToast(); ?>
<script>
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';
const CAN_MANAGE = <?= json_encode($canManage) ?>;
let currentTipe = 'jemput';

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function fmtRp(n){return 'Rp ' + Number(n||0).toLocaleString('id-ID')}
function fmtTime(d){if(!d)return'-';return new Date(d.replace(' ','T')).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}

function statusPill(s) {
  const cfg = {pending:['#FEF3C7','#92400E','🟡'],assigned:['#DBEAFE','#1E40AF','🔵'],menuju:['#CCFBF1','#0F766E','🟢'],sampai:['#EDE9FE','#5B21B6','🟣'],done:['#D1FAE5','#065F46','✅'],cancel:['#F3F4F6','#6B7280','⚪']};
  const c = cfg[s] || cfg.pending;
  return `<span style="display:inline-flex;align-items:center;gap:4px;background:${c[0]};color:${c[1]};padding:3px 10px;border-radius:100px;font-size:12px;font-weight:600">${c[2]} ${s}</span>`;
}

function switchTipe(t) {
  currentTipe = t;
  document.querySelectorAll('.aj-tab').forEach(b => {
    const active = b.dataset.tipe === t;
    b.style.borderBottomColor = active ? 'var(--teal)' : 'transparent';
    b.style.color = active ? '' : 'var(--gray)';
    b.style.fontWeight = active ? 700 : 600;
  });
  loadList();
}

async function loadList() {
  const r = await fetch('?action=list&tipe=' + currentTipe);
  const d = await r.json();
  const list = document.getElementById('ajList');
  if (!d.rows.length) { list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray)">Belum ada antar jemput hari ini</div>'; return; }
  list.innerHTML = d.rows.map(r => `
    <div class="hl-card" style="padding:14px 16px">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:200px">
          <div style="font-weight:700">${esc(r.nama)} · ${esc(r.telepon||'-')}</div>
          ${r.transaksi_id ? `<div style="font-size:12px;color:var(--teal-d);font-weight:600;margin-top:2px">#${esc(r.no_order||r.transaksi_id)}</div>` : ''}
          <div style="font-size:13px;color:var(--gray);margin-top:3px">${esc(r.alamat||r.catatan||'-')}</div>
          ${r.slot_waktu ? `<div style="font-size:12px;color:var(--gray);margin-top:2px">Slot: ${fmtTime(r.slot_waktu)}</div>` : ''}
          ${r.zona_nama ? `<div style="font-size:12px;margin-top:2px">${esc(r.zona_nama)} · ${fmtRp(r.fee)}</div>` : ''}
          <div style="margin-top:8px">${statusPill(r.status)}
            ${r.kurir_nama ? `<span style="margin-left:8px;font-size:12.5px">🛵 ${esc(r.kurir_nama)}</span>` : ''}
          </div>
        </div>
        ${CAN_MANAGE && r.status==='pending' ? `<button class="hl-btn hl-btn-primary hl-btn-sm" onclick="openAssign(${r.id})">Assign Kurir</button>` : ''}
      </div>
    </div>
  `).join('');
}

function openCreate() { alert('Implementasi di Task 6'); }
function openAssign(id) { alert('Implementasi di Task 6: assign ' + id); }

loadList();
</script>
```

- [ ] **Step 2: Manual test setelah deploy**

1. Buka /antar-jemput — page load, tab Jemput/Antar muncul
2. List kosong → "Belum ada antar jemput hari ini"
3. Insert dummy row via mysql lalu refresh — card muncul
4. Permission check: kasir tanpa antar.manage tidak lihat tombol Assign

- [ ] **Step 3: Commit**

```bash
git add antar-jemput.php
git commit -m "feat(antar): /antar-jemput skeleton + list view + tab UI"
```

---

### Task 6: `/antar-jemput` dispatcher actions (create, assign, status, transactional)

**Files:**
- Modify: `antar-jemput.php`

**Interfaces:**
- Consumes: existing skeleton dari Task 5
- Produces:
  - POST `?action=create` (CSRF) → insert hl_antar_jemput row
  - POST `?action=assign` (CSRF) → set kurir_id + status='assigned' (FOR UPDATE)
  - POST `?action=status` (CSRF) → update status (FOR UPDATE + transition guard)
  - POST `?action=cancel` (CSRF) → set status='cancel'
  - UI: modal create (form alamat/telepon/zona/slot) + modal assign (pilih kurir)

- [ ] **Step 1: Tambah action handlers di backend block**

Sebelum `echo json_encode(['error'=>'Unknown action'])` di Task 5, tambah:

```php
if ($action === 'create' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    requirePermission('antar.manage');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $tipe = in_array(($d['tipe'] ?? 'jemput'), ['jemput','antar'], true) ? $d['tipe'] : 'jemput';
    $nama = substr(trim($d['nama'] ?? ''), 0, 100);
    $hp   = substr(trim($d['telepon'] ?? ''), 0, 20);
    $alamat  = trim($d['alamat'] ?? '') ?: null;
    $catatan = trim($d['catatan'] ?? '') ?: null;
    $zonaId  = (int)($d['zona_id'] ?? 0) ?: null;
    $slot    = $d['slot_waktu'] ?? null;
    $transaksiId = (int)($d['transaksi_id'] ?? 0) ?: null;
    $pelangganId = (int)($d['pelanggan_id'] ?? 0) ?: null;

    if (!$nama) { echo json_encode(['error'=>'Nama wajib']); exit; }
    if (!$alamat && !$catatan) { echo json_encode(['error'=>'Alamat atau catatan/patokan wajib salah satu']); exit; }

    // Ambil fee dari zona
    $fee = 0;
    if ($zonaId) {
        $z = TenantQuery::rawOne("SELECT fee FROM hl_zona_antar WHERE id=? AND tenant_id=? AND outlet_id=? AND aktif=1", [$zonaId, $tid, $oid]);
        if ($z) $fee = (int)$z['fee'];
    }

    $userId = (int)(currentUser()['id'] ?? 0);
    TenantQuery::insert('hl_antar_jemput', [
        'tipe'=>$tipe, 'transaksi_id'=>$transaksiId, 'pelanggan_id'=>$pelangganId,
        'nama'=>$nama, 'telepon'=>$hp, 'alamat'=>$alamat, 'zona_id'=>$zonaId, 'fee'=>$fee,
        'slot_waktu'=>$slot, 'catatan'=>$catatan, 'created_by'=>$userId, 'outlet_id'=>$oid,
    ]);
    logAudit('antar_create', 'antar_jemput', "tipe=$tipe nama=$nama");
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'assign' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    requirePermission('antar.manage');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id       = (int)($d['id'] ?? 0);
    $kurirId  = (int)($d['kurir_id'] ?? 0);
    if ($id<=0 || $kurirId<=0) { echo json_encode(['error'=>'Input invalid']); exit; }

    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT status FROM hl_antar_jemput WHERE id=? AND tenant_id=? AND outlet_id=? FOR UPDATE");
        $st->execute([$id, $tid, $oid]);
        $current = $st->fetchColumn();
        if ($current === false) { throw new Exception('Tidak ditemukan'); }
        if ($current !== 'pending') { throw new Exception('Sudah diassign worker lain'); }

        // Verify kurir milik outlet aktif
        $k = TenantQuery::rawOne("SELECT id FROM hl_kurir WHERE id=? AND tenant_id=? AND outlet_id=? AND aktif=1", [$kurirId, $tid, $oid]);
        if (!$k) { throw new Exception('Kurir tidak valid'); }

        $upd = $db->prepare("UPDATE hl_antar_jemput SET kurir_id=?, status='assigned', updated_at=NOW() WHERE id=? AND tenant_id=? AND outlet_id=?");
        $upd->execute([$kurirId, $id, $tid, $oid]);
        logAudit('antar_assign', 'antar_jemput', "id=$id kurir=$kurirId");
        $db->commit();
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

if ($action === 'cancel' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    requirePermission('antar.manage');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($d['id'] ?? 0);
    $st = $db->prepare("UPDATE hl_antar_jemput SET status='cancel', updated_at=NOW() WHERE id=? AND tenant_id=? AND outlet_id=? AND status NOT IN ('done','cancel')");
    $st->execute([$id, $tid, $oid]);
    logAudit('antar_cancel', 'antar_jemput', "id=$id");
    echo json_encode(['ok'=>true]);
    exit;
}
```

- [ ] **Step 2: Tambah modal create + assign HTML di bawah list**

Sebelum `<?php renderToast(); ?>`, tambah:

```html
<!-- Modal Create -->
<div class="hl-modal-overlay" id="modalCreate">
  <div class="hl-modal" style="max-width:500px">
    <div class="hl-modal-header"><span class="hl-modal-title">📥 Jemput Baru</span></div>
    <div class="hl-modal-body">
      <label class="hl-label">Nama Pelanggan</label>
      <input type="text" id="c_nama" class="hl-input" maxlength="100">
      <label class="hl-label">Telepon</label>
      <input type="text" id="c_hp" class="hl-input" maxlength="20">
      <label class="hl-label">Alamat (opsional)</label>
      <textarea id="c_alamat" class="hl-input" rows="2"></textarea>
      <label class="hl-label">Catatan / Patokan (opsional)</label>
      <textarea id="c_catatan" class="hl-input" rows="2" placeholder="Dekat warung Bu Inah, pagar hitam..."></textarea>
      <label class="hl-label">Slot Waktu (opsional)</label>
      <input type="datetime-local" id="c_slot" class="hl-input">
      <label class="hl-label" id="lbl_zona" style="display:none">Zona</label>
      <select id="c_zona" class="hl-input" style="display:none"><option value="">-- Pilih zona --</option></select>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeCreate()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveCreate()">Simpan</button>
    </div>
  </div>
</div>

<!-- Modal Assign -->
<div class="hl-modal-overlay" id="modalAssign">
  <div class="hl-modal" style="max-width:400px">
    <div class="hl-modal-header"><span class="hl-modal-title">Assign Kurir</span></div>
    <div class="hl-modal-body">
      <input type="hidden" id="a_id" value="0">
      <label class="hl-label">Pilih Kurir Aktif</label>
      <select id="a_kurir" class="hl-input"><option value="">-- Pilih --</option></select>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeAssign()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="doAssign()">Assign</button>
    </div>
  </div>
</div>
```

- [ ] **Step 3: Replace JS stubs dengan implementation**

Replace `openCreate()` dan `openAssign()` stub:

```js
async function loadZonaForCreate() {
  // Ambil zona dari outlet-settings endpoint
  const r = await fetch('/outlet-settings.php?action=zona_list&outlet_id=<?= $oid ?>');
  const d = await r.json();
  const sel = document.getElementById('c_zona');
  const lbl = document.getElementById('lbl_zona');
  if (d.rows && d.rows.length) {
    sel.innerHTML = '<option value="">-- Tanpa zona --</option>' + d.rows.map(z => `<option value="${z.id}">${esc(z.nama)} (Rp ${Number(z.fee).toLocaleString('id-ID')})</option>`).join('');
    sel.style.display = lbl.style.display = '';
  } else {
    sel.style.display = lbl.style.display = 'none';
  }
}

function openCreate() {
  ['c_nama','c_hp','c_alamat','c_catatan','c_slot'].forEach(id => document.getElementById(id).value='');
  document.getElementById('c_zona').value = '';
  loadZonaForCreate();
  document.getElementById('modalCreate').classList.add('open');
}
function closeCreate() { document.getElementById('modalCreate').classList.remove('open'); }

async function saveCreate() {
  const payload = {
    tipe: currentTipe,
    nama: document.getElementById('c_nama').value,
    telepon: document.getElementById('c_hp').value,
    alamat: document.getElementById('c_alamat').value,
    catatan: document.getElementById('c_catatan').value,
    slot_waktu: document.getElementById('c_slot').value,
    zona_id: parseInt(document.getElementById('c_zona').value) || null,
  };
  const r = await fetch('?action=create', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(payload)});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('✅ Tersimpan','success'); closeCreate(); loadList();
}

async function openAssign(id) {
  document.getElementById('a_id').value = id;
  const r = await fetch('?action=kurir_list');
  const d = await r.json();
  const sel = document.getElementById('a_kurir');
  sel.innerHTML = '<option value="">-- Pilih --</option>' + (d.rows||[]).map(k => `<option value="${k.id}">${esc(k.nama)}</option>`).join('');
  document.getElementById('modalAssign').classList.add('open');
}
function closeAssign() { document.getElementById('modalAssign').classList.remove('open'); }

async function doAssign() {
  const id = parseInt(document.getElementById('a_id').value);
  const kurirId = parseInt(document.getElementById('a_kurir').value);
  if (!kurirId) { showToast('Pilih kurir','error'); return; }
  const r = await fetch('?action=assign', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id, kurir_id: kurirId})});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('✅ Kurir di-assign','success'); closeAssign(); loadList();
}
```

- [ ] **Step 4: Manual smoke test**

1. Owner buka /antar-jemput → klik "Jemput Baru" → isi nama+telepon+alamat → simpan → muncul di list
2. Klik "Assign Kurir" → pilih → status berubah jadi 'assigned' + nama kurir muncul
3. Test race: 2 browser assign yg sama → 1 success, 1 error

- [ ] **Step 5: Commit**

```bash
git add antar-jemput.php
git commit -m "feat(antar): dispatcher create + assign + cancel (transactional + race guard)"
```

---

### Task 7: `/kurir` mobile page + tasks list + status step buttons

**Files:**
- Create: `kurir.php`

**Interfaces:**
- Consumes: `requirePermission('antar.kurir')`, hl_antar_jemput dengan join hl_kurir.user_id = current user
- Produces:
  - GET `?action=list` → tasks hari ini untuk kurir yg login
  - POST `?action=status` (CSRF) → status next-step

- [ ] **Step 1: Buat kurir.php**

```php
<?php
$activePage = 'kurir';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';
require_once __DIR__ . '/components.php';

requirePermission('antar.kurir');
$user = currentUser();
$tid  = TenantResolver::id();
$oid  = TenantResolver::outletId();
$db   = Database::get();

// Resolve kurir_id dari user_id yang login
$st = $db->prepare("SELECT id FROM hl_kurir WHERE user_id=? AND tenant_id=? AND aktif=1 LIMIT 1");
$st->execute([(int)$user['id'], $tid]);
$kurirId = (int)($st->fetchColumn() ?: 0);

if (!$kurirId) {
    http_response_code(403);
    die('Akun kurir tidak ditemukan / tidak aktif. Hubungi owner.');
}

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $today = date('Y-m-d');
        $rows = TenantQuery::raw(
            "SELECT aj.*, t.no_order, t.nama_pelanggan AS order_nama
               FROM hl_antar_jemput aj
          LEFT JOIN hl_transaksi t ON t.id = aj.transaksi_id
              WHERE aj.tenant_id=? AND aj.kurir_id=?
                AND aj.status IN ('assigned','menuju','sampai')
                AND DATE(aj.updated_at) >= ?
              ORDER BY FIELD(aj.status,'menuju','sampai','assigned'), aj.slot_waktu ASC",
            [$tid, $kurirId, date('Y-m-d', strtotime('-1 day'))]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'status' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        $next = $d['next'] ?? '';
        $allowed = ['menuju','sampai']; // done handled di Task 8 dengan foto+signature
        if (!in_array($next, $allowed, true)) { echo json_encode(['error'=>'Status invalid']); exit; }

        try {
            $db->beginTransaction();
            $st = $db->prepare("SELECT status FROM hl_antar_jemput WHERE id=? AND tenant_id=? AND kurir_id=? FOR UPDATE");
            $st->execute([$id, $tid, $kurirId]);
            $current = $st->fetchColumn();
            if ($current === false) { throw new Exception('Tugas tidak ditemukan'); }

            // Transition validation
            $allowedFrom = ['menuju'=>'assigned', 'sampai'=>'menuju'];
            if ($current !== $allowedFrom[$next]) { throw new Exception('Transisi tidak valid (current=' . $current . ')'); }

            $upd = $db->prepare("UPDATE hl_antar_jemput SET status=?, updated_at=NOW() WHERE id=? AND tenant_id=? AND kurir_id=?");
            $upd->execute([$next, $id, $tid, $kurirId]);
            logAudit('antar_status', 'antar_jemput', "id=$id status=$next");
            $db->commit();
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

$pageTitle = '🛵 Tugas Saya';
renderHead($pageTitle);
?>
<body>
<div class="hl-main" style="max-width:520px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <div>
      <div style="font-size:13px;color:var(--gray)">👋 Hai,</div>
      <div style="font-weight:700;font-size:17px"><?= htmlspecialchars($user['nama']) ?></div>
    </div>
    <a href="/logout" class="hl-btn hl-btn-outline hl-btn-sm">Logout</a>
  </div>

  <h2 style="margin:0 0 12px;font-size:16px;color:var(--gray)">Tugas Hari Ini</h2>
  <div id="taskList" style="display:grid;gap:12px">⏳ Memuat...</div>
</div>

<?php renderToast(); ?>
<script>
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

async function loadTasks() {
  const r = await fetch('?action=list');
  const d = await r.json();
  const list = document.getElementById('taskList');
  if (!d.rows.length) { list.innerHTML = '<div style="padding:40px;text-align:center;color:var(--gray);background:#fff;border-radius:12px">🎉 Belum ada tugas</div>'; return; }
  list.innerHTML = d.rows.map(r => renderTask(r)).join('');
}

function renderTask(r) {
  const tipeLabel = r.tipe === 'jemput' ? '📥 JEMPUT' : '📤 ANTAR';
  const alamat = r.alamat || r.catatan || '-';
  const mapsBtn = r.alamat ? `<a href="https://maps.google.com/?q=${encodeURIComponent(r.alamat)}" target="_blank" class="hl-btn hl-btn-outline hl-btn-sm" style="margin-right:6px">📍 Maps</a>` : '';

  let actionBtn = '';
  if (r.status === 'assigned') {
    actionBtn = `<button class="hl-btn hl-btn-primary" style="width:100%;margin-top:10px" onclick="doStatus(${r.id},'menuju')">▶ Saya Menuju</button>`;
  } else if (r.status === 'menuju') {
    actionBtn = `<button class="hl-btn hl-btn-primary" style="width:100%;margin-top:10px" onclick="doStatus(${r.id},'sampai')">✅ Sampai Lokasi</button>`;
  } else if (r.status === 'sampai') {
    actionBtn = `<button class="hl-btn hl-btn-primary" style="width:100%;margin-top:10px" onclick="openDone(${r.id},'${r.tipe}')">🏁 Selesai</button>`;
  }

  return `
    <div class="hl-card" style="padding:16px">
      <div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--teal-d)">${tipeLabel}</div>
      <div style="font-weight:700;font-size:16px;margin-top:3px">${esc(r.nama)}</div>
      ${r.no_order ? `<div style="font-size:12.5px;color:var(--gray);margin-top:2px">#${esc(r.no_order)}</div>` : ''}
      <div style="font-size:13.5px;margin-top:8px;line-height:1.4">${esc(alamat)}</div>
      ${r.telepon ? `<div style="font-size:12.5px;color:var(--gray);margin-top:2px"><a href="tel:${esc(r.telepon)}" style="color:var(--teal-d)">📞 ${esc(r.telepon)}</a></div>` : ''}
      <div style="margin-top:10px">${mapsBtn}</div>
      ${actionBtn}
    </div>`;
}

async function doStatus(id, next) {
  if (!confirm('Konfirmasi status: ' + next + '?')) return;
  const r = await fetch('?action=status', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id, next})});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  loadTasks();
}

function openDone(id, tipe) { alert('Done modal di Task 8'); }

loadTasks();
setInterval(loadTasks, 30000); // refresh tiap 30 detik
</script>
</body>
```

- [ ] **Step 2: Manual test**

1. Login sebagai kurir → /kurir
2. Tugas tampil card per row
3. Tap "▶ Saya Menuju" → status update + card re-render dengan "✅ Sampai" button
4. Tap "✅ Sampai" → "🏁 Selesai" button muncul (stub alert untuk Task 8)
5. Refresh tiap 30s auto-load

- [ ] **Step 3: Commit**

```bash
git add kurir.php
git commit -m "feat(antar): /kurir mobile page + tasks list + status step buttons"
```

---

### Task 8: `/kurir` done modal (foto + signature) + mark_done action

**Files:**
- Modify: `kurir.php`

**Interfaces:**
- Consumes: existing skeleton dari Task 7
- Produces:
  - POST `?action=upload_foto` (CSRF, multipart) → return path
  - POST `?action=mark_done` (CSRF) → update status=done, simpan foto + signature, foto wajib untuk antar

- [ ] **Step 1: Tambah action upload_foto + mark_done sebelum 'Unknown action'**

```php
if ($action === 'upload_foto' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    require_once ROOT . '/core/FileUpload.php';
    $f = $_FILES['foto'] ?? null;
    if (!$f) { echo json_encode(['error'=>'File foto tidak ditemukan']); exit; }
    $res = FileUpload::uploadImage($f, 'uploads/foto_antar', 't' . $tid . '_o' . $oid);
    if (!empty($res['error'])) { echo json_encode(['error'=>$res['error']]); exit; }
    echo json_encode(['ok'=>true, 'path'=>$res['path']]);
    exit;
}

if ($action === 'mark_done' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id    = (int)($d['id'] ?? 0);
    $fotoPath  = trim($d['foto'] ?? '');
    $signature = $d['signature'] ?? '';
    $catatan   = trim($d['catatan'] ?? '');

    // Validate foto path (XSS guard)
    if ($fotoPath && !str_starts_with($fotoPath, 'uploads/foto_antar/')) {
        $fotoPath = '';
    }

    // Signature decode → save PNG
    $sigPath = null;
    if ($signature && preg_match('/^data:image\/png;base64,(.+)$/', $signature, $m)) {
        $bin = base64_decode($m[1]);
        if ($bin !== false && strlen($bin) < 1000000) {
            $fn = 'uploads/foto_antar/sig_t' . $tid . '_o' . $oid . '_' . bin2hex(random_bytes(8)) . '.png';
            if (file_put_contents(ROOT . '/' . $fn, $bin) !== false) {
                $sigPath = $fn;
            }
        }
    }

    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT status, tipe FROM hl_antar_jemput WHERE id=? AND tenant_id=? AND kurir_id=? FOR UPDATE");
        $st->execute([$id, $tid, $kurirId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Exception('Tugas tidak ditemukan'); }
        if ($row['status'] !== 'sampai') { throw new Exception('Status harus sampai dulu sebelum selesai'); }

        // Antar wajib foto
        if ($row['tipe'] === 'antar' && !$fotoPath) { throw new Exception('Foto bukti wajib untuk antar'); }

        $upd = $db->prepare("UPDATE hl_antar_jemput SET status='done', done_at=NOW(), foto_bukti=?, signature_path=?, catatan=COALESCE(NULLIF(?,''),catatan), updated_at=NOW() WHERE id=? AND tenant_id=? AND kurir_id=?");
        $upd->execute([$fotoPath ?: null, $sigPath, $catatan, $id, $tid, $kurirId]);
        logAudit('antar_done', 'antar_jemput', "id=$id tipe=" . $row['tipe']);
        $db->commit();
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($sigPath && file_exists(ROOT . '/' . $sigPath)) @unlink(ROOT . '/' . $sigPath);
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}
```

- [ ] **Step 2: Tambah modal done HTML + JS handlers**

Sebelum `<?php renderToast(); ?>`, tambah:

```html
<div class="hl-modal-overlay" id="modalDone">
  <div class="hl-modal" style="max-width:440px">
    <div class="hl-modal-header"><span class="hl-modal-title">🏁 Konfirmasi Selesai</span></div>
    <div class="hl-modal-body">
      <input type="hidden" id="d_id" value="0">
      <input type="hidden" id="d_tipe" value="">
      <label class="hl-label" id="lbl_foto">Foto Bukti</label>
      <input type="file" accept="image/*" capture="environment" id="d_foto" onchange="uploadDoneFoto(this)">
      <div id="d_fotoPreview" style="margin-top:8px"></div>
      <div id="sigBox" style="display:none">
        <label class="hl-label">Tanda Tangan Pelanggan</label>
        <canvas id="d_sig" width="400" height="120" style="border:1px solid var(--off);border-radius:8px;width:100%;background:#fff;touch-action:none"></canvas>
        <button type="button" onclick="clearSig()" style="background:none;border:none;color:var(--gray);font-size:12px;text-decoration:underline;cursor:pointer">Bersihkan TTD</button>
      </div>
      <label class="hl-label">Catatan (opsional)</label>
      <textarea id="d_catatan" class="hl-input" rows="2"></textarea>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeDone()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="submitDone()">🏁 Selesai</button>
    </div>
  </div>
</div>
```

Replace stub `openDone()`:

```js
let uploadedFotoPath = '';

function openDone(id, tipe) {
  document.getElementById('d_id').value = id;
  document.getElementById('d_tipe').value = tipe;
  document.getElementById('d_foto').value = '';
  document.getElementById('d_fotoPreview').innerHTML = '';
  document.getElementById('d_catatan').value = '';
  uploadedFotoPath = '';
  document.getElementById('sigBox').style.display = tipe === 'antar' ? 'block' : 'none';
  document.getElementById('lbl_foto').textContent = tipe === 'antar' ? 'Foto Bukti (wajib)' : 'Foto Bukti (opsional)';
  document.getElementById('modalDone').classList.add('open');
  setupSig();
}
function closeDone() { document.getElementById('modalDone').classList.remove('open'); }

async function uploadDoneFoto(input) {
  const f = input.files?.[0];
  if (!f) return;
  document.getElementById('d_fotoPreview').innerHTML = '⏳ Upload...';
  const fd = new FormData(); fd.append('foto', f); fd.append('_csrf', CSRF);
  const r = await fetch('?action=upload_foto', {method:'POST',headers:{'X-CSRF-Token':CSRF},body:fd});
  const d = await r.json();
  if (d.error) { document.getElementById('d_fotoPreview').innerHTML = '❌ ' + d.error; return; }
  uploadedFotoPath = d.path;
  document.getElementById('d_fotoPreview').innerHTML = `<img src="/${d.path}" style="max-width:160px;border-radius:8px">`;
}

function setupSig() {
  const c = document.getElementById('d_sig'); if (!c) return;
  const ctx = c.getContext('2d'); ctx.clearRect(0,0,c.width,c.height); ctx.strokeStyle='#000'; ctx.lineWidth=2; ctx.lineCap='round';
  let drawing = false;
  const pos = (e) => { const rect = c.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return {x:(t.clientX-rect.left)*c.width/rect.width, y:(t.clientY-rect.top)*c.height/rect.height}; };
  const start = (e) => { drawing=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); e.preventDefault(); };
  const move  = (e) => { if (!drawing) return; const p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); e.preventDefault(); };
  const end   = () => { drawing=false; };
  c.onmousedown=start; c.onmousemove=move; c.onmouseup=end;
  c.ontouchstart=start; c.ontouchmove=move; c.ontouchend=end;
}
function clearSig() { const c=document.getElementById('d_sig'); if (c) c.getContext('2d').clearRect(0,0,c.width,c.height); }

async function submitDone() {
  const id = parseInt(document.getElementById('d_id').value);
  const tipe = document.getElementById('d_tipe').value;
  const catatan = document.getElementById('d_catatan').value;
  if (tipe === 'antar' && !uploadedFotoPath) { showToast('Foto bukti wajib','error'); return; }
  const sig = (tipe === 'antar') ? document.getElementById('d_sig').toDataURL('image/png') : '';
  const r = await fetch('?action=mark_done', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id, foto: uploadedFotoPath, signature: sig, catatan})});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('🎉 Tugas selesai','success'); closeDone(); loadTasks();
}
```

- [ ] **Step 3: Manual test**

1. Kurir tap "🏁 Selesai" → modal terbuka. Antar → signature canvas + foto wajib. Jemput → no signature, foto opsional.
2. Foto upload → preview thumbnail muncul, path tersimpan
3. Submit → status berubah jadi 'done', card hilang dari list
4. Test antar tanpa foto → alert "Foto bukti wajib"

- [ ] **Step 4: Buat folder + commit**

```bash
mkdir -p uploads/foto_antar && touch uploads/foto_antar/.gitkeep
git add kurir.php uploads/foto_antar/.gitkeep
git commit -m "feat(antar): /kurir done modal (foto + signature) + mark_done action"
```

---

### Task 9: POS + Produksi integration — auto-create antar row

**Files:**
- Modify: `pos.php` (input order form + saveOrder action)
- Modify: `produksi.php` (stage diambil jenis=diantarkan auto-create)

**Interfaces:**
- Consumes: backend POST `/antar-jemput.php?action=create` ATAU direct INSERT hl_antar_jemput (recommended: direct insert since same outlet scope)
- Produces:
  - POS: checkbox + alamat fields kalau dicentang; on save: insert row tipe=antar
  - Produksi: saat stage=diambil dengan jenis=diantarkan, insert row tipe=antar

- [ ] **Step 1: POS form — tambah section antar**

Locate form input order di pos.php. Tambah section setelah field alamat/telepon (atau sebelum tombol save). Cari section "Item layanan" dan tambah sebelumnya:

```html
<div style="margin:14px 0;padding:12px 14px;background:#F0FDFC;border:1px solid #BBF0EA;border-radius:10px">
  <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
    <input type="checkbox" id="cb_antar" onchange="toggleAntarSection()"> 🛵 Antar ke Pelanggan
  </label>
  <div id="antarSection" style="display:none;margin-top:10px">
    <label style="font-size:12px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:.06em">Alamat (opsional)</label>
    <textarea id="antar_alamat" class="input" rows="2" placeholder="Jl. Mawar 12, RT 03/RW 04..."></textarea>
    <label style="font-size:12px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:.06em;margin-top:8px;display:block">Patokan/Catatan</label>
    <input type="text" id="antar_catatan" class="input" placeholder="Dekat warung Bu Inah">
    <div id="antarZonaWrap" style="display:none;margin-top:8px">
      <label style="font-size:12px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:.06em">Zona</label>
      <select id="antar_zona" class="input"><option value="">-- Pilih zona --</option></select>
    </div>
  </div>
</div>
```

Init JS: load zona kalau outlet pakai mode='zona'. Tambah di POS script block:

```js
async function toggleAntarSection() {
  const cb = document.getElementById('cb_antar');
  const sec = document.getElementById('antarSection');
  sec.style.display = cb.checked ? 'block' : 'none';
  if (cb.checked && !sec.dataset.loaded) {
    sec.dataset.loaded = '1';
    const r = await fetch('/outlet-settings.php?action=zona_list&outlet_id=<?= $oid ?>');
    const d = await r.json();
    if (d.rows && d.rows.length) {
      const sel = document.getElementById('antar_zona');
      sel.innerHTML = '<option value="">-- Pilih zona --</option>' + d.rows.map(z => `<option value="${z.id}">${z.nama} (Rp ${Number(z.fee).toLocaleString('id-ID')})</option>`).join('');
      document.getElementById('antarZonaWrap').style.display = 'block';
    }
  }
}
```

- [ ] **Step 2: POS saveOrder — tambah insert antar setelah order tersimpan**

Cari di pos.php saveOrder() (sekitar line 1830+) — setelah `data.id` (order ID) tersedia. Tambah:

```js
// Auto-create antar row kalau checkbox antar dicentang
if (document.getElementById('cb_antar')?.checked) {
  const antarPayload = {
    tipe: 'antar',
    transaksi_id: data.id,
    pelanggan_id: data.pelanggan_id || null,
    nama: document.getElementById('nama_pelanggan').value || data.nama_pelanggan,
    telepon: document.getElementById('telepon').value || '',
    alamat: document.getElementById('antar_alamat').value,
    catatan: document.getElementById('antar_catatan').value,
    zona_id: parseInt(document.getElementById('antar_zona').value) || null,
  };
  fetch('/antar-jemput.php?action=create', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(antarPayload)
  }).catch(e => console.warn('Antar auto-create failed', e));
}
```

Sesuaikan field name (`nama_pelanggan`, `telepon`) dengan yang sebenarnya di form pos.php — grep dulu untuk konfirmasi ID-nya. Adjust kalau beda.

- [ ] **Step 3: Produksi integration**

Di produksi.php submitStage() action save_stage handler (backend, action `save_stage`), setelah commit transaction sukses, kalau stage=diambil dan jenis=diantarkan, auto-create hl_antar_jemput row.

Cari di save_stage handler setelah `logAudit('proses_stage', ...)` line. Tambah:

```php
// Auto-create antar row kalau jenis=diantarkan dan belum ada
if ($stage === 'diambil' && ($dataFields['jenis'] ?? '') === 'diantarkan') {
    $existing = TenantQuery::rawOne("SELECT id FROM hl_antar_jemput WHERE transaksi_id=? AND tipe='antar' AND tenant_id=?", [$transaksiId, $tid]);
    if (!$existing) {
        $orderInfo = TenantQuery::rawOne("SELECT nama_pelanggan, telepon, pelanggan_id FROM hl_transaksi WHERE id=? AND tenant_id=?", [$transaksiId, $tid]);
        if ($orderInfo) {
            TenantQuery::insert('hl_antar_jemput', [
                'tipe'         => 'antar',
                'transaksi_id' => $transaksiId,
                'pelanggan_id' => $orderInfo['pelanggan_id'],
                'nama'         => $orderInfo['nama_pelanggan'] ?: 'Pelanggan',
                'telepon'      => $orderInfo['telepon'] ?: '',
                'catatan'      => 'Auto-created dari /produksi (jenis: diantarkan)',
                'outlet_id'    => $oid,
                'created_by'   => $userId,
            ]);
        }
    }
}
```

- [ ] **Step 4: Manual smoke test**

1. /pos buat order, centang Antar, isi alamat → save → cek hl_antar_jemput row tipe=antar tersimpan
2. /produksi stage diambil, pilih jenis=diantarkan → submit → cek hl_antar_jemput row auto-created (kalau belum ada)

- [ ] **Step 5: Commit**

```bash
git add pos.php produksi.php
git commit -m "feat(antar): integrasi POS (checkbox antar) + Produksi (auto-create dari jenis=diantarkan)"
```

---

### Task 10: Track integration + Report harian

**Files:**
- Modify: `track.php`
- Modify: `antar-jemput.php` (tambah view=report mode)

**Interfaces:**
- Consumes: hl_antar_jemput
- Produces:
  - track.php: section status kurir kalau ada antar_jemput row
  - /antar-jemput?view=report: page report aggregate harian

- [ ] **Step 1: Track page section status kurir**

Locate di track.php setelah section status proses (sekitar line 226 setelah pesan diambil). Tambah:

```php
// Section status antar (kalau ada hl_antar_jemput row untuk order ini)
if ($order) {
    try {
        $as = $db->prepare("SELECT aj.*, k.nama AS kurir_nama, k.no_hp AS kurir_hp
                              FROM hl_antar_jemput aj
                         LEFT JOIN hl_kurir k ON k.id = aj.kurir_id
                             WHERE aj.transaksi_id=? AND aj.tipe='antar'
                          ORDER BY aj.id DESC LIMIT 1");
        $as->execute([(int)$order['id']]);
        $antar = $as->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) { $antar = null; }
} else { $antar = null; }
```

Render di template (sebelum `</div>` close detail-order section):

```php
<?php if (!empty($antar) && in_array($antar['status'], ['assigned','menuju','sampai','done'], true)): ?>
<div style="margin-top:14px;padding:14px 16px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px">
  <div style="font-weight:700;color:#1E40AF;margin-bottom:6px">🛵 Status Antar</div>
  <?php if (!empty($antar['kurir_nama'])): ?>
    <div style="font-size:13.5px">Kurir: <strong><?= htmlspecialchars($antar['kurir_nama']) ?></strong>
    <?php if ($antar['kurir_hp']): ?> · <a href="tel:<?= htmlspecialchars($antar['kurir_hp']) ?>" style="color:#1E40AF"><?= htmlspecialchars($antar['kurir_hp']) ?></a><?php endif; ?>
    </div>
  <?php endif; ?>
  <div style="font-size:13px;margin-top:4px;color:#1E40AF">Status: <strong>
    <?php
      $stMap = ['assigned'=>'Kurir di-assign','menuju'=>'Dalam perjalanan','sampai'=>'Sampai lokasi','done'=>'Selesai diantar'];
      echo htmlspecialchars($stMap[$antar['status']] ?? $antar['status']);
    ?></strong> · <?= !empty($antar['updated_at']) ? date('H:i', strtotime($antar['updated_at'])) : '' ?></div>
</div>
<?php endif; ?>
```

- [ ] **Step 2: Report mode di antar-jemput.php**

Di top antar-jemput.php (setelah `$action = $_GET['action'] ?? '';`), tambah cek `$view = $_GET['view'] ?? '';`. Kalau `view==='report'`, render report page (skip list rendering).

Tambah di antar-jemput.php sebelum `$pageTitle = '🚚 Antar Jemput'`:

```php
$view = $_GET['view'] ?? '';
$reportDate = $_GET['date'] ?? date('Y-m-d');

if ($view === 'report') {
    // Aggregate query
    $stats = TenantQuery::rawOne(
        "SELECT
           SUM(tipe='jemput') AS jml_jemput,
           SUM(tipe='antar') AS jml_antar,
           SUM(status='done') AS jml_done,
           SUM(status IN ('pending','assigned','menuju','sampai')) AS jml_ongoing,
           SUM(status='cancel') AS jml_cancel,
           ROUND(AVG(CASE WHEN status='done' THEN TIMESTAMPDIFF(MINUTE, created_at, done_at) ELSE NULL END)) AS avg_minutes,
           SUM(CASE WHEN tipe='antar' AND status='done' THEN fee ELSE 0 END) AS fee_total
         FROM hl_antar_jemput
        WHERE tenant_id=? AND outlet_id=? AND DATE(created_at)=?",
        [$tid, $oid, $reportDate]
    );

    $perKurir = TenantQuery::raw(
        "SELECT k.nama, COUNT(*) AS total, SUM(aj.status='done') AS done,
                ROUND(AVG(CASE WHEN aj.status='done' THEN TIMESTAMPDIFF(MINUTE, aj.created_at, aj.done_at) ELSE NULL END)) AS avg_min
           FROM hl_antar_jemput aj
      LEFT JOIN hl_kurir k ON k.id = aj.kurir_id
          WHERE aj.tenant_id=? AND aj.outlet_id=? AND DATE(aj.created_at)=? AND aj.kurir_id IS NOT NULL
          GROUP BY k.id, k.nama
          ORDER BY done DESC, total DESC",
        [$tid, $oid, $reportDate]
    );
}
```

Render UI report (di dalam `<div class="hl-main">` ganti dengan):

```php
<?php if ($view === 'report'): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
  <h1 style="margin:0">📊 Report Antar Jemput</h1>
  <div>
    <input type="date" value="<?= htmlspecialchars($reportDate) ?>" onchange="window.location='?view=report&date='+this.value" class="hl-input" style="width:auto;display:inline-block">
    <a href="?" class="hl-btn hl-btn-outline">← Kembali</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:18px">
  <div class="hl-card" style="padding:14px;text-align:center"><div style="color:var(--gray);font-size:11px;text-transform:uppercase">Total</div><div style="font-size:24px;font-weight:800"><?= (int)($stats['jml_jemput'] + $stats['jml_antar']) ?></div><div style="font-size:11px;color:var(--gray)">📥 <?= (int)$stats['jml_jemput'] ?> · 📤 <?= (int)$stats['jml_antar'] ?></div></div>
  <div class="hl-card" style="padding:14px;text-align:center"><div style="color:var(--gray);font-size:11px;text-transform:uppercase">Selesai</div><div style="font-size:24px;font-weight:800;color:#065F46"><?= (int)$stats['jml_done'] ?></div></div>
  <div class="hl-card" style="padding:14px;text-align:center"><div style="color:var(--gray);font-size:11px;text-transform:uppercase">On-going</div><div style="font-size:24px;font-weight:800;color:#92400E"><?= (int)$stats['jml_ongoing'] ?></div></div>
  <div class="hl-card" style="padding:14px;text-align:center"><div style="color:var(--gray);font-size:11px;text-transform:uppercase">Avg Waktu</div><div style="font-size:24px;font-weight:800"><?= (int)$stats['avg_minutes'] ?: '-' ?>m</div></div>
  <div class="hl-card" style="padding:14px;text-align:center"><div style="color:var(--gray);font-size:11px;text-transform:uppercase">Fee</div><div style="font-size:24px;font-weight:800">Rp <?= number_format((int)$stats['fee_total'], 0, ',', '.') ?></div></div>
</div>

<h2 style="font-size:16px;margin:0 0 10px">Performance Kurir</h2>
<?php if (empty($perKurir)): ?>
  <div style="padding:30px;text-align:center;color:var(--gray)">Belum ada data</div>
<?php else: ?>
<div class="hl-card" style="padding:0">
  <?php foreach ($perKurir as $k): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #EEF1F8">
    <div><strong>🛵 <?= htmlspecialchars($k['nama']) ?></strong></div>
    <div style="font-size:13.5px;color:var(--gray)"><?= (int)$k['done'] ?>/<?= (int)$k['total'] ?> selesai · avg <?= (int)$k['avg_min'] ?: '-' ?>m</div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: /* normal list view dari Task 5+6 */ ?>
  <!-- existing list view di sini -->
<?php endif; ?>
```

Restructure file agar list view ada di `else` branch dari `view !== 'report'`.

- [ ] **Step 3: Manual smoke test**

1. Buka /track?order=KODE — kalau order ada hl_antar_jemput row → section status kurir muncul
2. Buka /antar-jemput?view=report — stats hari ini tampil
3. Ganti date picker → query refresh dengan date baru

- [ ] **Step 4: Commit**

```bash
git add track.php antar-jemput.php
git commit -m "feat(antar): integrasi /track status kurir + report harian"
```

---

## Self-Review Checklist (untuk implementer)

- [ ] Migration applied + tenant_schema.sql updated (3 tabel + ALTER outlets)
- [ ] Role kurir muncul di hl_roles backfilled ke semua tenant
- [ ] 3 permissions backfilled
- [ ] Sidebar items "Antar Jemput" + "Kurir" muncul untuk role yang berwenang
- [ ] /kurir-master CRUD + create_account berfungsi (username + password tampil sekali)
- [ ] Login sebagai role=kurir → redirect ke /kurir
- [ ] /antar-jemput list + create + assign + cancel berfungsi
- [ ] Race assign: FOR UPDATE lock validated (2 tab assign sama → 1 error)
- [ ] /kurir tasks list + status step (assigned→menuju→sampai) berfungsi
- [ ] Done modal: foto wajib antar, signature canvas antar, optional jemput
- [ ] foto_paths validated (starts with uploads/foto_antar/)
- [ ] POS checkbox antar → row auto-created saat save
- [ ] Produksi jenis=diantarkan → row auto-created
- [ ] Track: section status kurir muncul untuk order yang ada antar row
- [ ] Report harian aggregate stats correct + filter date picker

## Out of scope (tidak dikerjakan di plan ini)

- Real-time GPS tracking kurir
- Map view posisi kurir
- WA notif pelanggan status kurir
- Multi-outlet dispatch
- Komisi kurir / payroll integration
- Customer self-service form request jemput
