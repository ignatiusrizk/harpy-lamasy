<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/PushSender.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE hl_users (id INT, tenant_id INT, outlet_id INT, role TEXT, role_id INT, is_active INT)");
$db->exec("CREATE TABLE hl_role_push_event (id INTEGER PRIMARY KEY, tenant_id INT, role_id INT, event_kode TEXT)");
$db->exec("CREATE TABLE hl_karyawan_outlet (id INTEGER PRIMARY KEY, tenant_id INT, karyawan_id INT, outlet_id INT, is_active INT)");
$db->exec("CREATE TABLE hl_device_token (id INTEGER PRIMARY KEY, tenant_id INT, user_id INT, token TEXT, platform TEXT)");

// Tenant 1. Role 10 langganan 'order_baru'. Role 20 tidak.
$db->exec("INSERT INTO hl_role_push_event (tenant_id, role_id, event_kode) VALUES (1,10,'order_baru')");
// Users:
// u1: staf outlet 5, role 10 (langganan), punya token  -> MASUK
// u2: staf outlet 9, role 10 (langganan), punya token  -> TIDAK (outlet lain)
// u3: owner outlet 9, role 10 (langganan), punya token  -> MASUK (HQ cross-outlet)
// u4: staf outlet 5, role 20 (tak langganan), token     -> TIDAK (role tak langganan)
// u5: staf outlet 5, role 10 (langganan), TANPA token   -> TIDAK (tak ada device)
// u6: outlet utama 9 tapi assigned ke outlet 5 via karyawan_outlet, role 10, token -> MASUK
$db->exec("INSERT INTO hl_users (id,tenant_id,outlet_id,role,role_id,is_active) VALUES
  (1,1,5,'staff',10,1),(2,1,9,'staff',10,1),(3,1,9,'owner',10,1),
  (4,1,5,'staff',20,1),(5,1,5,'staff',10,1),(6,1,9,'kasir',10,1)");
$db->exec("INSERT INTO hl_karyawan_outlet (tenant_id,karyawan_id,outlet_id,is_active) VALUES (1,6,5,1)");
foreach ([1,2,3,4,6] as $uid) {
    $db->exec("INSERT INTO hl_device_token (tenant_id,user_id,token) VALUES (1,$uid,'tok$uid')");
}

$got = PushSender::resolveRecipients($db, 'order_baru', 1, 5);
sort($got);
eqv($got, [1,3,6], "resolusi penerima order_baru outlet 5");

// targetUserIds override: hanya user 6 (tetap harus langganan + punya token)
$got2 = PushSender::resolveRecipients($db, 'order_baru', 1, 5, [6,2]);
sort($got2);
eqv($got2, [6], "targetUserIds membatasi ke user tertentu yg eligible");

// tokensForUsers
$toks = PushSender::tokensForUsers($db, 1, [1,3]);
sort($toks);
eqv($toks, ['tok1','tok3'], "tokensForUsers ambil token milik user");

echo "ALL OK\n";
