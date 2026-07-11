<?php
require __DIR__ . '/../_assert.php';
if (!defined('DB_HOST')) {
    $mycnf = parse_ini_file(($_SERVER['HOME'] ?? getenv('HOME')) . '/.my.cnf') ?: [];
    define('DB_HOST', $mycnf['host']     ?? 'localhost');
    define('DB_USER', $mycnf['user']     ?? '');
    define('DB_PASS', $mycnf['password'] ?? '');
    define('DB_NAME', $mycnf['database'] ?? '');
    if (!defined('ROOT')) define('ROOT', dirname(__DIR__, 2));
}
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/BillingConfig.php';
require_once dirname(__DIR__, 2) . '/core/ManualPay.php';

$db = Database::get();

// ── Task 1: isEnabled / bankInfo ──────────────────────────────
// Simpan state config lama utk dipulihkan di akhir
$prev = [];
foreach (['manual_payment_enabled','manual_bank_name','manual_bank_account_no','manual_bank_holder','manual_payment_expiry_hours'] as $k) {
    $prev[$k] = BillingConfig::get($k);
}

BillingConfig::set('manual_payment_enabled', '0');
BillingConfig::set('manual_bank_name', 'BCA');
BillingConfig::set('manual_bank_account_no', '1234567890');
BillingConfig::set('manual_bank_holder', 'Test Holder');
ok(ManualPay::isEnabled() === false, 'isEnabled false saat switch=0 walau rekening lengkap');

BillingConfig::set('manual_payment_enabled', '1');
BillingConfig::set('manual_bank_account_no', '');
ok(ManualPay::isEnabled() === false, 'isEnabled false saat rekening tak lengkap walau switch=1');

BillingConfig::set('manual_bank_account_no', '1234567890');
ok(ManualPay::isEnabled() === true, 'isEnabled true saat switch=1 + rekening lengkap');

$info = ManualPay::bankInfo();
eqv($info['name'], 'BCA', 'bankInfo name benar');
eqv($info['account_no'], '1234567890', 'bankInfo account_no benar');
eqv($info['holder'], 'Test Holder', 'bankInfo holder benar');

echo "OK test_manual_pay Task1\n";

// Pulihkan config lama (biar tak ganggu prod DB lokal)
foreach ($prev as $k => $v) {
    if ($v === null) { Database::get()->prepare("DELETE FROM saas_billing_config WHERE key_name=?")->execute([$k]); }
    else { BillingConfig::set($k, $v); }
}
