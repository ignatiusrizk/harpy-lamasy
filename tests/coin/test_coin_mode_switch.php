<?php
// Test CoinModeManager::switchMode dua arah + konservasi saldo.
// Pakai tenant+outlet TEMP (clone baris tenant existing utk penuhi NOT NULL), lalu hapus di akhir.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/CoinModeManager.php';

$db = Database::get();

// ── Setup: clone tenant existing → temp tenant ──
$src = $db->query("SELECT * FROM tenants LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo "SKIP: tidak ada tenant\n"; exit(0); }
unset($src['id']);
// Override unique fields to avoid UNIQUE constraint violation
$src['slug']  = 'zz_test_coin_' . time();
$src['email'] = 'zz_test_coin_' . time() . '@test.invalid';
$cols = array_keys($src);
$db->prepare("INSERT INTO tenants (".implode(',', $cols).") VALUES (".implode(',', array_fill(0,count($cols),'?')).")")
   ->execute(array_values($src));
$tid = (int)$db->lastInsertId();

// 2 outlet temp (clone baris outlet existing kalau ada, else minimal)
$osrc = $db->query("SELECT * FROM outlets LIMIT 1")->fetch(PDO::FETCH_ASSOC);
function mkOutlet(PDO $db, array $osrc, int $tid, string $nama, int $isMain, int $bal): int {
    unset($osrc['id']);
    $osrc['tenant_id'] = $tid; $osrc['nama_outlet'] = $nama; $osrc['is_main'] = $isMain;
    $osrc['coin_balance'] = $bal; $osrc['status'] = 'active';
    // Override unique slug to avoid UNIQUE constraint violation
    $osrc['slug'] = 'zz_test_' . $tid . '_' . preg_replace('/[^a-z0-9]/', '_', strtolower($nama)) . '_' . time();
    if (array_key_exists('trial_coin_balance', $osrc)) $osrc['trial_coin_balance'] = 0;
    $c = array_keys($osrc);
    $db->prepare("INSERT INTO outlets (".implode(',', $c).") VALUES (".implode(',', array_fill(0,count($c),'?')).")")
       ->execute(array_values($osrc));
    return (int)$db->lastInsertId();
}
$o1 = mkOutlet($db, $osrc, $tid, 'ZZ_TEST_MAIN', 1, 0);
$o2 = mkOutlet($db, $osrc, $tid, 'ZZ_TEST_2', 0, 0);

$cleanup = function() use ($db, $tid, $o1, $o2) {
    $db->prepare("DELETE FROM coin_ledger WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM outlets WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
};

try {
    // ── (a) shared → per_outlet: pool tenant 300000 → outlet UTAMA ──
    $db->prepare("UPDATE tenants SET coin_mode='shared', coin_balance=300000 WHERE id=?")->execute([$tid]);
    $db->prepare("UPDATE outlets SET coin_balance=0 WHERE tenant_id=?")->execute([$tid]);
    $r = CoinModeManager::switchMode($tid, 'per_outlet', 'test');
    ok($r['ok'] === true && $r['moved'] === 300000, '(a) switch ke per_outlet ok, moved=300000');
    $mode = $db->query("SELECT coin_mode FROM tenants WHERE id=$tid")->fetchColumn();
    $tpool = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$tid")->fetchColumn();
    $b1 = (int)$db->query("SELECT coin_balance FROM outlets WHERE id=$o1")->fetchColumn();
    $b2 = (int)$db->query("SELECT coin_balance FROM outlets WHERE id=$o2")->fetchColumn();
    ok($mode === 'per_outlet', '(a) mode jadi per_outlet');
    ok($tpool === 0, '(a) tenant pool jadi 0');
    ok($b1 === 300000, '(a) outlet UTAMA dapat 300000');
    ok($b2 === 0, '(a) outlet non-utama tetap 0');
    ok((int)$db->query("SELECT COUNT(*) FROM coin_ledger WHERE tenant_id=$tid")->fetchColumn() === 2, '(a) 2 entri ledger');

    // ── (b) per_outlet → shared: SUM outlet (300000 + 50000) → tenant ──
    $db->prepare("UPDATE outlets SET coin_balance=50000 WHERE id=?")->execute([$o2]); // total outlet = 350000
    $r2 = CoinModeManager::switchMode($tid, 'shared', 'test');
    ok($r2['ok'] === true && $r2['moved'] === 350000, '(b) switch ke shared ok, moved=350000');
    $mode2 = $db->query("SELECT coin_mode FROM tenants WHERE id=$tid")->fetchColumn();
    $tpool2 = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$tid")->fetchColumn();
    $sumOut = (int)$db->query("SELECT COALESCE(SUM(coin_balance),0) FROM outlets WHERE tenant_id=$tid")->fetchColumn();
    ok($mode2 === 'shared', '(b) mode jadi shared');
    ok($tpool2 === 350000, '(b) tenant pool = 350000 (konservasi)');
    ok($sumOut === 0, '(b) semua outlet jadi 0');

    // ── (c) no-op saat mode sama ──
    $r3 = CoinModeManager::switchMode($tid, 'shared', 'test');
    ok($r3['ok'] === true && $r3['moved'] === 0, '(c) switch ke mode yang sama → no-op moved=0');

    // ── (d) mode tidak valid ──
    $r4 = CoinModeManager::switchMode($tid, 'bogus', 'test');
    ok($r4['ok'] === false, '(d) mode invalid ditolak');

    echo "OK test_coin_mode_switch\n";
} finally {
    $cleanup();
}
