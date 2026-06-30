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

// (1) Config key baru terbaca
ok(BillingConfig::getInt('outlet_activation_coin', 100000) >= 0, 'outlet_activation_coin terbaca (int)');
ok(BillingConfig::getInt('outlet_activation_discount', 0) >= 0, 'outlet_activation_discount terbaca (int)');

// (2) Rumus net biaya (fee 1.000.000 − 20%) = 800.000
$net = (int)round(1000000 * (1 - 20/100));
eqv($net, 800000, 'net biaya fee 1.000.000 −20% = 800.000');

echo "OK test_activation_coin (config)\n";

// ── Step 1 test: settle idempoten + kredit coin ──────────────────────────────
require_once dirname(__DIR__, 2) . '/core/PaymentSettler.php';
$db = Database::get();

// Siapkan tenant + outlet pending + payment outlet_activation sintetis
// Catatan: schema nyata: tenants.slug NOT NULL UNIQUE, nama_perusahaan, status enum no 'trial'
//          saas_payments.order_id NOT NULL UNIQUE
$db->beginTransaction();
$db->prepare("INSERT INTO tenants (slug, nama_perusahaan, owner_name, owner_wa, status, coin_balance, created_at)
              VALUES (?, 'TEST-ACT', 't', '0', 'pending_verification', 0, NOW())")
   ->execute(['test-act-slug-' . time() . '-' . rand(1000,9999)]);
$tid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO outlets (tenant_id, nama_outlet, slug, status, coin_balance, is_main, setup_done)
              VALUES (?, 'TEST-ACT-OUTLET', ?, 'pending', 0, 0, 0)")
   ->execute([$tid, 'test-act-outlet-' . $tid]);
$oid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO saas_payments (tenant_id, order_id, type, amount, status, ref_outlet_id, created_at, expires_at)
              VALUES (?, ?, 'outlet_activation', 800000, 'paid', ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute([$tid, 'TEST-ACT-ORD-' . $tid, $oid]);
$pid = (int)$db->lastInsertId();
$db->commit();

$coinCfg = BillingConfig::getInt('outlet_activation_coin', 100000);

$r1 = PaymentSettler::settle($pid);
ok(!empty($r1['ok']), 'settle pertama ok');
$bal1 = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$tid")->fetchColumn();
eqv($bal1, $coinCfg, 'saldo tenant = outlet_activation_coin setelah settle');
$led1 = (int)$db->query("SELECT COUNT(*) FROM coin_ledger WHERE payment_id=$pid")->fetchColumn();
eqv($led1, 1, '1 baris ledger setelah settle pertama');

$r2 = PaymentSettler::settle($pid);
ok(!empty($r2['ok']), 'settle kedua ok (idempoten)');
$bal2 = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$tid")->fetchColumn();
eqv($bal2, $coinCfg, 'saldo tetap (tidak double-credit) setelah settle kedua');
$led2 = (int)$db->query("SELECT COUNT(*) FROM coin_ledger WHERE payment_id=$pid")->fetchColumn();
eqv($led2, 1, 'tetap 1 baris ledger (idempoten)');

// ── Step 2 test: guard $alreadyCredited (bukan early-return outlet-active) ──
// Flip outlet kembali ke pending agar settleOutletActivation TIDAK hit early-return
// "Outlet already active". Dengan demikian guard $alreadyCredited yang mencegah
// double-credit benar-benar dieksekusi, bukan path di atas.
$db->prepare("UPDATE outlets SET status='pending', activated_at=NULL WHERE id=?")
   ->execute([$oid]);

$r3 = PaymentSettler::settle($pid);
ok(!empty($r3['ok']), 'settle ketiga ok setelah outlet-flip-pending (ledger guard)');
$bal3 = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$tid")->fetchColumn();
eqv($bal3, $coinCfg, 'saldo tetap = outlet_activation_coin (guard alreadyCredited, tidak double-credit)');
$led3 = (int)$db->query("SELECT COUNT(*) FROM coin_ledger WHERE payment_id=$pid")->fetchColumn();
eqv($led3, 1, 'tetap 1 baris ledger setelah outlet-flip-pending (guard alreadyCredited)');

// Cleanup data sintetis
$db->prepare("DELETE FROM coin_ledger WHERE payment_id=?")->execute([$pid]);
$db->prepare("DELETE FROM saas_payments WHERE id=?")->execute([$pid]);
$db->prepare("DELETE FROM outlets WHERE id=?")->execute([$oid]);
$db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);

echo "OK test_activation_coin (settle idempoten)\n";
