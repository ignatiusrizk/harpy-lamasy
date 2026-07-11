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

// ── Task 2: uniqueAmount + createPayment ──────────────────────
require_once dirname(__DIR__, 2) . '/core/MidtransClient.php';

// Siapkan tenant + bundle sintetis
$db->beginTransaction();
$db->prepare("INSERT INTO tenants (slug, nama_perusahaan, owner_name, owner_wa, email, status, coin_balance, created_at)
              VALUES (?, 'MP-TEST', 'MP Owner', '0811', 'mp@test.local', 'pending_verification', 0, NOW())")
   ->execute(['mp-test-' . time() . '-' . rand(1000,9999)]);
$mpTid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO saas_coin_bundles (nama, harga, coin_didapat, bonus_pct, is_active, urutan)
              VALUES ('MP Bundle', 20000, 100, 0, 1, 999)")->execute();
$mpBundle = (int)$db->lastInsertId();
$db->commit();

// uniqueAmount: base+code dalam range
$u1 = ManualPay::uniqueAmount($db, 20000);
ok($u1['code'] >= 1 && $u1['code'] <= 999, 'code dalam [1,999]');
eqv($u1['amount'], 20000 + $u1['code'], 'amount = base + code');

// createPayment: buat row manual pending
$row1 = ManualPay::createPayment($db, $mpTid, 'topup_coin', ['bundle'=>$mpBundle], 20000);
eqv($row1['payment_type'], 'manual_transfer', 'row payment_type=manual_transfer');
eqv($row1['status'], 'pending', 'row status pending');
ok((int)$row1['amount'] >= 20001 && (int)$row1['amount'] <= 20999, 'amount row = base+code unik');
ok(empty($row1['qr_string']), 'qr_string kosong utk manual');

// createPayment lagi utk type+ref sama → resume row yang sama (bukan buat baru)
$row2 = ManualPay::createPayment($db, $mpTid, 'topup_coin', ['bundle'=>$mpBundle], 20000);
eqv((int)$row2['id'], (int)$row1['id'], 'createPayment kedua me-resume row pending yang sama');

// uniqueAmount hindari tabrakan dgn amount pending yg sudah ada
$taken = (int)$row1['amount'];
$distinct = true;
for ($i=0;$i<20;$i++){ if (ManualPay::uniqueAmount($db, $taken - ($taken % 1000))['amount'] === $taken) { $distinct=false; break; } }
ok($distinct, 'uniqueAmount tak pernah tabrakan dgn amount pending existing');

echo "OK test_manual_pay Task2\n";

// Bersihkan data sintetis Task 2
$db->prepare("DELETE FROM saas_payments WHERE tenant_id=?")->execute([$mpTid]);
$db->prepare("DELETE FROM saas_coin_bundles WHERE id=?")->execute([$mpBundle]);
$db->prepare("DELETE FROM tenants WHERE id=?")->execute([$mpTid]);

// Pulihkan config lama (biar tak ganggu prod DB lokal)
foreach ($prev as $k => $v) {
    if ($v === null) { Database::get()->prepare("DELETE FROM saas_billing_config WHERE key_name=?")->execute([$k]); }
    else { BillingConfig::set($k, $v); }
}
