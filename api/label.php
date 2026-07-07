<?php
// ══════════════════════════════════════════════════════
// api/label.php — Print label stiker thermal 58/80mm untuk produksi
//
// GET ?id=<transaksi_id> → HTML auto-print
// Berisi: header brand + no_order BESAR + QR + pelanggan/telp +
// masuk/ambil + ringkasan layanan + parfum + catatan + badge status bayar
// ══════════════════════════════════════════════════════

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';

// Jangan di-cache WebView/browser — layout label bisa berubah antar rilis
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id   = (int)($_GET['id'] ?? 0);
$tid  = TenantResolver::id();
$oid  = TenantResolver::outletId();

if ($id <= 0) { http_response_code(400); exit('Bad id'); }

$order = TenantQuery::rawOne(
    "SELECT t.no_order, t.nama_pelanggan, t.telepon, t.tanggal, t.estimasi_selesai,
            t.estimasi_jam, t.status_bayar, t.sisa_bayar, t.total, t.parfum, t.catatan, t.catatan_internal,
            t.tipe_order, t.express_tier_nama,
            o.nama_outlet, o.label_size
       FROM hl_transaksi t
  LEFT JOIN outlets o ON o.id = t.outlet_id
      WHERE t.id=? AND t.tenant_id=? AND t.outlet_id=? LIMIT 1",
    [$id, $tid, $oid]
);

if (!$order) { http_response_code(404); exit('Order tidak ditemukan'); }

// Item layanan (maks 3 baris + "+N lainnya" biar label tak kepanjangan)
$items = TenantQuery::raw(
    "SELECT nama_layanan, jumlah, satuan FROM hl_transaksi_item
      WHERE transaksi_id=? AND tenant_id=? ORDER BY id LIMIT 4",
    [$id, $tid]
) ?: [];
$itemLines = [];
foreach (array_slice($items, 0, 3) as $it) {
    $j = rtrim(rtrim(number_format((float)$it['jumlah'], 2, ',', '.'), '0'), ',');
    $itemLines[] = $j . ' ' . $it['satuan'] . ' ' . $it['nama_layanan'];
}
if (count($items) > 3) $itemLines[] = '+' . (count($items) - 3) . ' layanan lainnya';

$size     = in_array(($order['label_size'] ?? '80'), ['58','80'], true) ? $order['label_size'] : '80';
$widthMm  = $size === '58' ? 58 : 80;
$qrMm     = $size === '58' ? 30 : 40;
$qrPx     = $size === '58' ? 180 : 240;
// No. order bisa panjang (ada prefix outlet) → font lebih kecil biar tak patah di tengah
$kodeSize = $size === '58' ? 13 : 16;

$nama    = $order['nama_pelanggan'] ?: '-';
$telp    = $order['telepon'] ?: '';
$kode    = $order['no_order'];
$outlet  = $order['nama_outlet'] ?: '';
$parfum  = trim((string)$order['parfum']);
$catatan = trim((string)$order['catatan']);
if (mb_strlen($catatan) > 90) $catatan = mb_substr($catatan, 0, 90) . '…';
$catatanInt = trim((string)($order['catatan_internal'] ?? ''));
if (mb_strlen($catatanInt) > 90) $catatanInt = mb_substr($catatanInt, 0, 90) . '…';

$tglMasuk = $order['tanggal'] ? date('d M', strtotime($order['tanggal'])) : '-';
// Ambil: estimasi_selesai; fallback tanggal + estimasi_jam (jangan tampil "-" kalau bisa dihitung)
if ($order['estimasi_selesai']) {
    $tglAmbil = date('d M Y', strtotime($order['estimasi_selesai']));
} elseif ($order['tanggal']) {
    $jam = max(1, (int)($order['estimasi_jam'] ?: 24));
    $tglAmbil = date('d M Y', strtotime($order['tanggal'] . " +{$jam} hours"));
} else {
    $tglAmbil = '-';
}

$isExpress = ($order['tipe_order'] === 'express') || !empty($order['express_tier_nama']);
$bayar     = $order['status_bayar'] ?: 'belum_bayar';
$sisa      = (float)$order['sisa_bayar'];
if ($bayar === 'lunas') {
    $bayarTxt = '✓ LUNAS';
} elseif ($bayar === 'dp') {
    $bayarTxt = 'SISA Rp ' . number_format($sisa, 0, ',', '.');
} else {
    $bayarTxt = 'BELUM BAYAR — Rp ' . number_format((float)$order['total'], 0, ',', '.');
}

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
  .label { padding: 0 0 4mm; text-align: center; }

  /* Header brand: bar hitam solid — kontras & aman di thermal */
  .outlet {
    background: #000; color: #fff;
    font-size: 8.5pt; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em;
    padding: 2mm 2mm;
  }
  .inner { padding: 2.5mm 3mm 0; }

  .kode {
    font-size: <?= $kodeSize ?>pt;
    font-weight: 800;
    letter-spacing: .01em;
    line-height: 1.15;
    margin: 1mm 0 1.5mm;
    word-break: normal;          /* wrap natural di tanda hubung, jangan patah di tengah kata */
    overflow-wrap: break-word;
    hyphens: none;
  }
  .express {
    display: inline-block; border: 2px solid #000; border-radius: 2mm;
    font-size: 8pt; font-weight: 800; letter-spacing: .12em;
    padding: .6mm 2.5mm; margin-bottom: 1.5mm; text-transform: uppercase;
  }
  .qr { margin: .5mm auto 1.5mm; width: <?= $qrMm ?>mm; height: <?= $qrMm ?>mm; }
  .qr img { width: 100%; height: 100%; display: block; }

  .sep { border: 0; border-top: 1px dashed #999; margin: 1.5mm 0; }

  /* Grid info: label kiri, nilai kanan — padat & mudah dipindai */
  .grid { font-size: 9pt; text-align: left; }
  .grid .r { display: flex; justify-content: space-between; gap: 2mm; margin-bottom: .8mm; }
  .grid .k { color: #555; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .06em; flex-shrink: 0; padding-top: .4mm; }
  .grid .v { font-weight: 700; text-align: right; overflow-wrap: anywhere; }

  .items { font-size: 8.5pt; text-align: left; line-height: 1.45; }
  .items .t { color: #555; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .06em; margin-bottom: .5mm; }
  .items div.i { font-weight: 700; }

  .catatan { font-size: 8pt; text-align: left; line-height: 1.4; }
  .catatan .t { color: #555; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .06em; }

  /* Badge status bayar: inverted (putih di atas hitam) — kelihatan dari jauh */
  .bayar {
    background: #000; color: #fff;
    font-size: 9.5pt; font-weight: 800; letter-spacing: .04em;
    padding: 1.6mm 2mm; margin-top: 2mm;
  }
  .bayar.lunas { background: #fff; color: #000; border: 2px solid #000; }

  .foot { font-size: 7pt; color: #666; margin-top: 1.6mm; letter-spacing: .04em; }

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

  <div class="inner">
    <div class="kode"><?= htmlspecialchars($kode) ?></div>
    <?php if ($isExpress): ?>
      <div class="express">⚡ Express<?= $order['express_tier_nama'] ? ' · ' . htmlspecialchars($order['express_tier_nama']) : '' ?></div>
    <?php endif; ?>

    <div class="qr"><img src="<?= htmlspecialchars($qrSrc) ?>" alt="QR <?= htmlspecialchars($kode) ?>"></div>

    <hr class="sep">
    <div class="grid">
      <div class="r"><span class="k">Pelanggan</span><span class="v"><?= htmlspecialchars($nama) ?></span></div>
      <?php if ($telp): ?>
      <div class="r"><span class="k">Telp</span><span class="v"><?= htmlspecialchars($telp) ?></span></div>
      <?php endif; ?>
      <div class="r"><span class="k">Masuk</span><span class="v"><?= htmlspecialchars($tglMasuk) ?></span></div>
      <div class="r"><span class="k">Ambil</span><span class="v"><?= htmlspecialchars($tglAmbil) ?></span></div>
      <?php if ($parfum): ?>
      <div class="r"><span class="k">Parfum</span><span class="v"><?= htmlspecialchars($parfum) ?></span></div>
      <?php endif; ?>
    </div>

    <?php if ($itemLines): ?>
      <hr class="sep">
      <div class="items">
        <div class="t">Layanan</div>
        <?php foreach ($itemLines as $l): ?>
          <div class="i"><?= htmlspecialchars($l) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($catatan): ?>
      <hr class="sep">
      <div class="catatan"><span class="t">Catatan:</span> <?= htmlspecialchars($catatan) ?></div>
    <?php endif; ?>

    <?php if ($catatanInt): ?>
      <hr class="sep">
      <div class="catatan"><span class="t">Internal:</span> <?= htmlspecialchars($catatanInt) ?></div>
    <?php endif; ?>

    <div class="bayar<?= $bayar === 'lunas' ? ' lunas' : '' ?>"><?= htmlspecialchars($bayarTxt) ?></div>
    <div class="foot">Terima kasih · <?= htmlspecialchars($outlet ?: 'LAMASY') ?></div>
  </div>
</div>

<script>window.LABEL_WIDTH_PX = <?= ($widthMm === 58) ? 384 : 576 ?>;</script>
<?php if (empty($_GET['embed'])): // embed=1 → dicetak via thermal BT (iframe), jangan window.print ?>
<script>
  // Auto print setelah QR image siap (atau timeout 1.5s kalau lambat)
  const img = document.querySelector('.qr img');
  let printed = false;
  const goPrint = () => { if (printed) return; printed = true; setTimeout(() => window.print(), 120); };
  if (img && img.complete) goPrint();
  else if (img) { img.addEventListener('load', goPrint); img.addEventListener('error', goPrint); }
  setTimeout(goPrint, 1500); // safety net
</script>
<?php endif; ?>
</body>
</html>
