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
// pakai tenant yg referral-nya bisa diaktifkan sementara dalam transaksi
$tid = (int)$db->query("SELECT id FROM tenants LIMIT 1")->fetchColumn();
$db->beginTransaction();
$db->prepare("UPDATE tenants SET referral_enabled=1, loyalty_enabled=1, referral_poin_pengajak=50, referral_poin_teman=25 WHERE id=?")->execute([$tid]);
// reset cache config (statis) — pakai proses baru tak bisa; uji lewat nilai balik saja
// buat 2 pelanggan dummy
$db->prepare("INSERT INTO hl_pelanggan (tenant_id,nama,telepon,poin_balance) VALUES (?,?,?,0)")->execute([$tid,'PengajakX','0810000001']);
$refrId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO hl_pelanggan (tenant_id,nama,telepon,poin_balance) VALUES (?,?,?,0)")->execute([$tid,'TemanY','0810000002']);
$refeId = (int)$db->lastInsertId();
$kode = Referral::codeFor($tid, $refrId);

// reset cfg cache via reflection (config di-cache statis)
$ref = new ReflectionClass('Referral'); $p = $ref->getProperty('cfgCache'); $p->setAccessible(true); $p->setValue([]);

$r = Referral::attribute($tid, $kode, $refeId);
ok($r['ok'] === true, 'teman baru → pending: '.json_encode($r));
$cnt = $db->prepare("SELECT COUNT(*) FROM hl_referral WHERE tenant_id=? AND referee_pelanggan_id=?");
$cnt->execute([$tid,$refeId]); eqv((int)$cnt->fetchColumn(),1,'1 record pending');

$dup = Referral::attribute($tid, $kode, $refeId);
ok($dup['ok'] === false, 'teman sudah direferral → tolak');

$self = Referral::attribute($tid, $kode, $refrId);
ok($self['ok'] === false, 'refer diri sendiri → tolak');

$db->rollBack();
echo "OK test_attribute\n";
