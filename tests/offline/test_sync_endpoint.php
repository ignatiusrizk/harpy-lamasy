<?php
// Test idempotency createOffline: panggil 2x dgn uuid sama → 1 order.
require __DIR__ . '/../_assert.php';

// Bootstrap DB constants dari ~/.my.cnf (untuk CLI test lokal) sebelum db.php di-require.
// db.php di server pakai 'localhost'; lokal pakai host remote dari my.cnf.
if (!defined('DB_HOST')) {
    $mycnf = parse_ini_file(($_SERVER['HOME'] ?? getenv('HOME')) . '/.my.cnf') ?: [];
    define('DB_HOST', $mycnf['host']     ?? 'localhost');
    define('DB_USER', $mycnf['user']     ?? '');
    define('DB_PASS', $mycnf['password'] ?? '');
    define('DB_NAME', $mycnf['database'] ?? '');
    if (!defined('ROOT')) define('ROOT', dirname(__DIR__, 2));
}
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/NotaFormatter.php';
require_once dirname(__DIR__, 2) . '/core/OrderCreator.php';

$db = Database::get();

// pakai tenant/outlet yang punya layanan aktif (layanan scoped ke outlet setelah fix I1)
$oidRow = $db->query(
    "SELECT outlet_id, tenant_id FROM hl_layanan WHERE outlet_id IS NOT NULL AND is_active=1 LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$tid = (int)($oidRow['tenant_id'] ?? 0);
$oid = (int)($oidRow['outlet_id'] ?? 0);
$lid = (int)$db->query(
    "SELECT id FROM hl_layanan WHERE tenant_id=$tid AND outlet_id=$oid AND is_active=1 LIMIT 1"
)->fetchColumn();
ok($tid>0 && $oid>0 && $lid>0, 'ada tenant/outlet/layanan utk test');

$uuid = 'test-'.bin2hex(random_bytes(8));
$payload = [
    'uuid'          => $uuid,
    'tempCode'      => 'OFF-ZZ-999',
    'tanggal'       => date('Y-m-d'),
    'nama_pelanggan'=> 'TEST OFFLINE',
    'items'         => [[
        'layanan_id'  => $lid,
        'nama_layanan'=> 'Test',
        'satuan'      => 'kg',
        'jumlah'      => 1,
        'harga_satuan'=> 1000,
        'subtotal'    => 1000,
    ]],
    'total'         => 1000,
    'dp'            => 1000,
    'metode_bayar'  => 'cash',
];
$user = ['id' => (int)$db->query("SELECT id FROM hl_users WHERE tenant_id=$tid LIMIT 1")->fetchColumn()];

$db->beginTransaction();
$r1 = OrderCreator::createOffline($db, $tid, $oid, $user, $payload);
ok($r1['ok'] === true, 'create pertama sukses: '.json_encode($r1));
$r2 = OrderCreator::createOffline($db, $tid, $oid, $user, $payload);
ok($r2['ok'] === true && !empty($r2['dedup']), 'create kedua dedup (idempoten): '.json_encode($r2));
eqv($r2['no_order'], $r1['no_order'], 'no_order sama (tak buat order baru)');
$cnt = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND offline_uuid=?");
$cnt->execute([$tid, $uuid]);
eqv((int)$cnt->fetchColumn(), 1, 'hanya 1 baris untuk uuid');
$db->rollBack(); // jangan kotori prod

echo "OK test_sync_endpoint\n";
