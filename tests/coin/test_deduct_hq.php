<?php
// Test hqBillingOutletId resolution. deductHq/canAffordHq butuh sesi (TenantResolver) → diuji via MCP/manual.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/CoinLedger.php';

$db = Database::get();
$src = $db->query("SELECT * FROM tenants LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo "SKIP: tidak ada tenant\n"; exit(0); }
unset($src['id']);
// Override unique fields to avoid UNIQUE constraint violation
$src['slug']  = 'zz_test_hq_' . time();
$src['email'] = 'zz_test_hq_' . time() . '@test.invalid';
$c = array_keys($src);
$db->prepare("INSERT INTO tenants (".implode(',', $c).") VALUES (".implode(',', array_fill(0,count($c),'?')).")")->execute(array_values($src));
$tid = (int)$db->lastInsertId();
$osrc = $db->query("SELECT * FROM outlets LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$osrc) {
    $db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
    echo "SKIP: tidak ada outlet\n";
    exit(0);
}
function mkO(PDO $db, array $o, int $tid, string $n, int $main, string $status): int {
    unset($o['id']); $o['tenant_id']=$tid; $o['nama_outlet']=$n; $o['is_main']=$main; $o['status']=$status; $o['coin_balance']=0;
    // Override unique slug to avoid UNIQUE constraint violation
    $o['slug'] = 'zz_hq_' . $tid . '_' . preg_replace('/[^a-z0-9]/', '_', strtolower($n)) . '_' . time();
    if (array_key_exists('trial_coin_balance', $o)) $o['trial_coin_balance'] = 0;
    $c=array_keys($o);
    $db->prepare("INSERT INTO outlets (".implode(',', $c).") VALUES (".implode(',', array_fill(0,count($c),'?')).")")->execute(array_values($o));
    return (int)$db->lastInsertId();
}
$main = mkO($db, $osrc, $tid, 'ZZ_HQ_MAIN', 1, 'active');
$other= mkO($db, $osrc, $tid, 'ZZ_HQ_2',    0, 'active');
$closed=mkO($db, $osrc, $tid, 'ZZ_HQ_CLOSED',0,'closed');

$cleanup = function() use ($db,$tid){ $db->prepare("DELETE FROM outlets WHERE tenant_id=?")->execute([$tid]); $db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]); };
try {
    // hq_coin_outlet_id NULL → outlet UTAMA
    $db->prepare("UPDATE tenants SET hq_coin_outlet_id=NULL WHERE id=?")->execute([$tid]);
    ok(CoinLedger::hqBillingOutletId($tid) === $main, 'NULL → outlet UTAMA');
    // valid override → outlet itu
    $db->prepare("UPDATE tenants SET hq_coin_outlet_id=? WHERE id=?")->execute([$other, $tid]);
    ok(CoinLedger::hqBillingOutletId($tid) === $other, 'override valid → outlet itu');
    // override ke outlet closed → fallback UTAMA
    $db->prepare("UPDATE tenants SET hq_coin_outlet_id=? WHERE id=?")->execute([$closed, $tid]);
    ok(CoinLedger::hqBillingOutletId($tid) === $main, 'override closed → fallback UTAMA');
    echo "OK test_deduct_hq\n";
} finally { $cleanup(); }
