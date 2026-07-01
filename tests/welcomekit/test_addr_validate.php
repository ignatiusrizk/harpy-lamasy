<?php
require __DIR__ . '/../_assert.php';
require_once dirname(__DIR__, 2) . '/add-outlet-validate.php';

$valid = ['nama_outlet'=>'Outlet A','penerima'=>'Budi','telepon'=>'08123456789','alamat'=>'Jl. Uji No 1','kota'=>'Bandung','kode_pos'=>'40111'];
eqv(aoValidateAddress($valid), [], 'alamat lengkap → tanpa error');
ok(count(aoValidateAddress(array_merge($valid,['penerima'=>'']))) > 0, 'penerima kosong → error');
ok(count(aoValidateAddress(array_merge($valid,['kode_pos'=>'']))) > 0, 'kode_pos kosong → error');
ok(count(aoValidateAddress(array_merge($valid,['alamat'=>'']))) > 0, 'alamat kosong → error');

// Additional edge cases
ok(count(aoValidateAddress(array_merge($valid,['penerima'=>'A']))) > 0, 'penerima 1 char → error');
ok(count(aoValidateAddress(array_merge($valid,['telepon'=>'1234567']))) > 0, 'telepon 7 digit → error');
ok(count(aoValidateAddress(array_merge($valid,['telepon'=>'081234567']))) === 0, 'telepon 9 digit → ok');
ok(count(aoValidateAddress(array_merge($valid,['kota'=>'B']))) > 0, 'kota 1 char → error');
ok(count(aoValidateAddress(array_merge($valid,['alamat'=>'Jl. 1']))) > 0, 'alamat <8 char → error');
ok(count(aoValidateAddress(array_merge($valid,['kode_pos'=>'1234']))) > 0, 'kode_pos 4 digit → error');
ok(count(aoValidateAddress(array_merge($valid,['kode_pos'=>'123456']))) > 0, 'kode_pos 6 digit → error');
ok(count(aoValidateAddress(array_merge($valid,['kode_pos'=>'1234a']))) > 0, 'kode_pos non-digit → error');

echo "OK test_addr_validate\n";
