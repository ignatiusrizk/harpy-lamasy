<?php
// ══════════════════════════════════════════════════════
// harga.php — PUBLIC /harga: halaman ringan daftar harga (gambar Kiloan + Satuan) dengan tag Open Graph.
// Tanpa login. Hanya data harga layanan aktif outlet tujuan; tanpa rekening / tenant_id / data pelanggan.
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/PublicLinks.php';

$db = Database::get();
$t  = PublicLinks::target($db);
$ok = false; $outletNama = 'Harpy Laundry'; $verK = $verS = 0; $diperbarui = '';
if ($t) {
    PublicLinks::refreshIfStale($db, $t);
    $fk = PublicLinks::file($t, 'kiloan'); $fs = PublicLinks::file($t, 'satuan');
    $ok = is_file($fk) && is_file($fs);
    if ($ok) { $verK = filemtime($fk); $verS = filemtime($fs); $diperbarui = PublicLinks::tanggalId(max($verK, $verS)); }
    $st = $db->prepare("SELECT nama_outlet FROM outlets WHERE id=? AND tenant_id=?");
    $st->execute([(int)$t['outlet_id'], (int)$t['tenant_id']]);
    $outletNama = (string)($st->fetchColumn() ?: $outletNama);
}
header('Cache-Control: public, max-age=300');
if (!$ok) http_response_code($t ? 503 : 404);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$base = PublicLinks::absBase();
$imgK = '/harga-kiloan.png?v=' . $verK; $imgS = '/harga-satuan.png?v=' . $verS;
$title = 'Daftar Harga ' . $outletNama;
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($title) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="Harga laundry kiloan dan satuan<?= $diperbarui ? ', diperbarui ' . $e($diperbarui) : '' ?>.">
<?php if ($ok): ?><meta property="og:image" content="<?= $e($base . $imgK) ?>">
<meta property="og:image:alt" content="Daftar harga laundry kiloan"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<style>
*{box-sizing:border-box}
body{margin:0;background:#1F3F66;color:#FBE9D0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  padding:max(12px,env(safe-area-inset-top)) max(12px,env(safe-area-inset-right)) max(24px,env(safe-area-inset-bottom)) max(12px,env(safe-area-inset-left))}
main{max-width:620px;margin:0 auto}
nav{display:flex;gap:10px;margin:4px 0 12px}
nav a{flex:1;text-align:center;padding:10px;border-radius:10px;background:#34597F;color:#FBE9D0;text-decoration:none;font-weight:600}
img{display:block;width:100%;height:auto;margin:0 0 18px;border-radius:6px}
.empty{padding:40px 8px;text-align:center;color:#A9BBD0}
</style>
</head>
<body>
<main>
<?php if ($ok): ?>
  <nav><a href="#kiloan">Kiloan</a><a href="#satuan">Satuan</a></nav>
  <img id="kiloan" src="<?= $e($imgK) ?>" alt="Daftar harga kiloan <?= $e($outletNama) ?>" width="1080">
  <img id="satuan" src="<?= $e($imgS) ?>" alt="Daftar harga satuan <?= $e($outletNama) ?>" width="1080">
<?php else: ?>
  <div class="empty">Daftar harga belum tersedia. Silakan hubungi kami lewat WhatsApp.</div>
<?php endif; ?>
</main>
</body>
</html>
