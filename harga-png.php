<?php
// ══════════════════════════════════════════════════════
// harga-png.php — PUBLIC /harga.png, /harga-kiloan.png, /harga-satuan.png
// Menyajikan PNG daftar harga yang sudah dibuat (assets/public-links/). Regenerasi
// HANYA bila layanan berubah atau file belum ada (PublicLinks::refreshIfStale) — bukan tiap request.
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/PublicLinks.php';

$kind = ($_GET['kind'] ?? 'kiloan') === 'satuan' ? 'satuan' : 'kiloan';
$db = Database::get();
$t  = PublicLinks::target($db);
if (!$t) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Tidak ditemukan.'); }
PublicLinks::refreshIfStale($db, $t);
$file = PublicLinks::file($t, $kind);
if (!is_file($file)) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); header('Retry-After: 60'); exit('Gambar belum siap.'); }
$mtime = filemtime($file); $size = filesize($file);
$etag  = '"' . md5($mtime . $size . $kind) . '"';
header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Type: image/png');
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="daftar-harga-' . $kind . '.png"');
readfile($file);
