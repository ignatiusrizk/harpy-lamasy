<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/OrderCreator.php';

$validL = [12, 13];
$validTierNames = ['express', 'super_express'];
$base = ['items'=>[['layanan_id'=>12,'jumlah'=>2,'harga_satuan'=>8000,'subtotal'=>16000]],
         'total'=>16000,'metode_bayar'=>'cash','dp'=>16000];

// (1) payload valid → no error
eqv(OrderCreator::validateOfflinePayload($base, $validL, $validTierNames), [], 'payload valid → no error');

// (2) items kosong → error
$noItems = array_merge($base, ['items'=>[]]);
ok(count(OrderCreator::validateOfflinePayload($noItems, $validL, $validTierNames)) > 0, 'items kosong → error');

// (3) layanan_id tak dikenal → error
$badL = ['items'=>[['layanan_id'=>99,'jumlah'=>1,'harga_satuan'=>1,'subtotal'=>1]],'total'=>1,'metode_bayar'=>'cash','dp'=>0];
ok(count(OrderCreator::validateOfflinePayload($badL, $validL, $validTierNames)) > 0, 'layanan_id tak dikenal → error');

// (4) express_tier_nama tak dikenal (validTierNames diberikan) → error
$badTier = ['items'=>[['layanan_id'=>12,'jumlah'=>1,'harga_satuan'=>1,'subtotal'=>1,'express_tier_nama'=>'ultra_express']],'total'=>1,'metode_bayar'=>'cash','dp'=>0];
ok(count(OrderCreator::validateOfflinePayload($badTier, $validL, $validTierNames)) > 0, 'express_tier_nama tak dikenal → error');

// (5) dp > total → error
$dpGtTotal = array_merge($base, ['dp'=>99999]);
ok(count(OrderCreator::validateOfflinePayload($dpGtTotal, $validL, $validTierNames)) > 0, 'dp > total → error');

// (6) field online-only (voucher_id) → error
$online = array_merge($base, ['voucher_id'=>5]);
ok(count(OrderCreator::validateOfflinePayload($online, $validL, $validTierNames)) > 0, 'field online-only → error');

// (7) metode non-tunai → error
$nonCash = array_merge($base, ['metode_bayar'=>'qris']);
ok(count(OrderCreator::validateOfflinePayload($nonCash, $validL, $validTierNames)) > 0, 'metode non-tunai → error');

echo "OK test_validate\n";
