# Loyalty Reward Multi-Outlet Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Loyalty reward bisa dikelola di HQ dengan multi-outlet checkbox + POS UX upgrade dengan catalog browser + portal pelanggan display read-only.

**Architecture:** Tabel junction `hl_poin_reward_outlet` (many-to-many reward↔outlet). Junction kosong = berlaku semua outlet. `hl_poin_reward.outlet_id` di-nullable (legacy keep). Query union global+specific via NOT EXISTS / EXISTS subqueries. 1 halaman HQ baru, 1 halaman outlet existing extend, POS catalog UI, portal section.

**Tech Stack:** PHP 8.1 + PDO + vanilla JS. Reuse `core/Loyalty.php`, existing hq_guard, tenant_guard.

## Global Constraints

- HQ pages pakai `hq_guard.php` + role check `owner|superadmin`.
- Outlet pages pakai `tenant_guard.php` + `requirePermission('pelanggan.view'|'pelanggan.edit')`.
- POST endpoints pakai `verifyCsrf()`.
- Junction convention: 0 rows = berlaku semua outlet; N rows = berlaku N outlet spesifik.
- `hl_poin_reward.outlet_id` nullable + legacy (jangan dipakai logic baru).
- mysql binary: `/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master`
- Spec: [docs/superpowers/specs/2026-06-22-loyalty-reward-multi-outlet-design.md](../specs/2026-06-22-loyalty-reward-multi-outlet-design.md)

## File Structure

- **Create** `superadmin/sql/loyalty_reward_multi_outlet_migration.sql` — junction + ALTER + backfill
- **Modify** `tenant/migrations/tenant_schema.sql` — append junction table + outlet_id nullable note
- **Modify** `core/Loyalty.php` — update `availableRewards()` query + add `applicableOutlets()`
- **Create** `hq/loyalty.php` — NEW HQ page (CRUD + multi-outlet checkbox)
- **Modify** `loyalty.php` — extend list dengan badge HQ/outlet + lock untuk non-owner
- **Modify** `pos.php` — catalog UI di order modal + JS apply + backend reward_id handler
- **Modify** `pelanggan.php` — section "Hadiah Tersedia"
- **Modify** `components.php` — hq sidebar item kalau perlu
- **Modify** `hq/_layout_open.php` — sidebar item `Sistem Poin` di group Analitik atau Master
- **Modify** `.htaccess` — whitelist `hq/loyalty` route

---

### Task 1: Migration junction table + backfill + tenant_schema

**Files:**
- Create: `superadmin/sql/loyalty_reward_multi_outlet_migration.sql`
- Modify: `tenant/migrations/tenant_schema.sql` (append junction + outlet_id nullable note)

**Interfaces:**
- Produces: tabel `hl_poin_reward_outlet`; kolom `hl_poin_reward.outlet_id` nullable

- [ ] **Step 1: Buat migration file**

```sql
-- superadmin/sql/loyalty_reward_multi_outlet_migration.sql
-- Junction reward↔outlet untuk multi-outlet targeting

CREATE TABLE IF NOT EXISTS hl_poin_reward_outlet (
  reward_id INT NOT NULL,
  outlet_id INT NOT NULL,
  PRIMARY KEY (reward_id, outlet_id),
  INDEX idx_outlet (outlet_id),
  FOREIGN KEY (reward_id) REFERENCES hl_poin_reward(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- outlet_id legacy → nullable (logic baru pakai junction)
ALTER TABLE hl_poin_reward MODIFY outlet_id INT NULL;

-- Backfill: tiap existing reward → 1 junction row dengan outlet existing
INSERT IGNORE INTO hl_poin_reward_outlet (reward_id, outlet_id)
SELECT id, outlet_id FROM hl_poin_reward WHERE outlet_id IS NOT NULL;
```

- [ ] **Step 2: Append ke tenant_schema.sql**

Find `CREATE TABLE IF NOT EXISTS hl_poin_reward` di tenant_schema.sql. After it, add the junction:

```sql
CREATE TABLE IF NOT EXISTS hl_poin_reward_outlet (
  reward_id INT NOT NULL,
  outlet_id INT NOT NULL,
  PRIMARY KEY (reward_id, outlet_id),
  INDEX idx_outlet (outlet_id),
  FOREIGN KEY (reward_id) REFERENCES hl_poin_reward(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Also if hl_poin_reward CREATE TABLE specifies `outlet_id INT NOT NULL`, update to `outlet_id INT DEFAULT NULL`. Read schema first to find exact line.

- [ ] **Step 3: Apply ke prod**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master < superadmin/sql/loyalty_reward_multi_outlet_migration.sql
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master -e "DESCRIBE hl_poin_reward_outlet; SHOW COLUMNS FROM hl_poin_reward LIKE 'outlet_id'; SELECT COUNT(*) AS jct_rows FROM hl_poin_reward_outlet; SELECT COUNT(*) AS rewards FROM hl_poin_reward;"
```

Expected: junction table exists + outlet_id NULL allowed + `jct_rows >= rewards` (semua existing rewards backfilled).

- [ ] **Step 4: Commit**

```bash
git add superadmin/sql/loyalty_reward_multi_outlet_migration.sql tenant/migrations/tenant_schema.sql
git commit -m "feat(loyalty): junction hl_poin_reward_outlet + nullable outlet_id + backfill"
```

---

### Task 2: Update `core/Loyalty.php` — availableRewards query + applicableOutlets method

**Files:**
- Modify: `core/Loyalty.php` line ~90 (availableRewards method)

**Interfaces:**
- Consumes: tabel `hl_poin_reward_outlet` dari Task 1
- Produces:
  - `Loyalty::availableRewards(int $tenantId, int $outletId, int $poinSaatIni): array` — return rewards yang apply ke outlet
  - `Loyalty::applicableOutlets(int $rewardId): array` — return array outlet_id (empty = all outlets convention)

- [ ] **Step 1: Replace availableRewards query**

Find di `core/Loyalty.php`:
```php
$stmt = $db->prepare("SELECT * FROM hl_poin_reward
                       WHERE tenant_id=? AND outlet_id=? AND is_active=1
                       ORDER BY poin_dibutuhkan ASC");
$stmt->execute([$tenantId, $outletId]);
```

Replace dengan:
```php
$stmt = $db->prepare(
    "SELECT r.* FROM hl_poin_reward r
      WHERE r.tenant_id=? AND r.is_active=1
        AND (
          NOT EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id)
          OR EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id AND outlet_id=?)
        )
      ORDER BY r.poin_dibutuhkan ASC"
);
$stmt->execute([$tenantId, $outletId]);
```

- [ ] **Step 2: Tambah method `applicableOutlets`**

After `availableRewards()` method, tambah:

```php
/** Return list outlet_id yang apply untuk reward. Empty array = berlaku semua outlet (no junction). */
public static function applicableOutlets(int $rewardId): array
{
    try {
        $db = Database::get();
        $st = $db->prepare("SELECT outlet_id FROM hl_poin_reward_outlet WHERE reward_id=? ORDER BY outlet_id");
        $st->execute([$rewardId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) { return []; }
}

/** Return true kalau reward dimanage HQ (global atau multi-outlet) — kasir tidak boleh edit. */
public static function isHqManaged(int $rewardId): bool
{
    $outlets = self::applicableOutlets($rewardId);
    return count($outlets) !== 1; // 0 = global, 2+ = multi-outlet
}
```

- [ ] **Step 3: Manual smoke test post-deploy**

Via DevTools fetch atau /loyalty page check. Verify availableRewards return correct set.

- [ ] **Step 4: Commit**

```bash
git add core/Loyalty.php
git commit -m "feat(loyalty): availableRewards via junction + applicableOutlets helper"
```

---

### Task 3: `/hq/loyalty` page (NEW)

**Files:**
- Create: `hq/loyalty.php`
- Modify: `hq/_layout_open.php` (sidebar item)
- Modify: `.htaccess` (route whitelist)

**Interfaces:**
- Produces:
  - GET `/hq/loyalty?action=list` → JSON rows (reward + outlet array)
  - GET `/hq/loyalty?action=outlets_list` → JSON outlets aktif di tenant
  - POST `/hq/loyalty?action=save` → upsert reward + rewrite junction
  - POST `/hq/loyalty?action=delete` → soft-delete (set is_active=0)

- [ ] **Step 1: Buat hq/loyalty.php**

```php
<?php
// hq/loyalty.php — HQ kelola reward loyalty (multi-outlet)
$activePage = 'hq-loyalty';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/Loyalty.php';

$role = currentUser()['role'] ?? '';
if (!in_array($role, ['owner','superadmin'], true)) {
    http_response_code(403);
    die('Akses hanya untuk owner.');
}

$tid = (int)TenantResolver::id();
$db  = Database::get();

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $rows = $db->prepare("SELECT * FROM hl_poin_reward WHERE tenant_id=? ORDER BY is_active DESC, poin_dibutuhkan");
        $rows->execute([$tid]);
        $list = $rows->fetchAll(PDO::FETCH_ASSOC);
        foreach ($list as &$r) {
            $r['outlets'] = Loyalty::applicableOutlets((int)$r['id']);
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
        verifyHqCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id          = (int)($d['id'] ?? 0);
        $nama        = substr(trim($d['nama_reward'] ?? ''), 0, 100);
        $deskripsi   = trim($d['deskripsi'] ?? '');
        $poin        = max(1, (int)($d['poin_dibutuhkan'] ?? 0));
        $tipe        = in_array(($d['tipe'] ?? ''), ['diskon_nominal','diskon_persen','gratis_layanan'], true) ? $d['tipe'] : 'diskon_nominal';
        $nilai       = max(0, (int)($d['nilai'] ?? 0));
        $minTransaksi = max(0, (int)($d['min_transaksi'] ?? 0));
        $maxRedeem   = max(0, (int)($d['max_redeem_per_bulan'] ?? 0));
        $isActive    = !empty($d['is_active']) ? 1 : 0;
        $scope       = $d['scope'] ?? 'all'; // 'all' | 'selected'
        $outletIds   = array_map('intval', (array)($d['outlet_ids'] ?? []));

        if (!$nama) { echo json_encode(['error'=>'Nama wajib']); exit; }

        try {
            $db->beginTransaction();
            if ($id > 0) {
                $st = $db->prepare("UPDATE hl_poin_reward SET nama_reward=?, deskripsi=?, poin_dibutuhkan=?, tipe=?, nilai=?, min_transaksi=?, max_redeem_per_bulan=?, is_active=? WHERE id=? AND tenant_id=?");
                $st->execute([$nama, $deskripsi, $poin, $tipe, $nilai, $minTransaksi, $maxRedeem, $isActive, $id, $tid]);
            } else {
                $st = $db->prepare("INSERT INTO hl_poin_reward (tenant_id, outlet_id, nama_reward, deskripsi, poin_dibutuhkan, tipe, nilai, min_transaksi, max_redeem_per_bulan, is_active) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)");
                $st->execute([$tid, $nama, $deskripsi, $poin, $tipe, $nilai, $minTransaksi, $maxRedeem, $isActive]);
                $id = (int)$db->lastInsertId();
            }

            // Rewrite junction
            $del = $db->prepare("DELETE FROM hl_poin_reward_outlet WHERE reward_id=?");
            $del->execute([$id]);
            if ($scope === 'selected' && !empty($outletIds)) {
                $ins = $db->prepare("INSERT IGNORE INTO hl_poin_reward_outlet (reward_id, outlet_id) VALUES (?, ?)");
                foreach ($outletIds as $oId) {
                    if ($oId > 0) $ins->execute([$id, $oId]);
                }
            }
            // scope='all' → junction kept empty (0 rows = berlaku semua)

            logAudit('reward_save', 'loyalty', "id=$id scope=$scope outlets=" . implode(',', $outletIds));
            $db->commit();
            echo json_encode(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[hq/loyalty save] ' . $e->getMessage());
            echo json_encode(['error' => 'Gagal simpan']);
        }
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyHqCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
        $st = $db->prepare("UPDATE hl_poin_reward SET is_active=0 WHERE id=? AND tenant_id=?");
        $st->execute([$id, $tid]);
        if (!$st->rowCount()) { echo json_encode(['error'=>'Tidak ditemukan']); exit; }
        logAudit('reward_delete', 'loyalty', "id=$id");
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

$pageTitle = '⭐ Sistem Poin (HQ)';
require ROOT . '/hq/_layout_open.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px">
  <h1 style="margin:0">⭐ Reward Loyalty (HQ)</h1>
  <button class="hq-btn hq-btn-primary" onclick="openEdit()">+ Tambah Reward</button>
</div>

<div id="rewardList" style="min-height:200px">⏳ Memuat...</div>

<!-- Modal edit -->
<div class="hq-modal-overlay" id="modalEdit">
  <div class="hq-modal" style="max-width:560px">
    <div class="hq-modal-header"><span>Tambah/Edit Reward</span></div>
    <div class="hq-modal-body">
      <input type="hidden" id="e_id" value="0">
      <label>Nama Reward</label>
      <input type="text" id="e_nama" class="hq-input" maxlength="100">
      <label>Deskripsi (opsional)</label>
      <textarea id="e_desk" class="hq-input" rows="2"></textarea>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
        <div>
          <label>Poin Dibutuhkan</label>
          <input type="number" id="e_poin" class="hq-input" min="1" value="50">
        </div>
        <div>
          <label>Tipe</label>
          <select id="e_tipe" class="hq-input">
            <option value="diskon_nominal">Diskon Rp</option>
            <option value="diskon_persen">Diskon %</option>
            <option value="gratis_layanan">Gratis Layanan</option>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
        <div>
          <label>Nilai</label>
          <input type="number" id="e_nilai" class="hq-input" min="0">
        </div>
        <div>
          <label>Min Transaksi (Rp)</label>
          <input type="number" id="e_min" class="hq-input" min="0" value="0">
        </div>
      </div>
      <label style="margin-top:10px">Max Redeem per Bulan (0 = unlimited)</label>
      <input type="number" id="e_max" class="hq-input" min="0" value="0">

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
      <button class="hq-btn hq-btn-primary" onclick="saveReward()">💾 Simpan</button>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= htmlspecialchars(hqCsrf()) ?>';
let outletsCache = null;

async function loadList() {
  const r = await fetch('?action=list');
  const d = await r.json();
  const list = document.getElementById('rewardList');
  if (!d.rows.length) { list.innerHTML = '<div style="padding:40px;text-align:center;color:#94A3B8">Belum ada reward</div>'; return; }
  list.innerHTML = d.rows.map(r => {
    const outletsLabel = r.outlets.length === 0
      ? '<span style="background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:6px;font-size:11px">🌐 Semua outlet</span>'
      : '<span style="background:#DBEAFE;color:#1E40AF;padding:2px 8px;border-radius:6px;font-size:11px">🏪 ' + r.outlets.length + ' outlet</span>';
    const tipeLabel = {diskon_nominal:'Diskon Rp', diskon_persen:'Diskon %', gratis_layanan:'Gratis Layanan'}[r.tipe] || r.tipe;
    return `
      <div style="background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:10px;display:flex;justify-content:space-between;gap:12px;border:1px solid #E5E7EB ${r.is_active==0?';opacity:.5':''}">
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:15px">⭐ ${esc(r.nama_reward)}</div>
          <div style="font-size:13px;color:#64748B;margin-top:3px">${r.poin_dibutuhkan} poin · ${esc(tipeLabel)} ${r.nilai}</div>
          <div style="margin-top:6px">${outletsLabel}${r.is_active==0 ? '<span style="background:#FEE;color:#991B1B;font-size:11px;padding:2px 8px;border-radius:6px;margin-left:6px">Non-aktif</span>' : ''}</div>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <button class="hq-btn-sm" onclick='openEdit(${JSON.stringify(r)})'>✏️</button>
          <button class="hq-btn-sm" onclick="deleteReward(${r.id})" style="color:#EF4444">🗑</button>
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
    </label>
  `).join('');
}

function openEdit(r) {
  document.getElementById('e_id').value     = r?.id || 0;
  document.getElementById('e_nama').value   = r?.nama_reward || '';
  document.getElementById('e_desk').value   = r?.deskripsi || '';
  document.getElementById('e_poin').value   = r?.poin_dibutuhkan || 50;
  document.getElementById('e_tipe').value   = r?.tipe || 'diskon_nominal';
  document.getElementById('e_nilai').value  = r?.nilai || 0;
  document.getElementById('e_min').value    = r?.min_transaksi || 0;
  document.getElementById('e_max').value    = r?.max_redeem_per_bulan || 0;
  document.getElementById('e_active').checked = r ? (r.is_active==1) : true;

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

async function saveReward() {
  const id = parseInt(document.getElementById('e_id').value);
  const scope = document.querySelector('input[name=e_scope]:checked')?.value || 'all';
  const outletIds = [...document.querySelectorAll('input[name=e_outlet]:checked')].map(el => parseInt(el.value));
  const payload = {
    id, scope, outlet_ids: outletIds,
    nama_reward: document.getElementById('e_nama').value,
    deskripsi: document.getElementById('e_desk').value,
    poin_dibutuhkan: parseInt(document.getElementById('e_poin').value),
    tipe: document.getElementById('e_tipe').value,
    nilai: parseInt(document.getElementById('e_nilai').value),
    min_transaksi: parseInt(document.getElementById('e_min').value),
    max_redeem_per_bulan: parseInt(document.getElementById('e_max').value),
    is_active: document.getElementById('e_active').checked ? 1 : 0,
  };
  const r = await fetch('?action=save', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(payload)});
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  closeEdit();
  loadList();
}

async function deleteReward(id) {
  if (!confirm('Non-aktifkan reward ini?')) return;
  await fetch('?action=delete', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id})});
  loadList();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
loadList();
</script>

<?php require ROOT . '/hq/_layout_close.php'; ?>
```

- [ ] **Step 2: Sidebar item di hq/_layout_open.php**

Find sidebar nav section. Cari section "Analitik" atau buat baru di group Master. Tambah:

```php
<a href="/hq/loyalty" class="hq-side-link <?= $_aPage === 'hq-loyalty' ? 'active' : '' ?>">
  <span class="ico">⭐</span> Sistem Poin
</a>
```

Letakkan di section Analitik atau group Master, posisi yang masuk akal.

- [ ] **Step 3: .htaccess whitelist hq/loyalty**

Find di .htaccess:
```
RewriteRule ^hq/(dashboard|outlet|laporan|keuangan|karyawan|mutasi|sdm|penggajian|roles|pelanggan|promo|layanan|droppoint|billing|coin-info|checklist|broadcast|audit|settings|struk|ai-chat|ai-churning|inventori|mesin)\.php$ /hq/$1 [R=301,L]
```

Append `|loyalty` ke list (kedua rules — 301 redirect + internal rewrite).

- [ ] **Step 4: Manual smoke test**

1. Login owner → /hq/loyalty → list reward existing tampil dengan badge outlet count
2. Klik "+ Tambah" → modal terbuka, pilih "Outlet tertentu" → outlet list muncul
3. Centang 2 outlet → simpan → row baru di list, junction terisi 2 rows
4. Edit reward → modal pre-fill correct → ubah scope → save → junction updated
5. Non-aktif → reward jadi opacity dim
6. Manager (non-owner) akses /hq/loyalty → 403

- [ ] **Step 5: Commit**

```bash
git add hq/loyalty.php hq/_layout_open.php .htaccess
git commit -m "feat(loyalty): /hq/loyalty CRUD dengan multi-outlet checkbox"
```

---

### Task 4: Outlet `/loyalty` extend dengan badge HQ/outlet + lock

**Files:**
- Modify: `loyalty.php`

**Interfaces:**
- Consumes: `Loyalty::applicableOutlets()`, `Loyalty::isHqManaged()` dari Task 2

- [ ] **Step 1: Update list query + render badge**

Find di loyalty.php action `reward_list` (atau similar). Update query:

```php
if ($action === 'reward_list') {
    $rows = TenantQuery::raw(
        "SELECT r.* FROM hl_poin_reward r
          WHERE r.tenant_id=? AND r.is_active=1
            AND (NOT EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id)
                 OR EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id AND outlet_id=?))
          ORDER BY r.poin_dibutuhkan",
        [$tid, $oid]
    );
    foreach ($rows as &$r) {
        $r['outlets_count'] = count(Loyalty::applicableOutlets((int)$r['id']));
        $r['hq_managed'] = $r['outlets_count'] !== 1;
    }
    echo json_encode(['rows' => $rows]);
    exit;
}
```

(Adjust query name kalau beda — read loyalty.php first.)

- [ ] **Step 2: UI badge + lock**

Di JS rendering reward list di loyalty.php, tambah badge + disable edit/hapus button:

```js
list.innerHTML = rows.map(r => {
  const badge = r.hq_managed
    ? '<span style="background:#DBEAFE;color:#1E40AF;padding:2px 8px;border-radius:6px;font-size:11px">🏢 HQ</span>'
    : '<span style="background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:6px;font-size:11px">🏪 Outlet ini</span>';
  // Disable kalau HQ-managed dan user bukan owner
  const canEdit = !r.hq_managed || USER_ROLE === 'owner' || USER_ROLE === 'superadmin';
  const editBtn = canEdit
    ? `<button onclick='editReward(${JSON.stringify(r)})'>✏️</button>`
    : `<span title="Reward HQ, edit dari /hq/loyalty">🔒</span>`;
  return `<div class="reward-card">... ${badge} ${editBtn} ...</div>`;
}).join('');
```

Adjust pattern sesuai existing rendering style di loyalty.php.

- [ ] **Step 3: Backend save action — auto-create junction row untuk outlet aktif**

Di handler `action=save` (create/update reward dari /loyalty outlet), setelah INSERT/UPDATE reward, tambah:

```php
// Outlet-scope: junction tunggal current outlet kalau buat baru (atau reset junction kalau outlet manager edit)
if ($id > 0) {
    // Edit: kalau dari outlet page, hanya boleh kalau reward outlet-spesifik current outlet
    $existingOutlets = Loyalty::applicableOutlets($id);
    if (count($existingOutlets) !== 1 || $existingOutlets[0] !== $oid) {
        $userRole = currentUser()['role'] ?? '';
        if (!in_array($userRole, ['owner','superadmin'], true)) {
            echo json_encode(['error'=>'Reward ini dikelola HQ. Edit lewat /hq/loyalty.']); exit;
        }
    }
} else {
    // Create baru: junction = current outlet only
    $insJct = $db->prepare("INSERT IGNORE INTO hl_poin_reward_outlet (reward_id, outlet_id) VALUES (?, ?)");
    $insJct->execute([$rewardId, $oid]);
}
```

- [ ] **Step 4: Manual smoke test**

1. Owner di outlet Tebet → /loyalty: lihat semua reward (global + Tebet-specific) dengan badge correct
2. Kasir/manager Tebet: tombol edit reward HQ disabled, edit outlet-specific OK
3. Kasir buat reward baru → junction auto-set ke Tebet only
4. Kasir di outlet Mall buka /loyalty → reward Tebet-specific tidak tampil (junction mismatch)

- [ ] **Step 5: Commit**

```bash
git add loyalty.php
git commit -m "feat(loyalty): /loyalty badge HQ/outlet + lock edit untuk reward HQ-managed"
```

---

### Task 5: POS UX catalog browser (frontend)

**Files:**
- Modify: `pos.php` (order modal section + JS)

**Interfaces:**
- Consumes: `Loyalty::availableRewards()` via existing endpoint `pelanggan_poin`
- Produces: catalog UI saat pelanggan terpilih + JS apply yang set hidden `reward_id` + auto-fill `redeem_poin`

- [ ] **Step 1: Tambah catalog UI di order modal**

Find di pos.php section input order (sekitar baris yang ada `f_redeem_poin` input). Tambah container sebelum/setelah existing input:

```html
<!-- Reward Catalog (visible kalau pelanggan ada + saldo poin) -->
<div id="rewardCatalog" style="display:none;margin:10px 0;padding:12px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px">
  <div style="font-weight:700;font-size:13px;color:#92400E;margin-bottom:8px">🎁 Tukarkan Poin (<span id="poinBalance">0</span> poin)</div>
  <div id="rewardCards" style="display:flex;flex-direction:column;gap:6px"></div>
  <input type="hidden" id="f_reward_id" value="0">
</div>
```

- [ ] **Step 2: JS render catalog saat pelanggan dipilih**

Find function yang handle pelanggan selection (probably `selectPelanggan` atau `pelanggan_poin` ajax handler response). Setelah fetch data pelanggan + rewards, render catalog:

```js
function renderRewardCatalog(poinBalance, rewards) {
  const box = document.getElementById('rewardCatalog');
  if (!rewards || rewards.length === 0) { box.style.display = 'none'; return; }
  box.style.display = 'block';
  document.getElementById('poinBalance').textContent = poinBalance;
  const cards = document.getElementById('rewardCards');
  cards.innerHTML = rewards.map(r => {
    const eligible = r.bisa_redeem;
    const tipeLabel = {diskon_nominal:'Diskon Rp', diskon_persen:'Diskon %', gratis_layanan:'Gratis Layanan'}[r.tipe] || r.tipe;
    return `
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:#fff;border-radius:8px;border:1px solid ${eligible?'#FDE68A':'#E5E7EB'}">
        <div>
          <div style="font-weight:600;font-size:13px">${esc(r.nama_reward)}</div>
          <div style="font-size:11px;color:#64748B">${r.poin_dibutuhkan} poin · ${esc(tipeLabel)} ${r.nilai}</div>
        </div>
        ${eligible
          ? `<button class="btn btn-teal-sm" onclick="applyReward(${r.id}, ${r.poin_dibutuhkan})">Pakai</button>`
          : `<span style="font-size:11px;color:#94A3B8">Kurang ${r.kurang}</span>`}
      </div>`;
  }).join('');
}

function applyReward(rewardId, poinNeeded) {
  document.getElementById('f_reward_id').value = rewardId;
  const rp = document.getElementById('f_redeem_poin');
  if (rp) { rp.value = poinNeeded; recalc(); }
  // Highlight selected
  document.querySelectorAll('#rewardCards button').forEach(b => b.disabled = false);
  event.target.disabled = true;
  event.target.textContent = '✓ Dipilih';
}

function clearReward() {
  document.getElementById('f_reward_id').value = '0';
  const rp = document.getElementById('f_redeem_poin');
  if (rp) { rp.value = 0; recalc(); }
  document.querySelectorAll('#rewardCards button').forEach(b => { b.disabled = false; b.textContent = 'Pakai'; });
}
```

- [ ] **Step 3: Wire ke existing pelanggan select**

Find existing `selectPelanggan(p)` atau setelah ajax `pelanggan_poin`. Setelah set `currentPelangganId`, call:

```js
// After receiving pelanggan data with poin + rewards
const r = await fetch('pos.php?action=pelanggan_poin&id=' + pelangganId);
const data = await r.json();
if (data.ok && data.pelanggan) {
  // existing pelanggan handling
  renderRewardCatalog(data.pelanggan.poin || 0, data.rewards || []);
}
```

Existing endpoint already returns `rewards` (line 141 di explorasi awal). Just need to call renderRewardCatalog.

- [ ] **Step 4: submitOrder payload include reward_id**

Find `submitOrder()` atau similar. Find payload object yang sudah include `redeem_poin`. Tambah:

```js
const payload = {
  // ... existing fields
  redeem_poin: ...,
  reward_id: parseInt(document.getElementById('f_reward_id')?.value || 0) || 0,
};
```

- [ ] **Step 5: Manual smoke test**

1. POS → pilih pelanggan dengan saldo 100 poin → catalog kotak kuning muncul
2. Reward 50 poin tampil "Pakai" button, reward 200 poin tampil "Kurang 100"
3. Klik Pakai → button jadi "✓ Dipilih", `redeem_poin` field auto-fill 50
4. Submit order → kirim `reward_id` ke backend
5. Cek hl_loyalty_log: row baru dengan reward_id terisi (backend handler nya Task 6)

- [ ] **Step 6: Commit**

```bash
git add pos.php
git commit -m "feat(loyalty): POS reward catalog UI + apply button"
```

---

### Task 6: POS backend reward_id handler

**Files:**
- Modify: `pos.php` (saveOrder action, redeem block sekitar line 282)

**Interfaces:**
- Consumes: existing `Loyalty::config`, `Loyalty::balance`. Tambah validation untuk reward_id.

- [ ] **Step 1: Tambah handling reward_id di saveOrder**

Find di pos.php saveOrder action, area `$redeemPoin = max(0, ...)` dan block loyalty redeem. Replace block dengan logic yang handle reward_id:

```php
// ── Loyalty redeem (poin → diskon) ──
$redeemPoin  = max(0, (int)($data['redeem_poin'] ?? 0));
$rewardId    = max(0, (int)($data['reward_id'] ?? 0));
$redeemValue = 0;

if (($redeemPoin > 0 || $rewardId > 0) && $pel_id && Loyalty::isEnabled($tid)) {
    $cfg = Loyalty::config($tid);
    $balPoin = Loyalty::balance($tid, (int)$pel_id);
    $maxRupiah = max(0, $subtotal - $diskon);

    if ($rewardId > 0) {
        // Validate reward applicable di outlet ini
        $reward = TenantQuery::rawOne(
            "SELECT r.* FROM hl_poin_reward r
              WHERE r.id=? AND r.tenant_id=? AND r.is_active=1
                AND (NOT EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id)
                     OR EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id AND outlet_id=?))
              LIMIT 1",
            [$rewardId, $tid, $oid]
        );
        if ($reward && $balPoin >= (int)$reward['poin_dibutuhkan']) {
            // Compute discount per tipe
            $redeemPoin = (int)$reward['poin_dibutuhkan'];
            switch ($reward['tipe']) {
                case 'diskon_nominal':
                    $redeemValue = (int)$reward['nilai'];
                    break;
                case 'diskon_persen':
                    $redeemValue = (int)floor($maxRupiah * ((int)$reward['nilai'] / 100));
                    break;
                case 'gratis_layanan':
                    $redeemValue = (int)$reward['nilai']; // assume nilai = rupiah layanan, atau adjust per spec
                    break;
            }
            $redeemValue = min($redeemValue, $maxRupiah);
            if ($catatan === '') $catatan = "Reward: " . $reward['nama_reward'];
            else $catatan .= " · Reward: " . $reward['nama_reward'];
        } else {
            $rewardId = 0;
            $redeemPoin = 0;
        }
    } else {
        // Manual numeric redeem (existing behavior)
        $maxPoin = min($balPoin, (int)floor($maxRupiah / $cfg['poin_value']));
        $redeemPoin = min($redeemPoin, $maxPoin);
        if ($redeemPoin > 0) {
            $redeemValue = $redeemPoin * $cfg['poin_value'];
            if ($catatan === '') $catatan = "Redeem $redeemPoin poin (-Rp " . number_format($redeemValue,0,',','.') . ")";
            else $catatan .= " · Redeem $redeemPoin poin (-Rp " . number_format($redeemValue,0,',','.') . ")";
        } else {
            $redeemPoin = 0;
        }
    }
} else {
    $redeemPoin = 0;
    $rewardId = 0;
}
```

- [ ] **Step 2: Pass reward_id ke Loyalty::redeem call**

Find existing Loyalty::redeem() call di pos.php. Pastikan reward_id passed sebagai parameter. Existing signature:
```php
public static function redeem(int $tid, int $oid, int $pelangganId, ?int $transaksiId, int $poin, int $balanceAfter, ?int $rewardId, string $keterangan): void
```

Pass `$rewardId > 0 ? $rewardId : null`.

- [ ] **Step 3: Manual smoke test**

1. POS pelanggan saldo 100 poin → pilih reward 50 poin "Diskon Rp 5.000"
2. Submit → cek total order kurangi Rp 5.000
3. Cek hl_loyalty_log row baru: poin=-50, reward_id=ID, keterangan=Reward nama
4. Cek hl_pelanggan.poin_saldo berkurang 50
5. Test reward tidak applicable di outlet (junction beda) → request gagal silent (redeemPoin=0, rewardId=0)

- [ ] **Step 4: Commit**

```bash
git add pos.php
git commit -m "feat(loyalty): POS backend reward_id validation + tipe-specific discount calc"
```

---

### Task 7: Portal pelanggan "Hadiah Tersedia" section

**Files:**
- Modify: `pelanggan.php`

**Interfaces:**
- Consumes: `Loyalty::availableRewards()` from Task 2

- [ ] **Step 1: Query rewards di pelanggan.php**

Sebelum render HTML, tambah load rewards. Pelanggan punya `pelanggan_id`. Cari outlet last order, lalu query rewards:

```php
// Cari outlet last order pelanggan untuk scope rewards
$lastOutletId = 0;
try {
    $st = $db->prepare("SELECT outlet_id FROM hl_transaksi WHERE pelanggan_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$pel['id']]);
    $lastOutletId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {}

// Load rewards yang apply di outlet tsb + tenant scope
$rewards = [];
if ($lastOutletId > 0) {
    require_once ROOT . '/core/Loyalty.php';
    // Tenant_id dari outlet
    try {
        $st = $db->prepare("SELECT tenant_id FROM outlets WHERE id=? LIMIT 1");
        $st->execute([$lastOutletId]);
        $tid = (int)($st->fetchColumn() ?: 0);
        if ($tid > 0) {
            $rewards = Loyalty::availableRewards($tid, $lastOutletId, $poin);
        }
    } catch (Throwable) {}
}
```

- [ ] **Step 2: Tambah section rendering di HTML**

Di template HTML pelanggan.php, sebelum atau setelah section Saldo, tambah:

```html
<?php if (!empty($rewards)): ?>
<div class="card">
  <h2>🎁 Hadiah Tersedia</h2>
  <?php foreach ($rewards as $r): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #F1F5F9">
      <div style="flex:1">
        <div style="font-weight:600;font-size:14px;<?= $r['bisa_redeem'] ? '' : 'color:#94A3B8' ?>">
          <?= $r['bisa_redeem'] ? '✅' : '⏳' ?> <?= htmlspecialchars($r['nama_reward']) ?>
        </div>
        <div style="font-size:11px;color:#64748B;margin-top:2px"><?= (int)$r['poin_dibutuhkan'] ?> poin<?= $r['bisa_redeem'] ? '' : ' (butuh ' . (int)$r['kurang'] . ' lagi)' ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  <div style="margin-top:10px;font-size:12px;color:#64748B;font-style:italic">💡 Kunjungi outlet untuk menukarkan hadiah</div>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Manual smoke test**

1. Pelanggan login portal → section Hadiah Tersedia muncul (kalau pelanggan punya order history)
2. Rewards yg bisa redeem ✅, rewards kurang poin ⏳
3. No action button (read-only sesuai spec)
4. Tidak ada section kalau pelanggan baru (lastOutletId = 0)

- [ ] **Step 4: Commit**

```bash
git add pelanggan.php
git commit -m "feat(loyalty): portal pelanggan 'Hadiah Tersedia' section (read-only)"
```

---

## Self-Review Checklist (untuk implementer)

- [ ] Migration applied + junction backfilled (semua existing rewards punya junction row)
- [ ] `Loyalty::availableRewards()` query pakai junction logic
- [ ] `Loyalty::applicableOutlets()` return correct list
- [ ] /hq/loyalty: owner-only, CRUD + multi-outlet checkbox, audit log
- [ ] /hq/loyalty di sidebar HQ
- [ ] /loyalty extend: badge HQ/outlet + lock untuk non-owner pada reward HQ-managed
- [ ] /loyalty create reward → auto junction row current outlet
- [ ] POS UX: catalog tampil kalau pelanggan ada + rewards available
- [ ] POS apply reward: reward_id passed + backend validate applicability + tipe-specific discount
- [ ] hl_loyalty_log entry dengan reward_id correct
- [ ] Portal /pelanggan: section Hadiah Tersedia render rewards from outlet last order

## Out of scope (Phase 2, defer)

- Kupon kode self-service (pelanggan generate)
- WA share kupon
- Reward "free product" dengan stock tracking
- Stacking multiple rewards
- Analytics reward usage
