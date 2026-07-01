<?php
// hq/supplier.php — Master Supplier (HQ, shared per tenant)
$activePage = 'hq-supplier';
$pageTitle  = 'Master Supplier';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

requirePermission('inventori.manage');

$db   = Database::get();
$tid  = (int) TenantResolver::id();
$user = currentUser();
$uid  = (int) ($user['id'] ?? 0);
$csrf = getCsrfToken();

// ── AJAX Handler ───────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    try {
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

        echo json_encode(['ok'=>false, 'error'=>'Unknown action']);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}

// ── HTML Render ────────────────────────────────────────────────
require __DIR__ . '/_layout_open.php';
?>

<style>
.supplier-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.5rem; }
.supplier-table-wrap { overflow-x:auto; }
.supplier-table { width:100%; border-collapse:collapse; font-size:.9rem; }
.supplier-table th { background:var(--bg-subtle,#f4f6fa); font-weight:600; padding:.6rem .75rem; text-align:left; white-space:nowrap; border-bottom:2px solid var(--border-color,#e2e8f0); }
.supplier-table td { padding:.6rem .75rem; border-bottom:1px solid var(--border-color,#e2e8f0); vertical-align:middle; }
.supplier-table tr:hover td { background:var(--row-hover,#f8fafc); }
.supplier-table .num { text-align:right; }
.badge-aktif { display:inline-block; padding:.15rem .5rem; border-radius:999px; font-size:.75rem; font-weight:600; background:#d1fae5; color:#065f46; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:200; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:.75rem; padding:1.5rem; width:100%; max-width:520px; max-height:90vh; overflow-y:auto; box-shadow:0 8px 40px rgba(0,0,0,.18); }
.modal-box h3 { margin:0 0 1rem; font-size:1.1rem; }
.form-row { margin-bottom:.85rem; }
.form-row label { display:block; font-size:.82rem; font-weight:600; margin-bottom:.3rem; color:var(--text-muted,#64748b); }
.form-row input, .form-row textarea, .form-row select {
  width:100%; padding:.45rem .65rem; border:1px solid var(--border-color,#e2e8f0);
  border-radius:.4rem; font-size:.9rem; box-sizing:border-box; font-family:inherit;
}
.form-row textarea { resize:vertical; min-height:64px; }
.modal-actions { display:flex; justify-content:flex-end; gap:.5rem; margin-top:1rem; }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem 1rem; border:none; border-radius:.45rem; font-size:.88rem; font-weight:600; cursor:pointer; transition:opacity .15s; }
.btn:hover { opacity:.85; }
.btn-primary { background:var(--color-primary,#1e40af); color:#fff; }
.btn-danger  { background:#dc2626; color:#fff; }
.btn-ghost   { background:transparent; border:1px solid var(--border-color,#e2e8f0); color:var(--text-muted,#64748b); }
.tbl-empty   { text-align:center; color:var(--text-muted,#64748b); padding:2rem; }
#supplierAlert { display:none; padding:.65rem 1rem; border-radius:.5rem; margin-bottom:1rem; font-size:.9rem; }
#supplierAlert.success { background:#d1fae5; color:#065f46; display:block; }
#supplierAlert.error   { background:#fee2e2; color:#991b1b; display:block; }
</style>

<div class="hq-page-header" style="margin-bottom:1.25rem;">
  <h1 class="hq-page-title">Master Supplier</h1>
  <p class="hq-page-sub">Data supplier bahan baku &amp; perlengkapan — berlaku untuk semua outlet.</p>
</div>

<div id="supplierAlert"></div>

<div class="supplier-toolbar">
  <div style="font-size:.88rem; color:var(--text-muted,#64748b);">
    <span id="supplierCount">—</span> supplier aktif
  </div>
  <button class="btn btn-primary" onclick="openModal()">+ Tambah Supplier</button>
</div>

<div class="supplier-table-wrap">
  <table class="supplier-table" id="supplierTable">
    <thead>
      <tr>
        <th>#</th>
        <th>Nama Supplier</th>
        <th>Kontak</th>
        <th>Telepon</th>
        <th>Term Pembayaran</th>
        <th class="num">Total PO</th>
        <th class="num">Nilai Diterima</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody id="supplierBody">
      <tr><td colspan="8" class="tbl-empty">Memuat data…</td></tr>
    </tbody>
  </table>
</div>

<!-- Modal Form -->
<div class="modal-overlay" id="supplierModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <h3 id="modalTitle">Tambah Supplier</h3>
    <form id="supplierForm" onsubmit="submitSupplier(event)">
      <input type="hidden" id="fId" name="id" value="0">
      <div class="form-row">
        <label for="fNama">Nama Supplier <span style="color:#dc2626">*</span></label>
        <input type="text" id="fNama" name="nama" maxlength="100" required placeholder="Contoh: CV Maju Jaya">
      </div>
      <div class="form-row">
        <label for="fKontak">Nama Kontak</label>
        <input type="text" id="fKontak" name="kontak_nama" maxlength="100" placeholder="Nama PIC">
      </div>
      <div class="form-row">
        <label for="fTelepon">Telepon</label>
        <input type="tel" id="fTelepon" name="telepon" maxlength="20" placeholder="628xxx">
      </div>
      <div class="form-row">
        <label for="fAlamat">Alamat</label>
        <textarea id="fAlamat" name="alamat" maxlength="500" placeholder="Jl. …"></textarea>
      </div>
      <div class="form-row">
        <label for="fTerm">Term Pembayaran</label>
        <input type="text" id="fTerm" name="term_pembayaran" maxlength="50" placeholder="Contoh: NET 30, COD">
      </div>
      <div class="form-row">
        <label for="fCatatan">Catatan</label>
        <textarea id="fCatatan" name="catatan" maxlength="500" placeholder="Catatan tambahan…"></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button type="submit" class="btn btn-primary" id="btnSave">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Constants ──────────────────────────────────────────────────
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Alert helper ───────────────────────────────────────────────
function showAlert(msg, type='success') {
  const el = document.getElementById('supplierAlert');
  el.textContent = msg;
  el.className = type;
  setTimeout(() => { el.className = ''; el.style.display = 'none'; }, 3500);
}

// ── Load supplier list ─────────────────────────────────────────
async function loadSupplier() {
  try {
    const r = await fetch('/hq/supplier?action=list_supplier', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Gagal memuat data');

    const rows = j.data;
    document.getElementById('supplierCount').textContent = rows.length;

    if (!rows.length) {
      document.getElementById('supplierBody').innerHTML =
        '<tr><td colspan="8" class="tbl-empty">Belum ada supplier. Klik "+ Tambah Supplier" untuk menambahkan.</td></tr>';
      return;
    }

    let html = '';
    rows.forEach((s, i) => {
      html += `<tr>
        <td>${i+1}</td>
        <td><strong>${esc(s.nama)}</strong></td>
        <td>${esc(s.kontak_nama||'—')}</td>
        <td>${esc(s.telepon||'—')}</td>
        <td>${esc(s.term_pembayaran||'—')}</td>
        <td class="num">${fmtNum(s.total_po||0)}</td>
        <td class="num">${fmtRp(s.nilai_po||0)}</td>
        <td>
          <button class="btn btn-ghost" style="padding:.3rem .6rem;font-size:.8rem" onclick="openModal(${JSON.stringify(s)})">Edit</button>
          <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.8rem;margin-left:.25rem" onclick="deleteSupplier(${s.id},${JSON.stringify(s.nama)})">Hapus</button>
        </td>
      </tr>`;
    });
    document.getElementById('supplierBody').innerHTML = html;
  } catch(e) {
    document.getElementById('supplierBody').innerHTML =
      `<tr><td colspan="8" class="tbl-empty" style="color:#dc2626">Gagal memuat: ${esc(e.message)}</td></tr>`;
  }
}

// ── Modal open/close ───────────────────────────────────────────
function openModal(data) {
  const modal = document.getElementById('supplierModal');
  const form  = document.getElementById('supplierForm');
  form.reset();
  if (data) {
    document.getElementById('modalTitle').textContent = 'Edit Supplier';
    document.getElementById('fId').value         = data.id;
    document.getElementById('fNama').value        = data.nama || '';
    document.getElementById('fKontak').value      = data.kontak_nama || '';
    document.getElementById('fTelepon').value     = data.telepon || '';
    document.getElementById('fAlamat').value      = data.alamat || '';
    document.getElementById('fTerm').value        = data.term_pembayaran || '';
    document.getElementById('fCatatan').value     = data.catatan || '';
  } else {
    document.getElementById('modalTitle').textContent = 'Tambah Supplier';
    document.getElementById('fId').value = '0';
  }
  modal.classList.add('open');
  document.getElementById('fNama').focus();
}

function closeModal() {
  document.getElementById('supplierModal').classList.remove('open');
}

// ── Save supplier ──────────────────────────────────────────────
async function submitSupplier(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSave');
  btn.disabled = true;
  btn.textContent = 'Menyimpan…';
  try {
    const fd = new FormData(document.getElementById('supplierForm'));
    const r = await fetch('/hq/supplier?action=save_supplier', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF },
      body: fd
    });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Gagal menyimpan');
    closeModal();
    showAlert('Supplier berhasil disimpan.', 'success');
    loadSupplier();
  } catch(err) {
    showAlert(err.message, 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Simpan';
  }
}

// ── Delete supplier ────────────────────────────────────────────
async function deleteSupplier(id, nama) {
  if (!confirm(`Hapus supplier "${nama}"? Data tidak dapat dipulihkan.`)) return;
  try {
    const fd = new FormData();
    fd.append('id', id);
    const r = await fetch('/hq/supplier?action=delete_supplier', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF },
      body: fd
    });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Gagal menghapus');
    showAlert('Supplier berhasil dihapus.', 'success');
    loadSupplier();
  } catch(err) {
    showAlert(err.message, 'error');
  }
}

// ── Init ───────────────────────────────────────────────────────
loadSupplier();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
