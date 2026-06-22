# Auto-Bonus Payroll Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bonus & penalti otomatis dihitung saat generate gaji bulanan via master rule (5 tipe) dengan multi-outlet targeting + breakdown komponen per karyawan.

**Architecture:** 3 tabel baru (`hl_bonus_rule`, `hl_bonus_rule_outlet`, `hl_gaji_komponen`). Evaluator class `core/BonusEvaluator.php` evaluate rules saat generate gaji. 1 halaman HQ baru `/hq/bonus-rule`, extend `/hq/penggajian` dengan checkbox + breakdown UI, extend `/api/payslip.php` untuk render komponen.

**Tech Stack:** PHP 8.1 + PDO. Reuse hq_guard, existing /hq/penggajian generate flow.

## Global Constraints

- HQ pages pakai `hq_guard.php` + role check `owner|superadmin`.
- POST endpoints pakai `verifyCsrf()` / `getCsrfToken()` (HQ pattern).
- Multi-outlet junction convention: 0 rows = berlaku semua outlet (sama pattern dengan loyalty reward).
- 5 tipe rule: hadir_penuh, tepat_waktu, lembur, zero_izin, penalti_telat.
- Re-evaluate idempotent: DELETE komponen non-manual sebelum re-INSERT.
- Komponen manual (jenis='manual') preserved saat re-evaluate.
- Telat detect via existing `outlets.jam_buka`.
- mysql: `/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master`
- Spec: [docs/superpowers/specs/2026-06-22-auto-bonus-payroll-design.md](../specs/2026-06-22-auto-bonus-payroll-design.md)

## File Structure

- **Create** `superadmin/sql/auto_bonus_migration.sql` — 3 tabel
- **Modify** `tenant/migrations/tenant_schema.sql` — append 3 tabel
- **Modify** `core/TenantProvisioner.php` — seed permission `bonus_rule.manage`
- **Modify** `hq/_layout_open.php` — sidebar item Bonus Rule (owner only)
- **Modify** `.htaccess` — whitelist hq/bonus-rule
- **Create** `hq/bonus-rule.php` — HQ CRUD page (owner only)
- **Create** `core/BonusEvaluator.php` — class evaluate + applyToGaji
- **Modify** `hq/penggajian.php` — checkbox "eval auto-bonus" + per-row breakdown UI + re-evaluate + tambah komponen manual
- **Modify** `api/payslip.php` — breakdown komponen di payslip

---

### Task 1: Migration 3 tabel + tenant_schema + apply

**Files:**
- Create: `superadmin/sql/auto_bonus_migration.sql`
- Modify: `tenant/migrations/tenant_schema.sql`

**Interfaces:**
- Produces: tabel `hl_bonus_rule`, `hl_bonus_rule_outlet`, `hl_gaji_komponen`

- [ ] **Step 1: Buat migration file**

```sql
-- superadmin/sql/auto_bonus_migration.sql
-- Auto-bonus payroll: master rule + junction multi-outlet + breakdown komponen

CREATE TABLE IF NOT EXISTS hl_bonus_rule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(100) NOT NULL,
  tipe ENUM('hadir_penuh','tepat_waktu','lembur','zero_izin','penalti_telat') NOT NULL,
  threshold INT NOT NULL DEFAULT 0,
  amount INT NOT NULL DEFAULT 0,
  amount_per_unit TINYINT(1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_bonus_rule_outlet (
  rule_id INT NOT NULL,
  outlet_id INT NOT NULL,
  PRIMARY KEY (rule_id, outlet_id),
  INDEX idx_outlet (outlet_id),
  FOREIGN KEY (rule_id) REFERENCES hl_bonus_rule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_gaji_komponen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gaji_id INT NOT NULL,
  jenis VARCHAR(40) NOT NULL,
  rule_id INT NULL,
  nama VARCHAR(100) NOT NULL,
  amount INT NOT NULL,
  keterangan TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gaji (gaji_id),
  FOREIGN KEY (gaji_id) REFERENCES hl_gaji(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Append ke tenant_schema.sql**

Same 3 CREATE TABLE blocks. Find appropriate position (setelah hl_gaji CREATE TABLE).

- [ ] **Step 3: Apply ke prod**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master < superadmin/sql/auto_bonus_migration.sql
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master -e "DESCRIBE hl_bonus_rule; DESCRIBE hl_bonus_rule_outlet; DESCRIBE hl_gaji_komponen;"
```

Expected: 3 tabel exist dengan columns sesuai spec.

- [ ] **Step 4: Commit**

```bash
git add superadmin/sql/auto_bonus_migration.sql tenant/migrations/tenant_schema.sql
git commit -m "feat(bonus): migration hl_bonus_rule + junction + hl_gaji_komponen"
```

---

### Task 2: Permission seed + sidebar + htaccess route

**Files:**
- Modify: `core/TenantProvisioner.php` (seedPermissions array)
- Modify: `hq/_layout_open.php` (sidebar item)
- Modify: `.htaccess` (route whitelist)

**Interfaces:**
- Produces: permission `bonus_rule.manage`, sidebar item Bonus Rule, route `/hq/bonus-rule`

- [ ] **Step 1: Tambah permission**

Di `core/TenantProvisioner.php` seedPermissions array, tambah:
```php
['bonus_rule.manage',    'bonus_rule','manage',        'Kelola master bonus & penalti rule (HQ)'],
```

Letakkan dekat permission HR/payroll lainnya (cari `karyawan.gaji` line).

- [ ] **Step 2: Backfill ke tenant existing**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master <<'SQL'
INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT DISTINCT tenant_id, 'bonus_rule.manage', 'bonus_rule', 'manage', 'Kelola master bonus & penalti rule (HQ)'
FROM hl_permissions WHERE kode='karyawan.gaji';
SQL
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master -e "SELECT COUNT(*) FROM hl_permissions WHERE kode='bonus_rule.manage';"
```

Expected: count > 0.

- [ ] **Step 3: Sidebar item di hq/_layout_open.php**

Find existing HQ sidebar nav. Cari section yang ada penggajian/karyawan. Tambah:

```php
<?php if ($hqIsOwner): ?>
<a href="/hq/bonus-rule"
   class="hq-side-link <?= $_aPage === 'hq-bonus-rule' ? 'active' : '' ?>">
  <span class="ico">🎯</span> Bonus Rule
</a>
<?php endif; ?>
```

Tambahkan setelah link Penggajian existing.

- [ ] **Step 4: htaccess whitelist**

Find di .htaccess kedua rule HQ (sekitar line 22 + line 36). Append `|bonus-rule` ke list.

Before:
```
RewriteRule ^hq/(dashboard|outlet|...|inventori|mesin|loyalty)\.php$ /hq/$1 [R=301,L]
```
After:
```
RewriteRule ^hq/(dashboard|outlet|...|inventori|mesin|loyalty|bonus-rule)\.php$ /hq/$1 [R=301,L]
```

Same to second rule (internal rewrite).

- [ ] **Step 5: Commit**

```bash
git add core/TenantProvisioner.php hq/_layout_open.php .htaccess
git commit -m "feat(bonus): permission bonus_rule.manage + sidebar Bonus Rule + htaccess route"
```

---

### Task 3: `/hq/bonus-rule` CRUD (NEW)

**Files:**
- Create: `hq/bonus-rule.php`

**Interfaces:**
- Consumes: hq_guard + hl_bonus_rule + hl_bonus_rule_outlet
- Produces:
  - GET `?action=list` → JSON rows + outlets per rule
  - GET `?action=outlets_list` → JSON outlets aktif tenant
  - POST `?action=save` (CSRF) → upsert rule + rewrite junction
  - POST `?action=delete` (CSRF) → soft-delete is_active=0

- [ ] **Step 1: Buat file**

```php
<?php
// hq/bonus-rule.php — HQ kelola bonus & penalti rule
$activePage = 'hq-bonus-rule';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

if (!$hqIsOwner) { http_response_code(403); die('Akses hanya untuk owner.'); }
requirePermission('bonus_rule.manage');

$tid = (int)TenantResolver::id();
$db  = Database::get();

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $rows = $db->prepare("SELECT * FROM hl_bonus_rule WHERE tenant_id=? ORDER BY is_active DESC, tipe, id");
        $rows->execute([$tid]);
        $list = $rows->fetchAll(PDO::FETCH_ASSOC);
        foreach ($list as &$r) {
            $st = $db->prepare("SELECT outlet_id FROM hl_bonus_rule_outlet WHERE rule_id=? ORDER BY outlet_id");
            $st->execute([(int)$r['id']]);
            $r['outlets'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        echo json_encode(['rows' => $list]);
        exit;
    }

    if ($action === 'outlets_list') {
        $rows = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status='active' ORDER BY is_main DESC, nama_outlet");
        $rows->execute([$tid]);
        echo json_encode(['rows' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id          = (int)($d['id'] ?? 0);
        $nama        = substr(trim($d['nama'] ?? ''), 0, 100);
        $tipe        = in_array(($d['tipe'] ?? ''), ['hadir_penuh','tepat_waktu','lembur','zero_izin','penalti_telat'], true) ? $d['tipe'] : '';
        $threshold   = (int)($d['threshold'] ?? 0);
        $amount      = (int)($d['amount'] ?? 0);
        $perUnit     = !empty($d['amount_per_unit']) ? 1 : 0;
        $isActive    = !empty($d['is_active']) ? 1 : 0;
        $scope       = in_array(($d['scope'] ?? ''), ['all','selected'], true) ? $d['scope'] : 'all';
        $outletIds   = array_map('intval', (array)($d['outlet_ids'] ?? []));

        if (!$nama || !$tipe) { echo json_encode(['error'=>'Nama + tipe wajib']); exit; }

        try {
            $db->beginTransaction();
            if ($id > 0) {
                $st = $db->prepare("UPDATE hl_bonus_rule SET nama=?, tipe=?, threshold=?, amount=?, amount_per_unit=?, is_active=? WHERE id=? AND tenant_id=?");
                $st->execute([$nama, $tipe, $threshold, $amount, $perUnit, $isActive, $id, $tid]);
            } else {
                $st = $db->prepare("INSERT INTO hl_bonus_rule (tenant_id, nama, tipe, threshold, amount, amount_per_unit, is_active) VALUES (?,?,?,?,?,?,?)");
                $st->execute([$tid, $nama, $tipe, $threshold, $amount, $perUnit, $isActive]);
                $id = (int)$db->lastInsertId();
            }

            // Rewrite junction (validate outlet_id belongs to tenant)
            $del = $db->prepare("DELETE FROM hl_bonus_rule_outlet WHERE rule_id=?");
            $del->execute([$id]);
            if ($scope === 'selected' && !empty($outletIds)) {
                $ins = $db->prepare("INSERT IGNORE INTO hl_bonus_rule_outlet (rule_id, outlet_id) SELECT ?, id FROM outlets WHERE id=? AND tenant_id=?");
                foreach ($outletIds as $oId) { if ($oId > 0) $ins->execute([$id, $oId, $tid]); }
            }

            logAudit('bonus_rule_save', 'bonus_rule', "id=$id tipe=$tipe scope=$scope outlets=" . implode(',', $outletIds));
            $db->commit();
            echo json_encode(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[hq/bonus-rule save] ' . $e->getMessage());
            echo json_encode(['error' => 'Gagal simpan']);
        }
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
        $st = $db->prepare("UPDATE hl_bonus_rule SET is_active=0 WHERE id=? AND tenant_id=?");
        $st->execute([$id, $tid]);
        if (!$st->rowCount()) { echo json_encode(['error'=>'Tidak ditemukan']); exit; }
        logAudit('bonus_rule_delete', 'bonus_rule', "id=$id");
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

$pageTitle = '🎯 Bonus Rule';
require ROOT . '/hq/_layout_open.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px">
  <h1 style="margin:0">🎯 Bonus & Penalti Rule</h1>
  <button class="hq-btn hq-btn-primary" onclick="openEdit()">+ Tambah Rule</button>
</div>

<div id="ruleList" style="min-height:200px">⏳ Memuat...</div>

<!-- Modal edit -->
<div class="hq-modal-overlay" id="modalEdit">
  <div class="hq-modal" style="max-width:560px">
    <div class="hq-modal-header"><span>Tambah/Edit Rule</span></div>
    <div class="hq-modal-body">
      <input type="hidden" id="e_id" value="0">
      <label>Nama Rule</label>
      <input type="text" id="e_nama" class="hq-input" maxlength="100" placeholder="Bonus Hadir Penuh">
      <label style="margin-top:10px">Tipe</label>
      <select id="e_tipe" class="hq-input" onchange="updateThresholdLabel()">
        <option value="hadir_penuh">Hadir Penuh</option>
        <option value="tepat_waktu">Tepat Waktu (min N hari)</option>
        <option value="lembur">Lembur (menit excess)</option>
        <option value="zero_izin">Zero Izin/Sakit</option>
        <option value="penalti_telat">Penalti Telat</option>
      </select>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
        <div>
          <label id="e_threshold_label">Threshold</label>
          <input type="number" id="e_threshold" class="hq-input" min="0" value="0">
        </div>
        <div>
          <label>Amount (Rp)</label>
          <input type="number" id="e_amount" class="hq-input" value="0">
        </div>
      </div>
      <label style="margin-top:10px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" id="e_per_unit"> Per unit excess (untuk lembur/penalti)
      </label>

      <div style="margin-top:14px;padding:12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px">
        <label style="font-weight:700;margin-bottom:8px;display:block">Berlaku di Outlet</label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px">
          <input type="radio" name="e_scope" value="all" checked onchange="toggleOutletPicker()"> Semua outlet
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="radio" name="e_scope" value="selected" onchange="toggleOutletPicker()"> Outlet tertentu
        </label>
        <div id="outletPicker" style="display:none;margin-top:10px;padding:10px;background:#fff;border:1px solid #E5E7EB;border-radius:6px;max-height:200px;overflow-y:auto"></div>
      </div>

      <label style="margin-top:10px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" id="e_active" checked> Aktif
      </label>
    </div>
    <div class="hq-modal-footer">
      <button class="hq-btn" onclick="closeEdit()">Batal</button>
      <button class="hq-btn hq-btn-primary" onclick="saveRule()">💾 Simpan</button>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= htmlspecialchars(getCsrfToken()) ?>';
let outletsCache = null;

const THRESHOLD_LABEL = {
  hadir_penuh: 'Threshold (n/a)',
  tepat_waktu: 'Min hari tepat waktu',
  lembur: 'Menit per hari (e.g. 480)',
  zero_izin: 'Threshold (n/a)',
  penalti_telat: 'Max telat diperbolehkan'
};

function updateThresholdLabel() {
  const tipe = document.getElementById('e_tipe').value;
  document.getElementById('e_threshold_label').textContent = THRESHOLD_LABEL[tipe] || 'Threshold';
}

async function loadList() {
  const r = await fetch('?action=list');
  const d = await r.json();
  const list = document.getElementById('ruleList');
  if (!d.rows.length) { list.innerHTML = '<div style="padding:40px;text-align:center;color:#94A3B8">Belum ada rule</div>'; return; }
  list.innerHTML = d.rows.map(r => {
    const outletsLabel = r.outlets.length === 0
      ? '<span style="background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:6px;font-size:11px">🌐 Semua outlet</span>'
      : '<span style="background:#DBEAFE;color:#1E40AF;padding:2px 8px;border-radius:6px;font-size:11px">🏪 ' + r.outlets.length + ' outlet</span>';
    const tipeLabel = {hadir_penuh:'Hadir Penuh', tepat_waktu:'Tepat Waktu', lembur:'Lembur', zero_izin:'Zero Izin', penalti_telat:'Penalti Telat'}[r.tipe] || r.tipe;
    const amountStr = r.amount_per_unit==1 ? `Rp ${r.amount}/unit` : `Rp ${Number(r.amount).toLocaleString('id-ID')}`;
    return `
      <div style="background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:10px;display:flex;justify-content:space-between;gap:12px;border:1px solid #E5E7EB ${r.is_active==0?';opacity:.5':''}">
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:15px">🎯 ${esc(r.nama)}</div>
          <div style="font-size:13px;color:#64748B;margin-top:3px">${esc(tipeLabel)} · threshold ${r.threshold} · ${amountStr}</div>
          <div style="margin-top:6px">${outletsLabel}${r.is_active==0 ? '<span style="background:#FEE;color:#991B1B;font-size:11px;padding:2px 8px;border-radius:6px;margin-left:6px">Non-aktif</span>' : ''}</div>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <button class="hq-btn-sm" onclick='openEdit(${JSON.stringify(r)})'>✏️</button>
          <button class="hq-btn-sm" onclick="deleteRule(${r.id})" style="color:#EF4444">🗑</button>
        </div>
      </div>`;
  }).join('');
}

async function toggleOutletPicker() {
  const scope = document.querySelector('input[name=e_scope]:checked')?.value;
  const picker = document.getElementById('outletPicker');
  if (scope !== 'selected') { picker.style.display = 'none'; return; }
  picker.style.display = 'block';
  if (!outletsCache) {
    const r = await fetch('?action=outlets_list');
    const d = await r.json();
    outletsCache = d.rows || [];
  }
  picker.innerHTML = outletsCache.map(o => `
    <label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer">
      <input type="checkbox" name="e_outlet" value="${o.id}"> ${esc(o.nama_outlet)}
    </label>`).join('');
}

function openEdit(r) {
  document.getElementById('e_id').value        = r?.id || 0;
  document.getElementById('e_nama').value      = r?.nama || '';
  document.getElementById('e_tipe').value      = r?.tipe || 'hadir_penuh';
  document.getElementById('e_threshold').value = r?.threshold || 0;
  document.getElementById('e_amount').value    = r?.amount || 0;
  document.getElementById('e_per_unit').checked = r ? (r.amount_per_unit==1) : false;
  document.getElementById('e_active').checked  = r ? (r.is_active==1) : true;
  updateThresholdLabel();

  const scope = (!r || r.outlets.length === 0) ? 'all' : 'selected';
  document.querySelectorAll('input[name=e_scope]').forEach(el => el.checked = (el.value === scope));
  toggleOutletPicker().then(() => {
    if (r?.outlets?.length) {
      r.outlets.forEach(oId => {
        const cb = document.querySelector(`input[name=e_outlet][value="${oId}"]`);
        if (cb) cb.checked = true;
      });
    }
  });

  document.getElementById('modalEdit').classList.add('open');
}
function closeEdit() { document.getElementById('modalEdit').classList.remove('open'); }

async function saveRule() {
  const id = parseInt(document.getElementById('e_id').value);
  const scope = document.querySelector('input[name=e_scope]:checked')?.value || 'all';
  const outletIds = [...document.querySelectorAll('input[name=e_outlet]:checked')].map(el => parseInt(el.value));
  const payload = {
    id, scope, outlet_ids: outletIds,
    nama: document.getElementById('e_nama').value,
    tipe: document.getElementById('e_tipe').value,
    threshold: parseInt(document.getElementById('e_threshold').value) || 0,
    amount: parseInt(document.getElementById('e_amount').value) || 0,
    amount_per_unit: document.getElementById('e_per_unit').checked ? 1 : 0,
    is_active: document.getElementById('e_active').checked ? 1 : 0,
  };
  const r = await fetch('?action=save', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(payload)});
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  closeEdit(); loadList();
}

async function deleteRule(id) {
  if (!confirm('Non-aktifkan rule ini?')) return;
  const r = await fetch('?action=delete', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id})});
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  loadList();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
loadList();
</script>

<?php require ROOT . '/hq/_layout_close.php'; ?>
```

- [ ] **Step 2: Manual smoke test**

1. Owner /hq/bonus-rule → list kosong
2. + Tambah → modal → pilih hadir_penuh, amount 500000, save → muncul di list dgn badge 🌐 Semua outlet
3. Edit → ubah ke outlet tertentu → centang 2 outlet → save → badge 🏪 2 outlet
4. Hapus → opacity dim
5. Manager (non-owner) akses → 403

- [ ] **Step 3: Commit**

```bash
git add hq/bonus-rule.php
git commit -m "feat(bonus): /hq/bonus-rule CRUD dengan multi-outlet checkbox"
```

---

### Task 4: `core/BonusEvaluator.php` (NEW)

**Files:**
- Create: `core/BonusEvaluator.php`

**Interfaces:**
- Produces:
  - `BonusEvaluator::evaluate(int $tid, int $userId, string $bulan, int $gajiPokok): array`
  - `BonusEvaluator::applyToGaji(int $gajiId): void`
  - `BonusEvaluator::workdays(string $bulan): int` (helper, public)

- [ ] **Step 1: Buat class**

```php
<?php
// core/BonusEvaluator.php — Evaluate bonus & penalti rule untuk gaji bulanan

class BonusEvaluator
{
    /** Hitung jumlah hari kerja dalam bulan (MVP: Senin-Sabtu, skip Minggu). */
    public static function workdays(string $bulan): int
    {
        // $bulan format 'YYYY-MM'
        $start = strtotime($bulan . '-01');
        if (!$start) return 26;
        $daysInMonth = (int)date('t', $start);
        $count = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ts = strtotime($bulan . '-' . str_pad($d, 2, '0', STR_PAD_LEFT));
            if (date('N', $ts) < 7) $count++; // 1-6 = Senin-Sabtu
        }
        return $count;
    }

    /** Return rules yang apply untuk karyawan di outlet tertentu. */
    private static function rulesForOutlet(int $tid, int $outletId): array
    {
        $db = Database::get();
        $st = $db->prepare(
            "SELECT r.* FROM hl_bonus_rule r
              WHERE r.tenant_id=? AND r.is_active=1
                AND (NOT EXISTS (SELECT 1 FROM hl_bonus_rule_outlet WHERE rule_id=r.id)
                     OR EXISTS (SELECT 1 FROM hl_bonus_rule_outlet WHERE rule_id=r.id AND outlet_id=?))
              ORDER BY r.tipe"
        );
        $st->execute([$tid, $outletId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Evaluate rules, return array komponen (NOT yet persisted). */
    public static function evaluate(int $tid, int $userId, string $bulan, int $gajiPokok): array
    {
        $db = Database::get();

        // Resolve karyawan outlet
        $u = $db->prepare("SELECT outlet_id FROM hl_users WHERE id=? AND tenant_id=? LIMIT 1");
        $u->execute([$userId, $tid]);
        $outletId = (int)($u->fetchColumn() ?: 0);
        if ($outletId === 0) return [];

        // Outlet config (jam_buka)
        $oRow = $db->prepare("SELECT jam_buka FROM outlets WHERE id=? AND tenant_id=? LIMIT 1");
        $oRow->execute([$outletId, $tid]);
        $jamBuka = $oRow->fetchColumn() ?: '08:00:00';

        // Absensi bulan
        $absSt = $db->prepare("SELECT tanggal, jam_masuk, jam_keluar, durasi_menit, status
                                 FROM hl_absensi
                                WHERE tenant_id=? AND user_id=? AND tanggal LIKE ?");
        $absSt->execute([$tid, $userId, $bulan . '-%']);
        $absen = $absSt->fetchAll(PDO::FETCH_ASSOC);

        // Stats
        $workdays = self::workdays($bulan);
        $hadirCount = 0;
        $tepatWaktuCount = 0;
        $telatCount = 0;
        $izinSakitCount = 0;
        $lemburTotal = 0; // total menit hadir
        foreach ($absen as $a) {
            if ($a['status'] === 'hadir') {
                $hadirCount++;
                if ($a['jam_masuk'] && $a['jam_masuk'] <= $jamBuka) $tepatWaktuCount++;
                if ($a['jam_masuk'] && $a['jam_masuk'] > $jamBuka)  $telatCount++;
                $lemburTotal += (int)($a['durasi_menit'] ?? 0);
            } elseif (in_array($a['status'], ['izin','sakit'], true)) {
                $izinSakitCount++;
            }
        }

        // Apply rules
        $rules = self::rulesForOutlet($tid, $outletId);
        $komponen = [];
        foreach ($rules as $r) {
            $thr = (int)$r['threshold'];
            $amt = (int)$r['amount'];
            $perUnit = (int)$r['amount_per_unit'] === 1;
            $name = $r['nama'];
            switch ($r['tipe']) {
                case 'hadir_penuh':
                    if ($hadirCount >= $workdays) {
                        $komponen[] = ['jenis'=>'bonus_hadir_penuh','rule_id'=>$r['id'],'nama'=>$name,'amount'=>$amt,'keterangan'=>"Hadir $hadirCount/$workdays"];
                    }
                    break;
                case 'tepat_waktu':
                    if ($tepatWaktuCount >= $thr) {
                        $komponen[] = ['jenis'=>'bonus_tepat_waktu','rule_id'=>$r['id'],'nama'=>$name,'amount'=>$amt,'keterangan'=>"Tepat waktu $tepatWaktuCount hari"];
                    }
                    break;
                case 'lembur':
                    // Lembur = sum(durasi excess di atas threshold per hari)
                    $excess = 0;
                    foreach ($absen as $a) {
                        if ($a['status'] === 'hadir') {
                            $d = (int)($a['durasi_menit'] ?? 0);
                            if ($d > $thr) $excess += ($d - $thr);
                        }
                    }
                    if ($excess > 0) {
                        $bonus = $perUnit ? ($excess * $amt) : $amt;
                        $komponen[] = ['jenis'=>'bonus_lembur','rule_id'=>$r['id'],'nama'=>$name,'amount'=>$bonus,'keterangan'=>"Lembur $excess menit excess"];
                    }
                    break;
                case 'zero_izin':
                    if ($izinSakitCount === 0 && $hadirCount > 0) {
                        $komponen[] = ['jenis'=>'bonus_zero_izin','rule_id'=>$r['id'],'nama'=>$name,'amount'=>$amt,'keterangan'=>"Tidak ada izin/sakit"];
                    }
                    break;
                case 'penalti_telat':
                    if ($telatCount > $thr) {
                        $excess = $telatCount - $thr;
                        $penalti = $perUnit ? ($excess * $amt) : $amt;
                        // Negative untuk potongan
                        $komponen[] = ['jenis'=>'penalti_telat','rule_id'=>$r['id'],'nama'=>$name,'amount'=>-abs($penalti),'keterangan'=>"Telat $telatCount kali (max $thr)"];
                    }
                    break;
            }
        }

        return $komponen;
    }

    /** Evaluate + persist komponen + recompute hl_gaji.bonus/potongan/total. */
    public static function applyToGaji(int $gajiId): void
    {
        $db = Database::get();
        $gj = $db->prepare("SELECT * FROM hl_gaji WHERE id=? LIMIT 1");
        $gj->execute([$gajiId]);
        $gaji = $gj->fetch(PDO::FETCH_ASSOC);
        if (!$gaji) return;

        $tid = (int)$gaji['tenant_id'];
        $userId = (int)$gaji['user_id'];
        $bulan = (string)$gaji['bulan'];
        $gajiPokok = (int)$gaji['gaji_pokok'];

        try {
            $db->beginTransaction();

            // DELETE komponen non-manual (preserve owner manual adjustments)
            $del = $db->prepare("DELETE FROM hl_gaji_komponen WHERE gaji_id=? AND jenis != 'manual'");
            $del->execute([$gajiId]);

            // INSERT komponen pokok
            $insPokok = $db->prepare("INSERT INTO hl_gaji_komponen (gaji_id, jenis, rule_id, nama, amount, keterangan) VALUES (?, 'pokok', NULL, 'Gaji Pokok', ?, NULL)");
            $insPokok->execute([$gajiId, $gajiPokok]);

            // Evaluate + INSERT rule-driven komponen
            $komponen = self::evaluate($tid, $userId, $bulan, $gajiPokok);
            $insK = $db->prepare("INSERT INTO hl_gaji_komponen (gaji_id, jenis, rule_id, nama, amount, keterangan) VALUES (?,?,?,?,?,?)");
            foreach ($komponen as $k) {
                $insK->execute([$gajiId, $k['jenis'], $k['rule_id'], $k['nama'], (int)$k['amount'], $k['keterangan']]);
            }

            // Recompute gaji totals (sum semua komponen including manual)
            $sumSt = $db->prepare("SELECT SUM(CASE WHEN amount>0 AND jenis!='pokok' THEN amount ELSE 0 END) AS sum_bonus,
                                          SUM(CASE WHEN amount<0 THEN ABS(amount) ELSE 0 END) AS sum_pot
                                     FROM hl_gaji_komponen WHERE gaji_id=?");
            $sumSt->execute([$gajiId]);
            $sums = $sumSt->fetch(PDO::FETCH_ASSOC);
            $bonus = (int)($sums['sum_bonus'] ?? 0);
            $potongan = (int)($sums['sum_pot'] ?? 0);
            $total = $gajiPokok + $bonus - $potongan;

            $upd = $db->prepare("UPDATE hl_gaji SET bonus=?, potongan=?, total=? WHERE id=?");
            $upd->execute([$bonus, $potongan, $total, $gajiId]);

            try { logAudit('gaji_bonus_eval', 'gaji', "id=$gajiId komponen=" . count($komponen) . " bonus=$bonus pot=$potongan"); } catch (Throwable) {}
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[BonusEvaluator applyToGaji] ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 2: Manual unit test (no formal test framework)**

Buat scratch test untuk verify evaluator logic:
```bash
cat > /tmp/bonus_test.php <<'EOF'
<?php
require_once '/Users/rizky/Documents/lamasy/master/config/db.php';
require_once '/Users/rizky/Documents/lamasy/core/Database.php';
require_once '/Users/rizky/Documents/lamasy/core/BonusEvaluator.php';

// Workdays test
echo "Workdays 2026-06: " . BonusEvaluator::workdays('2026-06') . " (expected 26)\n";
echo "Workdays 2026-02: " . BonusEvaluator::workdays('2026-02') . " (expected 24)\n";

// Live evaluate kalau ada karyawan + gaji existing
// Pilih karyawan + bulan dari prod via mysql query manual
EOF
php /tmp/bonus_test.php
rm /tmp/bonus_test.php
```

Adjust expected values setelah verify.

- [ ] **Step 3: Commit**

```bash
git add core/BonusEvaluator.php
git commit -m "feat(bonus): core/BonusEvaluator evaluate + applyToGaji + workdays helper"
```

---

### Task 5: `/hq/penggajian` extend (checkbox + breakdown UI + re-evaluate + komponen manual)

**Files:**
- Modify: `hq/penggajian.php`

**Interfaces:**
- Consumes: `BonusEvaluator::applyToGaji()` from Task 4
- Produces:
  - Checkbox "Evaluate auto-bonus" di generate form
  - Per-row tombol "Detail" expand → list komponen
  - Tombol "Re-evaluate" per gaji
  - Modal "Tambah Komponen Manual"

- [ ] **Step 1: Update generate action call evaluator**

Locate di hq/penggajian.php `action === 'generate'` (sekitar line 67). Setelah loop INSERT hl_gaji, tambah:

```php
// Setelah loop INSERT/UPDATE hl_gaji per karyawan, call evaluator kalau checkbox
$evalBonus = !empty($d['eval_bonus']);
if ($evalBonus) {
    require_once ROOT . '/core/BonusEvaluator.php';
    // Loop semua gaji bulan ini di tenant
    $gajis = $db->prepare("SELECT id FROM hl_gaji WHERE tenant_id=? AND bulan=?");
    $gajis->execute([$tid, $bulan]);
    foreach ($gajis->fetchAll(PDO::FETCH_COLUMN) as $gid) {
        BonusEvaluator::applyToGaji((int)$gid);
    }
}
```

- [ ] **Step 2: Tambah action `komponen_list`, `komponen_add`, `re_evaluate`**

Sebelum block "Unknown action" atau di top action handlers:

```php
if ($action === 'komponen_list') {
    $gajiId = (int)($_GET['gaji_id'] ?? 0);
    if ($gajiId <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
    // Verify gaji belongs to tenant
    $own = $db->prepare("SELECT 1 FROM hl_gaji WHERE id=? AND tenant_id=?");
    $own->execute([$gajiId, $tid]);
    if (!$own->fetchColumn()) { echo json_encode(['error'=>'Forbidden']); exit; }
    $rows = $db->prepare("SELECT * FROM hl_gaji_komponen WHERE gaji_id=? ORDER BY jenis='pokok' DESC, amount DESC, id");
    $rows->execute([$gajiId]);
    echo json_encode(['rows' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'komponen_add' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $gajiId = (int)($d['gaji_id'] ?? 0);
    $nama = substr(trim($d['nama'] ?? ''), 0, 100);
    $amount = (int)($d['amount'] ?? 0);
    $keterangan = trim($d['keterangan'] ?? '');
    if ($gajiId <= 0 || !$nama || $amount === 0) { echo json_encode(['error'=>'Input invalid']); exit; }
    $own = $db->prepare("SELECT 1 FROM hl_gaji WHERE id=? AND tenant_id=?");
    $own->execute([$gajiId, $tid]);
    if (!$own->fetchColumn()) { echo json_encode(['error'=>'Forbidden']); exit; }

    $ins = $db->prepare("INSERT INTO hl_gaji_komponen (gaji_id, jenis, rule_id, nama, amount, keterangan) VALUES (?, 'manual', NULL, ?, ?, ?)");
    $ins->execute([$gajiId, $nama, $amount, $keterangan]);

    // Recompute totals
    require_once ROOT . '/core/BonusEvaluator.php';
    // Trigger recompute by re-running applyToGaji which preserves manual
    // Actually applyToGaji also re-evaluates rules. For manual-only update, just recompute sums:
    $sumSt = $db->prepare("SELECT SUM(CASE WHEN amount>0 AND jenis!='pokok' THEN amount ELSE 0 END) AS sb,
                                  SUM(CASE WHEN amount<0 THEN ABS(amount) ELSE 0 END) AS sp,
                                  SUM(CASE WHEN jenis='pokok' THEN amount ELSE 0 END) AS sp_pokok
                             FROM hl_gaji_komponen WHERE gaji_id=?");
    $sumSt->execute([$gajiId]);
    $s = $sumSt->fetch(PDO::FETCH_ASSOC);
    $upd = $db->prepare("UPDATE hl_gaji SET bonus=?, potongan=?, total=? WHERE id=?");
    $upd->execute([(int)$s['sb'], (int)$s['sp'], (int)$s['sp_pokok'] + (int)$s['sb'] - (int)$s['sp'], $gajiId]);

    logAudit('komponen_add', 'gaji', "gaji_id=$gajiId nama=$nama amount=$amount");
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 're_evaluate' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $gajiId = (int)($d['gaji_id'] ?? 0);
    if ($gajiId <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
    $own = $db->prepare("SELECT 1 FROM hl_gaji WHERE id=? AND tenant_id=?");
    $own->execute([$gajiId, $tid]);
    if (!$own->fetchColumn()) { echo json_encode(['error'=>'Forbidden']); exit; }

    require_once ROOT . '/core/BonusEvaluator.php';
    BonusEvaluator::applyToGaji($gajiId);
    echo json_encode(['ok'=>true]);
    exit;
}
```

- [ ] **Step 3: UI — checkbox di form generate**

Find generate form di hq/penggajian.php (HTML, label "Generate" atau similar). Tambah checkbox sebelum tombol generate:

```html
<label style="display:flex;align-items:center;gap:8px;margin:10px 0;cursor:pointer">
  <input type="checkbox" id="eval_bonus" checked> Evaluate auto-bonus (apply rules)
</label>
```

JS submit handler tambah `eval_bonus`:
```js
body: JSON.stringify({bulan, outlet_id:oid, eval_bonus: document.getElementById('eval_bonus').checked})
```

- [ ] **Step 4: UI — per-row Detail + Re-evaluate + Manual**

Cari list rendering gaji per karyawan. Tambah tombol di tiap row:

```html
<button onclick="showKomponen(${gaji.id})">▾ Detail</button>
<button onclick="reEvaluate(${gaji.id})">🔄 Re-eval</button>
<button onclick="openAddKomponen(${gaji.id})">+ Manual</button>
```

JS handlers:
```js
async function showKomponen(gajiId) {
  const r = await fetch('?action=komponen_list&gaji_id=' + gajiId);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  const html = d.rows.map(k => `
    <tr>
      <td>${esc(k.jenis)}</td>
      <td>${esc(k.nama)}</td>
      <td style="text-align:right;font-weight:600;color:${k.amount>=0?'#065F46':'#991B1B'}">Rp ${Number(k.amount).toLocaleString('id-ID')}</td>
      <td style="font-size:12px;color:#64748B">${esc(k.keterangan||'')}</td>
    </tr>`).join('');
  // Show in modal or expand row
  const modal = document.getElementById('modalKomponen') || createKomponenModal();
  modal.querySelector('#komponenTbody').innerHTML = html;
  modal.classList.add('open');
}

async function reEvaluate(gajiId) {
  if (!confirm('Re-evaluate rules untuk gaji ini? (komponen manual tetap dipertahankan)')) return;
  const r = await fetch('?action=re_evaluate', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify({gaji_id: gajiId})});
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  showToast('✅ Re-evaluated', 'success');
  loadList(); // refresh list
}

async function openAddKomponen(gajiId) {
  const nama = prompt('Nama komponen (e.g. THR, Bonus Project, Potongan Pinjaman):');
  if (!nama) return;
  const amountStr = prompt('Amount (positive=bonus, negative=potongan):');
  if (amountStr === null) return;
  const amount = parseInt(amountStr);
  if (isNaN(amount) || amount === 0) { alert('Amount harus angka non-zero'); return; }
  const ket = prompt('Keterangan (opsional):') || '';
  const r = await fetch('?action=komponen_add', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify({gaji_id: gajiId, nama, amount, keterangan: ket})});
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  showToast('✅ Komponen ditambah', 'success');
  loadList();
}

function createKomponenModal() {
  const div = document.createElement('div');
  div.id = 'modalKomponen';
  div.className = 'hq-modal-overlay';
  div.innerHTML = `
    <div class="hq-modal" style="max-width:640px">
      <div class="hq-modal-header"><span>Breakdown Komponen Gaji</span>
        <button onclick="document.getElementById('modalKomponen').classList.remove('open')">✕</button></div>
      <div class="hq-modal-body">
        <table style="width:100%;font-size:13px">
          <thead><tr style="background:#F9FAFB"><th align="left">Jenis</th><th align="left">Nama</th><th align="right">Amount</th><th align="left">Ket</th></tr></thead>
          <tbody id="komponenTbody"></tbody>
        </table>
      </div>
    </div>`;
  document.body.appendChild(div);
  return div;
}
```

- [ ] **Step 5: Manual smoke test**

1. Owner /hq/bonus-rule → buat rule hadir_penuh +500k
2. Karyawan Budi outlet aktif, 26 hari absen status=hadir di bulan
3. /hq/penggajian → bulan tersebut → centang Evaluate auto-bonus → klik Generate
4. Lihat list: Budi total = pokok + 500k bonus
5. Klik "▾ Detail" → modal show 2 komponen (pokok + bonus_hadir_penuh)
6. Klik "+ Manual" → input nama=THR amount=1000000 → save → total naik 1jt
7. Klik "🔄 Re-eval" → komponen bonus_hadir_penuh re-computed, komponen manual TETAP

- [ ] **Step 6: Commit**

```bash
git add hq/penggajian.php
git commit -m "feat(bonus): /hq/penggajian eval checkbox + breakdown + re-evaluate + manual komponen"
```

---

### Task 6: `/api/payslip.php` extend dengan breakdown

**Files:**
- Modify: `api/payslip.php`

**Interfaces:**
- Consumes: hl_gaji_komponen
- Produces: payslip render dengan komponen breakdown

- [ ] **Step 1: Query komponen + render**

Find di api/payslip.php query gaji + render. Setelah load $gaji, tambah:

```php
// Load komponen kalau ada
$komponen = [];
try {
    $st = $db->prepare("SELECT jenis, nama, amount, keterangan FROM hl_gaji_komponen WHERE gaji_id=? ORDER BY jenis='pokok' DESC, amount DESC, id");
    $st->execute([(int)$gaji['id']]);
    $komponen = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}
```

Di HTML payslip template, find section yang render gaji breakdown (pokok + bonus + potongan + total). Replace atau extend:

```php
<?php if (!empty($komponen)): ?>
<table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:13px">
  <thead><tr style="background:#F3F4F6"><th align="left" style="padding:6px">Komponen</th><th align="right" style="padding:6px">Jumlah</th></tr></thead>
  <tbody>
    <?php foreach ($komponen as $k): ?>
    <tr>
      <td style="padding:5px 6px;border-bottom:1px solid #E5E7EB">
        <?= htmlspecialchars($k['nama']) ?>
        <?php if (!empty($k['keterangan'])): ?>
          <div style="font-size:11px;color:#6B7280"><?= htmlspecialchars($k['keterangan']) ?></div>
        <?php endif; ?>
      </td>
      <td align="right" style="padding:5px 6px;border-bottom:1px solid #E5E7EB;font-weight:600;color:<?= $k['amount']>=0 ? '#065F46' : '#991B1B' ?>">
        Rp <?= number_format((int)$k['amount'], 0, ',', '.') ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:#F0FDF4">
      <td style="padding:8px 6px;font-weight:700">TOTAL</td>
      <td align="right" style="padding:8px 6px;font-weight:700;font-size:15px;color:#0F766E">Rp <?= number_format((int)$gaji['total'], 0, ',', '.') ?></td>
    </tr>
  </tbody>
</table>
<?php else: ?>
  <!-- existing fallback render kalau no komponen -->
<?php endif; ?>
```

Keep existing simple render sebagai fallback (kalau ada gaji tanpa komponen, e.g., gaji lama sebelum migration).

- [ ] **Step 2: Manual smoke test**

1. Owner /hq/karyawan → klik Print Payslip karyawan yang punya komponen
2. PDF/HTML payslip render breakdown table dengan setiap komponen + total
3. Pelanggan/karyawan baca payslip → clear breakdown

- [ ] **Step 3: Commit**

```bash
git add api/payslip.php
git commit -m "feat(bonus): payslip breakdown komponen"
```

---

## Self-Review Checklist (untuk implementer)

- [ ] Migration applied: 3 tabel + 3 FK terbentuk benar
- [ ] Permission bonus_rule.manage backfilled ke tenant existing
- [ ] /hq/bonus-rule: owner only, sidebar gated
- [ ] BonusEvaluator: workdays helper benar (Senin-Sabtu count)
- [ ] Evaluator handle semua 5 tipe + per_unit logic
- [ ] applyToGaji idempotent: DELETE non-manual sebelum INSERT
- [ ] Komponen manual preserved saat re-evaluate
- [ ] /hq/penggajian: checkbox eval_bonus terhubung backend
- [ ] Per-row Detail / Re-eval / Manual buttons berfungsi
- [ ] Payslip render breakdown komponen
- [ ] Audit log entries untuk save_rule, gaji_bonus_eval, komponen_add

## Out of scope (Phase 2, defer)

- Notifikasi WA ke karyawan saat bonus dapat
- Bonus referral / komisi performa penjualan
- Custom rule via expression engine
- Per-outlet jam_buka per hari kerja
- Public holiday calendar nasional
- Bonus prorata join mid-month
- Multi-month rolling rule
