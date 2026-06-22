<?php
// ══════════════════════════════════════════════════════
// api/label.php — Print label stiker thermal 58mm untuk produksi
//
// GET ?id=<transaksi_id> → HTML auto-print
// Berisi: no_order BESAR + QR (encode no_order) + nama + tgl_ambil
// ══════════════════════════════════════════════════════

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';

$id   = (int)($_GET['id'] ?? 0);
$tid  = TenantResolver::id();
$oid  = TenantResolver::outletId();

if ($id <= 0) { http_response_code(400); exit('Bad id'); }

$order = TenantQuery::rawOne(
    "SELECT t.no_order, t.nama_pelanggan, t.estimasi_selesai, o.nama_outlet, o.label_size
       FROM hl_transaksi t
  LEFT JOIN outlets o ON o.id = t.outlet_id
      WHERE t.id=? AND t.tenant_id=? AND t.outlet_id=? LIMIT 1",
    [$id, $tid, $oid]
);

if (!$order) { http_response_code(404); exit('Order tidak ditemukan'); }

$size     = in_array(($order['label_size'] ?? '80'), ['58','80'], true) ? $order['label_size'] : '80';
$widthMm  = $size === '58' ? 58 : 80;
$qrMm     = $size === '58' ? 35 : 48;
$qrPx     = $size === '58' ? 180 : 240;
$kodeSize = $size === '58' ? 18 : 24;

$nama   = $order['nama_pelanggan'] ?: '-';
$kode   = $order['no_order'];
$outlet = $order['nama_outlet'] ?: '';
$tgl    = $order['estimasi_selesai']
    ? date('d M Y', strtotime($order['estimasi_selesai']))
    : '-';

$qrSrc  = "https://api.qrserver.com/v1/create-qr-code/?size={$qrPx}x{$qrPx}&data=" . urlencode($kode);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Label <?= htmlspecialchars($kode) ?></title>
<style>
  @page { size: <?= $widthMm ?>mm auto; margin: 0; }
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    width: <?= $widthMm ?>mm;
    font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    color: #000;
    background: #fff;
  }
  .label {
    padding: 4mm 3mm 5mm;
    text-align: center;
  }
  .outlet {
    font-size: 8pt;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #555;
    margin-bottom: 2mm;
    border-bottom: 1px dashed #999;
    padding-bottom: 1.5mm;
  }
  .kode {
    font-size: <?= $kodeSize ?>pt;
    font-weight: 800;
    letter-spacing: .02em;
    margin: 1mm 0 2mm;
    word-break: break-all;
  }
  .qr {
    margin: 1mm auto 2mm;
    width: <?= $qrMm ?>mm;
    height: <?= $qrMm ?>mm;
  }
  .qr img { width: 100%; height: 100%; display: block; }
  .meta { font-size: 9pt; line-height: 1.35; margin-top: 1mm; }
  .meta .lbl { color: #666; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .06em; }
  .meta .val { font-weight: 700; }
  .row { margin-bottom: 1.5mm; }

  /* Layar (preview sebelum print) */
  @media screen {
    body { background: #eee; padding: 16px; display: flex; justify-content: center; }
    .label { background: #fff; box-shadow: 0 4px 20px rgba(0,0,0,.15); width: <?= $widthMm ?>mm; }
  }
</style>
</head>
<body>
<div class="label">
  <?php if ($outlet): ?>
    <div class="outlet"><?= htmlspecialchars($outlet) ?></div>
  <?php endif; ?>

  <div class="kode"><?= htmlspecialchars($kode) ?></div>

  <div class="qr"><img src="<?= htmlspecialchars($qrSrc) ?>" alt="QR <?= htmlspecialchars($kode) ?>"></div>

  <div class="meta">
    <div class="row">
      <div class="lbl">Pelanggan</div>
      <div class="val"><?= htmlspecialchars($nama) ?></div>
    </div>
    <div class="row">
      <div class="lbl">Ambil</div>
      <div class="val"><?= htmlspecialchars($tgl) ?></div>
    </div>
  </div>
</div>

<script>
  // Auto print setelah QR image siap (atau timeout 1.5s kalau lambat)
  const img = document.querySelector('.qr img');
  let printed = false;
  const goPrint = () => { if (printed) return; printed = true; setTimeout(() => window.print(), 120); };
  if (img && img.complete) goPrint();
  else if (img) { img.addEventListener('load', goPrint); img.addEventListener('error', goPrint); }
  setTimeout(goPrint, 1500); // safety net
</script>
</body>
</html>
