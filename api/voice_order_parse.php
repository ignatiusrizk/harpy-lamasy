<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/VoiceOrderParser.php';
require_once ROOT . '/core/AIRateLimiter.php';
require_once ROOT . '/core/CoinLedger.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'reason'=>'method']); exit; }
verifyCsrf();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$transcript = trim((string)($data['transcript'] ?? ''));
if (mb_strlen($transcript) < 2) { echo json_encode(['ok'=>false,'reason'=>'no_speech']); exit; }
if (mb_strlen($transcript) > 500) $transcript = mb_substr($transcript, 0, 500);

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();

// Rate limit
if (!AIRateLimiter::canCall('ai_voice_order', $tid)) {
    echo json_encode(['ok'=>false,'reason'=>'rate_limited'] + AIRateLimiter::errorResponse('ai_voice_order'));
    exit;
}

try {
    // Katalog layanan aktif tenant+outlet
    $catalog = TenantQuery::raw(
        "SELECT id, nama, satuan FROM hl_layanan WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY nama",
        [$tid, $oid]
    );
    if (!$catalog) { echo json_encode(['ok'=>false,'reason'=>'no_catalog']); exit; }

    $parsed = VoiceOrderParser::parse($transcript, $catalog);

    if (empty($parsed['items'])) {
        echo json_encode(['ok'=>false,'reason'=>'no_match','heard'=>$transcript,'unmatched'=>$parsed['unmatched']]);
        exit;
    }

    // Sukses ≥1 item → potong coin; false = fitur nonaktif / coin kurang
    if (!CoinLedger::deduct('ai_voice_order')) {
        echo json_encode(['ok'=>false,'reason'=>'insufficient_coin']);
        exit;
    }
    echo json_encode(['ok'=>true, 'heard'=>$transcript, 'parsed'=>$parsed, 'unmatched'=>$parsed['unmatched']]);
} catch (Throwable $e) {
    ErrorLogger::log('voice', 'parse error: ' . $e->getMessage(), $tid ?? null, $oid ?? null);
    echo json_encode(['ok'=>false,'reason'=>'ai_error']);
}
