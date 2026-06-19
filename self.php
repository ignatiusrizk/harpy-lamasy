<?php
// ══════════════════════════════════════════════════════
// self.php — Public Self-Service Booking
//
// Customer scan QR di mesin → URL /self?m=KODE
// 1. Tampilkan info mesin (nama, tipe, status)
// 2. Kalau idle: pilih cycle, input nama+telepon, book
// 3. Kalau busy: tampilkan status running + countdown
//
// No login. Identifikasi tenant lewat kode mesin (unique per tenant+outlet,
// tapi karena prefix bisa beda tenant, kita pakai lookup by kode dengan
// outlet status check). Untuk safety, kode harus 100% match.
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantQuery.php';

date_default_timezone_set('Asia/Jakarta');

$kode = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $_GET['m'] ?? ''));
$action = $_GET['action'] ?? '';

function findMesinByKode(string $kode): ?array
{
    if (!$kode) return null;
    try {
        $db = Database::get();
        $st = $db->prepare(
            "SELECT m.*, o.nama_outlet, o.telepon AS outlet_telp,
                    t.nama_perusahaan
             FROM hl_mesin m
             JOIN outlets o ON o.id = m.outlet_id
             JOIN tenants t ON t.id = m.tenant_id
             WHERE m.kode = ? AND m.is_active = 1
               AND o.status IN ('active','trial','grace')
             LIMIT 1"
        );
        $st->execute([$kode]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) { return null; }
}

function getCycles(int $mesinId): array
{
    try {
        $db = Database::get();
        $st = $db->prepare("SELECT * FROM hl_mesin_cycle WHERE mesin_id=? AND is_active=1 ORDER BY urutan, durasi_menit");
        $st->execute([$mesinId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) { return []; }
}

function getActiveSesi(int $mesinId): ?array
{
    try {
        $db = Database::get();
        $st = $db->prepare("SELECT * FROM hl_mesin_sesi WHERE mesin_id=? AND status IN ('booked','running') ORDER BY id DESC LIMIT 1");
        $st->execute([$mesinId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) { return null; }
}

// AJAX: poll status (untuk refresh tanpa reload)
if ($action === 'status') {
    header('Content-Type: application/json');
    $mesin = findMesinByKode($kode);
    if (!$mesin) { echo json_encode(['error'=>'Mesin tidak ditemukan']); exit; }
    $sesi = getActiveSesi((int)$mesin['id']);
    echo json_encode(['mesin'=>$mesin, 'sesi'=>$sesi, 'server_time'=>date('Y-m-d H:i:s')]);
    exit;
}

// AJAX: book sesi baru
if ($action === 'book' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    $kodeIn = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $d['kode'] ?? ''));
    $mesin = findMesinByKode($kodeIn);
    if (!$mesin) { echo json_encode(['error'=>'Mesin tidak ditemukan']); exit; }

    // Cek apakah sudah ada sesi aktif (race condition: customer lain barengan)
    $existing = getActiveSesi((int)$mesin['id']);
    if ($existing) { echo json_encode(['error'=>'Mesin sedang dipakai. Refresh halaman.']); exit; }

    $cycleId = intval($d['cycle_id'] ?? 0);
    $nama    = substr(trim(strip_tags($d['nama'] ?? '')), 0, 80);
    $tel     = preg_replace('/[^0-9+]/', '', $d['telepon'] ?? '');
    $tel     = substr($tel, 0, 20);
    if (!$cycleId) { echo json_encode(['error'=>'Pilih cycle dulu']); exit; }
    if (!$nama)    { echo json_encode(['error'=>'Nama wajib diisi']); exit; }

    // Verify cycle milik mesin ini
    $db = Database::get();
    $st = $db->prepare("SELECT * FROM hl_mesin_cycle WHERE id=? AND mesin_id=? AND is_active=1");
    $st->execute([$cycleId, (int)$mesin['id']]);
    $cycle = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cycle) { echo json_encode(['error'=>'Cycle tidak valid']); exit; }

    // Insert sesi (status=booked, status_bayar=belum)
    try {
        $st = $db->prepare(
            "INSERT INTO hl_mesin_sesi
              (tenant_id, outlet_id, mesin_id, cycle_id, pelanggan_nama, pelanggan_telepon,
               durasi_menit, tarif, cycle_label, metode_bayar, status_bayar, status, booked_at)
             VALUES (?,?,?,?,?,?,?,?,?,'cash','belum','booked', NOW())"
        );
        $st->execute([
            (int)$mesin['tenant_id'], (int)$mesin['outlet_id'], (int)$mesin['id'], (int)$cycle['id'],
            $nama, $tel ?: null,
            (int)$cycle['durasi_menit'], (int)$cycle['tarif'], $cycle['label']
        ]);
        $sesiId = (int)$db->lastInsertId();
        $db->prepare("UPDATE hl_mesin SET status='booked' WHERE id=?")->execute([(int)$mesin['id']]);

        echo json_encode([
            'success'   => true,
            'sesi_id'   => $sesiId,
            'message'   => 'Booking berhasil. Silakan bayar ke kasir, mereka akan konfirmasi.',
            'outlet_telp' => $mesin['outlet_telp'] ?? '',
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
    }
    exit;
}

// Render page
$mesin = findMesinByKode($kode);
$cycles = $mesin ? getCycles((int)$mesin['id']) : [];
$activeSesi = $mesin ? getActiveSesi((int)$mesin['id']) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="theme-color" content="#0F1C3A">
<title><?= $mesin ? htmlspecialchars($mesin['nama']) : 'Self-Service' ?> · LaMaSy</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;background:linear-gradient(180deg,#0F1C3A 0%,#1B2D5A 100%);min-height:100vh;color:#1F2937}
.app{max-width:480px;margin:0 auto;min-height:100vh;display:flex;flex-direction:column}
.brand{padding:24px 20px 16px;text-align:center;color:white}
.brand-name{font-size:14px;font-weight:600;opacity:.9;margin:0 0 4px}
.brand-outlet{font-size:11px;opacity:.7;margin:0;font-weight:500}
.card{background:white;border-radius:20px 20px 0 0;flex:1;padding:24px 20px;margin-top:8px}

.mesin-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:20px}
.mesin-nama{font-size:22px;font-weight:800;color:#0F1C3A;margin:0 0 4px}
.mesin-meta{font-size:13px;color:#6B7280;margin:0}
.mesin-status{font-size:11px;font-weight:800;padding:5px 11px;border-radius:12px;text-transform:uppercase}
.status-idle{background:#D1FAE5;color:#065F46}
.status-running{background:#DBEAFE;color:#1E40AF}
.status-booked{background:#FEF3C7;color:#92400E}
.status-maintenance{background:#F3F4F6;color:#6B7280}

.section-title{font-size:13px;font-weight:700;color:#0F1C3A;margin:20px 0 10px;text-transform:uppercase;letter-spacing:.3px}

.cycle-grid{display:grid;gap:10px}
.cycle-btn{background:#F9FAFB;border:2px solid #E5E7EB;border-radius:12px;padding:14px 16px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;transition:all .2s;font-family:inherit;text-align:left;width:100%}
.cycle-btn:hover{border-color:#14B8A6}
.cycle-btn.selected{border-color:#14B8A6;background:#F0FDFA}
.cycle-label{font-size:14px;font-weight:700;color:#0F1C3A}
.cycle-durasi{font-size:11px;color:#6B7280;margin-top:2px}
.cycle-tarif{font-size:16px;font-weight:800;color:#14B8A6;font-family:'DM Mono',monospace}

.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;font-weight:700;color:#0F1C3A;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px}
.form-input{width:100%;padding:12px 14px;border:1.5px solid #E5E7EB;border-radius:10px;font-size:14px;font-family:inherit;color:#0F1C3A}
.form-input:focus{outline:none;border-color:#14B8A6}

.btn{display:block;width:100%;padding:14px;background:#14B8A6;color:#0F1C3A;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;font-family:inherit;margin-top:16px}
.btn:hover{background:#0F766E;color:white}
.btn:disabled{background:#E5E7EB;color:#9CA3AF;cursor:not-allowed}
.btn-outline{background:white;color:#0F1C3A;border:1.5px solid #E5E7EB}

.summary-box{background:linear-gradient(135deg,#F0FDFA,#CCFBF1);border:1px solid #99F6E4;border-radius:12px;padding:16px;margin:16px 0}
.summary-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px}
.summary-row.total{margin-top:10px;padding-top:10px;border-top:1px solid #99F6E4;font-size:16px;font-weight:800;color:#0F766E}

.running-display{text-align:center;padding:24px 16px}
.running-icon{font-size:48px;margin-bottom:8px}
.countdown{font-family:'DM Mono',monospace;font-size:48px;font-weight:800;color:#1E40AF;margin:12px 0}
.countdown.over{color:#EF4444}
.running-info{font-size:14px;color:#6B7280;margin-top:8px}
.running-info strong{color:#0F1C3A}

.notice{background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:12px 14px;font-size:13px;color:#92400E;margin:16px 0}
.notice.error{background:#FEE2E2;border-color:#FECACA;color:#991B1B}
.notice.success{background:#D1FAE5;border-color:#A7F3D0;color:#065F46}

.success-screen{text-align:center;padding:32px 20px}
.success-icon{font-size:64px;margin-bottom:8px}
.success-msg{font-size:18px;font-weight:800;color:#065F46;margin:0 0 12px}
.success-detail{font-size:14px;color:#374151;line-height:1.6}
</style>
</head>
<body>
<div class="app">

  <?php if (!$mesin): ?>
    <!-- ═════ MESIN NOT FOUND ═════ -->
    <div class="brand"><p class="brand-name">LaMaSy</p></div>
    <div class="card">
      <div class="success-screen">
        <div class="success-icon">😕</div>
        <h2 style="color:#991B1B;margin:0 0 8px">Mesin Tidak Ditemukan</h2>
        <p style="color:#6B7280">Kode mesin "<strong><?= htmlspecialchars($kode ?: '-') ?></strong>" tidak valid atau mesin sudah dinonaktifkan.</p>
        <p style="font-size:12px;color:#9CA3AF;margin-top:20px">Pastikan kamu scan QR di mesin yang aktif. Hubungi petugas kalau ada masalah.</p>
      </div>
    </div>
  <?php else: ?>

    <div class="brand">
      <p class="brand-name"><?= htmlspecialchars($mesin['nama_perusahaan'] ?? 'LaMaSy') ?></p>
      <p class="brand-outlet">📍 <?= htmlspecialchars($mesin['nama_outlet']) ?></p>
    </div>

    <div class="card">
      <div class="mesin-head">
        <div>
          <h1 class="mesin-nama"><?= htmlspecialchars($mesin['nama']) ?></h1>
          <p class="mesin-meta">
            <?= $mesin['tipe'] === 'cuci' ? '🧺 Mesin Cuci' : '🌬️ Mesin Pengering' ?>
            <?= $mesin['kapasitas'] > 0 ? ' · ' . $mesin['kapasitas'] . ' kg' : '' ?>
            · Kode: <code><?= htmlspecialchars($mesin['kode']) ?></code>
          </p>
        </div>
        <span class="mesin-status status-<?= $activeSesi ? $activeSesi['status'] : $mesin['status'] ?>"
              id="statusBadge">
          <?= $activeSesi ? $activeSesi['status'] : $mesin['status'] ?>
        </span>
      </div>

      <!-- ═════ STATE: SUCCESS (after booking) ═════ -->
      <div id="successScreen" style="display:none">
        <div class="success-screen">
          <div class="success-icon">✅</div>
          <p class="success-msg">Booking Berhasil</p>
          <p class="success-detail">
            Silakan <strong>bayar ke petugas/kasir</strong>. Setelah dikonfirmasi, mesin akan dinyalakan dan timer berjalan.
            <br><br>
            Tunggu di area outlet — kamu akan diingatkan saat cycle selesai.
          </p>
          <?php if (!empty($mesin['outlet_telp'])): ?>
          <a href="https://wa.me/<?= preg_replace('/[^0-9]/','', preg_replace('/^0/','62',$mesin['outlet_telp'])) ?>" class="btn btn-outline" style="text-decoration:none;display:inline-block;margin-top:16px">💬 Hubungi Outlet</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- ═════ STATE: RUNNING (mesin sedang aktif) ═════ -->
      <?php if ($activeSesi && $activeSesi['status'] === 'running'): ?>
      <div id="runningScreen">
        <div class="running-display">
          <div class="running-icon">⏱️</div>
          <p style="font-size:14px;color:#1E40AF;font-weight:700;margin:0">SEDANG BERJALAN</p>
          <div class="countdown" data-est="<?= date('c', strtotime($activeSesi['estimated_done_at'])) ?>">--:--</div>
          <p class="running-info">
            <strong><?= htmlspecialchars($activeSesi['pelanggan_nama']) ?></strong> · <?= htmlspecialchars($activeSesi['cycle_label'] ?? '') ?>
            <br>
            <small>Mulai: <?= date('H:i', strtotime($activeSesi['started_at'])) ?> · Selesai: <?= date('H:i', strtotime($activeSesi['estimated_done_at'])) ?></small>
          </p>
        </div>
        <div class="notice">⚠️ Mesin sedang dipakai. Tunggu sampai siklus selesai untuk booking baru.</div>
      </div>
      <?php elseif ($activeSesi && $activeSesi['status'] === 'booked'): ?>
      <!-- ═════ STATE: BOOKED (nunggu konfirmasi kasir) ═════ -->
      <div id="bookedScreen">
        <div class="running-display">
          <div class="running-icon">💳</div>
          <p style="font-size:14px;color:#92400E;font-weight:700;margin:0">MENUNGGU KONFIRMASI</p>
          <p class="running-info" style="margin-top:16px">
            <strong><?= htmlspecialchars($activeSesi['pelanggan_nama']) ?></strong>
            <br>Cycle: <?= htmlspecialchars($activeSesi['cycle_label'] ?? '') ?> · Rp <?= number_format($activeSesi['tarif'],0,',','.') ?>
          </p>
        </div>
        <div class="notice">Silakan bayar ke petugas kasir. Setelah dikonfirmasi, mesin akan dinyalakan otomatis.</div>
      </div>
      <?php else: ?>

      <!-- ═════ STATE: IDLE (form booking) ═════ -->
      <div id="bookForm">
        <?php if (empty($cycles)): ?>
        <div class="notice">Mesin ini belum punya opsi cycle. Hubungi petugas outlet untuk setup.</div>
        <?php else: ?>

        <div class="section-title">1. Pilih Cycle</div>
        <div class="cycle-grid">
          <?php foreach ($cycles as $c): ?>
            <button class="cycle-btn" onclick="selectCycle(<?= $c['id'] ?>, <?= $c['durasi_menit'] ?>, <?= $c['tarif'] ?>, '<?= htmlspecialchars(addslashes($c['label']), ENT_QUOTES) ?>', this)">
              <div>
                <div class="cycle-label"><?= htmlspecialchars($c['label']) ?></div>
                <div class="cycle-durasi"><?= $c['durasi_menit'] ?> menit</div>
              </div>
              <div class="cycle-tarif">Rp <?= number_format($c['tarif'], 0, ',', '.') ?></div>
            </button>
          <?php endforeach; ?>
        </div>

        <div class="section-title" style="margin-top:24px">2. Data Kamu</div>
        <div class="form-group">
          <label class="form-label">Nama</label>
          <input type="text" id="f_nama" class="form-input" placeholder="Nama panggilan" maxlength="80"/>
        </div>
        <div class="form-group">
          <label class="form-label">No. WhatsApp (opsional)</label>
          <input type="tel" id="f_telepon" class="form-input" placeholder="0812-xxxx-xxxx" maxlength="20"/>
        </div>

        <div class="summary-box" id="summaryBox" style="display:none">
          <div class="summary-row"><span>Mesin</span><span id="sumMesin"></span></div>
          <div class="summary-row"><span>Cycle</span><span id="sumCycle"></span></div>
          <div class="summary-row"><span>Durasi</span><span id="sumDurasi"></span></div>
          <div class="summary-row total"><span>Total bayar</span><span id="sumTarif"></span></div>
        </div>

        <button class="btn" id="btnBook" disabled onclick="doBook()">🪙 Book & Bayar di Kasir</button>
        <p style="text-align:center;font-size:11px;color:#9CA3AF;margin-top:12px">Setelah klik Book, bayar ke kasir untuk konfirmasi mulai.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div>

    <script>
    const KODE = <?= json_encode($kode) ?>;
    let selectedCycle = null;

    function selectCycle(id, durasi, tarif, label, el) {
      selectedCycle = { id, durasi, tarif, label };
      document.querySelectorAll('.cycle-btn').forEach(b => b.classList.remove('selected'));
      el.classList.add('selected');
      document.getElementById('sumMesin').textContent  = <?= json_encode($mesin['nama']) ?>;
      document.getElementById('sumCycle').textContent  = label;
      document.getElementById('sumDurasi').textContent = durasi + ' menit';
      document.getElementById('sumTarif').textContent  = 'Rp ' + tarif.toLocaleString('id-ID');
      document.getElementById('summaryBox').style.display = 'block';
      checkSubmit();
    }
    function checkSubmit() {
      const nama = document.getElementById('f_nama').value.trim();
      document.getElementById('btnBook').disabled = !(selectedCycle && nama);
    }
    ['f_nama','f_telepon'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', checkSubmit);
    });

    async function doBook() {
      const btn = document.getElementById('btnBook');
      btn.disabled = true; btn.textContent = '⏳ Memproses...';
      const r = await fetch('/self.php?action=book', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          kode: KODE,
          cycle_id: selectedCycle.id,
          nama: document.getElementById('f_nama').value,
          telepon: document.getElementById('f_telepon').value,
        }),
      });
      const j = await r.json();
      if (j.error) {
        alert('❌ ' + j.error);
        btn.disabled = false; btn.textContent = '🪙 Book & Bayar di Kasir';
        return;
      }
      // Switch ke success screen
      document.getElementById('bookForm').style.display = 'none';
      document.getElementById('successScreen').style.display = 'block';
      // Auto-reload setelah 5 detik untuk show "booked" state
      setTimeout(() => location.reload(), 5000);
    }

    // Countdown updater for running state
    function updateCountdown() {
      document.querySelectorAll('.countdown[data-est]').forEach(el => {
        const est = new Date(el.dataset.est).getTime();
        const diff = Math.floor((est - Date.now()) / 1000);
        if (diff <= 0) {
          el.textContent = '00:00';
          el.classList.add('over');
        } else {
          const m = Math.floor(diff / 60);
          const s = diff % 60;
          el.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        }
      });
    }
    setInterval(updateCountdown, 1000);
    updateCountdown();

    // Auto-refresh tiap 30s kalau lagi di state booked/running (biar pas done langsung refresh)
    <?php if ($activeSesi): ?>
    setInterval(() => location.reload(), 30000);
    <?php endif; ?>
    </script>

  <?php endif; ?>

</div>
</body>
</html>
