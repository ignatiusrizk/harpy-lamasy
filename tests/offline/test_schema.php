<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
$db = Database::get();
$cols = $db->query("SHOW COLUMNS FROM hl_transaksi")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('offline_ref', $cols), 'kolom offline_ref ada');
ok(in_array('offline_uuid', $cols), 'kolom offline_uuid ada');
$idx = $db->query("SHOW INDEX FROM hl_transaksi")->fetchAll(PDO::FETCH_ASSOC);
$names = array_column($idx, 'Key_name');
ok(in_array('uniq_offline_uuid', $names), 'unique key offline_uuid ada');
ok(in_array('idx_offline_ref', $names), 'index offline_ref ada');
echo "OK test_schema\n";
