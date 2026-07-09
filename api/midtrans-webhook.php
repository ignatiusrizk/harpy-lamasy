<?php
// ══════════════════════════════════════════════════════
// api/midtrans-webhook.php
// Webhook endpoint untuk Midtrans payment notifications.
// Public — tidak butuh session auth, tapi WAJIB verify signature.
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/../master/config/db.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/BillingConfig.php';
require_once __DIR__ . '/../core/MidtransClient.php';
require_once __DIR__ . '/../core/PaymentSettler.php';
require_once __DIR__ . '/../core/ErrorLogger.php';

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

// 1. Read body
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body || empty($body['order_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// 2. Verify signature (gracefully handle missing server_key)
try {
    $sigValid = MidtransClient::verifySignature($body);
} catch (Throwable $e) {
    http_response_code(401);
    if (class_exists('ErrorLogger')) {
        ErrorLogger::log('midtrans_webhook_verify_error', json_encode([
            'order_id' => $body['order_id'] ?? null,
            'error'    => $e->getMessage(),
        ]));
    }
    echo json_encode(['error' => 'Signature verification failed (config issue?)']);
    exit;
}
if (!$sigValid) {
    http_response_code(401);
    if (class_exists('ErrorLogger')) {
        ErrorLogger::log('midtrans_webhook_bad_sig', json_encode([
            'order_id' => $body['order_id'] ?? null,
            'raw' => $raw,
        ]));
    }
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// 3. Lookup payment
$db = Database::get();
$st = $db->prepare("SELECT * FROM saas_payments WHERE order_id=?");
$st->execute([$body['order_id']]);
$payment = $st->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    // Signature SUDAH valid (genuine dari Midtrans) tapi order tak ada di DB kita —
    // ini terjadi pada "Test notification URL" Midtrans (order_id dummy) atau race.
    // Ack 200 agar Midtrans tak retry berulang + tombol Test hijau. Tetap dicatat.
    http_response_code(200);
    if (class_exists('ErrorLogger')) {
        ErrorLogger::log('midtrans_webhook_order_notfound', json_encode([
            'order_id' => $body['order_id'] ?? null,
            'status'   => $body['transaction_status'] ?? null,
        ]));
    }
    echo json_encode(['ok' => true, 'note' => 'Order tidak ditemukan — di-ack (kemungkinan test/dummy)']);
    exit;
}

// 4. Idempotency — kalau status sudah paid, skip update
if ($payment['status'] === 'paid') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'note' => 'Already paid (idempotent)']);
    exit;
}

// 4b. Defense-in-depth: signature-verified amount must match stored amount
$gross = (int)round((float)($body['gross_amount'] ?? 0));
if ($gross !== (int)$payment['amount']) {
    if (class_exists('ErrorLogger')) {
        ErrorLogger::log('midtrans_webhook_amount_mismatch', json_encode([
            'order_id' => $body['order_id'],
            'expected' => $payment['amount'],
            'received' => $gross,
        ]));
    }
    http_response_code(200);  // accept (signed) but DO NOT settle
    echo json_encode(['ok' => false, 'error' => 'Amount mismatch — not settled']);
    exit;
}

// 5. Map Midtrans status → internal status
$txStatus = $body['transaction_status'] ?? '';
$fraudStatus = $body['fraud_status'] ?? 'accept';

$newStatus = 'pending';
if (($txStatus === 'capture' && $fraudStatus === 'accept') || $txStatus === 'settlement') {
    $newStatus = 'paid';
} elseif ($txStatus === 'expire') {
    $newStatus = 'expired';
} elseif (in_array($txStatus, ['cancel', 'deny', 'failure'], true)) {
    $newStatus = 'failed';
}

// 6. Update payment row
$db->prepare(
    "UPDATE saas_payments SET
        status = ?,
        paid_at = ?,
        midtrans_tx_id = ?,
        payment_type = ?,
        raw_response = ?
     WHERE id = ?"
)->execute([
    $newStatus,
    $newStatus === 'paid' ? date('Y-m-d H:i:s') : null,
    $body['transaction_id'] ?? null,
    $body['payment_type'] ?? null,
    json_encode($body),
    $payment['id'],
]);

// 7. Settle kalau jadi paid
if ($newStatus === 'paid') {
    $result = PaymentSettler::settle((int)$payment['id']);
    if (!$result['ok']) {
        // Log but still return 200 to Midtrans (avoid retry loop). SA harus fix manual.
        if (class_exists('ErrorLogger')) {
            ErrorLogger::log('payment_settle_failed', json_encode([
                'payment_id' => $payment['id'],
                'error' => $result['error'],
            ]));
        }
    }
}

// 8. Return 200 OK ke Midtrans
http_response_code(200);
echo json_encode(['ok' => true, 'status' => $newStatus]);
