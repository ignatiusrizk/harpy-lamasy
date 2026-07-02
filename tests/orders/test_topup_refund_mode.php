<?php
// Test topup applyBonus=false + transaction-aware. Seed pelanggan temp, cleanup shutdown.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/DepositManager.php';

$db  = Database::get();
$src = $db->query("SELECT * FROM hl_pelanggan LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo "SKIP: tidak ada pelanggan\n"; exit(0); }
$tid = (int)$src['tenant_id']; $oid = (int)$src['outlet_id'];
unset($src['id']);
$src['nama'] = 'zzrefund_' . time();
$src['saldo_deposit'] = 0;
// Generate unique portal_token if exists
if (isset($src['portal_token'])) {
    $src['portal_token'] = bin2hex(random_bytes(16));
}
$cols = array_keys($src);
$db->prepare("INSERT INTO hl_pelanggan (".implode(',', $cols).") VALUES (".implode(',', array_fill(0,count($cols),'?')).")")
   ->execute(array_values($src));
$pid = (int)$db->lastInsertId();

$cleanup = function() use ($db, $pid) {
    $db->prepare("DELETE FROM hl_deposit_topup WHERE pelanggan_id=?")->execute([$pid]);
    $db->prepare("DELETE FROM hl_pelanggan WHERE id=?")->execute([$pid]);
};
register_shutdown_function($cleanup);

// applyBonus=false → bonus 0, total_kredit = jumlah persis
[$topupId, $err] = DepositManager::topup($tid, $oid, $pid, 10000.0, 'refund_order', 'zzrefund test', null, null, false);
eqv($err, null, "topup refund tanpa error");
eqv((int)$topupId > 0, true, "topup_id > 0");
$row = $db->prepare("SELECT bonus, total_kredit FROM hl_deposit_topup WHERE pelanggan_id=? ORDER BY id DESC LIMIT 1");
$row->execute([$pid]); $row = $row->fetch(PDO::FETCH_ASSOC);
eqv((float)$row['bonus'], 0.0, "bonus tercatat 0");
eqv((float)$row['total_kredit'], 10000.0, "total_kredit = jumlah (tanpa bonus)");

// Transaction-aware: panggil dari dalam transaksi luar → tidak fatal, saldo ikut commit luar
$db->beginTransaction();
[$topupId2, $err2] = DepositManager::topup($tid, $oid, $pid, 5000.0, 'refund_order', 'zzrefund tx', null, null, false);
eqv($err2, null, "topup dalam transaksi luar tidak error");
eqv((int)$topupId2 > 0, true, "topup_id2 > 0");
$db->commit();
$saldo = $db->prepare("SELECT saldo_deposit FROM hl_pelanggan WHERE id=?");
$saldo->execute([$pid]);
eqv((float)$saldo->fetchColumn(), 15000.0, "saldo akhir 15000 (10000+5000)");

echo "ALL PASS\n";
