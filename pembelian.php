<?php
// ══════════════════════════════════════════════════════
// pembelian.php — Purchase Order per Outlet
//
// Backend skeleton untuk PO bahan baku: list, buat draft,
// simpan item, dan dropdown supplier/bahan. UI menyusul
// di Task 5. Dipesan/terima di Task 4.
// ══════════════════════════════════════════════════════
$activePage = 'pembelian';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';

$user = currentUser();

// Permission: butuh inventori.manage atau kas.create
if (!hasPermission('inventori.manage') && !hasPermission('kas.create')) {
    requirePermission('inventori.manage'); // exit dengan pesan akses ditolak
}
$canManage = hasPermission('inventori.manage') || hasPermission('kas.create');

// ── Helper: Generate nomor PO unik (PO/YYYY/MM/000N) ─
function generatePoNo(PDO $db, int $tid): string
{
    $ym     = date('Y/m');
    $prefix = "PO/$ym/";
    $s = $db->prepare("SELECT COUNT(*) FROM hl_po WHERE tenant_id = ? AND no_po LIKE ?");
    $s->execute([$tid, $prefix . '%']);
    return $prefix . str_pad((string)((int)$s->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
}

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // ─── PO LIST ──────────────────────────────────────
    if ($action === 'po_list') {
        $rows = TenantQuery::raw(
            "SELECT p.id, p.no_po, p.tanggal, p.status, p.total, s.nama AS supplier_nama
             FROM hl_po p
             LEFT JOIN hl_supplier s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.outlet_id = ?
             ORDER BY p.created_at DESC LIMIT 50",
            [$tid, $oid]
        );
        echo json_encode(['ok' => true, 'rows' => $rows]);
        exit;
    }

    // ─── SUPPLIER OPTS ────────────────────────────────
    if ($action === 'supplier_opts') {
        $rows = TenantQuery::raw(
            "SELECT id, nama FROM hl_supplier
             WHERE tenant_id = ? AND is_active = 1
             ORDER BY nama",
            [$tid]
        );
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ─── BAHAN OPTS ───────────────────────────────────
    if ($action === 'bahan_opts') {
        $rows = TenantQuery::raw(
            "SELECT id, nama, satuan, harga_beli
             FROM hl_bahan
             WHERE tenant_id = ? AND outlet_id = ? AND is_active = 1
             ORDER BY nama",
            [$tid, $oid]
        );
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ─── PO CREATE (draft + no_po generator) ──────────
    if ($action === 'po_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error' => 'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];

        $supplierId = (int)($d['supplier_id'] ?? 0);
        $tgl = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['tanggal'] ?? '') ? $d['tanggal'] : date('Y-m-d');

        if (!$supplierId) { echo json_encode(['error' => 'Pilih supplier']); exit; }

        $db = Database::get();

        // Validasi supplier milik tenant + aktif
        $sc = $db->prepare("SELECT 1 FROM hl_supplier WHERE id = ? AND tenant_id = ? AND is_active = 1");
        $sc->execute([$supplierId, $tid]);
        if (!$sc->fetchColumn()) { echo json_encode(['error' => 'Supplier tidak valid']); exit; }

        $noPo = generatePoNo($db, (int)$tid);
        $db->prepare(
            "INSERT INTO hl_po (tenant_id, outlet_id, supplier_id, no_po, tanggal, status, input_by)
             VALUES (?, ?, ?, ?, ?, 'draft', ?)"
        )->execute([$tid, $oid, $supplierId, $noPo, $tgl, (int)($user['id'] ?? 0)]);

        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId(), 'no_po' => $noPo]);
        exit;
    }

    // ─── PO GET (header + items) ──────────────────────
    if ($action === 'po_get') {
        $id  = (int)($_GET['id'] ?? 0);
        $hdr = TenantQuery::rawOne(
            "SELECT p.*, s.nama AS supplier_nama
             FROM hl_po p
             LEFT JOIN hl_supplier s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
             WHERE p.id = ? AND p.tenant_id = ? AND p.outlet_id = ?",
            [$id, $tid, $oid]
        );
        if (!$hdr) { echo json_encode(['error' => 'PO tidak ditemukan']); exit; }

        $items = TenantQuery::raw(
            "SELECT i.id, i.bahan_id, i.qty, i.harga_satuan, i.subtotal,
                    b.nama, b.satuan
             FROM hl_po_item i
             JOIN hl_bahan b ON b.id = i.bahan_id AND b.tenant_id = i.tenant_id
             WHERE i.po_id = ? AND i.tenant_id = ?
             ORDER BY i.id",
            [$id, $tid]
        );
        echo json_encode(['ok' => true, 'header' => $hdr, 'items' => $items]);
        exit;
    }

    // ─── PO SAVE ITEMS (draft only, replace + recompute total) ──
    if ($action === 'po_save_items' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error' => 'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];

        $poId  = (int)($d['po_id'] ?? 0);
        $items = $d['items'] ?? []; // [{bahan_id, qty, harga_satuan}]

        $db = Database::get();

        // Pastikan PO milik outlet ini dan masih draft
        $chk = $db->prepare("SELECT status FROM hl_po WHERE id = ? AND tenant_id = ? AND outlet_id = ?");
        $chk->execute([$poId, $tid, $oid]);
        $st = $chk->fetchColumn();
        if ($st === false) { echo json_encode(['error' => 'PO tidak ditemukan']); exit; }
        if ($st !== 'draft') { echo json_encode(['error' => 'PO sudah dipesan/diterima']); exit; }

        $db->beginTransaction();
        try {
            // Hapus item lama lalu sisipkan ulang
            $db->prepare("DELETE FROM hl_po_item WHERE po_id = ? AND tenant_id = ?")
               ->execute([$poId, $tid]);

            $ins = $db->prepare(
                "INSERT INTO hl_po_item (po_id, tenant_id, bahan_id, qty, harga_satuan, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            // Validasi bahan milik outlet ini + aktif
            $bahanChk = $db->prepare(
                "SELECT 1 FROM hl_bahan WHERE id = ? AND tenant_id = ? AND outlet_id = ? AND is_active = 1"
            );

            $total = 0;
            foreach ($items as $it) {
                $bahanId = (int)($it['bahan_id'] ?? 0);
                $qty     = max(1, (int)($it['qty'] ?? 0));
                $harga   = max(0, (int)($it['harga_satuan'] ?? 0));
                if (!$bahanId || $qty < 1) continue;

                $bahanChk->execute([$bahanId, $tid, $oid]);
                if (!$bahanChk->fetchColumn()) continue; // bahan tidak valid, skip

                $sub = $qty * $harga;
                $ins->execute([$poId, $tid, $bahanId, $qty, $harga, $sub]);
                $total += $sub;
            }

            $db->prepare("UPDATE hl_po SET total = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$total, $poId, $tid]);

            $db->commit();
            echo json_encode(['ok' => true, 'total' => $total]);
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ─── PO DIPESAN (draft → dipesan, validasi ≥1 item) ──
    if ($action === 'po_dipesan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error' => 'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $poId = (int)($d['po_id'] ?? 0);
        $db = Database::get();
        // validasi: draft + punya item
        $hdr = $db->prepare("SELECT status FROM hl_po WHERE id=? AND tenant_id=? AND outlet_id=?");
        $hdr->execute([$poId, $tid, $oid]);
        $st = $hdr->fetchColumn();
        if ($st === false) { echo json_encode(['error' => 'PO tidak ditemukan']); exit; }
        if ($st !== 'draft') { echo json_encode(['error' => 'PO bukan draft']); exit; }
        $cnt = $db->prepare("SELECT COUNT(*) FROM hl_po_item WHERE po_id=? AND tenant_id=?");
        $cnt->execute([$poId, $tid]);
        if ((int)$cnt->fetchColumn() < 1) { echo json_encode(['error' => 'PO belum punya item']); exit; }
        $db->prepare("UPDATE hl_po SET status='dipesan', dipesan_at=NOW() WHERE id=? AND tenant_id=? AND status='draft'")
           ->execute([$poId, $tid]);
        echo json_encode(['ok' => true]); exit;
    }

    // ─── PO TERIMA (FOR UPDATE + mutasi masuk, anti double-receive) ──
    if ($action === 'po_terima' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error' => 'Akses ditolak']); exit; }
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
            if (!$po) { $db->rollBack(); echo json_encode(['error' => 'PO tidak ditemukan']); exit; }
            if ($po['status'] !== 'dipesan') { $db->rollBack(); echo json_encode(['error' => 'PO harus berstatus dipesan']); exit; }

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
            echo json_encode(['ok' => true, 'count' => count($rows)]); exit;
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error' => $e->getMessage()]); exit; }
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Pembelian'); ?>
<style>
/* STATUS BADGES */
.po-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase}
.po-badge.draft    {background:#FEF3C7;color:#92400E}
.po-badge.dipesan  {background:#DBEAFE;color:#1E40AF}
.po-badge.diterima {background:#D1FAE5;color:#065F46}
.po-badge.batal    {background:#FEE2E2;color:#991B1B}

/* PO DETAIL PANEL */
.po-detail-hdr{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:18px}
.po-detail-box{background:var(--off);border-radius:var(--r);padding:12px 14px}
.po-detail-box .lbl{font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;margin-bottom:4px}
.po-detail-box .val{font-size:14px;font-weight:700;color:var(--navy)}

/* ITEM ROWS */
.po-item-row{display:grid;grid-template-columns:1fr 90px 110px 90px 28px;gap:8px;align-items:center;margin-bottom:8px}
.po-item-row input, .po-item-row select{width:100%}
.po-item-row .subtotal-cell{font-family:var(--mono);font-weight:700;color:var(--navy);text-align:right;font-size:13px}
.po-total-bar{display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:12px;padding-top:12px;border-top:2px solid rgba(27,45,90,.08)}
.po-total-label{font-size:13px;font-weight:700;color:var(--gray)}
.po-total-amount{font-size:clamp(14px,4.5vw,20px);white-space:nowrap;letter-spacing:-0.02em;font-weight:800;font-family:var(--mono);color:var(--navy)}

/* ACTION ROW */
.po-action-row{display:flex;gap:8px;margin-top:14px;justify-content:flex-end;flex-wrap:wrap}

/* LIFECYCLE BUTTONS */
.btn-dipesan{background:#2563EB;color:#fff}
.btn-dipesan:hover{background:#1D4ED8}
.btn-terima{background:#059669;color:#fff}
.btn-terima:hover{background:#047857}

@media(max-width:700px){.po-detail-hdr{grid-template-columns:1fr 1fr}.po-item-row{grid-template-columns:1fr 70px 90px 80px 24px}}
</style>
</head>
<body>
<?php renderTopbar('pembelian'); ?>
<div class="hl-main">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:12px;flex-wrap:wrap">
    <div>
      <h1 style="font-size:22px;font-weight:800;color:var(--navy);margin:0 0 4px">🛒 Pembelian (Purchase Order)</h1>
      <p style="color:var(--gray);font-size:13px;margin:0">Order pembelian bahan baku ke supplier — buat draft, kirim, catat penerimaan</p>
    </div>
    <?php if ($canManage): ?>
    <button class="hl-btn hl-btn-primary" onclick="poCreate()">➕ Buat PO</button>
    <?php endif; ?>
  </div>

  <!-- PO LIST -->
  <div class="hl-card">
    <div class="hl-table-wrap">
      <table class="hl-table">
        <thead>
          <tr>
            <th>No PO</th>
            <th>Tanggal</th>
            <th>Supplier</th>
            <th>Status</th>
            <th class="td-num">Total</th>
            <th style="width:100px">Aksi</th>
          </tr>
        </thead>
        <tbody id="bodyPoList">
          <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat data...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ═════ MODAL: BUAT PO ═════ -->
<div class="hl-modal-overlay" id="modalBuatPo">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title">➕ Buat Purchase Order</span>
      <button class="hl-modal-close" onclick="closeModal('modalBuatPo')">×</button>
    </div>
    <div class="hl-modal-body">
      <div class="hl-form-group">
        <label class="hl-label">Supplier <span class="req">*</span></label>
        <select id="po_supplier_id" class="hl-input">
          <option value="">— Pilih Supplier —</option>
        </select>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Tanggal PO</label>
        <input type="date" id="po_tanggal" class="hl-input"/>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal('modalBuatPo')">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="poSubmitCreate()">Buat Draft</button>
    </div>
  </div>
</div>

<!-- ═════ MODAL: DETAIL / EDIT PO ═════ -->
<div class="hl-modal-overlay" id="modalPo">
  <div class="hl-modal" style="max-width:760px">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="poModalTitle">Purchase Order</span>
      <button class="hl-modal-close" onclick="closeModal('modalPo')">×</button>
    </div>
    <div class="hl-modal-body" id="poModalBody">
      <div style="color:var(--gray);text-align:center;padding:30px">Memuat...</div>
    </div>
  </div>
</div>

<script>
const CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
function CSRF() { return csrfToken(); }

// ─── STATE ───────────────────────────────────────────
let _supplierOpts = null; // cache
let _bahanOpts    = null; // cache
let _currentPoId  = null;

// ─── CLOSE MODAL ────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ─── STATUS BADGE ────────────────────────────────────
function statusBadge(st) {
  const map = { draft:'📝 Draft', dipesan:'📤 Dipesan', diterima:'✅ Diterima', batal:'❌ Batal' };
  return `<span class="po-badge ${esc(st)}">${esc(map[st] || st)}</span>`;
}

// ─── LOAD PO LIST ─────────────────────────────────────
async function loadPoList() {
  const r = await fetch('/pembelian.php?action=po_list');
  const j = await r.json();
  const tbody = document.getElementById('bodyPoList');
  if (!j.ok || !j.rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--gray)">'
      + (j.ok ? 'Belum ada purchase order. ' + (CAN_MANAGE ? 'Klik <strong>Buat PO</strong> untuk mulai.' : '') : esc(j.error||'Gagal memuat'))
      + '</td></tr>';
    return;
  }
  tbody.innerHTML = j.rows.map(p => `
    <tr>
      <td><strong style="font-family:var(--mono)">${esc(p.no_po)}</strong></td>
      <td>${fmtTanggal(p.tanggal)}</td>
      <td>${esc(p.supplier_nama || '-')}</td>
      <td>${statusBadge(p.status)}</td>
      <td class="td-num">${p.total > 0 ? fmtRp(p.total) : '-'}</td>
      <td>
        <button class="hl-btn hl-btn-sm hl-btn-outline" onclick="poOpen(${parseInt(p.id)})">
          ${p.status === 'draft' && CAN_MANAGE ? '✏️ Edit' : '👁 Detail'}
        </button>
      </td>
    </tr>`).join('');
}

// ─── SUPPLIER OPTS (cached) ───────────────────────────
async function getSupplierOpts() {
  if (_supplierOpts) return _supplierOpts;
  const r = await fetch('/pembelian.php?action=supplier_opts');
  const j = await r.json();
  _supplierOpts = j.ok ? j.data : [];
  return _supplierOpts;
}

// ─── BAHAN OPTS (cached) ──────────────────────────────
async function getBahanOpts() {
  if (_bahanOpts) return _bahanOpts;
  const r = await fetch('/pembelian.php?action=bahan_opts');
  const j = await r.json();
  _bahanOpts = j.ok ? j.data : [];
  return _bahanOpts;
}

// ─── BUAT PO MODAL ───────────────────────────────────
async function poCreate() {
  const suppliers = await getSupplierOpts();
  const sel = document.getElementById('po_supplier_id');
  sel.innerHTML = '<option value="">— Pilih Supplier —</option>'
    + suppliers.map(s => `<option value="${parseInt(s.id)}">${esc(s.nama)}</option>`).join('');
  document.getElementById('po_tanggal').value = new Date().toISOString().split('T')[0];
  document.getElementById('modalBuatPo').classList.add('open');
}

async function poSubmitCreate() {
  const supplier_id = parseInt(document.getElementById('po_supplier_id').value) || 0;
  const tanggal     = document.getElementById('po_tanggal').value;
  if (!supplier_id) { showToast('Pilih supplier terlebih dahulu', 'error'); return; }
  const r = await fetch('/pembelian.php?action=po_create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF() },
    body: JSON.stringify({ supplier_id, tanggal })
  });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('PO ' + j.no_po + ' dibuat sebagai draft');
  closeModal('modalBuatPo');
  loadPoList();
  poOpen(j.id);
}

// ─── BUKA / DETAIL PO ────────────────────────────────
async function poOpen(id) {
  _currentPoId = id;
  document.getElementById('poModalTitle').textContent = 'Purchase Order';
  document.getElementById('poModalBody').innerHTML = '<div style="color:var(--gray);text-align:center;padding:30px">Memuat...</div>';
  document.getElementById('modalPo').classList.add('open');

  const [rPo, bahanOpts] = await Promise.all([
    fetch('/pembelian.php?action=po_get&id=' + id).then(r => r.json()),
    getBahanOpts()
  ]);

  if (rPo.error) {
    document.getElementById('poModalBody').innerHTML = `<div style="color:#DC2626;padding:20px">${esc(rPo.error)}</div>`;
    return;
  }

  const h = rPo.header;
  const items = rPo.items;
  const isDraft = h.status === 'draft';

  document.getElementById('poModalTitle').textContent = h.no_po;

  // Header info boxes
  let html = `
    <div class="po-detail-hdr">
      <div class="po-detail-box">
        <div class="lbl">Supplier</div>
        <div class="val">${esc(h.supplier_nama || '-')}</div>
      </div>
      <div class="po-detail-box">
        <div class="lbl">Tanggal</div>
        <div class="val">${fmtTanggal(h.tanggal)}</div>
      </div>
      <div class="po-detail-box">
        <div class="lbl">Status</div>
        <div class="val">${statusBadge(h.status)}</div>
      </div>
    </div>`;

  // Item tabel — editable kalau draft
  html += `<div id="poItemsWrap">`;

  if (isDraft && CAN_MANAGE) {
    // Header kolom edit
    html += `<div class="po-item-row" style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;margin-bottom:4px">
      <span>Bahan</span><span>Qty</span><span>Harga Satuan</span><span style="text-align:right">Subtotal</span><span></span>
    </div>`;
    // Existing items
    const bahanMap = {};
    bahanOpts.forEach(b => { bahanMap[b.id] = b; });
    items.forEach((it, idx) => {
      html += poItemRow(idx, bahanOpts, it.bahan_id, it.qty, it.harga_satuan, it.subtotal);
    });
    // Always show one empty row at bottom if no items
    if (!items.length) {
      html += poItemRow(0, bahanOpts, '', 1, 0, 0);
    }
    html += `</div>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="poAddItem()" style="margin-top:8px">➕ Tambah Item</button>
      <div class="po-total-bar">
        <span class="po-total-label">Total PO</span>
        <span class="po-total-amount" id="poTotalDisplay">Rp 0</span>
      </div>
      <div class="po-action-row">
        <button class="hl-btn hl-btn-outline" onclick="poSaveItems()">💾 Simpan Draft</button>
        <button class="hl-btn btn-dipesan" onclick="poDipesan()">📤 Tandai Dipesan</button>
      </div>`;
  } else {
    // READ-ONLY view
    if (!items.length) {
      html += '<p style="color:var(--gray);text-align:center;padding:20px">Tidak ada item.</p>';
    } else {
      html += `<table class="hl-table" style="margin-top:0">
        <thead><tr><th>Bahan</th><th>Satuan</th><th class="td-num">Qty</th><th class="td-num">Harga Satuan</th><th class="td-num">Subtotal</th></tr></thead>
        <tbody>`;
      items.forEach(it => {
        html += `<tr>
          <td><strong>${esc(it.nama)}</strong></td>
          <td>${esc(it.satuan)}</td>
          <td class="td-num">${parseInt(it.qty)}</td>
          <td class="td-num">${fmtRp(it.harga_satuan)}</td>
          <td class="td-num">${fmtRp(it.subtotal)}</td>
        </tr>`;
      });
      html += `</tbody></table>
        <div class="po-total-bar">
          <span class="po-total-label">Total PO</span>
          <span class="po-total-amount">${fmtRp(h.total)}</span>
        </div>`;
    }
    // Lifecycle buttons by status
    if (h.status === 'dipesan' && CAN_MANAGE) {
      html += `<div class="po-action-row">
        <button class="hl-btn btn-terima" onclick="poTerima()">✅ Terima Barang</button>
      </div>`;
    }
    html += `</div>`;
  }

  document.getElementById('poModalBody').innerHTML = html;

  // Recalc total untuk draft
  if (isDraft && CAN_MANAGE) {
    poRecalc();
    // Populate existing items subtotals
    items.forEach((it, idx) => {
      const row = document.querySelectorAll('#poItemsWrap .po-item-row')[idx];
      if (row) {
        const subCell = row.querySelector('.subtotal-cell');
        if (subCell) subCell.textContent = fmtRp(it.subtotal);
      }
    });
    poRecalc();
  }
}

// ─── BUILD ITEM ROW (HTML string) ─────────────────────
function poItemRow(idx, bahanOpts, bahanId, qty, harga, subtotal) {
  const opts = bahanOpts.map(b =>
    `<option value="${parseInt(b.id)}" data-harga="${parseInt(b.harga_beli||0)}" ${parseInt(b.id)==parseInt(bahanId)?'selected':''}>${esc(b.nama)} (${esc(b.satuan)})</option>`
  ).join('');
  return `<div class="po-item-row">
    <select class="hl-input po-bahan-sel" onchange="poOnBahanChange(this)">
      <option value="">— Pilih Bahan —</option>
      ${opts}
    </select>
    <input type="number" class="hl-input po-qty" min="1" value="${parseInt(qty)||1}" oninput="poRecalc()"/>
    <input type="number" class="hl-input po-harga" min="0" step="500" value="${parseInt(harga)||0}" oninput="poRecalc()"/>
    <span class="subtotal-cell">${fmtRp(subtotal)}</span>
    <button style="background:none;border:none;cursor:pointer;font-size:16px;color:#EF4444" onclick="this.closest('.po-item-row').remove();poRecalc()">✕</button>
  </div>`;
}

// ─── ADD ITEM ROW ─────────────────────────────────────
async function poAddItem() {
  const bahanOpts = await getBahanOpts();
  const wrap = document.getElementById('poItemsWrap');
  const idx = wrap.querySelectorAll('.po-item-row').length;
  const tmp = document.createElement('div');
  tmp.innerHTML = poItemRow(idx, bahanOpts, '', 1, 0, 0);
  wrap.appendChild(tmp.firstElementChild);
  poRecalc();
}

// ─── ON BAHAN CHANGE (auto-fill harga) ───────────────
function poOnBahanChange(sel) {
  const opt = sel.options[sel.selectedIndex];
  const harga = parseInt(opt.dataset.harga || 0);
  const row = sel.closest('.po-item-row');
  if (harga > 0) row.querySelector('.po-harga').value = harga;
  poRecalc();
}

// ─── RECALC SUBTOTALS + TOTAL ─────────────────────────
function poRecalc() {
  let total = 0;
  document.querySelectorAll('#poItemsWrap .po-item-row').forEach(row => {
    const qty   = parseInt(row.querySelector('.po-qty')?.value   || 0);
    const harga = parseInt(row.querySelector('.po-harga')?.value || 0);
    const sub   = qty * harga;
    const cell  = row.querySelector('.subtotal-cell');
    if (cell) cell.textContent = fmtRp(sub);
    total += sub;
  });
  const el = document.getElementById('poTotalDisplay');
  if (el) el.textContent = fmtRp(total);
}

// ─── COLLECT ITEMS ────────────────────────────────────
function collectPoItems() {
  const items = [];
  document.querySelectorAll('#poItemsWrap .po-item-row').forEach(row => {
    const bahanId = parseInt(row.querySelector('.po-bahan-sel')?.value || 0);
    const qty     = parseInt(row.querySelector('.po-qty')?.value || 0);
    const harga   = parseInt(row.querySelector('.po-harga')?.value || 0);
    if (bahanId && qty >= 1) items.push({ bahan_id: bahanId, qty, harga_satuan: harga });
  });
  return items;
}

// ─── SIMPAN ITEMS ─────────────────────────────────────
async function poSaveItems() {
  const items = collectPoItems();
  const r = await fetch('/pembelian.php?action=po_save_items', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF() },
    body: JSON.stringify({ po_id: _currentPoId, items })
  });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Item tersimpan. Total: ' + fmtRp(j.total));
  loadPoList();
}

// ─── TANDAI DIPESAN ───────────────────────────────────
async function poDipesan() {
  // Auto-save items dulu
  await poSaveItems();
  if (!await lmConfirm('Tandai PO ini sebagai "Dipesan"? Setelah ini item tidak bisa diubah.')) return;
  const r = await fetch('/pembelian.php?action=po_dipesan', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF() },
    body: JSON.stringify({ po_id: _currentPoId })
  });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('PO ditandai dipesan');
  closeModal('modalPo');
  loadPoList();
}

// ─── TERIMA BARANG ────────────────────────────────────
async function poTerima() {
  if (!await lmConfirm('Konfirmasi penerimaan barang? Stok akan otomatis ditambah ke inventori.')) return;
  const r = await fetch('/pembelian.php?action=po_terima', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF() },
    body: JSON.stringify({ po_id: _currentPoId })
  });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Barang diterima — ' + j.count + ' item masuk ke inventori ✅');
  closeModal('modalPo');
  loadPoList();
}

// ─── INIT ─────────────────────────────────────────────
(function init() {
  loadPoList();
  // Pre-fetch opts untuk performa modal
  getSupplierOpts();
  getBahanOpts();
})();
</script>

<?php renderToast(); ?>
</body>
</html>
