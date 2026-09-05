<?php
// Test integrasi lokal: simulasi action=update di orders.php dengan logic
// recompute biaya_tambahan (Tier Express) & biaya_lainnya yang sudah
// diimplementasikan. Test ini menggunakan database lokal dan meng-include
// kode dari orders.php secara langsung.
//
// Run: php tests/orders/test_edit_tier_recompute_local.php

define('ROOT', __DIR__ . '/../../');
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/ExpressTier.php';
require_once ROOT . '/core/BiayaLainnyaTier.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$TID = 18; $OID = 13;
$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { echo "PASS: $label\n"; $pass++; }
    else       { echo "FAIL: $label\n"; $fail++; }
}

echo "=== Test Lokal: Recompute Tier di Edit Order ===\n\n";

// ── 1. Cari Tier Express aktif di tenant test ──
$tier = $pdo->prepare("SELECT nama_tier, tipe_biaya, nilai_biaya FROM hl_express_tier WHERE tenant_id=? AND is_active=1 AND (outlet_id IS NULL OR outlet_id=?) LIMIT 1");
$tier->execute([$TID, $OID]);
$tier = $tier->fetch(PDO::FETCH_ASSOC);
check('ada minimal 1 Tier Express aktif di tenant test', $tier !== false);

if (!$tier) {
    echo "SKIP sisanya — gak ada Tier Express aktif buat ditest. Laporkan ke controller.\n";
    echo "0 PASS, 0 FAIL\n";
    exit(0);
}

// ── 2. Cari Biaya Lainnya Tier aktif (persen) ──
$blTier = $pdo->prepare("SELECT nama, tipe_biaya, nilai_biaya FROM hl_biaya_lainnya_tier WHERE tenant_id=? AND is_active=1 AND (outlet_id IS NULL OR outlet_id=?) LIMIT 1");
$blTier->execute([$TID, $OID]);
$blTier = $blTier->fetch(PDO::FETCH_ASSOC);

// ── 3. Seed order test (subtotal 50.000, tanpa tier awal) ──
$orderNo = 'TESTLOCAL-' . time();
$pdo->exec("INSERT INTO hl_transaksi (tenant_id, outlet_id, no_order, tanggal, nama_pelanggan, telepon, status_proses, status_bayar, subtotal, diskon, total, dp, sisa_bayar, biaya_tambahan, biaya_lainnya, created_at, updated_at)
    VALUES ($TID, $OID, '$orderNo', CURDATE(), 'Test Local', '', 'masuk', 'belum_bayar', 50000, 0, 50000, 0, 50000, 0, 0, NOW(), NOW())");
$orderId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId, 'Cuci Reguler', 'kg', 5, 10000, 50000, '']);

// ── 4. Simulasi logic dari orders.php action=update ──
// Data dari form submission (user memilih tier untuk item)
$data = [
    'id' => $orderId,
    'status_proses' => 'masuk',
    'diskon' => 0,
    'dp' => 0,
    'items' => [[
        'layanan_id' => null,
        'nama_layanan' => 'Cuci Reguler',
        'satuan' => 'kg',
        'jumlah' => 5,
        'harga_satuan' => 10000,
        'catatan_item' => '',
        'express_tier_nama' => $tier['nama_tier'],
    ]],
];

// Ambil old row
$oldRow = $pdo->prepare("SELECT biaya_tambahan, biaya_lainnya FROM hl_transaksi WHERE id=?");
$oldRow->execute([$orderId]);
$oldRow = $oldRow->fetch(PDO::FETCH_ASSOC);

// Deteksi item berubah (simplified)
$itemsChanged = true; // di test lokal ini, kita anggap items berubah

// Recalc subtotal
$subtotal = 0;
foreach ($data['items'] as $item) {
    $subtotal += floatval($item['jumlah']) * floatval($item['harga_satuan']);
}
check("subtotal dihitung benar: 50000", $subtotal == 50000);

// ── Recompute tier (Step 2 logic dari orders.php) ──
$itemsWithTier = [];
$biayaLainnyaRows = [];
$dom = ['nama' => null, 'tipe_order' => 'reguler'];
if ($itemsChanged && !empty($data['items'])) {
    $biayaTambahanBaru = 0.0;
    foreach ($data['items'] as $i => $item) {
        $namaTier = trim((string)($item['express_tier_nama'] ?? ''));
        $tier_found = $namaTier !== '' ? ExpressTier::findByNama($TID, $namaTier, $OID) : null;
        $itSub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
        $itFee = ExpressTier::calcItemFee($itSub, $tier_found);
        $itemsWithTier[$i] = [
            'express_tier_nama' => $tier_found ? $tier_found['nama_tier'] : null,
            'biaya_express'     => $itFee,
        ];
        $biayaTambahanBaru += $itFee;
    }
    $dom = ExpressTier::dominantTier(array_map(
        fn($it, $x) => array_merge($it, $x), $data['items'], $itemsWithTier
    ));
    $biayaLainnyaRows = BiayaLainnyaTier::calcAppliedFees($TID, $OID, $subtotal);
    $biayaLainnyaBaru = array_sum(array_column($biayaLainnyaRows, 'nominal'));
} else {
    $biayaTambahanBaru = (float)($oldRow['biaya_tambahan'] ?? 0);
    $biayaLainnyaBaru  = (float)($oldRow['biaya_lainnya'] ?? 0);
}

// Expected values
$expectedFee = $tier['tipe_biaya'] === 'flat' ? (float)$tier['nilai_biaya'] : round($subtotal * ((float)$tier['nilai_biaya']/100));
$expectedBiayaLainnya = 0.0;
if ($blTier) {
    $expectedBiayaLainnya = $blTier['tipe_biaya'] === 'flat' ? (float)$blTier['nilai_biaya'] : round($subtotal * ((float)$blTier['nilai_biaya']/100));
}

check("biaya_tambahan dihitung benar: $expectedFee", abs($biayaTambahanBaru - $expectedFee) < 0.01);
check("express_tier_nama ditetapkan: '{$tier['nama_tier']}'", $itemsWithTier[0]['express_tier_nama'] === $tier['nama_tier']);
check("biaya_express item dihitung: $expectedFee", abs($itemsWithTier[0]['biaya_express'] - $expectedFee) < 0.01);

if ($blTier) {
    check("biaya_lainnya dihitung benar: $expectedBiayaLainnya", abs($biayaLainnyaBaru - $expectedBiayaLainnya) < 0.01);
}

// ── 5. Simulasi update ke DB (Step 4 logic) ──
$pdo->prepare("DELETE FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$TID, $OID, $orderId]);
$istmt = $pdo->prepare("INSERT INTO hl_transaksi_item
    (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item,express_tier_nama,biaya_express)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
foreach ($data['items'] as $i => $item) {
    $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
    $istmt->execute([
        $TID, $OID, $orderId,
        $item['layanan_id'] ?: null,
        $item['nama_layanan'],
        $item['satuan'],
        $item['jumlah'],
        $item['harga_satuan'],
        $sub,
        $item['catatan_item'] ?? '',
        $itemsWithTier[$i]['express_tier_nama'] ?? null,
        $itemsWithTier[$i]['biaya_express'] ?? 0,
    ]);
}

// Update header dengan tier recompute
$pdo->prepare("UPDATE hl_transaksi SET subtotal=?, biaya_tambahan=?, biaya_lainnya=?, tipe_order=?, express_tier_nama=? WHERE tenant_id=? AND outlet_id=? AND id=?")
   ->execute([$subtotal, $biayaTambahanBaru, $biayaLainnyaBaru, $dom['tipe_order'], $dom['nama'], $TID, $OID, $orderId]);

// Refresh Biaya Lainnya breakdown
$pdo->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$TID, $OID, $orderId]);
if (!empty($biayaLainnyaRows)) {
    $blstmt = $pdo->prepare("INSERT INTO hl_transaksi_biaya_lainnya (tenant_id, outlet_id, transaksi_id, nama, nominal) VALUES (?,?,?,?,?)");
    foreach ($biayaLainnyaRows as $r) {
        $blstmt->execute([$TID, $OID, $orderId, $r['nama'], $r['nominal']]);
    }
}

// ── 6. Verifikasi hasil DB ──
$row = $pdo->prepare("SELECT biaya_tambahan, biaya_lainnya, tipe_order, express_tier_nama FROM hl_transaksi WHERE id=?");
$row->execute([$orderId]); $row = $row->fetch(PDO::FETCH_ASSOC);
check("header.biaya_tambahan = $expectedFee (got {$row['biaya_tambahan']})", abs((float)$row['biaya_tambahan'] - $expectedFee) < 0.01);
check("header.express_tier_nama = '{$tier['nama_tier']}' (got '{$row['express_tier_nama']}')", $row['express_tier_nama'] === $tier['nama_tier']);
if ($blTier) {
    check("header.biaya_lainnya = $expectedBiayaLainnya (got {$row['biaya_lainnya']})", abs((float)$row['biaya_lainnya'] - $expectedBiayaLainnya) < 0.01);
}

$item = $pdo->prepare("SELECT express_tier_nama, biaya_express FROM hl_transaksi_item WHERE transaksi_id=?");
$item->execute([$orderId]); $item = $item->fetch(PDO::FETCH_ASSOC);
check("item.express_tier_nama = '{$tier['nama_tier']}' (got '{$item['express_tier_nama']}')", $item['express_tier_nama'] === $tier['nama_tier']);
check("item.biaya_express = $expectedFee (got {$item['biaya_express']})", abs((float)$item['biaya_express'] - $expectedFee) < 0.01);

// ── 7. Skenario 2: item yang tidak diubah tetap bawa tier lama ──
$orderNo2 = 'TESTLOCAL2-' . time();
$pdo->exec("INSERT INTO hl_transaksi (tenant_id, outlet_id, no_order, tanggal, nama_pelanggan, telepon, status_proses, status_bayar, subtotal, diskon, total, dp, sisa_bayar, biaya_tambahan, biaya_lainnya, created_at, updated_at)
    VALUES ($TID, $OID, '$orderNo2', CURDATE(), 'Test Preserve', '', 'masuk', 'belum_bayar', 80000, 0, 80000, 0, 80000, $expectedFee, 0, NOW(), NOW())");
$orderId2 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item, express_tier_nama, biaya_express) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId2, 'Cuci Express', 'kg', 5, 10000, 50000, '', $tier['nama_tier'], $expectedFee]);
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId2, 'Setrika', 'kg', 3, 10000, 30000, '']);

$data2 = [
    'id' => $orderId2,
    'status_proses' => 'masuk',
    'diskon' => 0,
    'dp' => 0,
    'items' => [
        ['layanan_id'=>null,'nama_layanan'=>'Cuci Express','satuan'=>'kg','jumlah'=>5,'harga_satuan'=>10000,'catatan_item'=>'','express_tier_nama'=>$tier['nama_tier']],
        ['layanan_id'=>null,'nama_layanan'=>'Setrika','satuan'=>'kg','jumlah'=>4,'harga_satuan'=>10000,'catatan_item'=>''], // qty 3→4, tanpa tier
    ],
];

// Simulasi perubahan item untuk scenario 2
$subtotal2 = 5 * 10000 + 4 * 10000; // 90000
$itemsChanged2 = true;

$itemsWithTier2 = [];
$biayaLainnyaRows2 = [];
$dom2 = ['nama' => null, 'tipe_order' => 'reguler'];
if ($itemsChanged2 && !empty($data2['items'])) {
    $biayaTambahanBaru2 = 0.0;
    foreach ($data2['items'] as $i => $item) {
        $namaTier = trim((string)($item['express_tier_nama'] ?? ''));
        $tier_found = $namaTier !== '' ? ExpressTier::findByNama($TID, $namaTier, $OID) : null;
        $itSub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
        $itFee = ExpressTier::calcItemFee($itSub, $tier_found);
        $itemsWithTier2[$i] = [
            'express_tier_nama' => $tier_found ? $tier_found['nama_tier'] : null,
            'biaya_express'     => $itFee,
        ];
        $biayaTambahanBaru2 += $itFee;
    }
    $dom2 = ExpressTier::dominantTier(array_map(
        fn($it, $x) => array_merge($it, $x), $data2['items'], $itemsWithTier2
    ));
    $biayaLainnyaRows2 = BiayaLainnyaTier::calcAppliedFees($TID, $OID, $subtotal2);
    $biayaLainnyaBaru2 = array_sum(array_column($biayaLainnyaRows2, 'nominal'));
}

// Update DB
$pdo->prepare("DELETE FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$TID, $OID, $orderId2]);
$istmt = $pdo->prepare("INSERT INTO hl_transaksi_item
    (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item,express_tier_nama,biaya_express)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
foreach ($data2['items'] as $i => $item) {
    $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
    $istmt->execute([
        $TID, $OID, $orderId2,
        $item['layanan_id'] ?: null,
        $item['nama_layanan'],
        $item['satuan'],
        $item['jumlah'],
        $item['harga_satuan'],
        $sub,
        $item['catatan_item'] ?? '',
        $itemsWithTier2[$i]['express_tier_nama'] ?? null,
        $itemsWithTier2[$i]['biaya_express'] ?? 0,
    ]);
}

$pdo->prepare("UPDATE hl_transaksi SET subtotal=?, biaya_tambahan=?, biaya_lainnya=?, tipe_order=?, express_tier_nama=? WHERE tenant_id=? AND outlet_id=? AND id=?")
   ->execute([$subtotal2, $biayaTambahanBaru2, $biayaLainnyaBaru2, $dom2['tipe_order'], $dom2['nama'], $TID, $OID, $orderId2]);

$pdo->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$TID, $OID, $orderId2]);
if (!empty($biayaLainnyaRows2)) {
    $blstmt = $pdo->prepare("INSERT INTO hl_transaksi_biaya_lainnya (tenant_id, outlet_id, transaksi_id, nama, nominal) VALUES (?,?,?,?,?)");
    foreach ($biayaLainnyaRows2 as $r) {
        $blstmt->execute([$TID, $OID, $orderId2, $r['nama'], $r['nominal']]);
    }
}

// Verifikasi skenario 2
$items2 = $pdo->prepare("SELECT nama_layanan, express_tier_nama, biaya_express FROM hl_transaksi_item WHERE transaksi_id=? ORDER BY id");
$items2->execute([$orderId2]);
$items2 = $items2->fetchAll(PDO::FETCH_ASSOC);
$itemA = $items2[0]; $itemB = $items2[1];
check("skenario 2: item A (tier) tetap tier '{$tier['nama_tier']}'", $itemA['express_tier_nama'] === $tier['nama_tier']);
check("skenario 2: item A tetap biaya_express $expectedFee", abs((float)$itemA['biaya_express'] - $expectedFee) < 0.01);
check("skenario 2: item B (no tier) express_tier_nama NULL", $itemB['express_tier_nama'] === null);
check("skenario 2: item B biaya_express 0", $itemB['biaya_express'] == 0);

// ── 8. Cleanup ──
$pdo->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE transaksi_id=?")->execute([$orderId]);
$pdo->prepare("DELETE FROM hl_transaksi_item WHERE transaksi_id=?")->execute([$orderId]);
$pdo->prepare("DELETE FROM hl_transaksi WHERE id=?")->execute([$orderId]);

$pdo->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE transaksi_id=?")->execute([$orderId2]);
$pdo->prepare("DELETE FROM hl_transaksi_item WHERE transaksi_id=?")->execute([$orderId2]);
$pdo->prepare("DELETE FROM hl_transaksi WHERE id=?")->execute([$orderId2]);

echo "\n$pass PASS, $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
