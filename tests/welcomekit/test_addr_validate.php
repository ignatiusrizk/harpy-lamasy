<?php
require __DIR__ . '/../_assert.php';
require_once dirname(__DIR__, 2) . '/add-outlet-validate.php';

$valid = ['nama_outlet'=>'Outlet A','penerima'=>'Budi','telepon'=>'08123456789','alamat'=>'Jl. Uji No 1','kode_pos'=>'40111',
          'w_prov'=>'32','w_kota'=>'32.01','w_kec'=>'32.01.01','w_kel'=>'32.01.01.2001'];
eqv(aoValidateAddress($valid), [], 'alamat lengkap → tanpa error');
ok(count(aoValidateAddress(array_merge($valid,['penerima'=>'']))) > 0, 'penerima kosong → error');
ok(count(aoValidateAddress(array_merge($valid,['kode_pos'=>'']))) > 0, 'kode_pos kosong → error');
ok(count(aoValidateAddress(array_merge($valid,['alamat'=>'']))) > 0, 'alamat kosong → error');

// Additional edge cases
ok(count(aoValidateAddress(array_merge($valid,['penerima'=>'A']))) > 0, 'penerima 1 char → error');
ok(count(aoValidateAddress(array_merge($valid,['telepon'=>'1234567']))) > 0, 'telepon 7 digit → error');
ok(count(aoValidateAddress(array_merge($valid,['telepon'=>'081234567']))) === 0, 'telepon 9 digit → ok');
ok(count(aoValidateAddress(array_merge($valid,['w_kel'=>'']))) > 0, 'kelurahan kosong → error');
ok(count(aoValidateAddress(array_merge($valid,['w_prov'=>'']))) > 0, 'provinsi kosong → error');
ok(count(aoValidateAddress(array_merge($valid,['alamat'=>'Jl. 1']))) > 0, 'alamat <8 char → error');
ok(count(aoValidateAddress(array_merge($valid,['kode_pos'=>'1234']))) > 0, 'kode_pos 4 digit → error');
ok(count(aoValidateAddress(array_merge($valid,['kode_pos'=>'123456']))) > 0, 'kode_pos 6 digit → error');
ok(count(aoValidateAddress(array_merge($valid,['kode_pos'=>'1234a']))) > 0, 'kode_pos non-digit → error');

// Legacy mode (wizard SA registrasi): pakai teks `kota`, tanpa kode wilayah w_*
$legacy = ['penerima'=>'Budi','telepon'=>'08123456789','alamat'=>'Jl. Uji No 1','kota'=>'Bandung','kode_pos'=>'40111'];
ok(count(aoValidateAddress($legacy)) === 0, 'legacy kota teks → tanpa error');
ok(count(aoValidateAddress(array_merge($legacy,['kota'=>'']))) > 0, 'legacy kota kosong → error');

echo "OK test_addr_validate\n";
