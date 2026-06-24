<?php
require_once __DIR__ . '/../middleware/tenant_guard.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

$orderId = $_GET['order_id'] ?? '';
$tenantId = (int)($_SESSION['tenant_id'] ?? 0);

if (!$orderId || !$tenantId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$db = Database::get();
$st = $db->prepare("SELECT status, expires_at FROM saas_payments WHERE order_id=? AND tenant_id=?");
$st->execute([$orderId, $tenantId]);
$payment = $st->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    http_response_code(404);
    echo json_encode(['error' => 'Payment not found']);
    exit;
}

echo json_encode([
    'status' => $payment['status'],
    'expires_at' => $payment['expires_at'],
    'seconds_remaining' => max(0, strtotime($payment['expires_at']) - time()),
]);
