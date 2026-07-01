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
