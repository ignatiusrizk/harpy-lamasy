<?php
// ══════════════════════════════════════════════════════
// hq/inventori.php — Konsolidasi Inventori Lintas Outlet
//
// - Tabel pivot stok semua outlet (per bahan × outlet)
// - Aggregate total stok + nilai inventori per outlet
// - Transfer stok antar outlet (insert mutasi pair):
//     - 1 row tipe='transfer' di outlet asal  (transfer_pair_id link)
//     - 1 row tipe='masuk'    di outlet tujuan (transfer_pair_id link)
// - List alert konsolidasi
// ══════════════════════════════════════════════════════
$activePage = 'hq-inventori';
$pageTitle  = 'Inventori Lintas Outlet';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

// requirePermission/logAudit stubs sudah di hq_guard.php

$db   = Database::get();
$tid  = (int) TenantResolver::id();
$user = currentUser();
$uid  = (int) ($user['id'] ?? 0);
$csrf = getCsrfToken();

// ── AJAX Handler ───────────────────────────────────────
if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_GET['action'])) && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // List konsolidasi: pivot bahan × outlet
    if ($action === 'consolidated') {
        // Ambil semua outlet aktif tenant ini
        $outlets = $db->prepare(
            "SELECT id, nama_outlet FROM outlets
             WHERE tenant_id = ? AND status IN ('active','trial','grace')
             ORDER BY is_main DESC, nama_outlet ASC"
        );
        $outlets->execute([$tid]);
        $outletList = $outlets->fetchAll(PDO::FETCH_ASSOC);

        // Ambil semua stok terkini per outlet
        $stokStmt = $db->prepare(
            "SELECT id, outlet_id, nama, kategori, satuan, stok_terkini, stok_minimum, status_stok, harga_beli
             FROM hl_bahan_stok
             WHERE tenant_id = ? AND is_active = 1
             ORDER BY nama ASC"
        );
        $stokStmt->execute([$tid]);
        $stokRaw = $stokStmt->fetchAll(PDO::FETCH_ASSOC);

        // Pivot: group by nama → { outlet_id: stok, total, kategori, satuan }
        $pivot = [];
        $kritisPerOutlet = []; // per outlet hitung stok minim/habis
        foreach ($outletList as $o) {
            $kritisPerOutlet[$o['id']] = ['minim' => 0, 'habis' => 0];
        }
        foreach ($stokRaw as $r) {
            $key = $r['nama'];
            if (!isset($pivot[$key])) {
                $pivot[$key] = [
                    'nama'     => $r['nama'],
                    'kategori' => $r['kategori'],
                    'satuan'   => $r['satuan'],
                    'per_outlet' => [],
                    'total'    => 0,
                    'has_kritis'=> false,
                ];
            }
            $pivot[$key]['per_outlet'][$r['outlet_id']] = [
                'bahan_id'     => (int)$r['id'],
                'stok'         => (int)$r['stok_terkini'],
                'minimum'      => (int)$r['stok_minimum'],
                'status'       => $r['status_stok'],
                'harga_beli'   => (int)$r['harga_beli'],
            ];
            $pivot[$key]['total'] += (int)$r['stok_terkini'];
            if ($r['status_stok'] !== 'aman') {
                $pivot[$key]['has_kritis'] = true;
                if ($r['status_stok'] === 'habis') $kritisPerOutlet[$r['outlet_id']]['habis']++;
                else                               $kritisPerOutlet[$r['outlet_id']]['minim']++;
            }
        }

        echo json_encode([
            'outlets'         => $outletList,
            'rows'            => array_values($pivot),
            'kritis_per_outlet' => $kritisPerOutlet,
        ]);
        exit;
    }

    // List alert kritis dengan outlet name
    if ($action === 'alerts') {
        $rows = $db->prepare(
            "SELECT s.id, s.outlet_id, s.nama, s.kategori, s.satuan,
                    s.stok_terkini, s.stok_minimum, s.status_stok, o.nama_outlet
             FROM hl_bahan_stok s
             JOIN outlets o ON o.id = s.outlet_id
             WHERE s.tenant_id = ? AND s.is_active = 1
               AND s.stok_terkini <= s.stok_minimum
             ORDER BY (s.stok_terkini <= 0) DESC, o.nama_outlet ASC, s.nama ASC"
        );
        $rows->execute([$tid]);
        echo json_encode(['data' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Transfer antar outlet
    if ($action === 'transfer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission('inventori.manage');
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $bahanAsalId  = (int)($d['bahan_asal_id'] ?? 0);
        $outletAsalId = (int)($d['outlet_asal_id'] ?? 0);
        $outletTujuanId = (int)($d['outlet_tujuan_id'] ?? 0);
        $jumlah = (int)($d['jumlah'] ?? 0);
        $catatan = substr(trim(strip_tags($d['catatan'] ?? '')), 0, 200) ?: null;

        if (!$bahanAsalId || !$outletAsalId || !$outletTujuanId || $jumlah <= 0) {
            echo json_encode(['error' => 'Data transfer tidak lengkap']); exit;
        }
        if ($outletAsalId === $outletTujuanId) {
            echo json_encode(['error' => 'Outlet asal & tujuan tidak boleh sama']); exit;
        }

        // Verify bahan asal milik tenant ini
        $bahanAsal = $db->prepare(
            "SELECT s.nama, s.satuan, s.kategori, s.stok_terkini, s.stok_minimum, s.harga_beli, s.supplier
             FROM hl_bahan_stok s
             WHERE s.id = ? AND s.tenant_id = ? AND s.outlet_id = ?"
        );
        $bahanAsal->execute([$bahanAsalId, $tid, $outletAsalId]);
        $bahanAsal = $bahanAsal->fetch(PDO::FETCH_ASSOC);
        if (!$bahanAsal) { echo json_encode(['error' => 'Bahan asal tidak ditemukan']); exit; }
        if ($bahanAsal['stok_terkini'] < $jumlah) {
            echo json_encode(['error' => "Stok di outlet asal cuma {$bahanAsal['stok_terkini']} {$bahanAsal['satuan']}, tidak cukup untuk transfer $jumlah"]);
            exit;
        }

        // Verify outlet tujuan milik tenant ini & masih operasional
        $outletTujuan = $db->prepare(
            "SELECT id, nama_outlet FROM outlets
             WHERE id = ? AND tenant_id = ? AND status IN ('active','trial','grace')
             LIMIT 1"
        );
        $outletTujuan->execute([$outletTujuanId, $tid]);
        $outletTujuan = $outletTujuan->fetch(PDO::FETCH_ASSOC);
        if (!$outletTujuan) { echo json_encode(['error' => 'Outlet tujuan tidak valid atau nonaktif']); exit; }

        try {
            $db->beginTransaction();

            // Cari atau create bahan di outlet tujuan (match by nama)
            $bahanTujuan = $db->prepare(
                "SELECT id FROM hl_bahan WHERE tenant_id = ? AND outlet_id = ? AND nama = ? LIMIT 1"
            );
            $bahanTujuan->execute([$tid, $outletTujuanId, $bahanAsal['nama']]);
            $bahanTujuanId = (int)$bahanTujuan->fetchColumn();

            if (!$bahanTujuanId) {
                // Buat baru di outlet tujuan (stok_awal=0)
                $db->prepare(
                    "INSERT INTO hl_bahan (tenant_id, outlet_id, nama, kategori, satuan, stok_awal, stok_minimum, harga_beli, supplier, is_active)
                     VALUES (?,?,?,?,?,0,?,?,?,1)"
                )->execute([
                    $tid, $outletTujuanId, $bahanAsal['nama'], $bahanAsal['kategori'], $bahanAsal['satuan'],
                    $bahanAsal['stok_minimum'], $bahanAsal['harga_beli'], $bahanAsal['supplier']
                ]);
                $bahanTujuanId = (int)$db->lastInsertId();
            }

            // Stok sebelum/sesudah di kedua sisi
            $stokAsalSesudah = (int)$bahanAsal['stok_terkini'] - $jumlah;

            // Hitung stok tujuan sekarang
            $stokTujuanRow = $db->prepare(
                "SELECT stok_terkini FROM hl_bahan_stok WHERE id = ? AND tenant_id = ? AND outlet_id = ?"
            );
            $stokTujuanRow->execute([$bahanTujuanId, $tid, $outletTujuanId]);
            $stokTujuanSebelum = (int)$stokTujuanRow->fetchColumn();
            $stokTujuanSesudah = $stokTujuanSebelum + $jumlah;

            // INSERT mutasi 1: tipe='transfer' di outlet asal
            $db->prepare(
                "INSERT INTO hl_bahan_mutasi (tenant_id, outlet_id, bahan_id, tipe, jumlah, stok_sebelum, stok_sesudah, catatan, outlet_tujuan_id, input_by)
                 VALUES (?,?,?,'transfer',?,?,?,?,?,?)"
            )->execute([
                $tid, $outletAsalId, $bahanAsalId, $jumlah,
                $bahanAsal['stok_terkini'], $stokAsalSesudah,
                $catatan, $outletTujuanId, $uid
            ]);
            $pairId1 = (int)$db->lastInsertId();

            // INSERT mutasi 2: tipe='masuk' di outlet tujuan (link via transfer_pair_id)
            $db->prepare(
                "INSERT INTO hl_bahan_mutasi (tenant_id, outlet_id, bahan_id, tipe, jumlah, stok_sebelum, stok_sesudah, catatan, transfer_pair_id, input_by)
                 VALUES (?,?,?,'masuk',?,?,?,?,?,?)"
            )->execute([
                $tid, $outletTujuanId, $bahanTujuanId, $jumlah,
                $stokTujuanSebelum, $stokTujuanSesudah,
                ($catatan ? "Transfer dari outlet asal — $catatan" : "Transfer masuk dari outlet asal"),
                $pairId1, $uid
            ]);
            $pairId2 = (int)$db->lastInsertId();

            // Update pair_id di mutasi 1 untuk bi-directional link
            $db->prepare("UPDATE hl_bahan_mutasi SET transfer_pair_id = ? WHERE id = ?")
               ->execute([$pairId2, $pairId1]);

            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => "Transfer berhasil: {$jumlah} {$bahanAsal['satuan']} {$bahanAsal['nama']} → {$outletTujuan['nama_outlet']}",
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['error' => 'Transfer gagal: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

require __DIR__ . '/_layout_open.php';
?>

<style>
.inv-hq-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.inv-hq-card{background:#fff;border-radius:14px;padding:14px 16px;border:1px solid #E5E7EB;position:relative;overflow:hidden}
.inv-hq-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.inv-hq-card.outlet::before{background:linear-gradient(90deg,#3B82F6,#60A5FA)}
.inv-hq-card.kritis::before{background:linear-gradient(90deg,#EF4444,#F87171)}
.inv-hq-card.bahan::before{background:linear-gradient(90deg,#8B5CF6,#A78BFA)}
.inv-hq-card.nilai::before{background:linear-gradient(90deg,#10B981,#34D399)}
.inv-hq-num{font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:'DM Mono',monospace;margin-bottom:4px}
.inv-hq-label{font-size:11px;color:#6B7280;font-weight:600;text-transform:uppercase;letter-spacing:.3px}

.hq-tabs{display:flex;gap:4px;margin-bottom:18px;border-bottom:2px solid #E5E7EB}
.hq-tab{padding:10px 18px;cursor:pointer;font-weight:600;font-size:14px;color:#6B7280;background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s}
.hq-tab:hover{color:#0F1C3A}
.hq-tab.active{color:#0F766E;border-bottom-color:#14B8A6}

.pivot-table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;font-size:13px}
.pivot-table th,.pivot-table td{padding:10px 12px;border-bottom:1px solid #F3F4F6;text-align:left}
.pivot-table th{background:#F9FAFB;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#6B7280;font-weight:700}
.pivot-table td.num{text-align:right;font-family:'DM Mono',monospace;font-weight:700}
.pivot-stok-aman {color:#065F46}
.pivot-stok-minim{color:#D97706;font-weight:800}
.pivot-stok-habis{color:#EF4444;font-weight:800}
.pivot-stok-none {color:#D1D5DB}

.status-pill-mini{display:inline-block;width:10px;height:10px;border-radius:50%;vertical-align:middle;margin-left:4px}
.status-pill-mini.aman {background:#10B981}
.status-pill-mini.minim{background:#F59E0B}
.status-pill-mini.habis{background:#EF4444}

.transfer-btn{padding:4px 10px;background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer}
.transfer-btn:hover{background:#DBEAFE}

/* Mobile polish (≤640px) */
@media (max-width:640px){
  .inv-hq-summary{grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px}
  .inv-hq-card{padding:12px}
  .inv-hq-num{font-size:1.2rem}
  .inv-hq-label{font-size:10px;letter-spacing:.2px}
  .hq-page-header h1{font-size:20px}
  .hq-page-header p{font-size:13px}
  .hq-page-header .hl-btn{width:100%}
  .hq-tabs{overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch;gap:0}
  .hq-tab{padding:10px 14px;font-size:13px;white-space:nowrap}
  .pivot-table th,.pivot-table td{padding:8px 10px}
}

/* Table polish — scroll rapi + kolom pertama sticky + zebra */
.hq-tab-content > div[style*="overflow"]{border:1px solid #E5E7EB;border-radius:10px}
.pivot-table{min-width:100%}
.pivot-table th{white-space:nowrap;vertical-align:bottom}
.pivot-table td.num,.pivot-table th.num{white-space:nowrap}
.pivot-table tbody tr:nth-child(even){background:#FAFAFA}
.pivot-table th:first-child,.pivot-table td:first-child{position:sticky;left:0;background:#fff;box-shadow:1px 0 0 #F3F4F6}
.pivot-table thead th:first-child{background:#F9FAFB;z-index:2}
.pivot-table tbody tr:nth-child(even) td:first-child{background:#FAFAFA}
@media (max-width:640px){
  .pivot-table td:first-child{max-width:130px}
}
</style>

<div class="hq-page-wrap">
  <div class="hq-page-header" style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:18px;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="margin:0 0 4px;font-size:24px;font-weight:800;color:#0F1C3A">📦 Inventori Lintas Outlet</h1>
      <p style="margin:0;color:#6B7280;font-size:14px">Konsolidasi stok bahan baku semua outlet. Transfer stok antar outlet dengan satu klik.</p>
    </div>
    <?php if ($hqIsOwner): ?>
    <button class="hl-btn hl-btn-primary" onclick="openTransferModal()">↔️ Transfer Stok</button>
    <?php endif; ?>
  </div>

  <div class="inv-hq-summary">
    <div class="inv-hq-card outlet"><div class="inv-hq-num" id="sumOutletCount">-</div><div class="inv-hq-label">🏪 Outlet Aktif</div></div>
    <div class="inv-hq-card bahan"><div class="inv-hq-num" id="sumBahanCount">-</div><div class="inv-hq-label">📦 Total Item Bahan</div></div>
    <div class="inv-hq-card kritis"><div class="inv-hq-num" id="sumKritisCount" style="color:#EF4444">-</div><div class="inv-hq-label">⚠️ Stok Kritis (semua outlet)</div></div>
    <div class="inv-hq-card nilai"><div class="inv-hq-num" id="sumNilaiTotal" style="color:#10B981">-</div><div class="inv-hq-label">💎 Nilai Inventori Total</div></div>
  </div>

  <!-- Tabs -->
  <div class="hq-tabs">
    <button class="hq-tab active" onclick="switchHqTab('pivot',this)">📊 Pivot Stok per Outlet</button>
    <button class="hq-tab" onclick="switchHqTab('alert',this)">⚠️ Alert Kritis Lintas Outlet</button>
  </div>

  <!-- TAB: PIVOT -->
  <div id="hq-tab-pivot" class="hq-tab-content">
    <div style="overflow-x:auto">
      <table class="pivot-table" id="pivotTable">
        <thead><tr><th>Loading...</th></tr></thead>
        <tbody><tr><td>⏳ Memuat data...</td></tr></tbody>
      </table>
    </div>
    <p style="margin-top:12px;font-size:12px;color:#6B7280">
      🟢 = Stok aman · 🟡 = Minim · 🔴 = Habis · — = Belum terdaftar di outlet itu
    </p>
  </div>

  <!-- TAB: ALERT -->
  <div id="hq-tab-alert" class="hq-tab-content" style="display:none">
    <div style="overflow-x:auto">
      <table class="pivot-table">
        <thead>
          <tr><th>Outlet</th><th>Bahan</th><th>Kategori</th><th class="num">Stok</th><th class="num">Min</th><th>Status</th></tr>
        </thead>
        <tbody id="hqAlertBody">
          <tr><td colspan="6" style="text-align:center;padding:40px;color:#6B7280">⏳ Memuat data...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ═════ Modal Transfer ═════ -->
<div class="hl-modal-overlay" id="modalTransfer">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title">↔️ Transfer Stok Antar Outlet</span>
      <button class="hl-modal-close" onclick="closeTransferModal()">×</button>
    </div>
    <div class="hl-modal-body">
      <div class="hl-form-group">
        <label class="hl-label">Outlet Asal <span class="req">*</span></label>
        <select id="tf_outlet_asal" class="hl-input" onchange="loadBahanAsal()">
          <option value="">— Pilih outlet asal —</option>
        </select>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Bahan yang Mau Ditransfer <span class="req">*</span></label>
        <select id="tf_bahan" class="hl-input">
          <option value="">— Pilih outlet asal dulu —</option>
        </select>
        <small id="tf_stok_info" style="color:#6B7280;font-size:12px"></small>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Outlet Tujuan <span class="req">*</span></label>
        <select id="tf_outlet_tujuan" class="hl-input">
          <option value="">— Pilih outlet tujuan —</option>
        </select>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Jumlah Transfer <span class="req">*</span></label>
        <input type="number" id="tf_jumlah" class="hl-input" min="1" placeholder="0"/>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Catatan (opsional)</label>
        <input type="text" id="tf_catatan" class="hl-input" placeholder="Misal: outlet asal overstok" maxlength="200"/>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeTransferModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="doTransfer()">✅ Transfer</button>
    </div>
  </div>
</div>

<script>
let _consolidated = null;

async function loadConsolidated() {
  const r = await fetch('/hq/inventori?action=consolidated', { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
  const j = await r.json();
  _consolidated = j;

  // Summary
  document.getElementById('sumOutletCount').textContent = j.outlets.length;
  document.getElementById('sumBahanCount').textContent  = j.rows.length;
  let kritisTotal = 0, nilaiTotal = 0;
  j.rows.forEach(b => {
    for (const oid in b.per_outlet) {
      const o = b.per_outlet[oid];
      if (o.status !== 'aman') kritisTotal++;
      nilaiTotal += (o.stok * o.harga_beli);
    }
  });
  document.getElementById('sumKritisCount').textContent = kritisTotal;
  document.getElementById('sumNilaiTotal').textContent  = 'Rp ' + fmtNum(nilaiTotal);

  // Build pivot table
  const tbl = document.getElementById('pivotTable');
  let html = '<thead><tr><th>Bahan</th><th>Kategori</th>';
  j.outlets.forEach(o => { html += `<th class="num">${esc(o.nama_outlet)}</th>`; });
  html += '<th class="num">Total</th></tr></thead><tbody>';

  if (!j.rows.length) {
    html += `<tr><td colspan="${j.outlets.length + 3}" style="text-align:center;padding:40px;color:#6B7280">Belum ada bahan terdaftar. Tambah lewat halaman /inventori di tiap outlet.</td></tr>`;
  } else {
    j.rows.forEach(b => {
      html += `<tr><td><strong>${esc(b.nama)}</strong></td><td><span class="kategori-badge">${katLabel(b.kategori)}</span></td>`;
      j.outlets.forEach(o => {
        const cell = b.per_outlet[o.id];
        if (!cell) { html += `<td class="num pivot-stok-none">—</td>`; return; }
        const cls = 'pivot-stok-' + cell.status;
        const dot = cell.status !== 'aman' ? `<span class="status-pill-mini ${cell.status}"></span>` : '';
        html += `<td class="num ${cls}">${cell.stok} ${esc(b.satuan)}${dot}</td>`;
      });
      html += `<td class="num"><strong>${b.total} ${esc(b.satuan)}</strong></td></tr>`;
    });
  }
  html += '</tbody>';
  tbl.innerHTML = html;
}

async function loadAlertHq() {
  const r = await fetch('/hq/inventori?action=alerts', { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
  const j = await r.json();
  const tbody = document.getElementById('hqAlertBody');
  if (!j.data.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#10B981">✅ Semua stok aman di semua outlet!</td></tr>';
    return;
  }
  tbody.innerHTML = j.data.map(b => {
    const cls = 'pivot-stok-' + b.status_stok;
    const badge = b.status_stok === 'habis'
      ? '<span class="hl-badge hl-badge-red" style="font-size:11px">🔴 Habis</span>'
      : '<span class="hl-badge" style="font-size:11px;background:#FEF3C7;color:#92400E">⚠️ Minim</span>';
    return `<tr>
      <td>${esc(b.nama_outlet)}</td>
      <td><strong>${esc(b.nama)}</strong></td>
      <td><span class="kategori-badge">${katLabel(b.kategori)}</span></td>
      <td class="num ${cls}">${b.stok_terkini} ${esc(b.satuan)}</td>
      <td class="num">${b.stok_minimum}</td>
      <td>${badge}</td>
    </tr>`;
  }).join('');
}

function switchHqTab(tab, el) {
  document.querySelectorAll('.hq-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.hq-tab-content').forEach(t => t.style.display = 'none');
  document.getElementById('hq-tab-' + tab).style.display = 'block';
  if (tab === 'alert') loadAlertHq();
}

// ── TRANSFER MODAL ─────────────────────────────────
function openTransferModal() {
  if (!_consolidated) { alert('Tunggu data dimuat dulu'); return; }
  const selAsal   = document.getElementById('tf_outlet_asal');
  const selTujuan = document.getElementById('tf_outlet_tujuan');
  const opts = '<option value="">— Pilih outlet —</option>' + _consolidated.outlets.map(o => `<option value="${o.id}">${esc(o.nama_outlet)}</option>`).join('');
  selAsal.innerHTML = opts;
  selTujuan.innerHTML = opts;
  document.getElementById('tf_bahan').innerHTML = '<option value="">— Pilih outlet asal dulu —</option>';
  document.getElementById('tf_jumlah').value = '';
  document.getElementById('tf_catatan').value = '';
  document.getElementById('tf_stok_info').textContent = '';
  document.getElementById('modalTransfer').classList.add('open');
}
function closeTransferModal() { document.getElementById('modalTransfer').classList.remove('open'); }

function loadBahanAsal() {
  const oid = parseInt(document.getElementById('tf_outlet_asal').value);
  const selBahan = document.getElementById('tf_bahan');
  if (!oid) { selBahan.innerHTML = '<option value="">— Pilih outlet asal dulu —</option>'; return; }
  // Cari bahan yang punya stok > 0 di outlet ini
  const opts = ['<option value="">— Pilih bahan —</option>'];
  _consolidated.rows.forEach(b => {
    const cell = b.per_outlet[oid];
    if (cell && cell.stok > 0) {
      opts.push(`<option value="${cell.bahan_id}" data-stok="${cell.stok}" data-satuan="${esc(b.satuan)}">${esc(b.nama)} (${cell.stok} ${esc(b.satuan)})</option>`);
    }
  });
  selBahan.innerHTML = opts.join('');
  selBahan.onchange = () => {
    const sel = selBahan.options[selBahan.selectedIndex];
    document.getElementById('tf_stok_info').textContent = sel.value
      ? `Stok available di outlet asal: ${sel.dataset.stok} ${sel.dataset.satuan}`
      : '';
  };
}

async function doTransfer() {
  const bahanId  = parseInt(document.getElementById('tf_bahan').value);
  const oidAsal  = parseInt(document.getElementById('tf_outlet_asal').value);
  const oidTuj   = parseInt(document.getElementById('tf_outlet_tujuan').value);
  const jumlah   = parseInt(document.getElementById('tf_jumlah').value);
  const catatan  = document.getElementById('tf_catatan').value;

  if (!bahanId || !oidAsal || !oidTuj || jumlah <= 0) { alert('Lengkapi semua field'); return; }
  if (oidAsal === oidTuj) { alert('Outlet asal & tujuan tidak boleh sama'); return; }

  const r = await fetch('/hq/inventori?action=transfer', {
    method: 'POST',
    headers: { 'Content-Type':'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
    body: JSON.stringify({
      bahan_asal_id: bahanId,
      outlet_asal_id: oidAsal,
      outlet_tujuan_id: oidTuj,
      jumlah: jumlah,
      catatan: catatan,
    }),
  });
  const j = await r.json();
  if (j.error) { alert('❌ ' + j.error); return; }
  alert('✅ ' + j.message);
  closeTransferModal();
  loadConsolidated();
}

// ── HELPERS ────────────────────────────────────────
// esc/fmtNum sudah global di components.php
const katLabel = window.katLabelInventori;

// ── INIT ───────────────────────────────────────────
loadConsolidated();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
