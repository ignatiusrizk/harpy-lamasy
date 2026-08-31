<?php
// tests/biaya_lainnya/test_biaya_lainnya_tier.php
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
require_once dirname(__DIR__, 2) . '/core/BiayaLainnyaTier.php';

$db = Database::get();

// ── calcFee() murni, tanpa DB ───────────────────────────
eqv(BiayaLainnyaTier::calcFee(['tipe_biaya'=>'flat', 'nilai_biaya'=>5000], 100000), 5000.0, 'calcFee flat = nilai_biaya apa adanya');
eqv(BiayaLainnyaTier::calcFee(['tipe_biaya'=>'percent', 'nilai_biaya'=>2], 100000), 2000.0, 'calcFee percent = round(subtotal * nilai/100)');
eqv(BiayaLainnyaTier::calcFee(['tipe_biaya'=>'percent', 'nilai_biaya'=>2.5], 33333), 833.0, 'calcFee percent dibulatkan (round)');

// ── activeForTenant() + calcAppliedFees() — pakai tenant test terisolasi ──
// tenant_id 18 = Harpy Laundry (sudah dipakai test lain sesi ini), outlet 13.
// Bersihkan dulu supaya test idempoten kalau dijalankan berulang.
$tid = 18; $oid = 13;
$db->prepare("DELETE FROM hl_biaya_lainnya_tier WHERE tenant_id=? AND nama LIKE 'TEST_%'")->execute([$tid]);

$insTier = $db->prepare("INSERT INTO hl_biaya_lainnya_tier (tenant_id, outlet_id, nama, tipe_biaya, nilai_biaya, is_active) VALUES (?,?,?,?,?,?)");
$insTier->execute([$tid, null, 'TEST_Admin', 'flat', 2000, 1]);       // global, aktif
$insTier->execute([$tid, null, 'TEST_PPN', 'percent', 2, 1]);         // global, aktif
$insTier->execute([$tid, null, 'TEST_Nonaktif', 'flat', 9999, 0]);    // global, TIDAK aktif
$insTier->execute([$tid, $oid, 'TEST_Admin', 'flat', 3000, 1]);       // outlet-specific, override TEST_Admin

$tiers = BiayaLainnyaTier::activeForTenant($tid, $oid);
$namas = array_column($tiers, 'nama');
ok(in_array('TEST_PPN', $namas, true), 'activeForTenant include tier global aktif');
ok(!in_array('TEST_Nonaktif', $namas, true), 'activeForTenant EXCLUDE tier nonaktif');
$adminRows = array_values(array_filter($tiers, fn($t) => $t['nama'] === 'TEST_Admin'));
eqv(count($adminRows), 1, 'activeForTenant: outlet-specific MENGGANTIKAN global (nama sama), bukan dijumlah dobel');
eqv((float)$adminRows[0]['nilai_biaya'], 3000.0, 'activeForTenant: nilai yg dipakai adalah versi outlet-specific (3000), bukan global (2000)');

$fees = BiayaLainnyaTier::calcAppliedFees($tid, $oid, 100000);
$feeByNama = [];
foreach ($fees as $f) { $feeByNama[$f['nama']] = $f['nominal']; }
eqv($feeByNama['TEST_Admin'] ?? null, 3000.0, 'calcAppliedFees pakai override outlet-specific utk TEST_Admin');
eqv($feeByNama['TEST_PPN'] ?? null, 2000.0, 'calcAppliedFees hitung percent dari subtotal (100000*2%=2000)');
ok(!isset($feeByNama['TEST_Nonaktif']), 'calcAppliedFees TIDAK include tier nonaktif');

// Cleanup
$db->prepare("DELETE FROM hl_biaya_lainnya_tier WHERE tenant_id=? AND nama LIKE 'TEST_%'")->execute([$tid]);

echo "\nAll tests passed.\n";
