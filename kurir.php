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
          LEFT JOIN hl_transaksi t ON t.id = aj.transaksi_id AND t.tenant_id = aj.tenant_id
              WHERE aj.tenant_id=? AND aj.outlet_id=? AND aj.kurir_id=?
                AND aj.status IN ('assigned','menuju','sampai')
                AND DATE(aj.updated_at) >= ?
              ORDER BY FIELD(aj.status,'menuju','sampai','assigned'), aj.slot_waktu ASC",
            [$tid, $oid, $kurirId, date('Y-m-d', strtotime('-1 day'))]
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
            $st = $db->prepare("SELECT status FROM hl_antar_jemput WHERE id=? AND tenant_id=? AND outlet_id=? AND kurir_id=? FOR UPDATE");
            $st->execute([$id, $tid, $oid, $kurirId]);
            $current = $st->fetchColumn();
            if ($current === false) { throw new Exception('Tugas tidak ditemukan'); }

            // Transition validation
            $allowedFrom = ['menuju'=>'assigned', 'sampai'=>'menuju'];
            if ($current !== $allowedFrom[$next]) { throw new Exception('Transisi tidak valid (current=' . $current . ')'); }

            $upd = $db->prepare("UPDATE hl_antar_jemput SET status=?, updated_at=NOW() WHERE id=? AND tenant_id=? AND outlet_id=? AND kurir_id=?");
            $upd->execute([$next, $id, $tid, $oid, $kurirId]);
            logAudit('antar_status', 'antar_jemput', "id=$id status=$next");
            $db->commit();
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'upload_foto' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $f = $_FILES['foto'] ?? null;
        if (!$f) { echo json_encode(['error'=>'File foto tidak ditemukan']); exit; }
        $res = FileUpload::uploadImage($f, 'uploads/foto_antar', 't' . $tid . '_o' . $oid);
        if (!empty($res['error'])) { echo json_encode(['error'=>$res['error']]); exit; }
        echo json_encode(['ok'=>true, 'path'=>$res['path']]);
        exit;
    }

    if ($action === 'mark_done' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id    = (int)($d['id'] ?? 0);
        $fotoPath  = trim($d['foto'] ?? '');
        $signature = $d['signature'] ?? '';
        $catatan   = trim($d['catatan'] ?? '');

        // Validate foto path (XSS + path traversal guard)
        if ($fotoPath && (!str_starts_with($fotoPath, 'uploads/foto_antar/') || str_contains($fotoPath, '..'))) {
            $fotoPath = '';
        }

        // Signature decode → save PNG
        $sigPath = null;
        if ($signature && preg_match('/^data:image\/png;base64,(.+)$/', $signature, $m)) {
            $bin = base64_decode($m[1]);
            if ($bin !== false && strlen($bin) < 1000000) {
                $fn = 'uploads/foto_antar/sig_t' . $tid . '_o' . $oid . '_' . bin2hex(random_bytes(8)) . '.png';
                if (file_put_contents(ROOT . '/' . $fn, $bin) !== false) {
                    $sigPath = $fn;
                }
            }
        }

        try {
            $db->beginTransaction();
            $st = $db->prepare("SELECT status, tipe FROM hl_antar_jemput WHERE id=? AND tenant_id=? AND outlet_id=? AND kurir_id=? FOR UPDATE");
            $st->execute([$id, $tid, $oid, $kurirId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Exception('Tugas tidak ditemukan'); }
            if ($row['status'] !== 'sampai') { throw new Exception('Status harus sampai dulu sebelum selesai'); }

            // Antar wajib foto
            if ($row['tipe'] === 'antar' && !$fotoPath) { throw new Exception('Foto bukti wajib untuk antar'); }

            $upd = $db->prepare("UPDATE hl_antar_jemput SET status='done', done_at=NOW(), foto_bukti=?, signature_path=?, catatan=COALESCE(NULLIF(?,''),catatan), updated_at=NOW() WHERE id=? AND tenant_id=? AND outlet_id=? AND kurir_id=?");
            $upd->execute([$fotoPath ?: null, $sigPath, $catatan, $id, $tid, $oid, $kurirId]);
            logAudit('antar_done', 'antar_jemput', "id=$id tipe=" . $row['tipe']);
            $db->commit();
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($sigPath && file_exists(ROOT . '/' . $sigPath)) @unlink(ROOT . '/' . $sigPath);
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

<div class="hl-modal-overlay" id="modalDone">
  <div class="hl-modal" style="max-width:440px">
    <div class="hl-modal-header"><span class="hl-modal-title">🏁 Konfirmasi Selesai</span></div>
    <div class="hl-modal-body">
      <input type="hidden" id="d_id" value="0">
      <input type="hidden" id="d_tipe" value="">
      <label class="hl-label" id="lbl_foto">Foto Bukti</label>
      <input type="file" accept="image/*" capture="environment" id="d_foto" onchange="uploadDoneFoto(this)">
      <div id="d_fotoPreview" style="margin-top:8px"></div>
      <div id="sigBox" style="display:none">
        <label class="hl-label">Tanda Tangan Pelanggan</label>
        <canvas id="d_sig" width="400" height="120" style="border:1px solid var(--off);border-radius:8px;width:100%;background:#fff;touch-action:none"></canvas>
        <button type="button" onclick="clearSig()" style="background:none;border:none;color:var(--gray);font-size:12px;text-decoration:underline;cursor:pointer">Bersihkan TTD</button>
      </div>
      <label class="hl-label">Catatan (opsional)</label>
      <textarea id="d_catatan" class="hl-input" rows="2"></textarea>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeDone()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="submitDone()">🏁 Selesai</button>
    </div>
  </div>
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

let uploadedFotoPath = '';

function openDone(id, tipe) {
  document.getElementById('d_id').value = id;
  document.getElementById('d_tipe').value = tipe;
  document.getElementById('d_foto').value = '';
  document.getElementById('d_fotoPreview').innerHTML = '';
  document.getElementById('d_catatan').value = '';
  uploadedFotoPath = '';
  document.getElementById('sigBox').style.display = tipe === 'antar' ? 'block' : 'none';
  document.getElementById('lbl_foto').textContent = tipe === 'antar' ? 'Foto Bukti (wajib)' : 'Foto Bukti (opsional)';
  document.getElementById('modalDone').classList.add('open');
  setupSig();
}
function closeDone() { document.getElementById('modalDone').classList.remove('open'); }

async function uploadDoneFoto(input) {
  const f = input.files?.[0];
  if (!f) return;
  document.getElementById('d_fotoPreview').innerHTML = '⏳ Upload...';
  const fd = new FormData(); fd.append('foto', f); fd.append('_csrf', CSRF);
  const r = await fetch('?action=upload_foto', {method:'POST',headers:{'X-CSRF-Token':CSRF},body:fd});
  const d = await r.json();
  if (d.error) { document.getElementById('d_fotoPreview').innerHTML = '❌ ' + d.error; return; }
  uploadedFotoPath = d.path;
  document.getElementById('d_fotoPreview').innerHTML = `<img src="/${d.path}" style="max-width:160px;border-radius:8px">`;
}

function setupSig() {
  const c = document.getElementById('d_sig'); if (!c) return;
  const ctx = c.getContext('2d'); ctx.clearRect(0,0,c.width,c.height); ctx.strokeStyle='#000'; ctx.lineWidth=2; ctx.lineCap='round';
  let drawing = false;
  const pos = (e) => { const rect = c.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return {x:(t.clientX-rect.left)*c.width/rect.width, y:(t.clientY-rect.top)*c.height/rect.height}; };
  const start = (e) => { drawing=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); e.preventDefault(); };
  const move  = (e) => { if (!drawing) return; const p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); e.preventDefault(); };
  const end   = () => { drawing=false; };
  c.onmousedown=start; c.onmousemove=move; c.onmouseup=end;
  c.ontouchstart=start; c.ontouchmove=move; c.ontouchend=end;
}
function clearSig() { const c=document.getElementById('d_sig'); if (c) c.getContext('2d').clearRect(0,0,c.width,c.height); }

async function submitDone() {
  const id = parseInt(document.getElementById('d_id').value);
  const tipe = document.getElementById('d_tipe').value;
  const catatan = document.getElementById('d_catatan').value;
  if (tipe === 'antar' && !uploadedFotoPath) { showToast('Foto bukti wajib','error'); return; }
  const sig = (tipe === 'antar') ? document.getElementById('d_sig').toDataURL('image/png') : '';
  const r = await fetch('?action=mark_done', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id, foto: uploadedFotoPath, signature: sig, catatan})});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('🎉 Tugas selesai','success'); closeDone(); loadTasks();
}

loadTasks();
setInterval(loadTasks, 30000); // refresh tiap 30 detik
</script>
</body>
