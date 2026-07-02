<?php
$activePage = 'antar-jemput';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';
require_once ROOT . '/core/PushSender.php';
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
              LIMIT 500",
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
            $k = TenantQuery::rawOne("SELECT id, user_id FROM hl_kurir WHERE id=? AND tenant_id=? AND outlet_id=? AND aktif=1", [$kurirId, $tid, $oid]);
            if (!$k) { throw new Exception('Kurir tidak valid'); }

            $antarRow = TenantQuery::rawOne("SELECT alamat, catatan FROM hl_antar_jemput WHERE id=? AND tenant_id=? AND outlet_id=?", [$id, $tid, $oid]);
            $alamat = $antarRow['alamat'] ?? $antarRow['catatan'] ?? '';

            $upd = $db->prepare("UPDATE hl_antar_jemput SET kurir_id=?, status='assigned', updated_at=NOW() WHERE id=? AND tenant_id=? AND outlet_id=?");
            $upd->execute([$kurirId, $id, $tid, $oid]);
            logAudit('antar_assign', 'antar_jemput', "id=$id kurir=$kurirId");
            $db->commit();
            $kurirUserId = (int)($k['user_id'] ?? 0);
            if ($kurirUserId > 0) {
                PushSender::send('antar_baru', (int)$tid, (int)$oid, [
                    'title' => 'Tugas antar-jemput baru',
                    'body'  => (string)$alamat,
                    'url'   => '/kurir',
                ], [$kurirUserId]);
            }
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
<style>
/* ── Slot Waktu: chip hari + dropdown jam kustom (bukan native OS) ── */
.slot-days{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.slot-chip{
  padding:8px 14px;border:1.5px solid rgba(27,45,90,.12);border-radius:100px;
  background:var(--white);font-family:var(--font);font-size:13px;font-weight:600;
  color:var(--navy);cursor:pointer;transition:all .15s;
}
.slot-chip:hover{border-color:var(--teal)}
.slot-chip.active{background:var(--teal);border-color:var(--teal);color:#0F1C3A}
.kat-dd{position:relative}
.kat-trigger{
  width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;
  padding:11px 14px;border:1.5px solid rgba(27,45,90,.12);border-radius:var(--r);
  background:var(--white);font-family:var(--font);font-size:14px;color:var(--navy);
  cursor:pointer;text-align:left;transition:border-color .15s;
}
.kat-trigger:hover{border-color:var(--teal)}
.kat-trigger.open{border-color:var(--teal-d);box-shadow:0 0 0 3px rgba(53,232,213,.18)}
.kat-trigger .kat-ph{color:#9CA3AF}
.kat-trigger::after{
  content:"";width:16px;height:16px;flex-shrink:0;transition:transform .2s;
  background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%231CC4B2' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat center;
}
.kat-trigger.open::after{transform:rotate(180deg)}
.kat-panel{
  position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:60;
  background:#fff;border:1px solid #E5E9F2;border-radius:14px;padding:6px;
  box-shadow:0 14px 38px rgba(15,28,58,.18);max-height:280px;overflow-y:auto;
  animation:slotIn .14s ease;
}
@keyframes slotIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.kat-group{font-size:10.5px;font-weight:800;color:#6B7280;text-transform:uppercase;letter-spacing:.06em;padding:9px 12px 5px}
.kat-opt{
  display:flex;align-items:center;gap:11px;width:100%;padding:11px 12px;border:0;
  background:none;border-radius:9px;cursor:pointer;font-family:var(--font);font-size:14px;
  color:var(--navy);text-align:left;
}
.kat-opt:hover{background:#F0FDFA}
.kat-opt.is-active{background:#EAFBF8;font-weight:700}
.kat-opt .kat-e{font-size:19px;line-height:1;flex-shrink:0;width:24px;text-align:center}
.kat-opt .kat-l{flex:1}
.kat-opt .kat-ck{color:var(--teal-d);font-weight:800;font-size:15px}
/* Kalender kustom (pengganti native date picker) */
.aj-cal{padding:12px;max-height:none}
.aj-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.aj-cal-head button{width:32px;height:32px;border:0;background:#F0FDFA;border-radius:8px;cursor:pointer;font-size:17px;color:var(--teal-d);font-weight:800;line-height:1}
.aj-cal-title{font-weight:800;color:var(--navy);font-size:14px}
.aj-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.aj-cal-dow{text-align:center;font-size:10px;font-weight:800;color:#9CA3AF;padding:4px 0}
.aj-cal-day{aspect-ratio:1;border:0;background:none;border-radius:8px;cursor:pointer;font-size:13px;color:var(--navy);font-family:var(--font)}
.aj-cal-day:hover:not(:disabled){background:#F0FDFA}
.aj-cal-day:disabled{color:#D1D5DB;cursor:default}
.aj-cal-day.empty{visibility:hidden}
.aj-cal-day.today{font-weight:800;color:var(--teal-d)}
.aj-cal-day.sel{background:var(--teal);color:#0F1C3A;font-weight:800}
</style>
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
      <input type="hidden" id="c_slot">
      <div class="slot-days" id="slotDays">
        <button type="button" class="slot-chip" data-day="0">Hari ini</button>
        <button type="button" class="slot-chip" data-day="1">Besok</button>
        <button type="button" class="slot-chip" data-day="2">Lusa</button>
        <button type="button" class="slot-chip" data-day="pick">📅 Tanggal…</button>
      </div>
      <div id="ajCalWrap" style="position:relative">
        <div class="kat-panel aj-cal" id="ajCalPanel" hidden></div>
      </div>
      <div class="kat-dd" id="slotDD" style="margin-top:8px">
        <button type="button" class="kat-trigger" id="slotTrigger" onclick="slotToggle(event)">
          <span id="slotTriggerLabel" class="kat-ph">Pilih jam jemput…</span>
        </button>
        <div class="kat-panel" id="slotPanel" hidden></div>
      </div>
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

// ── Slot Waktu: pemilih hari + jam kustom ──
const SLOTS = [
  {v:8,  ico:'🌅', nm:'Pagi',  rg:'08–11'},
  {v:11, ico:'☀️', nm:'Siang', rg:'11–14'},
  {v:14, ico:'🌤️', nm:'Sore',  rg:'14–17'},
  {v:17, ico:'🌙', nm:'Malam', rg:'17–20'},
];
let slotDay = null, slotHour = null;
function slotDateStr(off){ const d=new Date(); d.setDate(d.getDate()+off);
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
function slotRecompute(){
  const c = document.getElementById('c_slot');
  c.value = (slotDay && slotHour!=null) ? (slotDay+' '+String(slotHour).padStart(2,'0')+':00:00') : '';
}
function slotPickDay(chip){
  document.querySelectorAll('#slotDays .slot-chip').forEach(x=>x.classList.remove('active'));
  chip.classList.add('active');
  if (chip.dataset.day==='pick'){ ajCalOpen(); return; }   // buka kalender kustom, tanggal di-set saat pilih
  ajCalClose();
  document.querySelector('#slotDays .slot-chip[data-day="pick"]').textContent = '📅 Tanggal…'; // reset label chip
  slotDay = slotDateStr(parseInt(chip.dataset.day,10));
  slotRecompute();
}
// ── Kalender kustom (pengganti native date picker) ──
let ajCalY, ajCalM;
function ajCalOpen(){
  const base = slotDay ? new Date(slotDay+'T00:00:00') : new Date();
  ajCalY = base.getFullYear(); ajCalM = base.getMonth();
  ajCalRender(); document.getElementById('ajCalPanel').hidden = false;
}
function ajCalClose(){ const p=document.getElementById('ajCalPanel'); if(p) p.hidden = true; }
function ajCalRender(){
  const mo=['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
  const today=new Date(); today.setHours(0,0,0,0);
  const first=new Date(ajCalY, ajCalM, 1).getDay();
  const days=new Date(ajCalY, ajCalM+1, 0).getDate();
  const pad=n=>String(n).padStart(2,'0');
  let h='<div class="aj-cal-head"><button type="button" data-nav="-1">‹</button>'
      + '<span class="aj-cal-title">'+mo[ajCalM]+' '+ajCalY+'</span>'
      + '<button type="button" data-nav="1">›</button></div><div class="aj-cal-grid">';
  ['M','S','S','R','K','J','S'].forEach(d=>h+='<div class="aj-cal-dow">'+d+'</div>');
  for(let i=0;i<first;i++) h+='<button class="aj-cal-day empty" disabled></button>';
  for(let d=1;d<=days;d++){
    const dt=new Date(ajCalY,ajCalM,d); dt.setHours(0,0,0,0);
    const v=ajCalY+'-'+pad(ajCalM+1)+'-'+pad(d);
    const cls=['aj-cal-day']; if(+dt===+today)cls.push('today'); if(v===slotDay)cls.push('sel');
    h+='<button type="button" class="'+cls.join(' ')+'" data-d="'+v+'"'+(dt<today?' disabled':'')+'>'+d+'</button>';
  }
  document.getElementById('ajCalPanel').innerHTML = h+'</div>';
}
document.getElementById('ajCalPanel').addEventListener('click', e=>{
  const nav=e.target.closest('[data-nav]');
  if(nav){ ajCalM+=parseInt(nav.dataset.nav,10); if(ajCalM<0){ajCalM=11;ajCalY--;} if(ajCalM>11){ajCalM=0;ajCalY++;} ajCalRender(); return; }
  const day=e.target.closest('.aj-cal-day');
  if(day && !day.disabled && day.dataset.d){
    slotDay=day.dataset.d;
    const dd=new Date(slotDay+'T00:00:00');
    document.querySelector('#slotDays .slot-chip[data-day="pick"]').textContent =
      '📅 '+dd.toLocaleDateString('id-ID',{day:'numeric',month:'short'});
    ajCalClose(); slotRecompute();
  }
});
function slotRender(){
  document.getElementById('slotPanel').innerHTML = '<div class="kat-group">Jam Jemput</div>' + SLOTS.map(s=>{
    const act = s.v===slotHour ? ' is-active':'';
    return '<button type="button" class="kat-opt'+act+'" data-h="'+s.v+'"><span class="kat-e">'+s.ico+'</span>'
         + '<span class="kat-l">'+s.nm+' <small style="color:#9CA3AF">'+s.rg+'</small></span>'
         + (act?'<span class="kat-ck">✓</span>':'')+'</button>';
  }).join('');
}
function slotToggle(e){ e.stopPropagation();
  const p=document.getElementById('slotPanel');
  if (p.hidden){ slotRender(); p.hidden=false; document.getElementById('slotTrigger').classList.add('open'); }
  else slotCloseP();
}
function slotCloseP(){ const p=document.getElementById('slotPanel'); if(p)p.hidden=true;
  document.getElementById('slotTrigger')?.classList.remove('open'); }
function slotPickHour(h){
  slotHour = h; const s = SLOTS.find(x=>x.v===h);
  const lbl = document.getElementById('slotTriggerLabel');
  lbl.textContent = s.ico+' '+s.nm+' ('+s.rg+')'; lbl.classList.remove('kat-ph');
  // Jam dipilih tapi hari belum → default Hari ini
  if (!slotDay){ const c0=document.querySelector('#slotDays .slot-chip[data-day="0"]'); if(c0) slotPickDay(c0); }
  slotRecompute(); slotCloseP();
}
function slotResetUI(){
  slotDay=null; slotHour=null;
  document.querySelectorAll('#slotDays .slot-chip').forEach(x=>x.classList.remove('active'));
  document.querySelector('#slotDays .slot-chip[data-day="pick"]').textContent = '📅 Tanggal…';
  ajCalClose();
  const lbl=document.getElementById('slotTriggerLabel'); lbl.textContent='Pilih jam jemput…'; lbl.classList.add('kat-ph');
}
document.getElementById('slotPanel').addEventListener('click', e=>{ const b=e.target.closest('.kat-opt'); if(b) slotPickHour(parseInt(b.dataset.h,10)); });
document.getElementById('slotDays').addEventListener('click', e=>{ const c=e.target.closest('.slot-chip'); if(c) slotPickDay(c); });
document.addEventListener('click', e=>{
  if(!e.target.closest('#slotDD')) slotCloseP();
  // Tutup kalender bila klik di luar area chip & kalender
  if(!e.target.closest('#ajCalWrap') && !e.target.closest('#slotDays')) ajCalClose();
});

function openCreate() {
  ['c_nama','c_hp','c_alamat','c_catatan','c_slot'].forEach(id => document.getElementById(id).value='');
  slotResetUI();
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
