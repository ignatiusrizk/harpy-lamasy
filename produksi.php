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

        $signaturePath = null;
        try {
            $db->beginTransaction();

            // Decode signature INSIDE transaction so we can clean up on rollback
            if ($signature && preg_match('/^data:image\/png;base64,(.+)$/', $signature, $m)) {
                $bin = base64_decode($m[1]);
                if ($bin !== false && strlen($bin) < 1000000) { // 1MB cap
                    $fn = 'uploads/foto_proses/sig_t' . $tid . '_o' . $oid . '_' . bin2hex(random_bytes(8)) . '.png';
                    if (file_put_contents(ROOT . '/' . $fn, $bin) !== false) {
                        $signaturePath = $fn;
                        $fotoPaths[] = $fn;
                    }
                }
            }
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
            // Cleanup orphan signature file on failure
            if (!empty($signaturePath) && file_exists(ROOT . '/' . $signaturePath)) {
                @unlink(ROOT . '/' . $signaturePath);
            }
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
.ol-content { max-width: 1200px; margin: 0 auto; padding-bottom: 100px; }
.stage-tab { padding:8px 14px;border:1px solid var(--off);background:#fff;border-radius:100px;font-size:13px;font-weight:600;white-space:nowrap;cursor:pointer; }
.stage-tab.active { background:var(--teal);color:#fff;border-color:var(--teal); }
.stage-tab .cnt { display:inline-block;margin-left:4px;background:rgba(0,0,0,.08);padding:1px 7px;border-radius:100px;font-size:11px; }
.stage-tab.active .cnt { background:rgba(255,255,255,.25); }
.order-card { background:#fff;border:1px solid var(--off);border-radius:12px;padding:12px 14px;cursor:pointer;transition:border .2s; }
.order-card:active { border-color:var(--teal); }
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.55);backdrop-filter:blur(6px);z-index:200;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:18px;padding:22px 22px 20px;box-shadow:0 24px 60px rgba(15,28,58,.32), 0 4px 12px rgba(15,28,58,.08);width:100%;max-width:440px;max-height:90vh;overflow-y:auto;animation:modalSlideUp .22s cubic-bezier(.2,.8,.3,1)}
@keyframes modalSlideUp{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.modal{animation:none}}

.modal .stage-header{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin:-4px -4px 16px;padding:0 4px 14px;border-bottom:1px solid #EEF1F8}
.modal .stage-header h3{margin:0;font-size:18px;font-weight:700;color:var(--navy-d);line-height:1.25}
.modal .stage-close{background:none;border:none;font-size:22px;line-height:1;cursor:pointer;color:var(--gray);padding:4px 8px;border-radius:8px;transition:all .15s}
.modal .stage-close:hover{color:var(--navy-d);background:#F3F4F6}

.modal label{display:block;font-size:11.5px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.06em;margin-top:14px;margin-bottom:6px}
.modal label:first-of-type{margin-top:0}
.modal input[type=text],.modal input[type=number],.modal select,.modal textarea{width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid #E5E7EB;border-radius:10px;font-size:14.5px;font-family:var(--font);background:#fff;color:var(--navy-d);transition:border-color .15s, box-shadow .15s}
.modal input[type=text]:focus,.modal input[type=number]:focus,.modal select:focus,.modal textarea:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(53,232,213,.2)}
.modal textarea{resize:vertical;min-height:64px;line-height:1.4}
.modal select{appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path fill='%236B7280' d='M6 8L0 0h12z'/></svg>");background-repeat:no-repeat;background-position:right 14px center;padding-right:36px;cursor:pointer}

.modal input[type=file]{width:100%;box-sizing:border-box;padding:14px;border:1.5px dashed rgba(27,45,90,.2);border-radius:10px;background:#F9FAFB;font-size:13px;cursor:pointer;transition:all .15s;color:var(--gray)}
.modal input[type=file]:hover{border-color:var(--teal);background:#F0FDFC;color:var(--navy-d)}
.modal input[type=file]::file-selector-button{margin-right:12px;padding:7px 14px;border-radius:8px;border:none;background:var(--teal);color:var(--navy-d);font-weight:700;font-size:12.5px;cursor:pointer;transition:background .15s}
.modal input[type=file]::file-selector-button:hover{background:var(--teal-d)}

.modal #fotoPreview img{box-shadow:0 2px 8px rgba(0,0,0,.08)}
.modal canvas{background:#fff}

/* Buttons — local fix (global .btn not defined in harpy-erp.css; only .hl-btn exists) */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 18px;border-radius:10px;font-family:var(--font);font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;border:1px solid var(--off);background:#fff;color:var(--navy-d)}
.btn:hover{border-color:var(--teal)}
.btn-primary{background:var(--teal);color:var(--navy-d);border-color:var(--teal)}
.btn-primary:hover{background:var(--teal-d);box-shadow:0 4px 14px rgba(53,232,213,.3)}

/* Scan FAB — page signature element. Floating at thumb-reach for mobile worker. */
.scan-fab{
  position:fixed;right:20px;bottom:20px;z-index:150;
  width:64px;height:64px;border-radius:50%;
  background:var(--teal);color:var(--navy-d);
  border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  font-size:28px;
  box-shadow:0 8px 24px rgba(53,232,213,.45), 0 2px 6px rgba(15,28,58,.12);
  transition:transform .15s, box-shadow .2s;
  animation:fabPulse 2.4s ease-in-out infinite;
}
.scan-fab:hover{box-shadow:0 12px 32px rgba(53,232,213,.55), 0 4px 10px rgba(15,28,58,.18)}
.scan-fab:active{transform:scale(.94);animation:none}
@keyframes fabPulse{
  0%,100%{box-shadow:0 8px 24px rgba(53,232,213,.45), 0 2px 6px rgba(15,28,58,.12)}
  50%{box-shadow:0 8px 24px rgba(53,232,213,.7), 0 0 0 8px rgba(53,232,213,.08)}
}
@media (prefers-reduced-motion:reduce){.scan-fab{animation:none}}
@media (max-width:480px){.scan-fab{right:16px;bottom:16px;width:58px;height:58px;font-size:24px}}
</style>
</head>
<body>
<?php renderTopbar($activePage); ?>

<main class="ol-main">
  <div class="ol-content">
    <h1 style="margin:0 0 16px">🧺 Produksi</h1>

    <!-- Stage tabs -->
    <div id="stageTabs" style="display:flex;gap:6px;overflow-x:auto;padding-bottom:8px;margin-bottom:12px;-webkit-overflow-scrolling:touch">
      <button class="stage-tab active" data-stage="terima"  onclick="switchStage('terima')">📥 Terima <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="cuci"    onclick="switchStage('cuci')">🫧 Cuci <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="kering"  onclick="switchStage('kering')">💨 Kering <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="setrika" onclick="switchStage('setrika')">👔 Setrika <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="siap"    onclick="switchStage('siap')">✅ Siap <span class="cnt"></span></button>
      <button class="stage-tab"        data-stage="diambil" onclick="switchStage('diambil')">📦 Diambil/Diantar <span class="cnt"></span></button>
    </div>

    <!-- Card list -->
    <div id="cardList" style="display:grid;gap:10px;grid-template-columns:1fr">
      <div style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat...</div>
    </div>

    <!-- Modal stage form (filled di Task 7) -->
    <div id="stageModal" class="modal-overlay" style="align-items:center;justify-content:center;padding:20px">
      <div class="modal">
        <div id="stageModalBody"></div>
      </div>
    </div>

    <!-- Modal scanner (filled di Task 8) -->
    <div id="scanModal" class="modal-overlay" style="align-items:center;justify-content:center;padding:20px">
      <div class="modal">
        <div class="stage-header">
          <h3>📷 Scan QR Order</h3>
          <button class="stage-close" onclick="stopScan()" aria-label="Tutup">×</button>
        </div>
        <div id="scanArea" style="width:100%;min-height:300px;border-radius:12px;overflow:hidden;background:#000"></div>
        <button class="btn" onclick="stopScan()" style="margin-top:14px;width:100%">Batal</button>
      </div>
    </div>
  </div>
</main>

<button class="scan-fab" onclick="startScan()" aria-label="Scan QR Order" title="Scan QR Order">📷</button>

<script src="/assets/html5-qrcode.min.js?v=<?= @filemtime(__DIR__ . '/assets/html5-qrcode.min.js') ?: '1' ?>"></script>
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
  const head = `<div class="stage-header">
    <h3>${stageTitle(stage)}</h3>
    <button class="stage-close" onclick="closeStageModal()" aria-label="Tutup">×</button>
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
      <label>Jenis Penyerahan</label>
      <select id="f_jenis" onchange="toggleDeliveryFields()">
        <option value="ambil_sendiri">📦 Diambil pelanggan di outlet</option>
        <option value="diantarkan">🛵 Diantarkan ke pelanggan</option>
      </select>

      <label>Foto Bukti (wajib)</label>
      <input type="file" accept="image/*" capture="environment" onchange="onFotoPick(this)" id="f_foto" required>
      <div id="fotoPreview" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"></div>

      <div id="sigSection">
        <label>Tanda Tangan Pelanggan</label>
        <canvas id="sigCanvas" width="400" height="120" style="border:1px solid var(--off);border-radius:8px;width:100%;touch-action:none;background:#fff"></canvas>
        <button type="button" onclick="clearSig()" style="margin-top:4px;font-size:12px;background:none;border:none;color:var(--gray);cursor:pointer;text-decoration:underline">Bersihkan TTD</button>
      </div>

      <label>Catatan</label>
      <textarea id="f_catatan" rows="2" placeholder="Optional: alamat antar, nama penerima, dll"></textarea>
      <button class="btn btn-primary" style="width:100%;margin-top:14px" onclick="submitStage()" id="btnDiambil">📦 Tandai Selesai</button>`;
  }

  return head + '<p style="color:var(--red)">Stage tidak dikenali</p>';
}

function toggleDeliveryFields() {
  const jenis = document.getElementById('f_jenis')?.value;
  const sigSection = document.getElementById('sigSection');
  const btn = document.getElementById('btnDiambil');
  if (!sigSection) return;
  if (jenis === 'diantarkan') {
    sigSection.style.display = 'none';
    if (btn) btn.textContent = '🛵 Tandai Diantar';
  } else {
    sigSection.style.display = 'block';
    if (btn) btn.textContent = '📦 Tandai Diambil';
  }
}

function stageTitle(s) {
  return {
    'terima': '📥 Terima Cucian',
    'cuci': '🫧 Mulai Cuci',
    'kering': '💨 Mulai Kering',
    'setrika': '👔 Mulai Setrika',
    'siap': '✅ Tandai Siap',
    'diambil': '📦 Selesai (Diambil / Diantar)',
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
  if (document.getElementById('f_jenis'))   data.jenis    = document.getElementById('f_jenis').value;

  // Signature: hanya kalau ambil_sendiri (diantarkan = no signature, customer not present)
  let signature = '';
  if (stage === 'diambil' && data.jenis === 'ambil_sendiri') {
    const sig = document.getElementById('sigCanvas');
    if (sig) signature = sig.toDataURL('image/png');
  }

  if (stage === 'diambil' && uploadedFoto.length === 0) {
    alert('Foto bukti wajib diisi.'); return;
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

let qrInstance = null;

async function startScan() {
  document.getElementById('scanModal').classList.add('open');
  try {
    qrInstance = new Html5Qrcode("scanArea");
    await qrInstance.start(
      {facingMode: "environment"},
      {fps: 10, qrbox: 250},
      async (decoded) => {
        await stopScan();
        // Extract no_order: URL param atau bare kode
        const m = decoded.match(/order=([A-Z0-9-]+)/i) || decoded.match(/^([A-Z0-9-]{3,})$/i);
        if (!m) { alert('QR tidak dikenali: ' + decoded.slice(0, 60)); return; }
        const r = await fetch('/produksi.php?action=get_by_kode&kode=' + encodeURIComponent(m[1]));
        const order = await r.json();
        if (order.error) { alert('❌ ' + order.error); return; }
        // Set currentStage berdasarkan status_proses order, lalu open modal
        const stageMap = {'masuk':'terima','cuci':'kering','kering':'setrika','setrika':'siap','siap':'diambil'};
        const nextStage = stageMap[order.status_proses] || order.status_proses;
        currentStage = nextStage;
        // Sync tab UI
        document.querySelectorAll('.stage-tab').forEach(b => b.classList.toggle('active', b.dataset.stage === nextStage));
        await openStageModal(order.id);
      },
      () => {} // silent scan errors
    );
  } catch (e) {
    alert('Tidak bisa akses kamera: ' + e.message + '\n\nGunakan input manual no_order.');
    stopScan();
    const kode = prompt('Input no_order manual:');
    if (kode) {
      const r = await fetch('/produksi.php?action=get_by_kode&kode=' + encodeURIComponent(kode));
      const order = await r.json();
      if (order.error) { alert(order.error); return; }
      const stageMap = {'masuk':'terima','cuci':'kering','kering':'setrika','setrika':'siap','siap':'diambil'};
      currentStage = stageMap[order.status_proses] || order.status_proses;
      document.querySelectorAll('.stage-tab').forEach(b => b.classList.toggle('active', b.dataset.stage === currentStage));
      await openStageModal(order.id);
    }
  }
}

async function stopScan() {
  if (qrInstance) {
    try { await qrInstance.stop(); } catch {}
    qrInstance = null;
  }
  document.getElementById('scanModal').classList.remove('open');
}

// Initial load
loadCards();
</script>

<?php renderToast(); ?>
</body>
</html>
