<?php
require __DIR__ . '/../_assert.php';

// Bootstrap DB constants dari ~/.my.cnf (untuk CLI test lokal) sebelum Database.php di-require.
if (!defined('DB_HOST')) {
    $mycnf = parse_ini_file(($_SERVER['HOME'] ?? getenv('HOME')) . '/.my.cnf') ?: [];
    define('DB_HOST', $mycnf['host']     ?? 'localhost');
    define('DB_USER', $mycnf['user']     ?? '');
    define('DB_PASS', $mycnf['password'] ?? '');
    define('DB_NAME', $mycnf['database'] ?? '');
    if (!defined('ROOT')) define('ROOT', dirname(__DIR__, 2));
}
require_once __DIR__ . '/../../core/Database.php';
$db = Database::get();
$tcols = $db->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
foreach (['referral_enabled','referral_poin_pengajak','referral_poin_teman','referral_max_per_pengajak'] as $c)
    ok(in_array($c,$tcols), "tenants.$c ada");
$pcols = $db->query("SHOW COLUMNS FROM hl_pelanggan")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('referral_code',$pcols), 'hl_pelanggan.referral_code ada');
$rcols = $db->query("SHOW COLUMNS FROM hl_referral")->fetchAll(PDO::FETCH_COLUMN);
foreach (['id','tenant_id','referrer_pelanggan_id','referee_pelanggan_id','kode','status','referee_first_order_id','poin_pengajak','poin_teman','created_at','paid_at'] as $c)
    ok(in_array($c,$rcols), "hl_referral.$c ada");
$idx = array_column($db->query("SHOW INDEX FROM hl_referral")->fetchAll(PDO::FETCH_ASSOC),'Key_name');
ok(in_array('uniq_referee',$idx), 'unique referee ada');
echo "OK test_schema\n";
