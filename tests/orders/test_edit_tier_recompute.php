<?php
// Test integrasi: action=update di orders.php recompute biaya_tambahan
// (Tier Express) & biaya_lainnya (Biaya Lainnya Tier) saat item order
// diedit. Hit endpoint ASLI via HTTP (curl+session), bukan simulasi —
// supaya benar2 nge-test wiring-nya, bukan cuma algoritma.
//
// Run: php tests/orders/test_edit_tier_recompute.php
// Server lokal harus sudah jalan: php -S localhost:8091 -t /path/to/worktree

require_once __DIR__ . '/../../master/config/db.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$BASE = 'http://localhost:8091';
$TID = 18; $OID = 13;
$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { echo "PASS: $label\n"; $pass++; }
    else       { echo "FAIL: $label\n"; $fail++; }
}

// ── 1. Fetch CSRF token dari login.php, lalu login ──
$cookieFile = tempnam(sys_get_temp_dir(), 'lmcookie');

// GET login.php untuk ambil CSRF token (dan init session cookie)
$ch = curl_init("$BASE/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true,
]);
$html = curl_exec($ch); curl_close($ch);

// Extract CSRF token dari form (name="_csrf" value="...")
preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m);
$csrf = $m[1] ?? '';
check('fetch login page & dapat csrf token', $csrf !== '');

// POST login dengan CSRF token (gunakan cookie jar yang sama)
$ch = curl_init("$BASE/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username'=>'admintest','password'=>'123456','_csrf'=>$csrf]),
    CURLOPT_FOLLOWLOCATION => true,
]);
$loginResp = curl_exec($ch); curl_close($ch);
// Jika login gagal, akan di-redirect balik ke login.php. Cek apakah ada error message di response.
$loginFailed = strpos($loginResp, 'tidak valid') !== false || strpos($loginResp, 'Username atau password') !== false;
check('login berhasil (tanpa CSRF/auth error)', !$loginFailed);

// ── 2. Cari 1 Tier Express aktif & 1 Biaya Lainnya Tier aktif (persen) di tenant ini ──
$tier = $pdo->prepare("SELECT nama_tier, tipe_biaya, nilai_biaya FROM hl_express_tier WHERE tenant_id=? AND is_active=1 AND (outlet_id IS NULL OR outlet_id=?) LIMIT 1");
$tier->execute([$TID, $OID]);
$tier = $tier->fetch(PDO::FETCH_ASSOC);
check('ada minimal 1 Tier Express aktif di tenant test', $tier !== false);

$blTier = $pdo->prepare("SELECT nama, tipe_biaya, nilai_biaya FROM hl_biaya_lainnya_tier WHERE tenant_id=? AND is_active=1 AND (outlet_id IS NULL OR outlet_id=?) LIMIT 1");
$blTier->execute([$TID, $OID]);
$blTier = $blTier->fetch(PDO::FETCH_ASSOC);

if (!$tier) { echo "SKIP sisanya — gak ada Tier Express aktif buat ditest.\n"; exit(1); }

// ── 3. Seed order test langsung ke DB (2 item, subtotal awal 50.000, tanpa tier) ──
$pdo->exec("INSERT INTO hl_transaksi (tenant_id, outlet_id, no_order, tanggal, nama_pelanggan, telepon, status_proses, status_bayar, subtotal, diskon, total, dp, sisa_bayar, biaya_tambahan, biaya_lainnya, created_at, updated_at)
    VALUES ($TID, $OID, 'TESTTIER-" . time() . "', CURDATE(), 'Test Tier Recompute', '', 'masuk', 'belum_bayar', 50000, 0, 50000, 0, 50000, 0, 0, NOW(), NOW())");
$orderId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId, 'Cuci Reguler', 'kg', 5, 10000, 50000, '']);

// ── 4. PUT action=update: ubah item jadi pakai tier Express ──
$itemSub = 5 * 10000;
$expectedFee = $tier['tipe_biaya'] === 'flat' ? (float)$tier['nilai_biaya'] : round($itemSub * ((float)$tier['nilai_biaya']/100));
$expectedBiayaLainnya = 0.0;
if ($blTier) {
    $expectedBiayaLainnya = $blTier['tipe_biaya'] === 'flat' ? (float)$blTier['nilai_biaya'] : round($itemSub * ((float)$blTier['nilai_biaya']/100));
}
$payload = json_encode([
    'id' => $orderId,
    'status_proses' => 'masuk',
    'diskon' => 0,
    'dp' => 0,
    'items' => [[
        'layanan_id' => null, 'nama_layanan' => 'Cuci Reguler', 'satuan' => 'kg',
        'jumlah' => 5, 'harga_satuan' => 10000, 'catatan_item' => '',
        'express_tier_nama' => $tier['nama_tier'],
    ]],
]);
$ch = curl_init("$BASE/orders.php?action=update");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"],
]);
$resp = json_decode(curl_exec($ch), true); curl_close($ch);
check('action=update tidak error', empty($resp['error']));

// ── 5. Verifikasi DB langsung ──
$row = $pdo->prepare("SELECT biaya_tambahan, biaya_lainnya, tipe_order, express_tier_nama FROM hl_transaksi WHERE id=?");
$row->execute([$orderId]); $row = $row->fetch(PDO::FETCH_ASSOC);
check("biaya_tambahan header = $expectedFee (got {$row['biaya_tambahan']})", abs((float)$row['biaya_tambahan'] - $expectedFee) < 0.01);
check("express_tier_nama header = '{$tier['nama_tier']}' (got '{$row['express_tier_nama']}')", $row['express_tier_nama'] === $tier['nama_tier']);
if ($blTier) {
    check("biaya_lainnya header = $expectedBiayaLainnya (got {$row['biaya_lainnya']})", abs((float)$row['biaya_lainnya'] - $expectedBiayaLainnya) < 0.01);
}

$item = $pdo->prepare("SELECT express_tier_nama, biaya_express FROM hl_transaksi_item WHERE transaksi_id=?");
$item->execute([$orderId]); $item = $item->fetch(PDO::FETCH_ASSOC);
check("item.express_tier_nama = '{$tier['nama_tier']}' (got '{$item['express_tier_nama']}')", $item['express_tier_nama'] === $tier['nama_tier']);
check("item.biaya_express = $expectedFee (got {$item['biaya_express']})", abs((float)$item['biaya_express'] - $expectedFee) < 0.01);

// ── Skenario 2: item yang gak diubah tetap bawa tier lamanya ──
$pdo->exec("INSERT INTO hl_transaksi (tenant_id, outlet_id, no_order, tanggal, nama_pelanggan, telepon, status_proses, status_bayar, subtotal, diskon, total, dp, sisa_bayar, biaya_tambahan, biaya_lainnya, created_at, updated_at)
    VALUES ($TID, $OID, 'TESTTIER2-" . time() . "', CURDATE(), 'Test Preserve Tier', '', 'masuk', 'belum_bayar', 80000, 0, 80000, 0, 80000, $expectedFee, 0, NOW(), NOW())");
$orderId2 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item, express_tier_nama, biaya_express) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId2, 'Cuci Express', 'kg', 5, 10000, 50000, '', $tier['nama_tier'], $expectedFee]);
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId2, 'Setrika', 'kg', 3, 10000, 30000, '']);

$payload2 = json_encode([
    'id' => $orderId2, 'status_proses' => 'masuk', 'diskon' => 0, 'dp' => 0,
    'items' => [
        ['layanan_id'=>null,'nama_layanan'=>'Cuci Express','satuan'=>'kg','jumlah'=>5,'harga_satuan'=>10000,'catatan_item'=>'','express_tier_nama'=>$tier['nama_tier']],
        ['layanan_id'=>null,'nama_layanan'=>'Setrika','satuan'=>'kg','jumlah'=>4,'harga_satuan'=>10000,'catatan_item'=>''], // qty berubah 3→4, tanpa tier
    ],
]);
$ch = curl_init("$BASE/orders.php?action=update");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload2,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"],
]);
$resp2 = json_decode(curl_exec($ch), true); curl_close($ch);
check('skenario 2: action=update tidak error', empty($resp2['error']));

$items2 = $pdo->prepare("SELECT nama_layanan, express_tier_nama, biaya_express FROM hl_transaksi_item WHERE transaksi_id=? ORDER BY id");
$items2->execute([$orderId2]);
$items2 = $items2->fetchAll(PDO::FETCH_ASSOC);
$itemA = $items2[0]; $itemB = $items2[1];
check("item A (gak diubah) tetap tier '{$tier['nama_tier']}'", $itemA['express_tier_nama'] === $tier['nama_tier']);
check("item A (gak diubah) tetap biaya_express $expectedFee", abs((float)$itemA['biaya_express'] - $expectedFee) < 0.01);
check("item B (qty berubah, tetap tanpa tier) express_tier_nama NULL", $itemB['express_tier_nama'] === null);

// ── Skenario 3: Fix untuk asymmetric comparison — order dengan tier sudah ada, update status saja (tanpa ubah item) ──
// Ini test kasus BUG yang diperbaiki: sebelumnya $itemsChanged=true karena $oldItemsStmt
// tidak include express_tier_nama, sekarang seharusnya $itemsChanged=false.
$pdo->exec("INSERT INTO hl_transaksi (tenant_id, outlet_id, no_order, tanggal, nama_pelanggan, telepon, status_proses, status_bayar, subtotal, diskon, total, dp, sisa_bayar, biaya_tambahan, biaya_lainnya, created_at, updated_at)
    VALUES ($TID, $OID, 'TESTTIER3-" . time() . "', CURDATE(), 'Test Item Unchanged', '', 'masuk', 'belum_bayar', 50000, 0, 50000, 0, 50000, $expectedFee, 0, NOW(), NOW())");
$orderId3 = (int)$pdo->lastInsertId();
// Insert item WITH tier already set
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item, express_tier_nama, biaya_express) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId3, 'Cuci Express', 'kg', 5, 10000, 50000, '', $tier['nama_tier'], $expectedFee]);

// Update dengan items PERSIS SAMA (sama nama, qty, harga, tier, catatan)
// tapi ubah status_proses dari 'masuk' ke 'cuci'
$payload3 = json_encode([
    'id' => $orderId3,
    'status_proses' => 'cuci',  // berubah dari 'masuk'
    'diskon' => 0,
    'dp' => 0,
    'items' => [[
        'layanan_id' => null, 'nama_layanan' => 'Cuci Express', 'satuan' => 'kg',
        'jumlah' => 5, 'harga_satuan' => 10000, 'catatan_item' => '',
        'express_tier_nama' => $tier['nama_tier'],  // SAMA dengan di DB
    ]],
]);
$ch = curl_init("$BASE/orders.php?action=update");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload3,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"],
]);
$resp3 = json_decode(curl_exec($ch), true); curl_close($ch);
check('skenario 3: status update tanpa ubah item → tidak error', empty($resp3['error']));

// Verify bahwa item di DB TIDAK berubah (tier & biaya_express tetap sama)
$item3 = $pdo->prepare("SELECT express_tier_nama, biaya_express FROM hl_transaksi_item WHERE transaksi_id=?");
$item3->execute([$orderId3]); $item3 = $item3->fetch(PDO::FETCH_ASSOC);
check("skenario 3: item.express_tier_nama tetap '{$tier['nama_tier']}'", $item3['express_tier_nama'] === $tier['nama_tier']);
check("skenario 3: item.biaya_express tetap $expectedFee", abs((float)$item3['biaya_express'] - $expectedFee) < 0.01);

// Verify header status berubah (bukan tetap 'masuk')
$order3 = $pdo->prepare("SELECT status_proses FROM hl_transaksi WHERE id=?");
$order3->execute([$orderId3]); $order3 = $order3->fetch(PDO::FETCH_ASSOC);
check("skenario 3: status_proses berubah ke 'cuci'", $order3['status_proses'] === 'cuci');

// ── 6. Cleanup ──
$pdo->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE transaksi_id=?")->execute([$orderId]);
$pdo->prepare("DELETE FROM hl_transaksi_item WHERE transaksi_id=?")->execute([$orderId]);
$pdo->prepare("DELETE FROM hl_transaksi WHERE id=?")->execute([$orderId]);

$pdo->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE transaksi_id=?")->execute([$orderId2]);
$pdo->prepare("DELETE FROM hl_transaksi_item WHERE transaksi_id=?")->execute([$orderId2]);
$pdo->prepare("DELETE FROM hl_transaksi WHERE id=?")->execute([$orderId2]);

$pdo->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE transaksi_id=?")->execute([$orderId3]);
$pdo->prepare("DELETE FROM hl_transaksi_item WHERE transaksi_id=?")->execute([$orderId3]);
$pdo->prepare("DELETE FROM hl_transaksi WHERE id=?")->execute([$orderId3]);
unlink($cookieFile);

echo "\n$pass PASS, $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
