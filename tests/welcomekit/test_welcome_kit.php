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
$db = Database::get();

$oc = $db->query("SHOW COLUMNS FROM outlets")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('penerima', $oc), 'outlets.penerima ada');
ok(in_array('kode_pos', $oc), 'outlets.kode_pos ada');

$t = $db->query("SHOW TABLES LIKE 'saas_welcome_kit'")->fetchAll();
ok(count($t) === 1, 'tabel saas_welcome_kit ada');
$wc = $db->query("SHOW COLUMNS FROM saas_welcome_kit")->fetchAll(PDO::FETCH_COLUMN);
foreach (['tenant_id','outlet_id','payment_id','trigger','penerima','hp','alamat','kota','kode_pos','items_json','status','kurir','resi','shipped_at','delivered_at','catatan'] as $c) {
    ok(in_array($c, $wc), "saas_welcome_kit.$c ada");
}

echo "OK test_welcome_kit (schema)\n";
