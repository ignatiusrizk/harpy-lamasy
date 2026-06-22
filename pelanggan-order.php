<?php
// pelanggan-order.php — Detail order untuk pelanggan login

define('ROOT', __DIR__);
require_once ROOT . '/middleware/pelanggan_guard.php';

requirePelangganLogin();
$pel = currentPelanggan();
$db = Database::get();

$noOrder = trim($_GET['o'] ?? '');
if (!$noOrder) { header('Location: /pelanggan'); exit; }

// Validate format
if (!preg_match('/^[A-Z0-9\-\/]{3,30}$/i', $noOrder)) {
    http_response_code(400);
    die('No order tidak valid');
}

// Load order — MUST belong to current pelanggan_id
$st = $db->prepare(
    "SELECT t.*, o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_wa
       FROM hl_transaksi t
  LEFT JOIN outlets o ON o.id = t.outlet_id
      WHERE t.no_order=? AND t.pelanggan_id=? LIMIT 1"
);
$st->execute([$noOrder, (int)$pel['id']]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(403);
    die('<!doctype html><meta charset=utf-8><div style="font-family:sans-serif;padding:40px;text-align:center"><h2>Tidak ditemukan</h2><p>Order tidak ada atau bukan milik akun Anda.</p><a href="/pelanggan">← Kembali ke portal</a></div>');
}

// Load items
$items = [];
try {
    $st = $db->prepare("SELECT nama_layanan, jumlah, satuan, harga_satuan, subtotal FROM hl_transaksi_item WHERE transaksi_id=? ORDER BY id");
    $st->execute([$order['id']]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

// Load antar info kalau ada (reuse query dari track.php)
$antar = null;
try {
    $as = $db->prepare("SELECT aj.*, k.nama AS kurir_nama, k.no_hp AS kurir_hp FROM hl_antar_jemput aj LEFT JOIN hl_kurir k ON k.id = aj.kurir_id WHERE aj.transaksi_id=? AND aj.tipe='antar' ORDER BY aj.id DESC LIMIT 1");
    $as->execute([(int)$order['id']]);
    $antar = $as->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {}

// Load bukti dari produksi
$bukti = null;
if (in_array(($order['status_proses'] ?? ''), ['diambil','selesai'], true)) {
    try {
        $bs = $db->prepare("SELECT data_json, foto_paths, catatan, created_at FROM hl_proses_input WHERE transaksi_id=? AND stage='diambil' ORDER BY id DESC LIMIT 1");
        $bs->execute([(int)$order['id']]);
        $bukti = $bs->fetch(PDO::FETCH_ASSOC);
        if ($bukti) {
            $bukti['data']  = json_decode($bukti['data_json'] ?: '{}', true) ?: [];
            $bukti['fotos'] = array_filter(array_map('trim', explode(',', $bukti['foto_paths'] ?? '')));
        }
    } catch (Throwable) { $bukti = null; }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0F1C3A">
<link rel="manifest" href="/assets/manifest.json">
<title>#<?= htmlspecialchars($order['no_order']) ?> — Portal</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#F8FAFC;min-height:100vh;padding:16px;color:#1E293B}
.wrap{max-width:520px;margin:0 auto}
.back{display:inline-block;color:#64748B;text-decoration:none;font-size:13px;margin-bottom:14px}
.card{background:#fff;border-radius:14px;padding:16px 18px;margin-bottom:12px;box-shadow:0 2px 10px rgba(15,28,58,.06)}
.no-order{font-family:var(--mono,monospace);background:#F0FDFB;color:#0F766E;padding:3px 9px;border-radius:7px;display:inline-block;font-size:13px;margin-bottom:8px}
.status-pill{display:inline-block;padding:4px 12px;border-radius:100px;font-size:12.5px;font-weight:600;background:#DBEAFE;color:#1E40AF}
.row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #F1F5F9}
.row:last-child{border-bottom:none}
.row .l{color:#64748B;font-size:13px}
.row .v{font-weight:600;font-size:13.5px}
.total{font-size:18px;font-weight:800;color:#0F766E}
.item{padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:13.5px}
.item:last-child{border-bottom:none}
.item .nm{font-weight:600}
.item .meta{color:#64748B;font-size:12px;margin-top:2px}
.bukti{margin-top:10px;padding:12px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px}
.bukti .lbl{font-size:12px;font-weight:700;color:#166534;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
.bukti img{width:120px;height:120px;object-fit:cover;border-radius:8px;margin-right:6px;margin-bottom:6px;border:1px solid #BBF7D0}
</style>
</head>
<body>
<div class="wrap">
  <a href="/pelanggan" class="back">← Kembali ke Portal</a>

  <div class="card">
    <div class="no-order">#<?= htmlspecialchars($order['no_order']) ?></div>
    <div style="margin-top:6px"><span class="status-pill"><?= htmlspecialchars($order['status_proses']) ?></span></div>
    <div style="margin-top:10px;font-size:13px;color:#64748B"><?= htmlspecialchars($order['nama_outlet'] ?? '') ?> · <?= date('d M Y', strtotime($order['tanggal'])) ?></div>
    <?php if (!empty($order['estimasi_selesai'])): ?>
      <div style="margin-top:4px;font-size:13px;color:#64748B">Estimasi: <?= date('d M Y', strtotime($order['estimasi_selesai'])) ?></div>
    <?php endif; ?>
  </div>

  <?php if ($antar && in_array($antar['status'], ['assigned','menuju','sampai','done'], true)): ?>
  <div class="card" style="border-left:4px solid #1E40AF">
    <div style="font-weight:700;color:#1E40AF;margin-bottom:6px">🛵 Status Antar</div>
    <?php if (!empty($antar['kurir_nama'])): ?>
      <div style="font-size:13.5px">Kurir: <strong><?= htmlspecialchars($antar['kurir_nama']) ?></strong></div>
    <?php endif; ?>
    <div style="font-size:13px;margin-top:4px">Status: <strong><?= htmlspecialchars($antar['status']) ?></strong></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3 style="margin:0 0 10px;font-size:14px;color:#0F1C3A">🧺 Item Cucian</h3>
    <?php foreach ($items as $it): ?>
      <div class="item">
        <div class="nm"><?= htmlspecialchars($it['nama_layanan']) ?></div>
        <div class="meta"><?= (float)$it['jumlah'] ?> <?= htmlspecialchars($it['satuan']) ?> × Rp <?= number_format((float)$it['harga_satuan'], 0, ',', '.') ?> = Rp <?= number_format((float)$it['subtotal'], 0, ',', '.') ?></div>
      </div>
    <?php endforeach; ?>
    <div class="row" style="margin-top:8px"><span class="l">Subtotal</span><span class="v">Rp <?= number_format((float)$order['subtotal'], 0, ',', '.') ?></span></div>
    <?php if ((float)$order['diskon'] > 0): ?>
      <div class="row"><span class="l">Diskon</span><span class="v">- Rp <?= number_format((float)$order['diskon'], 0, ',', '.') ?></span></div>
    <?php endif; ?>
    <div class="row"><span class="l">Total</span><span class="total">Rp <?= number_format((float)$order['total'], 0, ',', '.') ?></span></div>
    <div class="row"><span class="l">Status Bayar</span><span class="v"><?= htmlspecialchars($order['status_bayar']) ?></span></div>
  </div>

  <?php if (!empty($bukti['fotos'])): ?>
  <div class="card">
    <div class="bukti">
      <div class="lbl">📸 Bukti <?= ($bukti['data']['jenis'] ?? '') === 'diantarkan' ? 'Antar' : 'Serah Terima' ?></div>
      <div>
        <?php foreach ($bukti['fotos'] as $fp): if (!str_starts_with($fp, 'uploads/foto_proses/')) continue; ?>
          <a href="/<?= htmlspecialchars($fp) ?>" target="_blank">
            <img src="/<?= htmlspecialchars($fp) ?>" alt="Bukti">
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
