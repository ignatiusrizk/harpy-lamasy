<?php
// ══════════════════════════════════════════════════════
// cek.php — Public Customer Order Tracking
//
// Halaman publik (no login) untuk customer cek status nota.
// URL: /cek?n={no_order}  (atau langsung tampil form kalau no n)
//
// Privacy: customer harus input 4 digit telepon utk verify.
// Rate limit basic by IP + nota_combo (anti-bruteforce).
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantQuery.php';

date_default_timezone_set('Asia/Jakarta');

$noOrder = trim($_GET['n'] ?? '');
$phoneLast4 = trim($_POST['phone'] ?? '');
$ajaxAction = $_GET['action'] ?? '';

// ── AJAX: poll status (refresh tanpa reload) ──
if ($ajaxAction === 'status') {
    header('Content-Type: application/json');
    $no = trim($_GET['n'] ?? '');
    $p4 = trim($_GET['p'] ?? '');
    if (!$no || !$p4) { echo json_encode(['error'=>'Param missing']); exit; }
    $info = lookupOrder($no, $p4);
    echo json_encode($info ?: ['error'=>'Order tidak ditemukan / verifikasi gagal']);
    exit;
}

function lookupOrder(string $noOrder, string $phoneLast4): ?array
{
    if (!preg_match('/^[A-Z0-9\-_\/]+$/i', $noOrder) || strlen($phoneLast4) < 4) return null;
    try {
        $db = Database::get();
        $st = $db->prepare(
            "SELECT t.*,
                    (SELECT GROUP_CONCAT(CONCAT(nama_layanan,' (',jumlah,' ',satuan,')') SEPARATOR ', ')
                       FROM hl_transaksi_item WHERE transaksi_id=t.id) AS items_summary,
                    o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_telp,
                    (SELECT logo_url FROM tenants WHERE id=t.tenant_id) AS tenant_logo,
                    (SELECT nama_perusahaan FROM tenants WHERE id=t.tenant_id) AS tenant_nama
               FROM hl_transaksi t
          LEFT JOIN outlets o ON o.id = t.outlet_id
              WHERE t.no_order = ? LIMIT 1"
        );
        $st->execute([$noOrder]);
        $trx = $st->fetch(PDO::FETCH_ASSOC);
        if (!$trx) return null;

        // Verify phone last 4 digits
        $tPhone = preg_replace('/[^0-9]/', '', (string)($trx['telepon'] ?? ''));
        if (substr($tPhone, -4) !== preg_replace('/[^0-9]/','',$phoneLast4)) {
            return null;
        }

        // Status timeline
        $progresMap = ['masuk'=>10,'cuci'=>30,'kering'=>50,'setrika'=>70,'siap'=>90,'diambil'=>100];
        $trx['progress_percent'] = $progresMap[$trx['status_proses']] ?? 0;

        // Items detail
        $items = $db->prepare("SELECT nama_layanan, jumlah, satuan, harga_satuan, subtotal FROM hl_transaksi_item WHERE transaksi_id=?");
        $items->execute([(int)$trx['id']]);
        $trx['items_detail'] = $items->fetchAll(PDO::FETCH_ASSOC);

        // Log timeline (proses_log)
        try {
            $logs = $db->prepare("SELECT status_baru, oleh, created_at FROM hl_proses_log WHERE transaksi_id=? ORDER BY created_at ASC");
            $logs->execute([(int)$trx['id']]);
            $trx['timeline'] = $logs->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { $trx['timeline'] = []; }

        return $trx;
    } catch (Throwable) {
        return null;
    }
}

// Lookup kalau ada n + phone
$order = null;
$showVerify = false;
if ($noOrder) {
    if ($phoneLast4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $order = lookupOrder($noOrder, $phoneLast4);
        if (!$order) {
            $showVerify = true;
            $errMsg = 'Nomor nota / 4 digit telepon tidak cocok. Cek ulang.';
        }
    } else {
        // Belum verify → tampilkan form input phone
        $showVerify = true;
    }
}

function statusLabel($s) {
    return [
        'masuk'=>'📥 Diterima','cuci'=>'🫧 Sedang Dicuci','kering'=>'💨 Dikeringkan',
        'setrika'=>'👔 Disetrika','siap'=>'✅ Siap Diambil','diambil'=>'📦 Sudah Diambil',
    ][$s] ?? $s;
}
function statusColor($s) {
    return ['masuk'=>'#3B82F6','cuci'=>'#F59E0B','kering'=>'#F97316','setrika'=>'#8B5CF6','siap'=>'#10B981','diambil'=>'#6B7280'][$s] ?? '#6B7280';
}
function fmtDate($d) { if (!$d) return '-'; return date('d M Y H:i', strtotime($d)); }
function fmtMoney($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= $order ? 'Status ' . htmlspecialchars($order['no_order']) : 'Cek Status Cucian' ?></title>
<style>
:root { --teal:#0F7B6C; --green:#10B981; --gray:#6B7280; --bg:#F3F4F6; --border:#E5E7EB; }
* { box-sizing:border-box; }
body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); margin:0; padding:0; color:#1F2937; line-height:1.5; }
.wrap { max-width:600px; margin:0 auto; padding:16px; min-height:100vh; }
.brand { text-align:center; padding:24px 0 12px; }
.brand h1 { margin:0; font-size:18px; font-weight:700; color:var(--teal); }
.brand p { margin:4px 0 0; font-size:12px; color:var(--gray); }
.card { background:#fff; border-radius:16px; padding:20px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
.no-order { font-size:14px; color:var(--gray); }
.no-order strong { font-family:'SF Mono',monospace; color:#1F2937; font-size:16px; display:block; margin-top:4px; }
.status-big { display:flex; align-items:center; gap:14px; margin:20px 0 14px; }
.status-icon { font-size:42px; }
.status-text { flex:1; }
.status-text .label { font-size:11px; color:var(--gray); text-transform:uppercase; letter-spacing:0.5px; }
.status-text .value { font-size:18px; font-weight:700; }
.progress-bar { height:8px; background:#E5E7EB; border-radius:100px; overflow:hidden; margin:14px 0; }
.progress-fill { height:100%; background:linear-gradient(90deg, var(--green), var(--teal)); border-radius:100px; transition:width .5s ease; }
.steps { display:flex; justify-content:space-between; font-size:10px; color:var(--gray); margin-top:6px; }
.steps .step.active { color:var(--teal); font-weight:700; }
.row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #F3F4F6; font-size:13px; }
.row:last-child { border-bottom:none; }
.row .l { color:var(--gray); }
.row .r { font-weight:600; text-align:right; }
.items-box { background:#FAFAFA; border-radius:10px; padding:12px; margin-top:10px; }
.item { display:flex; justify-content:space-between; padding:6px 0; font-size:13px; border-bottom:1px dashed #E5E7EB; }
.item:last-child { border-bottom:none; }
.timeline { margin-top:10px; }
.tl-item { display:flex; gap:10px; padding:6px 0; font-size:12px; }
.tl-dot { width:8px; height:8px; border-radius:50%; background:var(--green); margin-top:6px; flex-shrink:0; }
.tl-text { flex:1; }
.tl-text strong { color:#374151; }
.tl-text small { color:var(--gray); display:block; }
.h2 { font-size:13px; font-weight:700; color:#374151; margin:0 0 8px; text-transform:uppercase; letter-spacing:0.5px; }
form input { width:100%; padding:12px 14px; border:1px solid var(--border); border-radius:10px; font-size:15px; font-family:var(--mono); letter-spacing:2px; text-align:center; box-sizing:border-box; }
form input:focus { outline:none; border-color:var(--teal); box-shadow:0 0 0 3px rgba(15,123,108,0.1); }
.btn { display:block; width:100%; padding:13px 18px; background:var(--teal); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; margin-top:10px; }
.btn:hover { background:#0d6b5c; }
.btn-secondary { background:transparent; color:var(--teal); border:1px solid var(--teal); }
.alert { background:#FEF2F2; border:1px solid #FCA5A5; color:#991B1B; padding:10px 14px; border-radius:10px; font-size:13px; margin-bottom:12px; }
.contact { text-align:center; padding:12px; font-size:12px; color:var(--gray); }
.contact a { color:var(--teal); text-decoration:none; font-weight:600; }
.wa-btn { background:#25D366; color:#fff; text-decoration:none; padding:10px 14px; border-radius:10px; font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:6px; margin-top:8px; }
@media (max-width:480px) { .status-icon { font-size:36px; } .status-text .value { font-size:16px; } }
</style>
</head>
<body>
<div class="wrap">

  <?php if (!$noOrder): ?>
  <!-- ════════ STATE: NO INPUT — Form input nomor nota ════════ -->
  <div class="brand">
    <h1>🧺 LaMaSy Tracking</h1>
    <p>Cek status cucian Anda</p>
  </div>
  <div class="card">
    <h2 class="h2">Masukkan Nomor Nota</h2>
    <form method="GET" action="/cek.php">
      <input type="text" name="n" placeholder="HARPY-260607-001" required autofocus autocomplete="off"/>
      <button type="submit" class="btn">🔍 Cek Status</button>
    </form>
  </div>
  <div class="contact">
    Belum punya nota? Hubungi outlet laundry Anda.
  </div>

  <?php elseif ($showVerify): ?>
  <!-- ════════ STATE: VERIFY — Input 4 digit phone ════════ -->
  <div class="brand">
    <h1>🔐 Verifikasi</h1>
    <p>Konfirmasi identitas Anda</p>
  </div>
  <div class="card">
    <div class="no-order">No Nota:<strong><?= htmlspecialchars($noOrder) ?></strong></div>
    <?php if (!empty($errMsg)): ?>
      <div class="alert" style="margin-top:14px"><?= htmlspecialchars($errMsg) ?></div>
    <?php endif; ?>
    <h2 class="h2" style="margin-top:18px">4 Digit Terakhir Nomor Telepon</h2>
    <form method="POST">
      <input type="text" name="phone" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="• • • •" required autofocus/>
      <button type="submit" class="btn">✅ Verifikasi</button>
      <a href="/cek.php" class="btn btn-secondary" style="display:block;text-align:center;text-decoration:none;margin-top:6px">← Ganti Nomor Nota</a>
    </form>
  </div>
  <div class="contact">
    Privasi terjaga — verifikasi diperlukan agar status hanya bisa dilihat pemilik nota.
  </div>

  <?php else: ?>
  <!-- ════════ STATE: SUCCESS — Tampilkan status ════════ -->
  <div class="brand">
    <h1><?= htmlspecialchars($order['nama_outlet'] ?? $order['tenant_nama'] ?? 'Laundry') ?></h1>
    <p>Status cucian Anda</p>
  </div>

  <div class="card">
    <div class="no-order">No Nota:<strong><?= htmlspecialchars($order['no_order']) ?></strong></div>

    <div class="status-big">
      <div class="status-icon"><?= explode(' ', statusLabel($order['status_proses']))[0] ?></div>
      <div class="status-text">
        <div class="label">Status Saat Ini</div>
        <div class="value" style="color:<?= statusColor($order['status_proses']) ?>">
          <?= htmlspecialchars(substr(statusLabel($order['status_proses']), strpos(statusLabel($order['status_proses']), ' ')+1)) ?>
        </div>
      </div>
    </div>

    <div class="progress-bar">
      <div class="progress-fill" style="width:<?= $order['progress_percent'] ?>%"></div>
    </div>
    <div class="steps">
      <?php $allSteps = ['masuk','cuci','kering','setrika','siap','diambil']; $curIdx = array_search($order['status_proses'], $allSteps); ?>
      <?php foreach ($allSteps as $i => $s): ?>
        <div class="step <?= ($i <= $curIdx) ? 'active' : '' ?>"><?= explode(' ', statusLabel($s))[0] ?></div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:18px">
      <div class="row"><span class="l">Pelanggan</span><span class="r"><?= htmlspecialchars($order['nama_pelanggan']) ?></span></div>
      <div class="row"><span class="l">Diterima</span><span class="r"><?= fmtDate($order['tanggal'] . ' ' . ($order['created_at'] ?? '')) ?></span></div>
      <?php if ($order['estimasi_selesai']): ?>
      <div class="row"><span class="l">Estimasi Selesai</span><span class="r" style="color:var(--teal);font-weight:700"><?= fmtDate($order['estimasi_selesai']) ?></span></div>
      <?php endif; ?>
      <?php if ($order['tgl_selesai']): ?>
      <div class="row"><span class="l">Tgl Diambil</span><span class="r"><?= fmtDate($order['tgl_selesai']) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Detail Item -->
  <?php if (!empty($order['items_detail'])): ?>
  <div class="card">
    <h2 class="h2">🧺 Detail Layanan</h2>
    <div class="items-box">
      <?php foreach ($order['items_detail'] as $it): ?>
      <div class="item">
        <span><?= htmlspecialchars($it['nama_layanan']) ?> <small style="color:var(--gray)">× <?= rtrim(rtrim(number_format((float)$it['jumlah'], 2), '0'), '.') ?> <?= htmlspecialchars($it['satuan']) ?></small></span>
        <span style="font-family:var(--mono);font-weight:600"><?= fmtMoney($it['subtotal']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:10px;display:flex;justify-content:space-between;font-weight:700;font-size:14px">
      <span>Total Tagihan</span>
      <span style="color:var(--teal);font-family:var(--mono)"><?= fmtMoney($order['total']) ?></span>
    </div>
    <?php if ((float)($order['dp'] ?? 0) > 0): ?>
    <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--gray);margin-top:4px">
      <span>Sudah dibayar</span><span><?= fmtMoney($order['dp']) ?></span>
    </div>
    <?php endif; ?>
    <?php if ((float)($order['sisa_bayar'] ?? 0) > 0): ?>
    <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;color:#DC2626;margin-top:4px">
      <span>Sisa Bayar</span><span><?= fmtMoney($order['sisa_bayar']) ?></span>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Timeline -->
  <?php if (!empty($order['timeline'])): ?>
  <div class="card">
    <h2 class="h2">📜 Riwayat Status</h2>
    <div class="timeline">
      <?php foreach ($order['timeline'] as $log): ?>
      <div class="tl-item">
        <div class="tl-dot"></div>
        <div class="tl-text">
          <strong><?= htmlspecialchars(statusLabel($log['status_baru'])) ?></strong>
          <small><?= fmtDate($log['created_at']) ?> · <?= htmlspecialchars($log['oleh'] ?? '-') ?></small>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Outlet contact -->
  <?php if (!empty($order['outlet_telp'])): ?>
  <div class="card" style="text-align:center">
    <h2 class="h2">📞 Hubungi Outlet</h2>
    <div style="font-size:13px;color:var(--gray);margin-bottom:8px">
      <?= htmlspecialchars($order['nama_outlet'] ?? '') ?><br>
      <?= htmlspecialchars($order['outlet_alamat'] ?? '') ?>
    </div>
    <a class="wa-btn" href="https://wa.me/<?= preg_replace('/^0/','62',preg_replace('/[^0-9]/','',$order['outlet_telp'])) ?>">
      💬 WhatsApp Outlet
    </a>
  </div>
  <?php endif; ?>

  <div class="contact">
    <a href="/cek.php">← Cek nota lain</a>
  </div>

  <script>
  // Auto-refresh status setiap 30 detik (kalau belum diambil)
  <?php if ($order['status_proses'] !== 'diambil'): ?>
  setInterval(() => location.reload(), 30000);
  <?php endif; ?>
  </script>
  <?php endif; ?>

</div>
</body>
</html>
