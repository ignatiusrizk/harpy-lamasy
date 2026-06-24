<?php
// hq/investor.php — Investor & Bagi Hasil
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/FinancialCalculator.php';
require_once ROOT . '/core/BagiHasilCalculator.php';

requirePermission('investor.manage');

$db   = Database::get();
$tid  = (int) TenantResolver::id();
$user = currentUser();
$uid  = (int) ($user['id'] ?? 0);
$csrf = getCsrfToken();

// ── AJAX Handler ────────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    verifyCsrf();
    try {
        // ── list_investor ──────────────────────────────────────
        if ($action === 'list_investor') {
            $st = $db->prepare("SELECT i.*, o.nama_outlet
                                FROM hl_investor i
                                LEFT JOIN outlets o ON o.id=i.outlet_id AND o.tenant_id=i.tenant_id
                                WHERE i.tenant_id=? AND i.is_active=1
                                ORDER BY i.scope, i.nama");
            $st->execute([$tid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            // total % per pool
            $pools = [];
            foreach ($rows as $r) {
                $key = $r['scope']==='outlet' ? ('outlet_'.$r['outlet_id']) : 'tenant';
                $pools[$key] = ($pools[$key] ?? 0) + (float)$r['persentase'];
            }
            echo json_encode(['ok'=>true, 'data'=>$rows, 'pools'=>$pools]);
            exit;
        }

        // ── save_investor ──────────────────────────────────────
        if ($action === 'save_investor') {
            $id      = (int)($_POST['id'] ?? 0);
            $nama    = substr(trim(strip_tags($_POST['nama'] ?? '')), 0, 100);
            $telepon = substr(preg_replace('/[^0-9+\-\s]/','', $_POST['telepon'] ?? ''), 0, 20);
            $scope   = in_array($_POST['scope'] ?? '', ['tenant','outlet'], true) ? $_POST['scope'] : 'tenant';
            $outletId= $scope==='outlet' ? (int)($_POST['outlet_id'] ?? 0) : null;
            $modal   = max(0, (int)($_POST['modal_disetor'] ?? 0));
            $persen  = max(0, min(100, (float)($_POST['persentase'] ?? 0)));
            $joined  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['joined_at'] ?? '') ? $_POST['joined_at'] : date('Y-m-d');
            $catatan = substr(trim(strip_tags($_POST['catatan'] ?? '')), 0, 500);
            if ($nama==='') throw new RuntimeException('Nama wajib diisi');
            if ($scope==='outlet' && !$outletId) throw new RuntimeException('Pilih outlet');

            // modal lama (untuk delta jurnal)
            $modalLama = 0;
            if ($id) {
                $g = $db->prepare("SELECT modal_disetor FROM hl_investor WHERE id=? AND tenant_id=?");
                $g->execute([$id, $tid]);
                $modalLama = (int)$g->fetchColumn();
            }

            $db->beginTransaction();
            try {
                if ($id) {
                    $db->prepare("UPDATE hl_investor SET nama=?, telepon=?, scope=?, outlet_id=?,
                                  modal_disetor=?, persentase=?, joined_at=?, catatan=?
                                  WHERE id=? AND tenant_id=?")
                       ->execute([$nama,$telepon,$scope,$outletId,$modal,$persen,$joined,$catatan,$id,$tid]);
                } else {
                    $db->prepare("INSERT INTO hl_investor
                                  (tenant_id, nama, telepon, scope, outlet_id, modal_disetor, persentase, joined_at, catatan)
                                  VALUES (?,?,?,?,?,?,?,?,?)")
                       ->execute([$tid,$nama,$telepon,$scope,$outletId,$modal,$persen,$joined,$catatan]);
                    $id = (int)$db->lastInsertId();
                }
                // jurnal modal delta (kredit modal_disetor kalau delta+, debit kalau delta-)
                $delta = $modal - $modalLama;
                if ($delta !== 0) {
                    $coaModal = $db->prepare("SELECT id FROM hl_coa WHERE tenant_id=? AND kode='3-1001' LIMIT 1");
                    $coaModal->execute([$tid]);
                    $coaId = (int)$coaModal->fetchColumn();
                    if ($coaId) {
                        $arah = $delta > 0 ? 'kredit' : 'debit';
                        $jOutletId = $outletId ?: (int)TenantResolver::outletId();
                        $db->prepare("INSERT INTO hl_jurnal_manual
                            (tenant_id, outlet_id, coa_id, tanggal, periode, keterangan, tipe, jumlah, arah, input_by)
                            VALUES (?,?,?,?,?,?, 'modal_disetor', ?, ?, ?)")
                           ->execute([$tid, $jOutletId, $coaId,
                                      $joined, substr($joined,0,7),
                                      "Setoran modal investor: $nama", abs($delta), $arah, $uid]);
                    }
                }
                $db->commit();
                echo json_encode(['ok'=>true, 'id'=>$id]);
                exit;
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // ── delete_investor (soft) ─────────────────────────────
        if ($action === 'delete_investor') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE hl_investor SET is_active=0 WHERE id=? AND tenant_id=?")->execute([$id,$tid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        echo json_encode(['ok'=>false, 'error'=>'Action tidak dikenal']);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}

// ── GET: Load data untuk render ─────────────────────────────────
$allOutlets = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status!='closed' ORDER BY is_main DESC, nama_outlet");
$allOutlets->execute([$tid]);
$outlets = $allOutlets->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'hq-investor';
$pageTitle  = 'Investor & Bagi Hasil';
require __DIR__ . '/_layout_open.php';
?>
<style>
  h1{font-size:1.3rem;font-weight:800;color:#0F1C3A;margin-bottom:18px}

  /* Tab shell */
  .inv-tabs{display:flex;gap:4px;margin-bottom:22px;border-bottom:2px solid #E5E7EB}
  .inv-tab{padding:9px 18px;font-size:13px;font-weight:600;color:#6B7280;border:none;background:none;
           cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;border-radius:6px 6px 0 0;font-family:inherit}
  .inv-tab.active{color:#0F1C3A;border-bottom-color:#35E8D5;background:#F0FDFB}
  .inv-panel{display:none}.inv-panel.active{display:block}

  /* Card */
  .inv-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.06);margin-bottom:18px}
  .inv-card h3{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px}

  /* Table */
  table.inv-tbl{width:100%;border-collapse:collapse;font-size:13px}
  table.inv-tbl th{background:#F9FAFB;padding:9px 12px;text-align:left;font-size:11px;
                   color:#6B7280;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
  table.inv-tbl th.num,table.inv-tbl td.num{text-align:right}
  table.inv-tbl td{padding:11px 12px;border-bottom:1px solid #F3F4F6;color:#1F2937}
  table.inv-tbl tr:last-child td{border-bottom:none}
  table.inv-tbl tr:hover td{background:#F9FAFB}

  /* Badges */
  .badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
  .badge-tenant{background:#EDE9FE;color:#5B21B6}
  .badge-outlet{background:#DBEAFE;color:#1D4ED8}

  /* Form */
  .inv-form{display:grid;gap:14px}
  .inv-form .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .inv-form label{font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block}
  .inv-form input,.inv-form select,.inv-form textarea{
    width:100%;padding:9px 11px;border:1.5px solid #E5E7EB;border-radius:8px;
    font-size:13px;font-family:inherit;background:#fff;box-sizing:border-box}
  .inv-form input:focus,.inv-form select:focus,.inv-form textarea:focus{outline:none;border-color:#35E8D5}
  .inv-form textarea{resize:vertical;min-height:60px}
  .inv-form input[type=radio]{width:auto;margin-right:6px}

  /* Buttons */
  .btn{padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;
       font-family:inherit;transition:.15s}
  .btn-primary{background:#0F1C3A;color:#fff}.btn-primary:hover{opacity:.85}
  .btn-teal{background:#35E8D5;color:#0F1C3A}.btn-teal:hover{background:#2dd4c4}
  .btn-red{background:#EF4444;color:#fff}.btn-red:hover{background:#DC2626}
  .btn-outline{background:#fff;border:1.5px solid #E5E7EB;color:#374151}
  .btn-outline:hover{background:#F9FAFB}
  .btn-sm{padding:5px 12px;font-size:12px}

  /* Bar */
  .bar-between{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
  .bar-between h3{margin:0}

  /* Pool warning */
  .pool-bar{height:6px;background:#E5E7EB;border-radius:3px;overflow:hidden;margin-top:6px}
  .pool-fill{height:100%;background:#35E8D5;border-radius:3px;transition:width .4s}
  .pool-fill.warn{background:#F59E0B}
  .pool-fill.danger{background:#EF4444}
  #poolWarning{font-size:12px;color:#92400E;background:#FFFBEB;border:1px solid #FCD34D;
               border-radius:8px;padding:10px 14px;margin-bottom:14px;display:none}

  /* Toast */
  #invToast{position:fixed;bottom:24px;right:24px;background:#0F1C3A;color:#fff;padding:12px 20px;
            border-radius:10px;font-size:13px;display:none;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.2)}

  /* Modal */
  .modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;align-items:center;justify-content:center}
  .modal-bg.open{display:flex}
  .modal{background:#fff;border-radius:16px;padding:28px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
  .modal h4{font-size:16px;font-weight:800;color:#0F1C3A;margin-bottom:20px}
  .modal-footer{display:flex;gap:10px;margin-top:20px;justify-content:flex-end}
</style>

<h1>👥 Investor & Bagi Hasil</h1>

<div class="inv-tabs">
  <button class="inv-tab active" onclick="invTab('daftar', this)">👥 Daftar Investor</button>
  <button class="inv-tab" onclick="invTab('baghasil', this)">💰 Bagi Hasil</button>
</div>

<!-- ══════════ TAB: DAFTAR INVESTOR ══════════ -->
<div class="inv-panel active" id="tab-daftar">
  <div id="poolWarning"></div>
  <div class="inv-card">
    <div class="bar-between">
      <h3>👥 Daftar Investor</h3>
      <button class="btn btn-teal btn-sm" onclick="openInvestorModal()">+ Tambah Investor</button>
    </div>
    <table class="inv-tbl">
      <thead><tr>
        <th>Nama Investor</th>
        <th>Telepon</th>
        <th>Scope</th>
        <th>Outlet</th>
        <th class="num">Modal Disetor</th>
        <th class="num">% Bagi Hasil</th>
        <th>Bergabung</th>
        <th>Aksi</th>
      </tr></thead>
      <tbody id="invBody">
        <tr><td colspan="8" style="text-align:center;padding:20px;color:#9CA3AF">Memuat...</td></tr>
      </tbody>
    </table>
    <div id="invSummary" style="margin-top:12px;font-size:13px;color:#6B7280"></div>
  </div>
</div>

<!-- ══════════ TAB: BAGI HASIL (placeholder Task 4) ══════════ -->
<div class="inv-panel" id="tab-baghasil">
  <div id="tabBagiHasil">
    <div class="inv-card" style="text-align:center;padding:40px;color:#9CA3AF">
      <div style="font-size:2rem;margin-bottom:12px">💰</div>
      <div style="font-weight:600;color:#6B7280">Tab Bagi Hasil akan diisi di Task 4</div>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Tambah / Edit Investor ══════════ -->
<div class="modal-bg" id="modalInvestor">
  <div class="modal">
    <h4 id="modalInvestorTitle">Tambah Investor</h4>
    <input type="hidden" id="invId" value="0">
    <div class="inv-form">
      <div>
        <label>Nama Investor</label>
        <input type="text" id="invNama" placeholder="Mis: Budi Santoso">
      </div>
      <div>
        <label>No. Telepon</label>
        <input type="text" id="invTelepon" placeholder="08xx-xxxx-xxxx">
      </div>
      <div>
        <label>Scope Investasi</label>
        <div style="display:flex;gap:18px;margin-top:6px">
          <label style="font-weight:400;font-size:13px;margin:0">
            <input type="radio" name="invScope" id="invScopeTenant" value="tenant" checked onchange="toggleOutletField()">
            Seluruh Tenant
          </label>
          <label style="font-weight:400;font-size:13px;margin:0">
            <input type="radio" name="invScope" id="invScopeOutlet" value="outlet" onchange="toggleOutletField()">
            Per Outlet
          </label>
        </div>
      </div>
      <div id="invOutletField" style="display:none">
        <label>Outlet</label>
        <select id="invOutletId">
          <?php foreach ($outlets as $o): ?>
          <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['nama_outlet']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="row2">
        <div>
          <label>Modal Disetor (Rp)</label>
          <input type="text" id="invModal" placeholder="0" oninput="fmtInput(this)">
        </div>
        <div>
          <label>% Bagi Hasil</label>
          <input type="number" id="invPersen" placeholder="0" step="0.01" min="0" max="100">
        </div>
      </div>
      <div>
        <label>Tanggal Bergabung</label>
        <input type="date" id="invJoined" value="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label>Catatan (opsional)</label>
        <textarea id="invCatatan" placeholder="Mis: Investor awal, sesuai perjanjian notaris..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalInvestor')">Batal</button>
      <button class="btn btn-teal" onclick="saveInvestor()">Simpan</button>
    </div>
  </div>
</div>

<div id="invToast"></div>

<?php require __DIR__ . '/_layout_close.php'; ?>
<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';

// ── Helpers ──────────────────────────────────────────────────────
function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function rp(n) { return 'Rp ' + Number(n||0).toLocaleString('id-ID'); }
function fmtInput(el) {
    const raw = el.value.replace(/\D/g, '');
    el.value = raw ? Number(raw).toLocaleString('id-ID') : '';
}
function unFmt(val) { return parseInt((val||'0').replace(/\D/g,'')) || 0; }

function toast(msg, ok = true) {
    const t = document.getElementById('invToast');
    t.textContent = (ok ? '✓ ' : '✗ ') + msg;
    t.style.display = 'block';
    t.style.borderLeft = '3px solid ' + (ok ? '#35E8D5' : '#EF4444');
    clearTimeout(t._to);
    t._to = setTimeout(() => t.style.display = 'none', 3500);
}

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── Tab switch ──────────────────────────────────────────────────
function invTab(name, btn) {
    document.querySelectorAll('.inv-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.inv-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
    if (name === 'daftar') loadInvestor();
}

// ── API helper ──────────────────────────────────────────────────
async function api(action, data = {}, method = 'GET') {
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    const url = 'investor.php?action=' + action;
    const r = await fetch(url, method === 'POST'
        ? { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        : { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF } }
    );
    return r.json();
}

// ── Outlet field toggle ─────────────────────────────────────────
function toggleOutletField() {
    const isOutlet = document.getElementById('invScopeOutlet').checked;
    document.getElementById('invOutletField').style.display = isOutlet ? '' : 'none';
}

// ── Modal open ──────────────────────────────────────────────────
function openInvestorModal(row) {
    if (row) {
        document.getElementById('modalInvestorTitle').textContent = 'Edit Investor';
        document.getElementById('invId').value = row.id;
        document.getElementById('invNama').value = row.nama || '';
        document.getElementById('invTelepon').value = row.telepon || '';
        // scope radio
        const isOutlet = row.scope === 'outlet';
        document.getElementById('invScopeTenant').checked = !isOutlet;
        document.getElementById('invScopeOutlet').checked = isOutlet;
        toggleOutletField();
        if (isOutlet && row.outlet_id) {
            document.getElementById('invOutletId').value = row.outlet_id;
        }
        document.getElementById('invModal').value = Number(row.modal_disetor||0).toLocaleString('id-ID');
        document.getElementById('invPersen').value = row.persentase || 0;
        document.getElementById('invJoined').value = row.joined_at || '';
        document.getElementById('invCatatan').value = row.catatan || '';
    } else {
        document.getElementById('modalInvestorTitle').textContent = 'Tambah Investor';
        document.getElementById('invId').value = '0';
        document.getElementById('invNama').value = '';
        document.getElementById('invTelepon').value = '';
        document.getElementById('invScopeTenant').checked = true;
        document.getElementById('invScopeOutlet').checked = false;
        toggleOutletField();
        document.getElementById('invModal').value = '';
        document.getElementById('invPersen').value = '';
        document.getElementById('invJoined').value = new Date().toISOString().slice(0, 10);
        document.getElementById('invCatatan').value = '';
    }
    openModal('modalInvestor');
}

// ── Load investors ───────────────────────────────────────────────
async function loadInvestor() {
    document.getElementById('invBody').innerHTML =
        '<tr><td colspan="8" style="text-align:center;padding:20px;color:#9CA3AF">Memuat...</td></tr>';
    const d = await api('list_investor');
    if (!d.ok) {
        document.getElementById('invBody').innerHTML =
            `<tr><td colspan="8" style="color:#EF4444;padding:12px">${esc(d.error||'Gagal memuat data')}</td></tr>`;
        return;
    }
    const rows = d.data;

    // Pool warning check
    const warnEl = document.getElementById('poolWarning');
    const overPools = Object.entries(d.pools || {}).filter(([,v]) => v > 100);
    if (overPools.length) {
        warnEl.style.display = '';
        warnEl.innerHTML = '⚠️ Peringatan: total % bagi hasil melebihi 100% pada pool: ' +
            overPools.map(([k, v]) => `<strong>${k}</strong> (${v.toFixed(2)}%)`).join(', ');
    } else {
        warnEl.style.display = 'none';
    }

    if (!rows.length) {
        document.getElementById('invBody').innerHTML =
            '<tr><td colspan="8" style="text-align:center;padding:20px;color:#9CA3AF">Belum ada investor</td></tr>';
        document.getElementById('invSummary').textContent = '';
        return;
    }

    let totalModal = 0;
    const tbody = rows.map(r => {
        totalModal += parseInt(r.modal_disetor || 0);
        const scopeBadge = r.scope === 'outlet'
            ? `<span class="badge badge-outlet">Outlet</span>`
            : `<span class="badge badge-tenant">Tenant</span>`;
        const outletNama = r.scope === 'outlet' ? esc(r.nama_outlet || '-') : '-';
        return `<tr>
          <td><strong>${esc(r.nama)}</strong></td>
          <td style="font-size:12px;color:#6B7280">${esc(r.telepon||'-')}</td>
          <td>${scopeBadge}</td>
          <td style="font-size:12px">${outletNama}</td>
          <td class="num">${rp(r.modal_disetor)}</td>
          <td class="num"><strong>${parseFloat(r.persentase||0).toFixed(2)}%</strong></td>
          <td style="font-size:12px">${r.joined_at||'-'}</td>
          <td style="white-space:nowrap">
            <button class="btn btn-outline btn-sm" onclick='openInvestorModal(${JSON.stringify(r)})'>Edit</button>
            <button class="btn btn-outline btn-sm" style="color:#EF4444;margin-left:4px" onclick="deleteInvestor(${r.id},'${esc(r.nama)}')">Hapus</button>
          </td>
        </tr>`;
    }).join('');
    document.getElementById('invBody').innerHTML = tbody;
    document.getElementById('invSummary').textContent =
        `Total ${rows.length} investor aktif · Total modal: ${rp(totalModal)}`;
}

// ── Save investor ────────────────────────────────────────────────
async function saveInvestor() {
    const isOutlet = document.getElementById('invScopeOutlet').checked;
    const d = await api('save_investor', {
        id:           document.getElementById('invId').value,
        nama:         document.getElementById('invNama').value,
        telepon:      document.getElementById('invTelepon').value,
        scope:        isOutlet ? 'outlet' : 'tenant',
        outlet_id:    isOutlet ? document.getElementById('invOutletId').value : '',
        modal_disetor: unFmt(document.getElementById('invModal').value),
        persentase:   document.getElementById('invPersen').value,
        joined_at:    document.getElementById('invJoined').value,
        catatan:      document.getElementById('invCatatan').value,
    }, 'POST');
    if (d.ok) {
        closeModal('modalInvestor');
        toast('Investor berhasil disimpan');
        loadInvestor();
    } else {
        toast(d.error || 'Gagal menyimpan', false);
    }
}

// ── Delete investor (soft) ───────────────────────────────────────
async function deleteInvestor(id, nama) {
    if (!confirm(`Hapus investor "${nama}"?\nData tidak akan benar-benar dihapus (soft delete).`)) return;
    const d = await api('delete_investor', { id }, 'POST');
    if (d.ok) { toast('Investor dihapus'); loadInvestor(); }
    else toast(d.error || 'Gagal', false);
}

// ── Init ─────────────────────────────────────────────────────────
loadInvestor();
</script>
