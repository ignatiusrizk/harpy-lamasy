<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
$db = Database::get();
$cols = $db->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('hq_coin_outlet_id', $cols), 'kolom tenants.hq_coin_outlet_id ada');
echo "OK test_schema (coin governance)\n";
