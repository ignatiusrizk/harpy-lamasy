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
$view = $_GET['view'] ?? '';
$reportDate = $_GET['date'] ?? date('Y-m-d');

if ($view === 'report') {
    // ponytail: simple aggregate, no extra abstraction
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
        if ($id <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
        $st = $db->prepare("UPDATE hl_antar_jemput SET status='cancel', updated_at=NOW() WHERE id=? AND tenant_id=? AND outlet_id=? AND status NOT IN ('done','cancel')");
        $st->execute([$id, $tid, $oid]);
        if (!$st->rowCount()) { echo json_encode(['error'=>'Tidak ditemukan atau sudah selesai/dibatalkan']); exit; }
        logAudit('antar_cancel', 'antar_jemput', "id=$id");
        echo json_encode(['ok'=>true]);
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
    <div class="hl-card" style="padding:14px;text-align:center"><div style="color:var(--gray);font-size:11px;text-transform:uppercase">Avg Waktu</div><div style="font-size:24px;font-weight:800"><?= (int)$stats['avg_minutes'] > 0 ? (int)$stats['avg_minutes'].'m' : '—' ?></div></div>
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
      <div style="font-size:13.5px;color:var(--gray)"><?= (int)$k['done'] ?>/<?= (int)$k['total'] ?> selesai · avg <?= (int)$k['avg_min'] > 0 ? (int)$k['avg_min'].'m' : '—' ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php else: ?>
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
<?php endif; ?>
</div>

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
  const list = document.getElementById('ajList');
  if (!list) return; // view=report tidak punya #ajList, skip
  const r = await fetch('?action=list&tipe=' + currentTipe);
  const d = await r.json();
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

async function loadZonaForCreate() {
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

loadList();
setInterval(loadList, 30000);
</script>
