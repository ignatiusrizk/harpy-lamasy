<?php
require __DIR__ . '/../_assert.php';

// Bootstrap DB constants dari ~/.my.cnf (untuk CLI test lokal) sebelum db.php di-require.
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
// cari order yang punya offline_ref (kalau ada di prod) — uji query OR cocok
$sql = "SELECT id FROM hl_transaksi WHERE (no_order = :k OR offline_ref = :k) LIMIT 1";
$st = $db->prepare($sql);
ok($st !== false, 'query lookup OR offline_ref valid (prepare sukses)');
echo "OK test_lookup\n";
