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
setInterval(loadList, 30000);
</script>
