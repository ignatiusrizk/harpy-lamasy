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
require __DIR__ . '/../../core/Loyalty.php';
require __DIR__ . '/../../core/Referral.php';
$db = Database::get();
$tid = (int)$db->query("SELECT id FROM tenants LIMIT 1")->fetchColumn();
$db->beginTransaction();
$db->prepare("UPDATE tenants SET referral_enabled=1, loyalty_enabled=1, referral_poin_pengajak=50, referral_poin_teman=25, referral_max_per_pengajak=0 WHERE id=?")->execute([$tid]);
$db->prepare("INSERT INTO hl_pelanggan (tenant_id,nama,telepon,poin_balance) VALUES (?,?,?,0)")->execute([$tid,'P','0811']); $refr=(int)$db->lastInsertId();
$db->prepare("INSERT INTO hl_pelanggan (tenant_id,nama,telepon,poin_balance) VALUES (?,?,?,0)")->execute([$tid,'T','0812']); $refe=(int)$db->lastInsertId();
$db->prepare("INSERT INTO hl_referral (tenant_id,referrer_pelanggan_id,referee_pelanggan_id,kode,status,poin_pengajak,poin_teman) VALUES (?,?,?,?, 'pending',50,25)")->execute([$tid,$refr,$refe,'P-ABC']);
$db->commit(); // commit setup before calling payoutOnFirstLunas which uses Loyalty::adjust (its own transaction)

Referral::payoutOnFirstLunas($tid, $refe, 9999, null);

$db->beginTransaction(); // restart transaction for cleanup
$balR=(int)$db->query("SELECT poin_balance FROM hl_pelanggan WHERE id=$refr")->fetchColumn();
$balE=(int)$db->query("SELECT poin_balance FROM hl_pelanggan WHERE id=$refe")->fetchColumn();
eqv($balR,50,'pengajak +50'); eqv($balE,25,'teman +25');
$stt=$db->query("SELECT status FROM hl_referral WHERE tenant_id=$tid AND referee_pelanggan_id=$refe")->fetchColumn();
eqv($stt,'paid','status paid');

Referral::payoutOnFirstLunas($tid, $refe, 9999, null); // 2x → idempoten
eqv((int)$db->query("SELECT poin_balance FROM hl_pelanggan WHERE id=$refr")->fetchColumn(),50,'idempoten: pengajak tetap 50');
$db->rollBack();
echo "OK test_payout\n";
