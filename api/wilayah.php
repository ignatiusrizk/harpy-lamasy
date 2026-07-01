<?php
// api/wilayah.php — cascade wilayah (Provinsi→Kota→Kecamatan→Kelurahan)
// GET ?parent=<kode> → anak langsung. parent kosong → daftar provinsi.
// Bootstrap RINGAN (bukan tenant_guard penuh): endpoint ini hanya baca tabel
// referensi publik ref_wilayah — tak butuh konteks outlet/tenant. tenant_guard
// bikin tenant baru (belum punya outlet / masih onboarding) kena redirect
// onboarding → fetch dapat HTML, dropdown provinsi gagal load.
define('ROOT', dirname(__DIR__));
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// Cukup pastikan ada sesi login (user/tenant) — tak perlu outlet.
if (empty($_SESSION['user_id']) && empty($_SESSION['tenant_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'data' => []]);
    exit;
}

require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

try {
    $db = Database::get();
    $parent = trim($_GET['parent'] ?? '');
    if ($parent === '') {
        $st = $db->prepare("SELECT kode, nama, kodepos FROM ref_wilayah WHERE level=1 ORDER BY nama");
        $st->execute();
    } else {
        if (!preg_match('/^[0-9.]{2,13}$/', $parent)) {
            echo json_encode(['ok' => true, 'data' => []]); exit;
        }
        $st = $db->prepare("SELECT kode, nama, kodepos FROM ref_wilayah WHERE parent_kode=? ORDER BY nama");
        $st->execute([$parent]);
    }
    echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Gagal memuat wilayah']);
}
