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
$db = Database::get();

$oc = $db->query("SHOW COLUMNS FROM outlets")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('penerima', $oc), 'outlets.penerima ada');
ok(in_array('kode_pos', $oc), 'outlets.kode_pos ada');

$t = $db->query("SHOW TABLES LIKE 'saas_welcome_kit'")->fetchAll();
ok(count($t) === 1, 'tabel saas_welcome_kit ada');
$wc = $db->query("SHOW COLUMNS FROM saas_welcome_kit")->fetchAll(PDO::FETCH_COLUMN);
foreach (['tenant_id','outlet_id','payment_id','trigger','penerima','hp','alamat','kota','kode_pos','items_json','status','kurir','resi','shipped_at','delivered_at','catatan'] as $c) {
    ok(in_array($c, $wc), "saas_welcome_kit.$c ada");
}

echo "OK test_welcome_kit (schema)\n";

// ── WelcomeKit domain logic tests ──────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/core/BillingConfig.php';
require_once dirname(__DIR__, 2) . '/core/WelcomeKit.php';

// items() decode
ok(count(WelcomeKit::items()) >= 1, 'items() decode config (>=1 item)');

// Siapkan tenant+outlet+payment sintetis
$db->beginTransaction();
$db->prepare("INSERT INTO tenants (nama_perusahaan, owner_name, owner_wa, slug, status, coin_balance, created_at)
              VALUES ('WK-TEST','t','0', ?, 'active', 0, NOW())")->execute(['wk-'.time().rand(100,999)]);
$tid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO outlets (tenant_id, nama_outlet, slug, status, coin_balance, is_main, setup_done, penerima, telepon, alamat, kota, kode_pos)
              VALUES (?, 'WK-OUTLET', ?, 'active', 0, 1, 0, 'Budi', '08123', 'Jl. Uji 1', 'Bandung', '40111')")
   ->execute([$tid, 'wk-out-'.$tid]);
$oid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
              VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute([$tid, $oid, 'WK-ORD-'.$tid]);
$pid = (int)$db->lastInsertId();
$db->commit();

try {
    // (a) create → 1 record pending, snapshot benar
    $r1 = WelcomeKit::createForOutlet($db, $tid, $oid, $pid, 'outlet_activation');
    ok(!empty($r1['ok']) && !empty($r1['id']), 'createForOutlet ok + id');
    $row = $db->query("SELECT * FROM saas_welcome_kit WHERE id=".(int)$r1['id'])->fetch(PDO::FETCH_ASSOC);
    eqv($row['status'], 'pending', 'status pending');
    eqv($row['penerima'], 'Budi', 'snapshot penerima');
    eqv($row['kode_pos'], '40111', 'snapshot kode_pos');
    ok(strpos($row['items_json'], 'thermal') !== false, 'snapshot items berisi thermal');

    // (b) idempoten: create 2× payment sama → tetap 1
    $r2 = WelcomeKit::createForOutlet($db, $tid, $oid, $pid, 'outlet_activation');
    ok(!empty($r2['skipped']), 'create kedua skipped (idempoten)');
    $cnt = (int)$db->query("SELECT COUNT(*) FROM saas_welcome_kit WHERE payment_id=".$pid)->fetchColumn();
    eqv($cnt, 1, 'tetap 1 record utk payment sama');

    // (c) markShipped + markDelivered
    ok(WelcomeKit::markShipped((int)$r1['id'], 'JNE', 'RESI123'), 'markShipped ok');
    $row = $db->query("SELECT status,kurir,resi,shipped_at FROM saas_welcome_kit WHERE id=".(int)$r1['id'])->fetch(PDO::FETCH_ASSOC);
    eqv($row['status'], 'shipped', 'status shipped'); eqv($row['kurir'], 'JNE', 'kurir'); eqv($row['resi'], 'RESI123', 'resi');
    ok(!empty($row['shipped_at']), 'shipped_at terisi');
    ok(WelcomeKit::markDelivered((int)$r1['id']), 'markDelivered ok');
    eqv($db->query("SELECT status FROM saas_welcome_kit WHERE id=".(int)$r1['id'])->fetchColumn(), 'delivered', 'status delivered');

    // (d) statusForOutlet
    $st = WelcomeKit::statusForOutlet($oid);
    ok($st && $st['status'] === 'delivered', 'statusForOutlet delivered');

    // (e) disabled → no-op
    $db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
                  VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
       ->execute([$tid, $oid, 'WK-ORD2-'.$tid]);
    $pid2 = (int)$db->lastInsertId();
    BillingConfig::set('welcome_kit_enabled', '0', null);
    ok(!WelcomeKit::enabled(), 'enabled() false saat config 0');
    $r3 = WelcomeKit::createForOutlet($db, $tid, $oid, $pid2, 'outlet_activation');
    ok(empty($r3['ok']) && !empty($r3['skipped']), 'disabled → skip create');
    $cnt2 = (int)$db->query("SELECT COUNT(*) FROM saas_welcome_kit WHERE payment_id=".$pid2)->fetchColumn();
    eqv($cnt2, 0, 'disabled → 0 record');
    // (f) settleOutletActivation membuat welcome_kit
    BillingConfig::set('welcome_kit_enabled', '1', null); // restore dari (e)
    require_once dirname(__DIR__, 2) . '/core/PaymentSettler.php';
    $db->prepare("UPDATE outlets SET status='pending' WHERE id=?")->execute([$oid]);
    $db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
                  VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
       ->execute([$tid, $oid, 'WK-ORD3-'.$tid]);
    $pid3 = (int)$db->lastInsertId();
    $res = PaymentSettler::settleOutletActivation((function() use ($db,$pid3){ $s=$db->prepare("SELECT * FROM saas_payments WHERE id=?"); $s->execute([$pid3]); return $s->fetch(PDO::FETCH_ASSOC); })());
    ok(!empty($res['ok']), 'settleOutletActivation ok');
    $kitCnt = (int)$db->query("SELECT COUNT(*) FROM saas_welcome_kit WHERE payment_id=".$pid3)->fetchColumn();
    eqv($kitCnt, 1, 'settle membuat 1 welcome_kit');
} finally {
    // Cleanup — always run even on failure (config restore BEFORE row cleanup)
    BillingConfig::set('welcome_kit_enabled', '1', null);
    $db->prepare("DELETE FROM saas_welcome_kit WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM saas_payments WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM outlets WHERE id=?")->execute([$oid]);
    $db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
}

echo "OK test_welcome_kit\n";
