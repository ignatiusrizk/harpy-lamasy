<?php
// ══════════════════════════════════════════════════════
// qris-png.php — PUBLIC /qris.png: sajikan berkas QRIS apa adanya (tanpa resize/kompres).
// Hanya melayani outlet tujuan di hl_public_link. Tanpa login.
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/PublicLinks.php';

header('X-Robots-Tag: noindex, nofollow');
$db = Database::get();
$t  = PublicLinks::target($db);
$rel = '';
if ($t) {
    $st = $db->prepare("SELECT qris_image FROM outlets WHERE id=? AND tenant_id=?");
    $st->execute([(int)$t['outlet_id'], (int)$t['tenant_id']]);
    $rel = (string)($st->fetchColumn() ?: '');
}
$base = realpath(ROOT . '/assets/outlet-qris');
$abs  = $rel !== '' ? realpath(ROOT . $rel) : false;
if (!$t || !$base || !$abs || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('QRIS belum tersedia.');
}
PublicLinks::serveImage($abs, 86400, 'qris-harpy-laundry');
