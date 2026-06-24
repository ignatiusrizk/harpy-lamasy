# Stok Opname Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sesi stok opname per-outlet: snapshot stok sistem → input fisik → finalize generate mutasi adjust per selisih → riwayat tersimpan.

**Architecture:** 2 tabel (hl_opname header + hl_opname_item detail). Tab "Stok Opname" di inventori.php (outlet) + 5 AJAX action. Finalize reuse pola adjust existing (stok_sebelum=sistem, stok_sesudah=fisik, jumlah=abs(selisih)). View hl_bahan_stok auto-update.

**Tech Stack:** PHP 8 vanilla, MariaDB, inventori.php AJAX pattern (action + verifyCsrf + TenantQuery), hl_bahan_mutasi adjust.

## Global Constraints

- 2 tabel: hl_opname (outlet_id, tanggal, status draft/selesai, total_item, total_selisih_item, nilai_selisih BIGINT, input_by, finalized_at), hl_opname_item (opname_id, bahan_id, stok_sistem, stok_fisik NULL, selisih, mutasi_id)
- Finalize adjust pattern (verbatim dari inventori.php): `jumlah=abs(stok_fisik-stok_sistem)`, `stok_sebelum=stok_sistem`, `stok_sesudah=stok_fisik`, tipe='adjust', catatan="Opname #{id}"
- stok_fisik NULL = skip (tidak dihitung, no adjust)
- selisih = stok_fisik - stok_sistem (signed)
- nilai_selisih = Σ(selisih × harga_beli bahan)
- Finalize transaksional, idempotent (draft→selesai sekali, status check)
- Snapshot stok_sistem dari hl_bahan_stok.stok_terkini saat create
- Permission `inventori.manage` (reuse), tenant+outlet scope semua query
- CSRF verifyCsrf semua POST. XSS esc render.
- inventori.php pattern: `$action=$_GET['action']; if($action){header json; $tid=TenantResolver::id(); $oid=TenantResolver::outletId(); ...}`; POST pakai verifyCsrf; TenantQuery::raw/rawOne; tab via `inv-tab` + `switchTab(name,el)`.
- mysql client: /opt/homebrew/opt/mysql-client/bin/mysql. No php CLI → smoke deploy/browser.

---

## File Structure

**New:**
- `db/migrations/2026-06-24-stok-opname.sql` — 2 tabel

**Modified:**
- `inventori.php` — tab Stok Opname + 5 AJAX action + finalize + JS

---

## Task 1: Schema Migration

**Files:**
- Create: `db/migrations/2026-06-24-stok-opname.sql`

**Interfaces:**
- Produces: tabel `hl_opname`, `hl_opname_item`.

- [ ] **Step 1: Create migration**

Write `db/migrations/2026-06-24-stok-opname.sql`:

```sql
-- Stok Opname
CREATE TABLE IF NOT EXISTS hl_opname (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  tanggal DATE NOT NULL,
  status ENUM('draft','selesai') NOT NULL DEFAULT 'draft',
  total_item INT NOT NULL DEFAULT 0,
  total_selisih_item INT NOT NULL DEFAULT 0,
  nilai_selisih BIGINT NOT NULL DEFAULT 0,
  catatan TEXT NULL,
  input_by INT NULL,
  finalized_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_outlet (tenant_id, outlet_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_opname_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opname_id INT NOT NULL,
  tenant_id INT NOT NULL,
  bahan_id INT NOT NULL,
  stok_sistem INT NOT NULL,
  stok_fisik INT NULL,
  selisih INT NOT NULL DEFAULT 0,
  mutasi_id INT NULL,
  INDEX idx_opname (opname_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Apply + verify**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-stok-opname.sql
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC hl_opname; DESC hl_opname_item" 2>&1 | head -30
```
Expected: 2 tabel dengan kolom sesuai.

- [ ] **Step 3: Commit**

```bash
git add db/migrations/2026-06-24-stok-opname.sql
git commit -m "feat(opname): schema hl_opname + hl_opname_item

Sesi opname (header: outlet, tanggal, status, ringkasan selisih) +
detail item (stok_sistem snapshot, stok_fisik, selisih, mutasi_id link).
Additive, no change tabel existing."
```

---

## Task 2: Backend Read Actions

**Files:**
- Modify: `inventori.php` (AJAX action block, dekat action existing)

**Interfaces:**
- Consumes: hl_opname/hl_opname_item (Task 1), hl_bahan_stok view, TenantQuery
- Produces: AJAX `opname_list`, `opname_create`, `opname_get`, `opname_save_fisik`

- [ ] **Step 1: opname_list**

Di inventori.php, dalam blok `if ($action)`, tambah (dekat list_stok):
```php
    // ─── OPNAME: list riwayat ──────────────────────────
    if ($action === 'opname_list') {
        $rows = TenantQuery::raw(
            "SELECT id, tanggal, status, total_item, total_selisih_item, nilai_selisih, finalized_at, created_at
             FROM hl_opname WHERE tenant_id=? AND outlet_id=? ORDER BY created_at DESC LIMIT 50",
            [$tid, $oid]
        );
        echo json_encode(['ok'=>true, 'rows'=>$rows]);
        exit;
    }
```

- [ ] **Step 2: opname_create (snapshot)**

```php
    // ─── OPNAME: buat sesi draft + snapshot ────────────
    if ($action === 'opname_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $db = Database::get();
        $db->beginTransaction();
        try {
            $tgl = date('Y-m-d');
            $db->prepare("INSERT INTO hl_opname (tenant_id, outlet_id, tanggal, status, input_by)
                          VALUES (?,?,?, 'draft', ?)")
               ->execute([$tid, $oid, $tgl, (int)($user['id'] ?? 0)]);
            $opnameId = (int)$db->lastInsertId();

            // Snapshot semua bahan aktif outlet dari view
            $bahan = $db->prepare("SELECT id AS bahan_id, stok_terkini FROM hl_bahan_stok
                                   WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY nama");
            $bahan->execute([$tid, $oid]);
            $rows = $bahan->fetchAll(PDO::FETCH_ASSOC);
            $ins = $db->prepare("INSERT INTO hl_opname_item (opname_id, tenant_id, bahan_id, stok_sistem)
                                 VALUES (?,?,?,?)");
            foreach ($rows as $b) {
                $ins->execute([$opnameId, $tid, (int)$b['bahan_id'], (int)$b['stok_terkini']]);
            }
            $db->prepare("UPDATE hl_opname SET total_item=? WHERE id=?")
               ->execute([count($rows), $opnameId]);
            $db->commit();
            echo json_encode(['ok'=>true, 'id'=>$opnameId]);
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }
```
(Verify `Database::get()` available di scope — atau pakai TenantQuery untuk insert. inventori.php pakai TenantQuery::raw untuk read; untuk transaksi pakai Database::get(). Cek pola action existing yang transaksional, mis. transfer/adjust, untuk cara dapat $db.)

- [ ] **Step 3: opname_get (detail + items)**

```php
    // ─── OPNAME: detail sesi + items ───────────────────
    if ($action === 'opname_get') {
        $id = (int)($_GET['id'] ?? 0);
        $hdr = TenantQuery::rawOne(
            "SELECT * FROM hl_opname WHERE id=? AND tenant_id=? AND outlet_id=?",
            [$id, $tid, $oid]
        );
        if (!$hdr) { echo json_encode(['error'=>'Sesi tidak ditemukan']); exit; }
        $items = TenantQuery::raw(
            "SELECT oi.id, oi.bahan_id, oi.stok_sistem, oi.stok_fisik, oi.selisih,
                    b.nama, b.satuan, b.kategori
             FROM hl_opname_item oi
             JOIN hl_bahan b ON b.id=oi.bahan_id AND b.tenant_id=oi.tenant_id
             WHERE oi.opname_id=? AND oi.tenant_id=? ORDER BY b.nama",
            [$id, $tid]
        );
        echo json_encode(['ok'=>true, 'header'=>$hdr, 'items'=>$items]);
        exit;
    }
```

- [ ] **Step 4: opname_save_fisik (draft only)**

```php
    // ─── OPNAME: simpan stok fisik (draft) ─────────────
    if ($action === 'opname_save_fisik' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $opnameId = (int)($d['opname_id'] ?? 0);
        $items = $d['items'] ?? []; // [{item_id, stok_fisik}]
        $db = Database::get();
        // pastikan sesi draft milik outlet ini
        $chk = $db->prepare("SELECT status FROM hl_opname WHERE id=? AND tenant_id=? AND outlet_id=?");
        $chk->execute([$opnameId, $tid, $oid]);
        $st = $chk->fetchColumn();
        if ($st === false) { echo json_encode(['error'=>'Sesi tidak ditemukan']); exit; }
        if ($st !== 'draft') { echo json_encode(['error'=>'Sesi sudah selesai']); exit; }

        $db->beginTransaction();
        try {
            $upd = $db->prepare("UPDATE hl_opname_item oi
                                 JOIN hl_opname_item base ON base.id=oi.id
                                 SET oi.stok_fisik=?, oi.selisih = ? - oi.stok_sistem
                                 WHERE oi.id=? AND oi.tenant_id=? AND oi.opname_id=?");
            foreach ($items as $it) {
                $itemId = (int)($it['item_id'] ?? 0);
                $fisikRaw = $it['stok_fisik'];
                if ($fisikRaw === '' || $fisikRaw === null) {
                    // kosong → set NULL, selisih 0
                    $db->prepare("UPDATE hl_opname_item SET stok_fisik=NULL, selisih=0
                                  WHERE id=? AND tenant_id=? AND opname_id=?")
                       ->execute([$itemId, $tid, $opnameId]);
                    continue;
                }
                $fisik = max(0, (int)$fisikRaw);
                $db->prepare("UPDATE hl_opname_item
                              SET stok_fisik=?, selisih = ? - stok_sistem
                              WHERE id=? AND tenant_id=? AND opname_id=?")
                   ->execute([$fisik, $fisik, $itemId, $tid, $opnameId]);
            }
            $db->commit();
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }
```
(Catatan: hapus dead `$upd` prepare di atas loop — pakai prepare di dalam loop seperti yang dieksekusi. Implementer rapikan: gunakan 2 prepared statement (set-null + set-value) sekali di luar loop, execute di dalam.)

- [ ] **Step 5: Verify**

```bash
grep -nE "opname_list|opname_create|opname_get|opname_save_fisik" inventori.php
```
Expected: 4 action.

- [ ] **Step 6: Commit**

```bash
git add inventori.php
git commit -m "feat(opname): backend read actions

opname_list (riwayat), opname_create (sesi draft + snapshot stok_terkini
per bahan aktif outlet), opname_get (detail+items), opname_save_fisik
(simpan stok_fisik + recompute selisih, draft only, kosong=NULL skip).
Permission inventori.manage, tenant+outlet scope, transaksional."
```

---

## Task 3: Finalize (adjust generation)

**Files:**
- Modify: `inventori.php` (action opname_finalize)

**Interfaces:**
- Consumes: hl_opname/item, hl_bahan (harga_beli), hl_bahan_mutasi (adjust)
- Produces: AJAX `opname_finalize` — transaksional adjust + ringkasan + status selesai

- [ ] **Step 1: opname_finalize**

```php
    // ─── OPNAME: finalize (adjust + ringkasan) ─────────
    if ($action === 'opname_finalize' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $opnameId = (int)($d['opname_id'] ?? 0);
        $db = Database::get();

        $hdr = $db->prepare("SELECT status, tanggal FROM hl_opname WHERE id=? AND tenant_id=? AND outlet_id=?");
        $hdr->execute([$opnameId, $tid, $oid]);
        $h = $hdr->fetch(PDO::FETCH_ASSOC);
        if (!$h) { echo json_encode(['error'=>'Sesi tidak ditemukan']); exit; }
        if ($h['status'] !== 'draft') { echo json_encode(['error'=>'Sesi sudah selesai']); exit; }

        $db->beginTransaction();
        try {
            // Items dgn fisik terisi + selisih != 0 (JOIN bahan untuk harga + outlet)
            $items = $db->prepare(
                "SELECT oi.id, oi.bahan_id, oi.stok_sistem, oi.stok_fisik, oi.selisih, b.harga_beli
                 FROM hl_opname_item oi
                 JOIN hl_bahan b ON b.id=oi.bahan_id AND b.tenant_id=oi.tenant_id
                 WHERE oi.opname_id=? AND oi.tenant_id=? AND oi.stok_fisik IS NOT NULL");
            $items->execute([$opnameId, $tid]);
            $rows = $items->fetchAll(PDO::FETCH_ASSOC);

            $totalSelisihItem = 0;
            $nilaiSelisih = 0;
            $insMut = $db->prepare(
                "INSERT INTO hl_bahan_mutasi
                   (tenant_id, outlet_id, bahan_id, tipe, jumlah, stok_sebelum, stok_sesudah, catatan, input_by)
                 VALUES (?,?,?, 'adjust', ?, ?, ?, ?, ?)");
            $linkItem = $db->prepare("UPDATE hl_opname_item SET mutasi_id=? WHERE id=?");
            foreach ($rows as $it) {
                $selisih = (int)$it['selisih'];
                if ($selisih === 0) continue;
                $insMut->execute([
                    $tid, $oid, (int)$it['bahan_id'],
                    abs($selisih), (int)$it['stok_sistem'], (int)$it['stok_fisik'],
                    "Opname #{$opnameId} " . $h['tanggal'], (int)($user['id'] ?? 0)
                ]);
                $linkItem->execute([(int)$db->lastInsertId(), (int)$it['id']]);
                $totalSelisihItem++;
                $nilaiSelisih += $selisih * (int)$it['harga_beli'];
            }

            $db->prepare("UPDATE hl_opname
                          SET status='selesai', finalized_at=NOW(),
                              total_selisih_item=?, nilai_selisih=?
                          WHERE id=? AND tenant_id=? AND status='draft'")
               ->execute([$totalSelisihItem, $nilaiSelisih, $opnameId, $tid]);

            $db->commit();
            echo json_encode(['ok'=>true, 'total_selisih_item'=>$totalSelisihItem, 'nilai_selisih'=>$nilaiSelisih]);
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }
```

- [ ] **Step 2: Verify**

```bash
grep -nE "opname_finalize|tipe', 'adjust'|'adjust'" inventori.php | head
```

- [ ] **Step 3: Commit**

```bash
git add inventori.php
git commit -m "feat(opname): finalize transaksional + adjust generation

opname_finalize: untuk tiap item fisik-terisi dgn selisih≠0, generate
hl_bahan_mutasi tipe='adjust' (jumlah=abs(selisih), stok_sebelum=sistem,
stok_sesudah=fisik, catatan Opname#), link mutasi_id. Hitung ringkasan
(total_selisih_item, nilai_selisih=Σselisih×harga_beli). Status draft→selesai
sekali (idempotent). stok_terkini auto via view."
```

---

## Task 4: Tab UI + JS

**Files:**
- Modify: `inventori.php` (tab button + panel + JS)

**Interfaces:**
- Consumes: actions Task 2-3
- Produces: tab Stok Opname (riwayat + form input + finalize + detail)

- [ ] **Step 1: Tambah tab button**

Di `.inv-tabs` (sekitar line 328-331), setelah tab Restock Alert:
```php
    <button class="inv-tab" onclick="switchTab('opname',this)">📋 Stok Opname</button>
```

- [ ] **Step 2: Tambah panel HTML**

Setelah panel tab existing (alert), tambah div panel opname (default hidden, di-show via switchTab):
```html
<div id="tab-opname" class="inv-panel" style="display:none">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h3 style="margin:0;font-size:15px">📋 Riwayat Stok Opname</h3>
    <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="opnameCreate()">+ Mulai Opname Baru</button>
  </div>
  <div id="opnameListWrap"><div style="color:#9CA3AF;padding:20px;text-align:center">Memuat…</div></div>
  <div id="opnameDetailWrap" style="display:none;margin-top:16px"></div>
</div>
```
(Verify nama class panel existing — apakah `inv-panel` atau pakai id `tab-stok`/`tab-mutasi`. Lihat switchTab existing + panel wrapper, samakan.)

- [ ] **Step 3: JS — switchTab hook + load list**

Pastikan `switchTab('opname',el)` show panel `tab-opname` + panggil `loadOpnameList()`. Cek implementasi switchTab existing (cara map tab→panel). Tambah cabang opname.

```javascript
async function loadOpnameList() {
  const wrap = document.getElementById('opnameListWrap');
  document.getElementById('opnameDetailWrap').style.display = 'none';
  const r = await fetch('inventori.php?action=opname_list');
  const d = await r.json();
  if (!d.ok) { wrap.innerHTML = '<div style="color:#DC2626;padding:20px">'+esc(d.error||'Gagal')+'</div>'; return; }
  if (!d.rows.length) { wrap.innerHTML = '<div style="color:#9CA3AF;padding:20px;text-align:center">Belum ada opname.</div>'; return; }
  let h = '<table class="hl-table"><thead><tr><th>Tanggal</th><th>Status</th><th class="num">Item</th><th class="num">Selisih</th><th class="num">Nilai</th><th></th></tr></thead><tbody>';
  d.rows.forEach(o => {
    const badge = o.status==='selesai' ? '<span style="color:#059669">✓ Selesai</span>' : '<span style="color:#92400E">Draft</span>';
    const nilai = o.status==='selesai' ? fmtNilai(o.nilai_selisih) : '-';
    const aksi = o.status==='selesai'
      ? `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="opnameOpen(${o.id})">👁 Detail</button>`
      : `<button class="hl-btn hl-btn-primary hl-btn-sm" onclick="opnameOpen(${o.id})">Lanjut</button>`;
    h += `<tr><td>${new Date(o.tanggal).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}</td>
      <td>${badge}</td><td class="num">${o.total_item}</td>
      <td class="num">${o.status==='selesai'?o.total_selisih_item:'-'}</td>
      <td class="num">${nilai}</td><td>${aksi}</td></tr>`;
  });
  wrap.innerHTML = h + '</tbody></table>';
}
function fmtNilai(n){ n=parseInt(n)||0; const s=n<0?'−':(n>0?'+':''); return s+'Rp '+Math.abs(n).toLocaleString('id-ID'); }

async function opnameCreate() {
  if (!confirm('Mulai sesi opname baru? Stok sistem akan di-snapshot sekarang.')) return;
  const fd = new FormData(); fd.append('_csrf', CSRF);
  const r = await fetch('inventori.php?action=opname_create', {method:'POST', body:fd});
  const d = await r.json();
  if (!d.ok) { alert(d.error||'Gagal'); return; }
  opnameOpen(d.id);
}
```
(Verify nama token CSRF JS (`CSRF` / `csrf` / meta) + helper `esc`/`fmtNum` dari inventori.php existing — samakan.)

- [ ] **Step 4: JS — opnameOpen (detail/form) + save + finalize**

```javascript
async function opnameOpen(id) {
  const r = await fetch('inventori.php?action=opname_get&id='+id);
  const d = await r.json();
  if (!d.ok) { alert(d.error||'Gagal'); return; }
  const h = d.header, items = d.items;
  const isDraft = h.status === 'draft';
  const wrap = document.getElementById('opnameDetailWrap');
  document.getElementById('opnameListWrap').style.display = 'none';
  wrap.style.display = 'block';

  let rows = items.map(it => {
    const fisik = it.stok_fisik === null ? '' : it.stok_fisik;
    const sel = it.stok_fisik === null ? '-' : (it.selisih>0?'+'+it.selisih:it.selisih);
    const selColor = it.selisih<0?'#DC2626':(it.selisih>0?'#059669':'#6B7280');
    const inputCell = isDraft
      ? `<input type="number" min="0" data-item="${it.id}" value="${fisik}" oninput="opnameRecalc(this,${it.stok_sistem})" style="width:80px;padding:4px 8px;border:1px solid #D1D5DB;border-radius:6px">`
      : (it.stok_fisik===null?'-':it.stok_fisik);
    return `<tr><td>${esc(it.nama)}</td><td>${esc(it.satuan)}</td><td class="num">${it.stok_sistem}</td>
      <td class="num">${inputCell}</td><td class="num sel-cell" style="color:${selColor}">${sel}</td></tr>`;
  }).join('');

  wrap.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <h3 style="margin:0;font-size:15px">Opname ${new Date(h.tanggal).toLocaleDateString('id-ID')} ${isDraft?'<span style="color:#92400E;font-size:12px">[Draft]</span>':'<span style="color:#059669;font-size:12px">[Selesai]</span>'}</h3>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadOpnameList();document.getElementById('opnameListWrap').style.display='block'">← Kembali</button>
    </div>
    <table class="hl-table"><thead><tr><th>Bahan</th><th>Satuan</th><th class="num">Sistem</th><th class="num">Fisik</th><th class="num">Selisih</th></tr></thead><tbody>${rows}</tbody></table>
    ${isDraft ? `<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
      <button class="hl-btn hl-btn-outline" onclick="opnameSave(${id})">💾 Simpan Draft</button>
      <button class="hl-btn hl-btn-primary" onclick="opnameFinalize(${id})">✅ Finalize & Adjust</button></div>` : ''}`;
}

function opnameRecalc(input, sistem) {
  const fisik = input.value === '' ? null : parseInt(input.value);
  const cell = input.closest('tr').querySelector('.sel-cell');
  if (fisik === null || isNaN(fisik)) { cell.textContent='-'; cell.style.color='#6B7280'; return; }
  const sel = fisik - sistem;
  cell.textContent = sel>0?'+'+sel:sel;
  cell.style.color = sel<0?'#DC2626':(sel>0?'#059669':'#6B7280');
}

function collectItems() {
  return [...document.querySelectorAll('#opnameDetailWrap input[data-item]')].map(i => ({
    item_id: parseInt(i.dataset.item), stok_fisik: i.value
  }));
}

async function opnameSave(id) {
  const r = await fetch('inventori.php?action=opname_save_fisik', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({_csrf:CSRF, opname_id:id, items:collectItems()})
  });
  const d = await r.json();
  if (!d.ok) { alert(d.error||'Gagal'); return; }
  alert('Draft tersimpan');
}

async function opnameFinalize(id) {
  await opnameSave(id); // simpan dulu
  if (!confirm('Finalize opname? Selisih akan jadi penyesuaian stok permanen.')) return;
  const r = await fetch('inventori.php?action=opname_finalize', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({_csrf:CSRF, opname_id:id})
  });
  const d = await r.json();
  if (!d.ok) { alert(d.error||'Gagal'); return; }
  alert(`Opname selesai. ${d.total_selisih_item} bahan disesuaikan, nilai selisih ${fmtNilai(d.nilai_selisih)}.`);
  loadOpnameList(); document.getElementById('opnameListWrap').style.display='block';
}
```
(Verify CSRF transport: inventori.php POST existing pakai FormData `_csrf` atau header `X-CSRF-Token`? Samakan — verifyCsrf cek mana. Sesuaikan opnameSave/Finalize.)

- [ ] **Step 5: Verify + smoke**

```bash
grep -nE "tab-opname|loadOpnameList|opnameFinalize|opnameRecalc" inventori.php
curl -s -o /dev/null -w "%{http_code}\n" "https://lamasy.harpy.id/inventori"
```

- [ ] **Step 6: Commit**

```bash
git add inventori.php
git commit -m "feat(opname): tab UI + JS

Tab Stok Opname: riwayat sesi, mulai opname (snapshot), form input fisik
dgn selisih auto (merah/hijau), simpan draft, finalize. Detail read-only
sesi selesai. Reuse esc/fmt + CSRF + tab pattern inventori.php."
```

---

## Task 5: E2E + Deploy

**Files:** None

- [ ] **Step 1: Push + prod migration**
```bash
git push origin main
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-stok-opname.sql
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC hl_opname; DESC hl_opname_item" 2>&1 | head -5
```

- [ ] **Step 2: HTTP smoke**
```bash
curl -s -o /dev/null -w "GET /inventori %{http_code}\n" "https://lamasy.harpy.id/inventori"
```
Expected: 302 (auth gate).

- [ ] **Step 3: Browser E2E (login staff/owner di outlet ada bahan)**

| # | Action | Expected |
|---|--------|----------|
| 1 | /inventori → tab Stok Opname | Riwayat (kosong/ada) + tombol Mulai |
| 2 | Mulai Opname Baru | Sesi draft + form semua bahan (sistem=stok terkini, fisik kosong) |
| 3 | Isi fisik: 1 kurang, 1 lebih, 1 sama | Selisih auto: −N merah, +N hijau, 0 abu |
| 4 | Simpan Draft → kembali → buka lagi | Fisik tersimpan |
| 5 | Finalize | Konfirmasi → "X bahan disesuaikan, nilai ..." → status Selesai |
| 6 | Tab Mutasi | Mutasi adjust opname muncul (catatan "Opname #") |
| 7 | Tab Stok | stok_terkini bahan = fisik yang di-input |
| 8 | Riwayat opname | Sesi selesai + ringkasan; detail read-only |

- [ ] **Step 4: DB cross-check**
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT o.id, o.tanggal, o.status, o.total_selisih_item, o.nilai_selisih,
  (SELECT COUNT(*) FROM hl_opname_item i WHERE i.opname_id=o.id) items
FROM hl_opname o ORDER BY o.id DESC LIMIT 5;" 2>&1
```

- [ ] **Step 5: Update ledger**
```bash
cat >> .superpowers/sdd/progress.md <<'EOF'

Stok Opname COMPLETE 2026-06-24 WIB.
Final state: <base>..<head>
EOF
```

---

## Self-Review Checklist

### Spec Coverage
- ✅ §3.2 schema → Task 1
- ✅ §3.3 create+snapshot → Task 2; finalize adjust → Task 3
- ✅ §4.1 UI tab + form → Task 4
- ✅ §4.2 5 actions → Task 2 (4) + Task 3 (1)
- ✅ §5 edge cases → Task 2 (kosong=skip, draft check), Task 3 (idempotent status, selisih 0)
- ✅ §6 security → permission inventori.manage + tenant/outlet scope + CSRF + transaksional
- ✅ §7 testing → Task 5

### Placeholder Scan
✓ Code lengkap. Beberapa step minta verify nama helper existing (CSRF token JS, esc/fmtNum, switchTab map, panel class, $db acquisition) — flagged eksplisit, implementer baca inventori.php. AJAX bodies + finalize transaksi diberikan penuh.

### Type/Name Consistency
- ✅ Action names konsisten: opname_list/create/get/save_fisik (T2), opname_finalize (T3) → JS fetch (T4)
- ✅ Tabel kolom konsisten T1 → T2/T3
- ✅ adjust pattern (jumlah=abs, stok_sebelum=sistem, stok_sesudah=fisik) sesuai inventori.php existing
- ✅ selisih = fisik - sistem konsisten (save + finalize + JS recalc)
- ✅ nilai_selisih = Σ(selisih × harga_beli) — T3 compute, T4 render fmtNilai

### Notes (verify saat implementasi)
- Task 2 Step 2/4: cara dapat `$db` (Database::get()) untuk transaksi — cek action transaksional existing (transfer/adjust) di inventori.php.
- Task 2 Step 4: rapikan dead `$upd` prepare — pakai prepared statement di luar loop, execute dalam loop.
- Task 4: nama CSRF token JS + transport (FormData `_csrf` vs header X-CSRF-Token) — samakan dgn verifyCsrf + POST existing. Helper esc/fmtNum + switchTab panel-map + panel wrapper class — verify dari inventori.php.
