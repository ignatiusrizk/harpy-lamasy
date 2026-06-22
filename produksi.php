<?php
// ══════════════════════════════════════════════════════
// produksi.php — Mobile-first worker app
//
// Stage forms: terima, cuci, kering, setrika, siap, diambil
// Actions: ?action=list|get_by_kode|mesin_list|upload_foto|save_stage
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';

requirePermission('produksi.work');

$tid    = TenantResolver::id();
$oid    = TenantResolver::outletId();
$userId = (int)(currentUser()['id'] ?? 0);
$db     = Database::get();

// Stage mapping
const STAGE_FROM = [
  'terima'  => null,
  'cuci'    => 'masuk',
  'kering'  => 'cuci',
  'setrika' => 'kering',
  'siap'    => 'setrika',
  'diambil' => 'siap',
];
const STAGE_TO = [
  'terima'  => null,
  'cuci'    => 'cuci',
  'kering'  => 'kering',
  'setrika' => 'setrika',
  'siap'    => 'siap',
  'diambil' => 'diambil',
];

$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $stage = $_GET['stage'] ?? 'masuk';
        // Map stage tab to status_proses filter
        $statusMap = [
            'terima'  => 'masuk',       // sama dengan masuk; differ by foto_paths existence
            'cuci'    => 'cuci',
            'kering'  => 'kering',
            'setrika' => 'setrika',
            'siap'    => 'siap',
            'diambil' => 'diambil',
        ];
        $statusFilter = $statusMap[$stage] ?? 'masuk';
        $rows = TenantQuery::raw(
            "SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon, t.total,
                    t.status_proses, t.tanggal, t.estimasi_selesai,
                    (SELECT COUNT(*) FROM hl_transaksi_item WHERE transaksi_id=t.id) AS jml_item
               FROM hl_transaksi t
              WHERE t.tenant_id=? AND t.outlet_id=? AND t.status_proses=?
              ORDER BY t.tanggal DESC LIMIT 100",
            [$tid, $oid, $statusFilter]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'get_by_kode') {
        $kode = trim($_GET['kode'] ?? '');
        if (!$kode) { echo json_encode(['error' => 'Kode kosong']); exit; }
        $order = TenantQuery::rawOne(
            "SELECT id, no_order, nama_pelanggan, telepon, total, status_proses, estimasi_selesai
               FROM hl_transaksi
              WHERE tenant_id=? AND outlet_id=? AND no_order=? LIMIT 1",
            [$tid, $oid, $kode]
        );
        if (!$order) { echo json_encode(['error' => 'Order tidak ditemukan']); exit; }
        echo json_encode($order);
        exit;
    }

    if ($action === 'mesin_list') {
        $jenis = $_GET['jenis'] ?? '';
        if (!in_array($jenis, ['cuci','kering'], true)) {
            echo json_encode(['error' => 'Jenis invalid']); exit;
        }
        $rows = TenantQuery::raw(
            "SELECT id, nama, kode FROM hl_mesin
              WHERE tenant_id=? AND outlet_id=? AND tipe=? AND status!='maintenance'
              ORDER BY nama",
            [$tid, $oid, $jenis]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'upload_foto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $f = $_FILES['foto'] ?? null;
        if (!$f) { echo json_encode(['error' => 'File foto tidak ditemukan']); exit; }
        $res = FileUpload::uploadImage($f, 'uploads/foto_proses', 't' . $tid . '_o' . $oid);
        if (!empty($res['error'])) { echo json_encode(['error' => $res['error']]); exit; }
        echo json_encode(['ok' => true, 'path' => $res['path']]);
        exit;
    }

    if ($action === 'save_stage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $transaksiId = (int)($d['transaksi_id'] ?? 0);
        $stage       = $d['stage'] ?? '';
        $dataFields  = $d['data'] ?? [];
        $fotoPaths   = $d['foto'] ?? [];          // array of paths
        // Validate paths: only accept paths from our upload endpoint (prevents XSS via foto_paths render)
        $fotoPaths = array_values(array_filter($fotoPaths, function($p) {
            return is_string($p) && str_starts_with($p, 'uploads/foto_proses/');
        }));
        $catatan     = trim($d['catatan'] ?? '');
        $signature   = $d['signature'] ?? '';     // data URL untuk stage diambil

        if ($transaksiId <= 0 || !array_key_exists($stage, STAGE_FROM)) {
            echo json_encode(['error' => 'Input tidak valid']); exit;
        }

        // Decode signature dataURL → save as PNG → append to foto_paths
        if ($signature && preg_match('/^data:image\/png;base64,(.+)$/', $signature, $m)) {
            $bin = base64_decode($m[1]);
            if ($bin !== false && strlen($bin) < 1000000) { // 1MB cap
                $fn = 'uploads/foto_proses/sig_t' . $tid . '_o' . $oid . '_' . bin2hex(random_bytes(8)) . '.png';
                if (file_put_contents(ROOT . '/' . $fn, $bin) !== false) {
                    $fotoPaths[] = $fn;
                }
            }
        }

        try {
            $db->beginTransaction();
            $st = $db->prepare(
                "SELECT status_proses FROM hl_transaksi
                  WHERE id=? AND tenant_id=? AND outlet_id=? FOR UPDATE"
            );
            $st->execute([$transaksiId, $tid, $oid]);
            $current = $st->fetchColumn();
            if ($current === false) { throw new Exception('Order tidak ditemukan'); }

            $expectedFrom = STAGE_FROM[$stage];
            if ($expectedFrom !== null && $current !== $expectedFrom) {
                throw new Exception('Order sudah diupdate worker lain. Refresh halaman.');
            }

            // Insert input record (outlet_id explicit — hl_proses_input not in outletTables)
            TenantQuery::insert('hl_proses_input', [
                'outlet_id'    => $oid,
                'transaksi_id' => $transaksiId,
                'stage'        => $stage,
                'karyawan_id'  => $userId,
                'data_json'    => json_encode($dataFields),
                'foto_paths'   => implode(',', $fotoPaths),
                'catatan'      => $catatan,
            ]);

            // Update status (kecuali stage 'terima')
            $newStatus = STAGE_TO[$stage];
            if ($newStatus !== null) {
                $upd = $db->prepare(
                    "UPDATE hl_transaksi SET status_proses=?, updated_at=NOW()
                      WHERE id=? AND tenant_id=? AND outlet_id=?"
                );
                $upd->execute([$newStatus, $transaksiId, $tid, $oid]);

                // Log status change
                // Schema: id, tenant_id, transaksi_id, status_lama, status_baru, tipe, catatan, oleh, created_at
                $byName = currentUser()['nama'] ?? '';
                $logSt = $db->prepare(
                    "INSERT INTO hl_proses_log (tenant_id, transaksi_id, status_lama, status_baru, tipe, catatan, oleh)
                     VALUES (?, ?, ?, ?, 'produksi', ?, ?)"
                );
                $logSt->execute([
                    $tid, $transaksiId, $current, $newStatus,
                    'Stage ' . $stage . ' via /produksi',
                    $byName,
                ]);
            }

            logAudit('proses_stage', 'transaksi', "id={$transaksiId} stage={$stage}");
            $db->commit();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[produksi save_stage] ' . $e->getMessage());
            // Allow specific known error messages, generic for unknown
            $msg = $e->getMessage();
            $knownErrors = ['Order tidak ditemukan', 'Order sudah diupdate worker lain. Refresh halaman.', 'Input tidak valid'];
            if (!in_array($msg, $knownErrors, true)) {
                $msg = 'Gagal menyimpan. Coba lagi sebentar.';
            }
            echo json_encode(['error' => $msg]);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action: ' . $action]);
    exit;
}

$activePage = 'produksi';
$pageTitle  = '🧺 Produksi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php require __DIR__ . '/components.php'; ?>
<?php renderHead($pageTitle); ?>
<style>
.ol-main { padding: 20px; }
.ol-content { max-width: 1200px; margin: 0 auto; }
.stage-tab { padding:8px 14px;border:1px solid var(--off);background:#fff;border-radius:100px;font-size:13px;font-weight:600;white-space:nowrap;cursor:pointer; }
.stage-tab.active { background:var(--teal);color:#fff;border-color:var(--teal); }
.stage-tab .cnt { display:inline-block;margin-left:4px;background:rgba(0,0,0,.08);padding:1px 7px;border-radius:100px;font-size:11px; }
.stage-tab.active .cnt { background:rgba(255,255,255,.25); }
.order-card { background:#fff;border:1px solid var(--off);border-radius:12px;padding:12px 14px;cursor:pointer;transition:border .2s; }
.order-card:active { border-color:var(--teal); }
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.6);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal label{display:block;font-size:13px;font-weight:600;margin-top:10px;margin-bottom:4px}
.modal input[type=text],.modal input[type=number],.modal select,.modal textarea{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--off);border-radius:8px;font-size:14px}
.modal textarea{resize:vertical}
</style>
</head>
<body>
<?php renderTopbar($activePage); ?>

<main class="ol-main">
  <div class="ol-content">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h1 style="margin:0">🧺 Produksi</h1>
      <button class="btn btn-primary" onclick="startScan()">📷 Scan QR</button>
    </div>

    <!-- Stage tabs -->
    <div id="stageTabs" style="display:flex;gap:6px;overflow-x:auto;padding-bottom:8px;margin-bottom:12px;-webkit-overflow-scrolling:touch">
      <button class="stage-tab active" data-stage="terima"  onclick="switchStage('terima')">📥 Terima <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="cuci"    onclick="switchStage('cuci')">🫧 Cuci <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="kering"  onclick="switchStage('kering')">💨 Kering <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="setrika" onclick="switchStage('setrika')">👔 Setrika <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="siap"    onclick="switchStage('siap')">✅ Siap <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="diambil" onclick="switchStage('diambil')">📦 Diambil <span class="cnt"></span></button>
    </div>

    <!-- Card list -->
    <div id="cardList" style="display:grid;gap:10px;grid-template-columns:1fr">
      <div style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat...</div>
    </div>

    <!-- Modal stage form (filled di Task 7) -->
    <div id="stageModal" class="modal-overlay" style="align-items:center;justify-content:center;padding:20px">
      <div class="modal" style="max-width:480px;max-height:90vh;overflow-y:auto">
        <div id="stageModalBody"></div>
      </div>
    </div>

    <!-- Modal scanner (filled di Task 8) -->
    <div id="scanModal" class="modal-overlay" style="align-items:center;justify-content:center;padding:20px">
      <div class="modal" style="max-width:480px">
        <h3 style="margin:0 0 12px">📷 Scan QR Order</h3>
        <div id="scanArea" style="width:100%;min-height:300px"></div>
        <button class="btn" onclick="stopScan()" style="margin-top:12px;width:100%">Batal</button>
      </div>
    </div>
  </div>
</main>

<script>
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}

let currentStage = 'terima';
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';

function switchStage(stage) {
  currentStage = stage;
  document.querySelectorAll('.stage-tab').forEach(b => b.classList.toggle('active', b.dataset.stage === stage));
  loadCards();
}

async function loadCards() {
  const list = document.getElementById('cardList');
  list.innerHTML = '<div style="text-align:center;padding:30px;color:var(--gray)">⏳ Memuat...</div>';
  try {
    const r = await fetch('/produksi.php?action=list&stage=' + currentStage);
    const d = await r.json();
    if (d.error) { list.innerHTML = '<div style="padding:20px;color:var(--red)">❌ ' + d.error + '</div>'; return; }
    if (!d.rows.length) {
      list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray)">Tidak ada order di stage ini</div>';
      return;
    }
    list.innerHTML = d.rows.map(r => `
      <div class="order-card" onclick="openStageModal(${r.id})">
        <div style="font-weight:700;font-size:15px">#${r.no_order} · ${esc(r.nama_pelanggan||'(no name)')}</div>
        <div style="color:var(--gray);font-size:13px;margin-top:3px">${r.jml_item} item · Rp ${Number(r.total||0).toLocaleString('id-ID')}</div>
        <div style="margin-top:8px"><span class="badge b-${r.status_proses}">${r.status_proses}</span></div>
      </div>`).join('');
  } catch (e) {
    list.innerHTML = '<div style="padding:20px;color:var(--red)">❌ Network error: ' + e.message + '</div>';
  }
}

// ── Task 7: Stage forms ──────────────────────────────
let mesinCache = { cuci: null, kering: null };

async function getMesinList(jenis) {
  if (mesinCache[jenis]) return mesinCache[jenis];
  const r = await fetch('/produksi.php?action=mesin_list&jenis=' + jenis);
  const d = await r.json();
  mesinCache[jenis] = d.rows || [];
  return mesinCache[jenis];
}

function openStageModal(orderId) {
  // Stage diambil dari tab aktif (currentStage). Tidak perlu fetch ulang —
  // form input tidak butuh detail order; submit-nya hanya kirim orderId + stage.
  const body = document.getElementById('stageModalBody');
  body.innerHTML = renderStageForm(currentStage, orderId);
  document.getElementById('stageModal').classList.add('open');
}

function closeStageModal() {
  document.getElementById('stageModal').classList.remove('open');
}

function renderStageForm(stage, orderId) {
  const head = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h3 style="margin:0">${stageTitle(stage)}</h3>
    <button onclick="closeStageModal()" style="background:none;border:none;font-size:24px;cursor:pointer">×</button>
  </div>
  <input type="hidden" id="f_orderId" value="${orderId}">
  <input type="hidden" id="f_stage" value="${stage}">`;

  if (stage === 'terima') {
    return head + `
      <label>Foto Kondisi (max 3)</label>
      <input type="file" accept="image/*" capture="environment" multiple onchange="onFotoPick(this)" id="f_foto">
      <div id="fotoPreview" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"></div>
      <label>Catatan Kondisi</label>
      <textarea id="f_catatan" rows="3" placeholder="Noda, robek, atau hal khusus..."></textarea>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">💾 Simpan Dokumentasi</button>`;
  }

  if (stage === 'cuci') {
    return head + `
      <label>Mesin Cuci</label>
      <select id="f_mesin"><option value="">-- Pilih --</option></select>
      <label style="margin-top:10px">Berat Masuk (kg)</label>
      <input type="number" step="0.1" min="0" id="f_berat" placeholder="5.0">
      <label style="margin-top:10px">Program</label>
      <select id="f_program">
        <option value="putih">Putih</option>
        <option value="berwarna">Berwarna</option>
        <option value="halus">Halus</option>
        <option value="jeans">Jeans</option>
      </select>
      <label style="margin-top:10px">Catatan</label>
      <textarea id="f_catatan" rows="2"></textarea>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">▶ Mulai Cuci</button>`;
  }

  if (stage === 'kering') {
    return head + `
      <label>Mesin Pengering</label>
      <select id="f_mesin"><option value="">-- Pilih --</option></select>
      <label style="margin-top:10px">Durasi Target (menit)</label>
      <input type="number" min="1" id="f_durasi" placeholder="45">
      <label style="margin-top:10px">Suhu</label>
      <select id="f_suhu">
        <option value="rendah">Rendah</option>
        <option value="sedang" selected>Sedang</option>
        <option value="tinggi">Tinggi</option>
      </select>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">▶ Mulai Kering</button>`;
  }

  if (stage === 'setrika') {
    return head + `
      <label>Foto Hasil (opsional)</label>
      <input type="file" accept="image/*" capture="environment" multiple onchange="onFotoPick(this)" id="f_foto">
      <div id="fotoPreview" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"></div>
      <label>Catatan</label>
      <textarea id="f_catatan" rows="2"></textarea>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">▶ Mulai Setrika</button>`;
  }

  if (stage === 'siap') {
    return head + `
      <label>Lokasi Rak / Nomor Plastik</label>
      <input type="text" id="f_lokasi" maxlength="50" placeholder="Rak A-12 / Plastik #5">
      <label style="margin-top:10px">Foto Packing (opsional)</label>
      <input type="file" accept="image/*" capture="environment" multiple onchange="onFotoPick(this)" id="f_foto">
      <div id="fotoPreview" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"></div>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">✅ Tandai Siap</button>`;
  }

  if (stage === 'diambil') {
    return head + `
      <label>Foto Serah Terima (wajib)</label>
      <input type="file" accept="image/*" capture="environment" onchange="onFotoPick(this)" id="f_foto" required>
      <div id="fotoPreview" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"></div>
      <label style="margin-top:10px">Tanda Tangan</label>
      <canvas id="sigCanvas" width="400" height="120" style="border:1px solid var(--off);border-radius:8px;width:100%;touch-action:none"></canvas>
      <button onclick="clearSig()" style="margin-top:4px;font-size:12px">Bersihkan TTD</button>
      <label style="margin-top:10px">Catatan</label>
      <textarea id="f_catatan" rows="2"></textarea>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()">📦 Tandai Diambil</button>`;
  }

  return head + '<p style="color:var(--red)">Stage tidak dikenali</p>';
}

function stageTitle(s) {
  return {
    'terima': '📥 Terima Cucian',
    'cuci': '🫧 Mulai Cuci',
    'kering': '💨 Mulai Kering',
    'setrika': '👔 Mulai Setrika',
    'siap': '✅ Tandai Siap',
    'diambil': '📦 Tandai Diambil',
  }[s] || s;
}

// ── Foto upload state
let uploadedFoto = [];

async function onFotoPick(input) {
  uploadedFoto = [];
  document.getElementById('fotoPreview').innerHTML = '⏳ Upload...';
  for (const f of input.files) {
    const fd = new FormData();
    fd.append('foto', f);
    fd.append('_csrf', CSRF);
    const r = await fetch('/produksi.php?action=upload_foto', {
      method:'POST', headers:{'X-CSRF-Token':CSRF}, body: fd
    });
    const d = await r.json();
    if (d.ok) uploadedFoto.push(d.path);
  }
  document.getElementById('fotoPreview').innerHTML = uploadedFoto.map(p =>
    `<img src="/${p}" style="width:64px;height:64px;object-fit:cover;border-radius:6px">`
  ).join('');
}

// ── Signature canvas (stage diambil)
function setupSig() {
  const c = document.getElementById('sigCanvas');
  if (!c) return;
  const ctx = c.getContext('2d');
  ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round';
  let drawing = false;
  const pos = (e) => {
    const rect = c.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    return {x: (t.clientX - rect.left) * c.width / rect.width,
            y: (t.clientY - rect.top) * c.height / rect.height};
  };
  const start = (e) => { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); };
  const move  = (e) => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); };
  const end   = () => { drawing = false; };
  c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); c.addEventListener('mouseup', end);
  c.addEventListener('touchstart', start, {passive:false}); c.addEventListener('touchmove', move, {passive:false}); c.addEventListener('touchend', end);
}
function clearSig() {
  const c = document.getElementById('sigCanvas');
  if (c) c.getContext('2d').clearRect(0,0,c.width,c.height);
}

// Wrap openStageModal: render dulu (sync), lalu populate mesin dropdown + setup signature (async)
const _origOpenStageModal = openStageModal;
openStageModal = async function(orderId) {
  _origOpenStageModal(orderId);
  uploadedFoto = [];

  // Populate mesin dropdown kalau form punya field mesin
  const mesinEl = document.getElementById('f_mesin');
  if (mesinEl) {
    const jenis = (currentStage === 'cuci') ? 'cuci' : 'kering';
    const mesins = await getMesinList(jenis);
    mesinEl.innerHTML = '<option value="">-- Pilih --</option>' +
      mesins.map(m => `<option value="${m.id}">${esc(m.nama)} (${esc(m.kode||'')})</option>`).join('');
  }

  // Setup signature canvas kalau stage = diambil
  setupSig();
};

async function submitStage() {
  const orderId = parseInt(document.getElementById('f_orderId').value);
  const stage   = document.getElementById('f_stage').value;
  const catatan = document.getElementById('f_catatan')?.value || '';
  const data = {};
  if (document.getElementById('f_mesin'))   data.mesin_id = document.getElementById('f_mesin').value;
  if (document.getElementById('f_berat'))   data.berat    = document.getElementById('f_berat').value;
  if (document.getElementById('f_program')) data.program  = document.getElementById('f_program').value;
  if (document.getElementById('f_durasi'))  data.durasi   = document.getElementById('f_durasi').value;
  if (document.getElementById('f_suhu'))    data.suhu     = document.getElementById('f_suhu').value;
  if (document.getElementById('f_lokasi'))  data.lokasi   = document.getElementById('f_lokasi').value;

  let signature = '';
  const sig = document.getElementById('sigCanvas');
  if (sig) signature = sig.toDataURL('image/png');

  if (stage === 'diambil' && uploadedFoto.length === 0) {
    alert('Foto serah terima wajib diisi.'); return;
  }

  const r = await fetch('/produksi.php?action=save_stage', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({transaksi_id: orderId, stage, data, foto: uploadedFoto, catatan, signature})
  });
  const d = await r.json();
  if (d.ok) {
    closeStageModal();
    loadCards();
  } else {
    alert('❌ ' + (d.error || 'Gagal simpan'));
  }
}

function startScan() {
  // Implementation di Task 8
  alert('Scanner akan diisi di Task 8');
}
function stopScan() {
  document.getElementById('scanModal').classList.remove('open');
}

// Initial load
loadCards();
</script>

<?php renderToast(); ?>
</body>
</html>
