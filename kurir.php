<?php
$activePage = 'kurir';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';
require_once __DIR__ . '/components.php';

requirePermission('antar.kurir');
$user = currentUser();
$tid  = TenantResolver::id();
$oid  = TenantResolver::outletId();
$db   = Database::get();

// Resolve kurir_id dari user_id yang login
$st = $db->prepare("SELECT id FROM hl_kurir WHERE user_id=? AND tenant_id=? AND aktif=1 LIMIT 1");
$st->execute([(int)$user['id'], $tid]);
$kurirId = (int)($st->fetchColumn() ?: 0);

if (!$kurirId) {
    http_response_code(403);
    die('Akun kurir tidak ditemukan / tidak aktif. Hubungi owner.');
}

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $today = date('Y-m-d');
        $rows = TenantQuery::raw(
            "SELECT aj.*, t.no_order, t.nama_pelanggan AS order_nama
               FROM hl_antar_jemput aj
          LEFT JOIN hl_transaksi t ON t.id = aj.transaksi_id
              WHERE aj.tenant_id=? AND aj.kurir_id=?
                AND aj.status IN ('assigned','menuju','sampai')
                AND DATE(aj.updated_at) >= ?
              ORDER BY FIELD(aj.status,'menuju','sampai','assigned'), aj.slot_waktu ASC",
            [$tid, $kurirId, date('Y-m-d', strtotime('-1 day'))]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'status' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        $next = $d['next'] ?? '';
        $allowed = ['menuju','sampai']; // done handled di Task 8 dengan foto+signature
        if (!in_array($next, $allowed, true)) { echo json_encode(['error'=>'Status invalid']); exit; }

        try {
            $db->beginTransaction();
            $st = $db->prepare("SELECT status FROM hl_antar_jemput WHERE id=? AND tenant_id=? AND kurir_id=? FOR UPDATE");
            $st->execute([$id, $tid, $kurirId]);
            $current = $st->fetchColumn();
            if ($current === false) { throw new Exception('Tugas tidak ditemukan'); }

            // Transition validation
            $allowedFrom = ['menuju'=>'assigned', 'sampai'=>'menuju'];
            if ($current !== $allowedFrom[$next]) { throw new Exception('Transisi tidak valid (current=' . $current . ')'); }

            $upd = $db->prepare("UPDATE hl_antar_jemput SET status=?, updated_at=NOW() WHERE id=? AND tenant_id=? AND kurir_id=?");
            $upd->execute([$next, $id, $tid, $kurirId]);
            logAudit('antar_status', 'antar_jemput', "id=$id status=$next");
            $db->commit();
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

$pageTitle = '🛵 Tugas Saya';
renderHead($pageTitle);
?>
<body>
<div class="hl-main" style="max-width:520px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <div>
      <div style="font-size:13px;color:var(--gray)">👋 Hai,</div>
      <div style="font-weight:700;font-size:17px"><?= htmlspecialchars($user['nama']) ?></div>
    </div>
    <a href="/logout" class="hl-btn hl-btn-outline hl-btn-sm">Logout</a>
  </div>

  <h2 style="margin:0 0 12px;font-size:16px;color:var(--gray)">Tugas Hari Ini</h2>
  <div id="taskList" style="display:grid;gap:12px">⏳ Memuat...</div>
</div>

<?php renderToast(); ?>
<script>
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

async function loadTasks() {
  const r = await fetch('?action=list');
  const d = await r.json();
  const list = document.getElementById('taskList');
  if (!d.rows.length) { list.innerHTML = '<div style="padding:40px;text-align:center;color:var(--gray);background:#fff;border-radius:12px">🎉 Belum ada tugas</div>'; return; }
  list.innerHTML = d.rows.map(r => renderTask(r)).join('');
}

function renderTask(r) {
  const tipeLabel = r.tipe === 'jemput' ? '📥 JEMPUT' : '📤 ANTAR';
  const alamat = r.alamat || r.catatan || '-';
  const mapsBtn = r.alamat ? `<a href="https://maps.google.com/?q=${encodeURIComponent(r.alamat)}" target="_blank" class="hl-btn hl-btn-outline hl-btn-sm" style="margin-right:6px">📍 Maps</a>` : '';

  let actionBtn = '';
  if (r.status === 'assigned') {
    actionBtn = `<button class="hl-btn hl-btn-primary" style="width:100%;margin-top:10px" onclick="doStatus(${r.id},'menuju')">▶ Saya Menuju</button>`;
  } else if (r.status === 'menuju') {
    actionBtn = `<button class="hl-btn hl-btn-primary" style="width:100%;margin-top:10px" onclick="doStatus(${r.id},'sampai')">✅ Sampai Lokasi</button>`;
  } else if (r.status === 'sampai') {
    actionBtn = `<button class="hl-btn hl-btn-primary" style="width:100%;margin-top:10px" onclick="openDone(${r.id},'${r.tipe}')">🏁 Selesai</button>`;
  }

  return `
    <div class="hl-card" style="padding:16px">
      <div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--teal-d)">${tipeLabel}</div>
      <div style="font-weight:700;font-size:16px;margin-top:3px">${esc(r.nama)}</div>
      ${r.no_order ? `<div style="font-size:12.5px;color:var(--gray);margin-top:2px">#${esc(r.no_order)}</div>` : ''}
      <div style="font-size:13.5px;margin-top:8px;line-height:1.4">${esc(alamat)}</div>
      ${r.telepon ? `<div style="font-size:12.5px;color:var(--gray);margin-top:2px"><a href="tel:${esc(r.telepon)}" style="color:var(--teal-d)">📞 ${esc(r.telepon)}</a></div>` : ''}
      <div style="margin-top:10px">${mapsBtn}</div>
      ${actionBtn}
    </div>`;
}

async function doStatus(id, next) {
  if (!confirm('Konfirmasi status: ' + next + '?')) return;
  const r = await fetch('?action=status', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id, next})});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  loadTasks();
}

function openDone(id, tipe) { alert('Done modal di Task 8'); }

loadTasks();
setInterval(loadTasks, 30000); // refresh tiap 30 detik
</script>
</body>
