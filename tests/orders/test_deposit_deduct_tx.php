<?php
// DepositManager::deduct: mandiri, numpang transaksi luar, saldo kurang → exception & rollback utuh.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/DepositManager.php';

$db  = Database::get();
$src = $db->query("SELECT * FROM hl_pelanggan LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo "SKIP: tidak ada pelanggan\n"; exit(0); }
$tid = (int)$src['tenant_id']; $oid = (int)$src['outlet_id'];
unset($src['id']);
$src['nama'] = 'zzdeduct_' . time();
$src['saldo_deposit'] = 20000;
if (isset($src['portal_token'])) $src['portal_token'] = bin2hex(random_bytes(16));
$cols = array_keys($src);
$db->prepare("INSERT INTO hl_pelanggan (".implode(',', $cols).") VALUES (".implode(',', array_fill(0,count($cols),'?')).")")
   ->execute(array_values($src));
$pid = (int)$db->lastInsertId();
register_shutdown_function(function() use ($db, $pid) {
    try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable) {}
    $db->prepare("DELETE FROM hl_deposit_usage WHERE pelanggan_id=?")->execute([$pid]);
    $db->prepare("DELETE FROM hl_pelanggan WHERE id=?")->execute([$pid]);
});
$saldo = function() use ($db, $pid) { $s = $db->prepare("SELECT saldo_deposit FROM hl_pelanggan WHERE id=?"); $s->execute([$pid]); return (float)$s->fetchColumn(); };

[$u, $e] = DepositManager::deduct($tid, $oid, $pid, 5000.0, null, 'zz mandiri');
eqv($e, null, "deduct mandiri tanpa error"); eqv($saldo(), 15000.0, "saldo 15000");

$db->beginTransaction();
[$u, $e] = DepositManager::deduct($tid, $oid, $pid, 5000.0, null, 'zz dalam tx');
eqv($e, null, "deduct dalam tx luar tanpa error"); $db->commit();
eqv($saldo(), 10000.0, "saldo 10000 setelah commit luar");

$db->beginTransaction();
DepositManager::deduct($tid, $oid, $pid, 1000.0, null, 'zz akan rollback');
try { DepositManager::deduct($tid, $oid, $pid, 999999.0, null, 'zz kurang'); eqv(true, false, "harus throw"); }
catch (RuntimeException $ex) { eqv(true, true, "saldo kurang dlm tx luar → exception"); }
$db->rollBack();
eqv($saldo(), 10000.0, "rollback luar membatalkan deduct sebelumnya");

[$u, $e] = DepositManager::deduct($tid, $oid, $pid, 999999.0, null, 'zz kurang mandiri');
eqv($e !== null, true, "saldo kurang mandiri → error string"); eqv($saldo(), 10000.0, "saldo tak berubah");
echo "OK\n";
