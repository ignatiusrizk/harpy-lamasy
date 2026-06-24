<?php
// ══════════════════════════════════════════════════════
// cron/payment-cleanup.php
// Auto-expire pending payments past expires_at.
// Run via Hostinger cron tab — daily or hourly.
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/../master/config/db.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/BillingConfig.php';

// Hostinger blocks /cron/ HTTP access at server level.
// Mode 1: PHP CLI direct (Hostinger cron tab "php /path/to/cron/payment-cleanup.php") — skip auth
// Mode 2: HTTP curl (manual debugging) — require secret via key=
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $expected = BillingConfig::get('cron_secret', '');
    if (!$expected) {
        http_response_code(503);
        exit('Cron secret not configured. Set "cron_secret" in saas_billing_config.');
    }
    $key = $_GET['key'] ?? '';
    if (!hash_equals($expected, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
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
