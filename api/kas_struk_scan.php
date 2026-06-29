<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/ReceiptScanner.php';
require_once ROOT . '/core/AIRateLimiter.php';
require_once ROOT . '/core/CoinLedger.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'reason'=>'method']); exit; }
verifyCsrf();
if (!hasPermission('kas.create')) { echo json_encode(['ok'=>false,'reason'=>'forbidden']); exit; }

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$fotoPath = (string)($data['foto_path'] ?? '');

// Validasi path: harus di folder bukti kas + prefix tenant ini (anti traversal/cross-tenant)
$prefix = 'uploads/kas_bukti/t' . $tid . '_o' . $oid;
$norm   = str_replace('\\', '/', $fotoPath);
if (strpos($norm, '..') !== false || strpos($norm, $prefix) !== 0) {
    echo json_encode(['ok'=>false,'reason'=>'bad_path']); exit;
}
$full = ROOT . '/' . $norm;
if (!is_file($full)) { echo json_encode(['ok'=>false,'reason'=>'bad_path']); exit; }

if (!AIRateLimiter::canCall('ai_kas_struk', $tid)) {
    echo json_encode(['ok'=>false,'reason'=>'rate_limited'] + AIRateLimiter::errorResponse('ai_kas_struk'));
    exit;
}

try {
    $bytes = file_get_contents($full);
    $mime  = function_exists('mime_content_type') ? (mime_content_type($full) ?: 'image/jpeg') : 'image/jpeg';
    if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) $mime = 'image/jpeg';
    $base64 = base64_encode($bytes);

    $res = ReceiptScanner::scan($base64, $mime);
    if (empty($res['ok'])) { echo json_encode(['ok'=>false,'reason'=>'not_receipt']); exit; }

    if (!CoinLedger::deduct('ai_kas_struk')) { echo json_encode(['ok'=>false,'reason'=>'insufficient_coin']); exit; }

    echo json_encode(['ok'=>true, 'parsed'=>[
        'jumlah'     => $res['jumlah'],
        'tanggal'    => $res['tanggal'],
        'keterangan' => $res['keterangan'],
        'kategori'   => $res['kategori'],
    ], 'foto_path'=>$norm]);
} catch (Throwable $e) {
    ErrorLogger::log('kas_struk', 'scan error: ' . $e->getMessage(), $tid, $oid);
    echo json_encode(['ok'=>false,'reason'=>'ai_error']);
}
