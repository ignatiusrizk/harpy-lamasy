<?php
// ══════════════════════════════════════════════════════
// cron/payment-cleanup.php
// Auto-expire pending payments past expires_at.
// Run via Hostinger cron tab — daily or hourly.
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/../master/config/db.php';
require_once __DIR__ . '/../core/Database.php';

// Simple secret check (anti-abuse public URL)
$expected = 'lamasy-cleanup-2026';  // Bisa pindah ke BillingConfig later
$key = $_GET['key'] ?? '';
if ($key !== $expected) {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Jakarta');

$db = Database::get();
$st = $db->prepare(
    "UPDATE saas_payments SET status='expired' WHERE status='pending' AND expires_at < NOW()"
);
$st->execute();
$count = $st->rowCount();

echo json_encode([
    'ok' => true,
    'expired' => $count,
    'timestamp' => date('Y-m-d H:i:s'),
]);
