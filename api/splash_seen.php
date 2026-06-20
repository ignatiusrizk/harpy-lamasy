<?php
// ══════════════════════════════════════════════════════
// api/splash_seen.php — Mark splash as seen via AJAX
//
// POST JSON: { type: 'onboarding'|'whats_new'|'tips', ref_id: string|null }
// Header   : X-CSRF-Token
//
// Response : { success: true } atau { error: "..." }
//
// Dipanggil dari closeSplash() / markSplashSeen() di components.php JS.
// ══════════════════════════════════════════════════════
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/SplashManager.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    verifyCsrf();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF invalid']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$type  = (string)($payload['type']   ?? '');
$refId = $payload['ref_id'] ?? null;
if ($refId !== null) {
    $refId = substr((string)$refId, 0, 100);
}

$allowed = ['onboarding', 'whats_new', 'tips'];
if (!in_array($type, $allowed, true)) {
    echo json_encode(['error' => 'Invalid type']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['error' => 'No session']);
    exit;
}

SplashManager::markSeen($userId, $type, $refId ?: null);

echo json_encode(['success' => true]);
