<?php
// Verifikasi logika upsert lewat sqlite. Karena sqlite tak punya
// "ON DUPLICATE KEY UPDATE", test menegaskan kontrak: token unik tak duplikat.
require __DIR__ . '/../_assert.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE hl_device_token (id INTEGER PRIMARY KEY, tenant_id INT, user_id INT, token TEXT UNIQUE, platform TEXT, last_seen TEXT)");

// Emulasi upsert portable untuk test:
function upsert(PDO $db, int $t, int $u, string $tok, string $plat): void {
    if ($tok === '') return;
    $db->prepare("INSERT INTO hl_device_token (tenant_id,user_id,token,platform)
                  VALUES (?,?,?,?)
                  ON CONFLICT(token) DO UPDATE SET user_id=excluded.user_id, tenant_id=excluded.tenant_id, platform=excluded.platform")
       ->execute([$t,$u,$tok,$plat ?: 'android']);
}

upsert($db, 1, 5, 'abc', 'android');
upsert($db, 1, 5, 'abc', 'android'); // re-register sama
upsert($db, 1, 7, 'abc', 'ios');     // token pindah user
upsert($db, 1, 5, '', 'android');    // token kosong → diabaikan

$rows = $db->query("SELECT user_id, platform FROM hl_device_token")->fetchAll(PDO::FETCH_ASSOC);
eqv(count($rows), 1, "token unik: tetap 1 row setelah re-register & reassign");
eqv($rows[0]['user_id'], 7, "token ter-reassign ke user terbaru");
eqv($rows[0]['platform'], 'ios', "platform ikut ter-update");

echo "ALL OK\n";
