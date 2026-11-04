<?php
// ══════════════════════════════════════════════════════
// harga-png.php — PUBLIC /harga.png: sajikan gambar daftar harga yang diunggah, apa adanya.
// Hanya outlet tujuan di hl_public_link. Tanpa login.
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/PublicLinks.php';

$t   = PublicLinks::target(Database::get());
$abs = $t ? PublicLinks::hargaFile($t) : null;
if (!$abs) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Daftar harga belum tersedia.'); }
PublicLinks::serveImage($abs, 300, 'daftar-harga-harpy-laundry');
