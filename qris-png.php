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
$mime = mime_content_type($abs);
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) { http_response_code(404); exit; }
$mtime = filemtime($abs); $size = filesize($abs);
$etag  = '"' . md5($abs . $mtime . $size) . '"';
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="qris-harpy-laundry.' . ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime] . '"');
readfile($abs);
