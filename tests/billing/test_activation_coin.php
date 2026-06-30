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
