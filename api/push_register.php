<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/PushSender.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$token    = trim((string)($data['token'] ?? ''));
$platform = trim((string)($data['platform'] ?? 'android'));

if ($token === '') {
    http_response_code(422);
    echo json_encode(['error' => 'token kosong']);
    exit;
}
try {
    PushSender::registerToken(Database::get(), (int)$_SESSION['tenant_id'], (int)$_SESSION['user_id'], $token, $platform);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    ErrorLogger::log('push', 'push_register gagal: ' . $e->getMessage(), (int)($_SESSION['tenant_id'] ?? 0));
    http_response_code(500);
    echo json_encode(['error' => 'gagal simpan token']);
}
