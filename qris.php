<?php
// ══════════════════════════════════════════════════════
// qris.php — PUBLIC /qris: halaman ringan berisi QRIS untuk dibuka pelanggan dari WhatsApp.
// Tanpa login. TIDAK menampilkan nomor rekening / tenant_id / data pelanggan
// (label QRIS internal sengaja tidak dipakai — bisa memuat nomor rekening).
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/PublicLinks.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: public, max-age=300');
$db = Database::get();
$t  = PublicLinks::target($db);
$qris = null; $outletNama = 'Harpy Laundry';
if ($t) {
    $st = $db->prepare("SELECT nama_outlet, qris_image FROM outlets WHERE id=? AND tenant_id=?");
    $st->execute([(int)$t['outlet_id'], (int)$t['tenant_id']]);
    if ($o = $st->fetch(PDO::FETCH_ASSOC)) {
        $outletNama = $o['nama_outlet'] ?: $outletNama;
        $abs = $o['qris_image'] ? realpath(ROOT . $o['qris_image']) : false;
        if ($abs && is_file($abs)) $qris = $abs;
    }
}
$merchant = $t['merchant_nama'] ?? 'Harpy Laundry';
$ver  = $qris ? filemtime($qris) : 0;
$img  = '/qris.png?v=' . $ver;
$abs_img = PublicLinks::absBase() . $img;
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (!$qris) http_response_code(404);
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>QRIS Pembayaran <?= $e($outletNama) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="QRIS Pembayaran <?= $e($outletNama) ?>">
<meta property="og:description" content="Scan QRIS untuk membayar. Isi nominal sesuai nota, lalu kirim bukti bayar lewat WhatsApp.">
<?php if ($qris): ?><meta property="og:image" content="<?= $e($abs_img) ?>">
<meta property="og:image:alt" content="QRIS <?= $e($merchant) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<style>
*{box-sizing:border-box}
body{margin:0;background:#EEF3F8;color:#14213D;font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  padding:max(16px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(24px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}
main{max-width:460px;margin:0 auto}
h1{font-size:22px;line-height:1.2;margin:8px 0 2px}
.m{color:#4A5568;margin:0 0 16px;font-weight:600}
.card{background:#fff;border-radius:14px;padding:14px;box-shadow:0 1px 3px rgba(20,33,61,.12)}
.card img{display:block;width:100%;height:auto;border-radius:8px}
ol{margin:18px 0 0;padding-left:1.3em} li{margin:8px 0}
.empty{padding:28px 8px;text-align:center;color:#4A5568}
</style>
</head>
<body>
<main>
  <h1>Pembayaran QRIS</h1>
  <p class="m"><?= $e($merchant) ?></p>
  <?php if ($qris): ?>
  <div class="card"><img src="<?= $e($img) ?>" alt="QRIS <?= $e($merchant) ?>" width="600" height="600"></div>
  <ol>
    <li>Scan QRIS di atas dengan aplikasi e-wallet atau mobile banking.</li>
    <li>Isi nominal sesuai nota.</li>
    <li>Kirim bukti bayar ke chat WhatsApp kami.</li>
  </ol>
  <?php else: ?>
  <div class="card empty">QRIS belum tersedia. Silakan hubungi kami lewat WhatsApp.</div>
  <?php endif; ?>
</main>
</body>
</html>
