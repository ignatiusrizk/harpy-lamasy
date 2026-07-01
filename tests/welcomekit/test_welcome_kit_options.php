<?php
require __DIR__ . '/../_assert.php';
if (!defined('DB_HOST')) {
    $mycnf = parse_ini_file(($_SERVER['HOME'] ?? getenv('HOME')) . '/.my.cnf') ?: [];
    define('DB_HOST', $mycnf['host'] ?? 'localhost');
    define('DB_USER', $mycnf['user'] ?? '');
    define('DB_PASS', $mycnf['password'] ?? '');
    define('DB_NAME', $mycnf['database'] ?? '');
    if (!defined('ROOT')) define('ROOT', dirname(__DIR__, 2));
}
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/BillingConfig.php';
$db = Database::get();

$oc = $db->query("SHOW COLUMNS FROM outlets")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('welcome_kit_choice', $oc), 'outlets.welcome_kit_choice ada');
$wc = $db->query("SHOW COLUMNS FROM saas_welcome_kit")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('kit_nama', $wc), 'saas_welcome_kit.kit_nama ada');

$opts = json_decode(BillingConfig::get('welcome_kit_options', '[]'), true);
ok(is_array($opts) && count($opts) >= 1, 'welcome_kit_options berisi >=1 opsi');
ok(!empty(array_filter($opts, fn($o) => !empty($o['default']))), 'ada opsi default');

echo "OK test_welcome_kit_options (schema)\n";

// ── WelcomeKit options logic tests ────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/core/WelcomeKit.php';

// Set config opsi uji
$optsCfg = '[{"key":"standar","nama":"Standar","default":true,"items":[{"nama":"Roll thermal","qty":2}]},'
         . '{"key":"printer","nama":"Paket Printer","items":[{"nama":"Roll thermal","qty":4}]}]';
BillingConfig::set('welcome_kit_options', $optsCfg, null);
register_shutdown_function(fn() => BillingConfig::set('welcome_kit_options', '', null));

ok(count(WelcomeKit::options()) === 2, 'options() = 2 opsi');
eqv(WelcomeKit::defaultOption()['key'], 'standar', 'defaultOption = standar');
eqv(WelcomeKit::optionByKey('printer')['nama'], 'Paket Printer', 'optionByKey printer');
ok(WelcomeKit::optionByKey('nope') === null, 'optionByKey key tak ada → null');
eqv(WelcomeKit::resolveChoiceKey('printer'), 'printer', 'resolveChoiceKey valid');
eqv(WelcomeKit::resolveChoiceKey('nope'), 'standar', 'resolveChoiceKey invalid → default');
eqv(WelcomeKit::resolveChoiceKey(null), 'standar', 'resolveChoiceKey null → default');

// createForOutlet snapshot choice
$db->beginTransaction();
$db->prepare("INSERT INTO tenants (nama_perusahaan, owner_name, owner_wa, slug, status, coin_balance, created_at)
              VALUES ('WKO-TEST','t','0', ?, 'active', 0, NOW())")->execute(['wko-'.time().rand(100,999)]);
$tid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO outlets (tenant_id, nama_outlet, slug, status, coin_balance, is_main, setup_done, penerima, telepon, alamat, kota, kode_pos, welcome_kit_choice)
              VALUES (?, 'WKO-OUT', ?, 'active', 0, 1, 0, 'Budi', '08123', 'Jl 1', 'Bandung', '40111', 'printer')")
   ->execute([$tid, 'wko-out-'.$tid]);
$oid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
              VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute([$tid, $oid, 'WKO-ORD-'.$tid]);
$pid = (int)$db->lastInsertId();
$db->commit();

try {
    $r = WelcomeKit::createForOutlet($db, $tid, $oid, $pid, 'outlet_activation');
    ok(!empty($r['ok']), 'createForOutlet ok');
    $row = $db->query("SELECT kit_nama, items_json FROM saas_welcome_kit WHERE id=".(int)$r['id'])->fetch(PDO::FETCH_ASSOC);
    eqv($row['kit_nama'], 'Paket Printer', 'snapshot kit_nama = pilihan owner');
    ok(strpos($row['items_json'], '"qty":4') !== false, 'snapshot items = opsi printer (4)');

    // choice kosong → default
    $db->prepare("UPDATE outlets SET welcome_kit_choice=NULL WHERE id=?")->execute([$oid]);
    $db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
                  VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
       ->execute([$tid, $oid, 'WKO-ORD2-'.$tid]);
    $pid2 = (int)$db->lastInsertId();
    $r2 = WelcomeKit::createForOutlet($db, $tid, $oid, $pid2, 'outlet_activation');
    $row2 = $db->query("SELECT kit_nama FROM saas_welcome_kit WHERE id=".(int)$r2['id'])->fetch(PDO::FETCH_ASSOC);
    eqv($row2['kit_nama'], 'Standar', 'choice kosong → opsi default');
} finally {
    $db->prepare("DELETE FROM saas_welcome_kit WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM saas_payments WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM outlets WHERE id=?")->execute([$oid]);
    $db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
}

echo "OK test_welcome_kit_options\n";
