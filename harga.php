<?php
// ══════════════════════════════════════════════════════
// harga.php — PUBLIC /harga: halaman ringan berisi gambar daftar harga yang diunggah, dengan tag Open Graph.
// Tanpa login. Tanpa rekening / tenant_id / data pelanggan.
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/PublicLinks.php';

$db = Database::get();
$t  = PublicLinks::target($db);
$abs = $t ? PublicLinks::hargaFile($t) : null;
$outletNama = 'Harpy Laundry';
if ($t) {
    $st = $db->prepare("SELECT nama_outlet FROM outlets WHERE id=? AND tenant_id=?");
    $st->execute([(int)$t['outlet_id'], (int)$t['tenant_id']]);
    $outletNama = (string)($st->fetchColumn() ?: $outletNama);
}
header('Cache-Control: public, max-age=300');
if (!$abs) http_response_code($t ? 503 : 404);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$img = '/harga.png?v=' . ($abs ? filemtime($abs) : 0);
$title = 'Daftar Harga ' . $outletNama;
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($title) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="Daftar harga laundry kiloan dan satuan.">
<?php if ($abs): ?><meta property="og:image" content="<?= $e(PublicLinks::absBase() . $img) ?>">
<meta property="og:image:alt" content="<?= $e($title) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<style>
*{box-sizing:border-box}
body{margin:0;background:#1F3F66;color:#FBE9D0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  padding:max(12px,env(safe-area-inset-top)) max(12px,env(safe-area-inset-right)) max(24px,env(safe-area-inset-bottom)) max(12px,env(safe-area-inset-left))}
main{max-width:620px;margin:0 auto}
img{display:block;width:100%;height:auto;border-radius:6px}
.empty{padding:40px 8px;text-align:center;color:#A9BBD0}
</style>
</head>
<body>
<main>
<?php if ($abs): ?>
  <img src="<?= $e($img) ?>" alt="<?= $e($title) ?>" width="1131" height="1600">
<?php else: ?>
  <div class="empty">Daftar harga belum tersedia. Silakan hubungi kami lewat WhatsApp.</div>
<?php endif; ?>
</main>
</body>
</html>
