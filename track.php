<?php
// ══════════════════════════════════════════════════════
// track.php — PUBLIC tracking page (no auth required)
//
// URL: track.php?order=<no_order>  ATAU  track.php?hp=<nomor>
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/StrukGenerator.php';

$db = Database::get();

// ── Resolve order ─────────────────────────────────────
$noOrder = trim($_GET['order'] ?? '');
$hp      = trim($_GET['hp'] ?? '');

$order = null;
$err   = '';
$outlet = null;
$items  = [];
$poinEarned = 0;

if ($noOrder) {
    try {
        $st = $db->prepare("SELECT t.*, o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_wa,
                                    o.qris_image, o.qris_label
                              FROM hl_transaksi t
                         LEFT JOIN outlets o ON o.id=t.outlet_id
                             WHERE (t.no_order=? OR t.offline_ref=?) LIMIT 1");
        $st->execute([$noOrder, $noOrder]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) $err = "Order *{$noOrder}* tidak ditemukan. Cek lagi nomor order ya.";
    } catch (Throwable $e) { $err = 'Gagal mengambil data.'; }
} elseif ($hp) {
    // Cari order terakhir by HP
    try {
        $clean = preg_replace('/[^0-9]/', '', $hp);
        if (str_starts_with($clean, '62')) $clean = '0' . substr($clean, 2);
        $st = $db->prepare("SELECT t.*, o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_wa,
                                    o.qris_image, o.qris_label
                              FROM hl_transaksi t
                         LEFT JOIN outlets o ON o.id=t.outlet_id
                             WHERE REPLACE(REPLACE(REPLACE(t.telepon,'-',''),' ',''),'+','') LIKE ?
                             ORDER BY t.id DESC LIMIT 1");
        $st->execute(['%' . $clean . '%']);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) $err = "Tidak ada order untuk nomor *{$hp}*. Cek lagi ya.";
    } catch (Throwable $e) { $err = 'Gagal mengambil data.'; }
}

if ($order) {
    try {
        $st = $db->prepare("SELECT nama_layanan, jumlah, satuan, harga_satuan, subtotal
                              FROM hl_transaksi_item WHERE transaksi_id=? ORDER BY id");
        $st->execute([$order['id']]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {}

    // Poin earned dari transaksi ini (kalau ada)
    try {
        $st = $db->prepare("SELECT poin, balance_after FROM hl_loyalty_log
                             WHERE transaksi_id=? AND type='earn' LIMIT 1");
        $st->execute([$order['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $poinEarned = (int)$row['poin'];
            $order['saldo_poin'] = (int)$row['balance_after'];
        }
    } catch (Throwable) {}
}

// Status pipeline
$pipeline = [
    'masuk'   => ['icon'=>'📥','label'=>'Masuk'],
    'cuci'    => ['icon'=>'🫧','label'=>'Cuci'],
    'kering'  => ['icon'=>'💨','label'=>'Kering'],
    'setrika' => ['icon'=>'👔','label'=>'Setrika'],
    'siap'    => ['icon'=>'✅','label'=>'Siap'],
];
$pipelineKeys = array_keys($pipeline);
$currentIdx = -1;
if ($order) {
    $st = $order['status_proses'] ?? '';
    if ($st === 'diambil' || $st === 'selesai') $currentIdx = count($pipelineKeys); // semua done
    else $currentIdx = array_search($st, $pipelineKeys, true);
    if ($currentIdx === false) $currentIdx = 0;
}

// Greeting
$jam = (int)date('H');
$greet = $jam < 11 ? 'Selamat pagi' : ($jam < 15 ? 'Selamat siang' : ($jam < 18 ? 'Selamat sore' : 'Selamat malam'));

// Section status antar (kalau ada hl_antar_jemput row untuk order ini)
if ($order) {
    try {
        $as = $db->prepare("SELECT aj.*, k.nama AS kurir_nama, k.no_hp AS kurir_hp
                              FROM hl_antar_jemput aj
                         LEFT JOIN hl_kurir k ON k.id = aj.kurir_id
                             WHERE aj.transaksi_id=? AND aj.tipe='antar'
                          ORDER BY aj.id DESC LIMIT 1");
        $as->execute([(int)$order['id']]);
        $antar = $as->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) { $antar = null; }
} else { $antar = null; }

// Cek bukti penyerahan (dari produksi stage 'diambil') — untuk display di status final
$bukti = null;
if ($order && in_array(($order['status_proses'] ?? ''), ['diambil','selesai'], true)) {
    try {
        $bs = $db->prepare("SELECT data_json, foto_paths, catatan, created_at
                              FROM hl_proses_input
                             WHERE transaksi_id=? AND stage='diambil'
                          ORDER BY id DESC LIMIT 1");
        $bs->execute([(int)$order['id']]);
        $bukti = $bs->fetch(PDO::FETCH_ASSOC);
        if ($bukti) {
            $bukti['data']  = json_decode($bukti['data_json'] ?: '{}', true) ?: [];
            $bukti['fotos'] = array_filter(array_map('trim', explode(',', $bukti['foto_paths'] ?? '')));
        }
    } catch (Throwable) { $bukti = null; }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Track Cucian <?= $order ? '— ' . htmlspecialchars($order['no_order']) : '' ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
       background: linear-gradient(135deg, #0F1C3A 0%, #1E3A8A 50%, #312E81 100%);
       min-height: 100vh; padding: 20px; color: #1E293B; }
.wrap { max-width: 480px; margin: 0 auto; }
.brand { text-align: center; color: #fff; margin-bottom: 20px }
.brand h1 { font-size: 1.4rem; font-weight: 800; letter-spacing: -.02em }
.brand p { font-size: 13px; opacity: .8; margin-top: 4px }
.card { background: #fff; border-radius: 18px; padding: 22px 20px; box-shadow: 0 10px 40px rgba(0,0,0,.18); margin-bottom: 14px }

.search-box { display:flex; gap:6px; margin-top:12px }
.search-box input { flex:1; padding:11px 14px; font-size:14px; border:1.5px solid #E2E8F0; border-radius:10px; outline:none; transition:border .15s }
.search-box input:focus { border-color: #35E8D5 }
.search-box button { padding:11px 18px; background:linear-gradient(135deg,#35E8D5,#0891B2); color:#fff; border:none; border-radius:10px; font-weight:700; font-size:14px; cursor:pointer }

.head-info { margin-bottom:18px }
.head-info .no-order { font-family: 'SF Mono', Monaco, monospace; font-size:13px; color:#0F766E; background:#F0FDFB; padding:3px 9px; border-radius:7px; display:inline-block; margin-bottom:8px }
.head-info .greet { font-size:1.3rem; font-weight:800; color:#0F1C3A }
.head-info .sub { font-size:13px; color:#64748B; margin-top:3px }

.pipeline { display:flex; align-items:center; justify-content:space-between; margin:24px 0 8px; position:relative }
.pipeline::before { content:''; position:absolute; top:18px; left:18px; right:18px; height:2px; background:#E2E8F0; z-index:0 }
.pl-step { position:relative; z-index:1; display:flex; flex-direction:column; align-items:center; flex:1 }
.pl-dot  { width:36px; height:36px; border-radius:50%; background:#F1F5F9; color:#94A3B8; display:flex; align-items:center; justify-content:center; font-size:16px; border:2px solid #fff; box-shadow:0 0 0 1px #E2E8F0 }
.pl-step.done .pl-dot, .pl-step.active .pl-dot { background:linear-gradient(135deg,#35E8D5,#06B6D4); color:#fff; box-shadow:0 0 0 1px #0891B2 }
.pl-step.active .pl-dot { animation: pulse 1.5s ease-in-out infinite }
@keyframes pulse { 0%,100% { transform:scale(1) } 50% { transform:scale(1.12); box-shadow:0 0 0 6px rgba(53,232,213,.25) } }
.pl-lbl { font-size:10px; margin-top:5px; color:#94A3B8; font-weight:600 }
.pl-step.done .pl-lbl, .pl-step.active .pl-lbl { color:#0F1C3A }

.countdown { background:linear-gradient(90deg,#FEF3C7,#FED7AA); border-radius:12px; padding:11px 14px; text-align:center; margin:12px 0; color:#92400E; font-size:13px; font-weight:600 }
.countdown.danger { background:linear-gradient(90deg,#FEE2E2,#FECACA); color:#991B1B }
.countdown.success { background:linear-gradient(90deg,#D1FAE5,#A7F3D0); color:#065F46 }
.countdown .timer { font-family: 'SF Mono', Monaco, monospace; font-size:1.1rem; font-weight:800; display:block; margin-top:2px }

.detail-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px dashed #E2E8F0; font-size:14px }
.detail-row:last-child { border-bottom:0 }
.detail-row .lbl { color:#64748B }
.detail-row .val { font-weight:600; color:#0F1C3A }

.poin-box { background:linear-gradient(135deg,#F0FDFB,#ECFDF5); border:1px solid #B6F0E6; border-radius:12px; padding:12px 14px; margin-top:10px; font-size:13px; color:#0F766E; text-align:center }
.poin-box .big { font-size:1.4rem; font-weight:800; color:#0F1C3A; margin:4px 0 }

.outlet-box { background:#F8FAFC; border-radius:12px; padding:12px 14px; margin-top:14px; font-size:13px }
.outlet-box .row { display:flex; gap:8px; align-items:flex-start; margin-bottom:6px }
.outlet-box .row:last-child { margin:0 }
.outlet-box a { color:#0891B2; text-decoration:none; font-weight:600 }

.wa-btn { display:flex; align-items:center; justify-content:center; gap:8px; background:#25D366; color:#fff; padding:13px 20px; border-radius:12px; text-decoration:none; font-weight:700; font-size:15px; margin-top:12px; box-shadow:0 4px 14px rgba(37,211,102,.35) }

.error-box { background:#FEE2E2; border-left:4px solid #DC2626; padding:14px 16px; border-radius:10px; color:#991B1B; font-size:14px }

.brand-footer { text-align:center; color:#fff; opacity:.5; font-size:11px; margin-top:18px }
</style>
</head>
<body>
<div class="wrap">

  <div class="brand">
    <h1>🧺 Track Cucian Kamu</h1>
    <p>Lacak status laundry real-time</p>
  </div>

  <?php if (!$order): ?>
    <!-- FORM PENCARIAN -->
    <div class="card">
      <?php if ($err): ?>
        <div class="error-box"><?= htmlspecialchars($err) ?></div>
      <?php else: ?>
        <div style="font-size:14px;color:#1E293B;margin-bottom:8px">
          Masukkan <strong>nomor order</strong> atau <strong>nomor HP</strong> kamu:
        </div>
      <?php endif; ?>
      <form method="GET" action="track.php" class="search-box" style="margin-top:14px">
        <input type="text" name="order" placeholder="HL-20251015-001 atau 0812xxx" autofocus
               value="<?= htmlspecialchars($noOrder ?: $hp) ?>"/>
        <button type="submit">Cari</button>
      </form>
      <div style="font-size:11px;color:#94A3B8;margin-top:8px;text-align:center">
        Tip: Cek struk untuk nomor order
      </div>
    </div>
  <?php else: ?>

    <!-- DETAIL ORDER -->
    <div class="card">
      <div class="head-info">
        <span class="no-order"><?= htmlspecialchars($order['no_order']) ?></span>
        <div class="greet"><?= $greet ?>, <?= htmlspecialchars($order['nama_pelanggan']) ?>! 👋</div>
        <?php if (in_array($order['status_proses'], ['siap','diambil','selesai'], true)): ?>
          <?php if ($order['status_proses'] === 'siap'): ?>
            <div class="sub" style="color:#065F46;font-weight:700;margin-top:6px">🎉 Cucian kamu SUDAH SELESAI dan siap diambil!</div>
          <?php else: ?>
            <div class="sub" style="color:#065F46;margin-top:6px">✅ Order sudah diambil. Terima kasih sudah laundry di sini!</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="sub">Status cucian kamu saat ini:</div>
        <?php endif; ?>
      </div>

      <!-- PIPELINE -->
      <div class="pipeline">
        <?php foreach ($pipelineKeys as $i => $key): $p = $pipeline[$key];
          $cls = $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'active' : '');
        ?>
          <div class="pl-step <?= $cls ?>">
            <div class="pl-dot"><?= $p['icon'] ?></div>
            <div class="pl-lbl"><?= $p['label'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- COUNTDOWN -->
      <?php if (in_array($order['status_proses'], ['masuk','cuci','kering','setrika'], true) && !empty($order['estimasi_selesai'])): ?>
        <?php $estTs = strtotime($order['estimasi_selesai']); ?>
        <div class="countdown" id="cd" data-est="<?= $estTs ?>">
          ⏱️ Estimasi selesai: <strong><?= date('d M, H:i', $estTs) ?></strong>
          <span class="timer" id="cdTimer">--</span>
        </div>
      <?php elseif ($order['status_proses'] === 'siap'): ?>
        <div class="countdown success">
          🎉 Yuk diambil! Cucian sudah rapi menunggu kamu.
        </div>
      <?php elseif ($order['status_proses'] === 'diambil' || $order['status_proses'] === 'selesai'): ?>
        <?php
          $jenis = $bukti['data']['jenis'] ?? 'ambil_sendiri';
          $tglSelesai = !empty($order['tgl_selesai']) ? date('d M Y', strtotime($order['tgl_selesai'])) : (!empty($bukti['created_at']) ? date('d M Y', strtotime($bukti['created_at'])) : '-');
        ?>
        <div class="countdown success">
          <?php if ($jenis === 'diantarkan'): ?>
            🛵 Cucian sudah diantar pada <?= htmlspecialchars($tglSelesai) ?>. Terima kasih!
          <?php else: ?>
            ✅ Order sudah diambil pada <?= htmlspecialchars($tglSelesai) ?>
          <?php endif; ?>
        </div>
        <?php if (!empty($bukti['fotos'])): ?>
        <div style="margin-top:14px;padding:14px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:12px">
          <div style="font-size:13px;font-weight:700;color:#166534;margin-bottom:8px">
            📸 Bukti <?= $jenis === 'diantarkan' ? 'antar' : 'serah terima' ?>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php foreach ($bukti['fotos'] as $fp): if (!str_starts_with($fp, 'uploads/foto_proses/')) continue; ?>
              <a href="/<?= htmlspecialchars($fp) ?>" target="_blank">
                <img src="/<?= htmlspecialchars($fp) ?>" alt="Bukti" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #BBF7D0">
              </a>
            <?php endforeach; ?>
          </div>
          <?php if (!empty($bukti['catatan'])): ?>
            <div style="margin-top:8px;font-size:12.5px;color:#166534">📝 <?= htmlspecialchars($bukti['catatan']) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- DETAIL ORDER -->
      <div style="margin-top:14px">
        <?php if ($items): ?>
        <div class="detail-row">
          <span class="lbl">Layanan</span>
          <span class="val" style="text-align:right;max-width:60%">
            <?php foreach ($items as $it): ?>
              <?= htmlspecialchars($it['nama_layanan']) ?> (<?= floatval($it['jumlah']) ?> <?= htmlspecialchars($it['satuan']) ?>)<br>
            <?php endforeach; ?>
          </span>
        </div>
        <?php endif; ?>
        <div class="detail-row">
          <span class="lbl">Total</span>
          <span class="val">Rp <?= number_format((float)$order['total'], 0, ',', '.') ?></span>
        </div>
        <?php if ((float)$order['sisa_bayar'] > 0): ?>
        <div class="detail-row">
          <span class="lbl">Sisa Bayar</span>
          <span class="val" style="color:#DC2626">Rp <?= number_format((float)$order['sisa_bayar'], 0, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <div class="detail-row">
          <span class="lbl">Pembayaran</span>
          <span class="val">
            <?= ['lunas'=>'✅ Lunas','dp'=>'⚡ DP','belum_bayar'=>'⏳ Bayar saat ambil'][$order['status_bayar']] ?? $order['status_bayar'] ?>
          </span>
        </div>
      </div>

      <?php
        $trackTmpl = StrukGenerator::loadTemplate((int)$order['tenant_id'], (int)$order['outlet_id'], 'retail');
        $trackAid  = StrukGenerator::paymentAidFor($order, $trackTmpl, $order);
      ?>
      <?php if ($trackAid): ?>
      <div style="margin-top:14px;padding:14px 16px;background:#F0FDF4;border:1px solid #86EFAC;border-radius:12px;text-align:center">
        <?php if ($trackAid['type'] === 'qris'): ?>
          <img src="<?= htmlspecialchars($trackAid['image']) ?>" alt="QRIS"
               style="max-width:200px;width:100%;border-radius:8px;margin-bottom:8px">
          <?php if (!empty($trackAid['label'])): ?>
            <div style="font-size:13px;color:#166534;margin-bottom:4px"><?= htmlspecialchars($trackAid['label']) ?></div>
          <?php endif; ?>
          <div style="font-weight:700;color:#166534">Sisa Bayar: Rp <?= number_format($trackAid['sisa_bayar'], 0, ',', '.') ?></div>
          <div style="font-size:12px;color:#166534;margin-top:4px">Scan lalu masukkan nominal di atas secara manual</div>
        <?php else: ?>
          <div style="font-weight:700;color:#166534">Transfer ke <?= htmlspecialchars($trackAid['bank']) ?> — <?= htmlspecialchars($trackAid['nomor']) ?></div>
          <?php if (!empty($trackAid['atas_nama'])): ?>
            <div style="font-size:13px;color:#166534">a.n. <?= htmlspecialchars($trackAid['atas_nama']) ?></div>
          <?php endif; ?>
          <div style="font-weight:700;color:#166534;margin-top:4px">Sisa Bayar: Rp <?= number_format($trackAid['sisa_bayar'], 0, ',', '.') ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- POIN INFO -->
      <?php if ($poinEarned > 0): ?>
        <div class="poin-box">
          🌟 Kamu dapat <strong><?= $poinEarned ?> poin</strong> dari transaksi ini!
          <div class="big">Saldo: <?= number_format((int)($order['saldo_poin'] ?? 0)) ?> poin</div>
          <small>Tukar poin untuk diskon di transaksi berikutnya!</small>
        </div>
      <?php endif; ?>

      <?php if (!empty($antar) && in_array($antar['status'], ['assigned','menuju','sampai','done'], true)): ?>
      <div style="margin-top:14px;padding:14px 16px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px">
        <div style="font-weight:700;color:#1E40AF;margin-bottom:6px">🛵 Status Antar</div>
        <?php if (!empty($antar['kurir_nama'])): ?>
          <div style="font-size:13.5px">Kurir: <strong><?= htmlspecialchars($antar['kurir_nama']) ?></strong>
          <?php if ($antar['kurir_hp']): ?> · <a href="tel:<?= htmlspecialchars($antar['kurir_hp']) ?>" style="color:#1E40AF"><?= htmlspecialchars($antar['kurir_hp']) ?></a><?php endif; ?>
          </div>
        <?php endif; ?>
        <div style="font-size:13px;margin-top:4px;color:#1E40AF">Status: <strong>
          <?php
            $stMap = ['assigned'=>'Kurir di-assign','menuju'=>'Dalam perjalanan','sampai'=>'Sampai lokasi','done'=>'Selesai diantar'];
            echo htmlspecialchars($stMap[$antar['status']] ?? $antar['status']);
          ?></strong> · <?= !empty($antar['updated_at']) ? date('H:i', strtotime($antar['updated_at'])) : '' ?></div>
      </div>
      <?php endif; ?>

      <!-- OUTLET INFO -->
      <?php if (!empty($order['nama_outlet'])): ?>
        <div class="outlet-box">
          <div class="row">
            <span>📍</span>
            <div>
              <strong><?= htmlspecialchars($order['nama_outlet']) ?></strong>
              <?php if (!empty($order['outlet_alamat'])): ?>
                <div style="font-size:12px;color:#64748B;margin-top:2px"><?= htmlspecialchars($order['outlet_alamat']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php
          $waOutlet = preg_replace('/[^0-9]/', '', $order['outlet_wa'] ?? '');
          if ($waOutlet) {
              if (str_starts_with($waOutlet, '0')) $waOutlet = '62' . substr($waOutlet, 1);
              elseif (!str_starts_with($waOutlet, '62')) $waOutlet = '62' . $waOutlet;
              $waMsg = "Halo, saya mau tanya order *" . $order['no_order'] . "*";
        ?>
          <a class="wa-btn" href="https://wa.me/<?= $waOutlet ?>?text=<?= urlencode($waMsg) ?>" target="_blank">
            💬 Hubungi Outlet via WhatsApp
          </a>
        <?php } ?>
      <?php endif; ?>
    </div>

    <!-- BACK -->
    <div style="text-align:center;margin-top:10px">
      <a href="/track" style="color:#fff;opacity:.7;font-size:12px;text-decoration:none">← Cari order lain</a>
    </div>
  <?php endif; ?>

  <div class="brand-footer">Powered by Harpy Laundry System</div>
</div>

<?php if ($order && in_array($order['status_proses'] ?? '', ['masuk','cuci','kering','setrika'], true)): ?>
<script>
const cd = document.getElementById('cd');
if (cd) {
  const est = parseInt(cd.dataset.est) * 1000;
  const timer = document.getElementById('cdTimer');
  function tick(){
    const diff = est - Date.now();
    const abs  = Math.abs(diff);
    const h = Math.floor(abs / 3600000);
    const m = Math.floor((abs % 3600000) / 60000);
    if (diff < 0) {
      cd.classList.remove('success');
      cd.classList.add('danger');
      timer.textContent = '⚠️ TERLAMBAT ' + h + ' jam ' + m + ' menit';
    } else {
      timer.textContent = '⏳ Sisa: ' + h + ' jam ' + m + ' menit';
    }
  }
  tick();
  setInterval(tick, 30000);
}
</script>
<?php endif; ?>
</body>
</html>
