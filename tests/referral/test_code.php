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
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/Referral.php';
$db = Database::get();
$tid = (int)$db->query("SELECT tenant_id FROM hl_pelanggan LIMIT 1")->fetchColumn();
$pid = (int)$db->query("SELECT id FROM hl_pelanggan WHERE tenant_id=$tid LIMIT 1")->fetchColumn();
ok($tid>0 && $pid>0, 'ada pelanggan untuk test');

$db->beginTransaction();
$code1 = Referral::codeFor($tid, $pid);
ok(preg_match('/^[A-Z0-9]+-[A-Z0-9]{3}$/', $code1) === 1, "format kode valid: $code1");
$code2 = Referral::codeFor($tid, $pid);
eqv($code2, $code1, 'panggilan kedua kode stabil (tak regenerate)');
eqv(Referral::resolveCode($tid, $code1), $pid, 'resolveCode → pelanggan id benar');
ok(Referral::resolveCode($tid, 'NGAWUR-XYZ') === null, 'kode tak dikenal → null');
$db->rollBack();
echo "OK test_code\n";
