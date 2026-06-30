<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/OrderCreator.php';

$validL = [12, 13];
$validT = [3, 4];
$base = ['items'=>[['layanan_id'=>12,'tier_id'=>3,'qty'=>2,'harga'=>8000,'subtotal'=>16000]],
         'total'=>16000,'metode'=>'cash','dp'=>16000];

eqv(OrderCreator::validateOfflinePayload($base, $validL, $validT), [], 'payload valid → no error');

$noItems = array_merge($base, ['items'=>[]]);
ok(count(OrderCreator::validateOfflinePayload($noItems, $validL, $validT)) > 0, 'items kosong → error');

$badL = ['items'=>[['layanan_id'=>99,'tier_id'=>3,'qty'=>1,'harga'=>1,'subtotal'=>1]],'total'=>1,'metode'=>'cash','dp'=>0];
ok(count(OrderCreator::validateOfflinePayload($badL, $validL, $validT)) > 0, 'layanan_id tak dikenal → error');

$badTier = ['items'=>[['layanan_id'=>12,'tier_id'=>77,'qty'=>1,'harga'=>1,'subtotal'=>1]],'total'=>1,'metode'=>'cash','dp'=>0];
ok(count(OrderCreator::validateOfflinePayload($badTier, $validL, $validT)) > 0, 'tier_id tak dikenal → error');

$dpGtTotal = array_merge($base, ['dp'=>99999]);
ok(count(OrderCreator::validateOfflinePayload($dpGtTotal, $validL, $validT)) > 0, 'dp > total → error');

$online = array_merge($base, ['voucher_id'=>5]);
ok(count(OrderCreator::validateOfflinePayload($online, $validL, $validT)) > 0, 'field online-only → error');

$nonCash = array_merge($base, ['metode'=>'qris']);
ok(count(OrderCreator::validateOfflinePayload($nonCash, $validL, $validT)) > 0, 'metode non-tunai → error');

echo "OK test_validate\n";
