<?php
// api/wilayah.php — cascade wilayah (Provinsi→Kota→Kecamatan→Kelurahan)
// GET ?parent=<kode> → anak langsung. parent kosong → daftar provinsi.
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';   // sediakan Database + sesi login

header('Content-Type: application/json');

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
