<?php
// php tests/public_links/test_pick_rows.php — logika murni pemilihan baris daftar harga
require __DIR__ . '/../../core/PublicLinks.php';
function ok(string $n, bool $c): void { echo ($c ? 'PASS ' : 'FAIL ') . $n . PHP_EOL; if (!$c) $GLOBALS['fail'] = true; }
$r = fn($nama,$kat,$sat,$harga,$min=0,$est=24,$urut=0,$act=1) =>
  ['nama'=>$nama,'kategori'=>$kat,'satuan'=>$sat,'harga'=>$harga,'qty_minimum'=>$min,'estimasi_jam'=>$est,'urutan'=>$urut,'is_active'=>$act];
$rows = [
  $r('Reguler (Offline)','Reguler','kg',7000,2,48), $r('Reguler (Online)','Reguler','kg',8000,1,48),
  $r('Express 6 Jam (Offline)','Express','kg',19000,2,6), $r('Express 6 Jam (Online)','Express','kg',22000,2,6),
  $r('Setrika','Kiloan','kg',5000,2), $r('Cuci Lama','Kiloan','kg',9000,0,24,0,0),
  $r('Sprei B2B','B2B / Korporat','pcs',8000), $r('Treatment Noda Khusus','B2B / Korporat','pcs',5000),
  $r('Laundry Koin / SelfService','Self-Service','item',8000), $r('Sabun / Softener','Tambahan Self-Service','pcs',1000),
  $r('Keset','Satuan','pcs',5000,1,48), $r('Keset (Online)','Satuan','pcs',8000,0,48),
  $r('Packing Satuan Lipat','Satuan','pcs',500), $r('Satuan - Gorden','Satuan','pcs',8000,0,48),
  $r('Bedcover Express','Express','pcs',50000), $r('Karpet','Satuan','meter',25000,2),
];
$p = PublicLinks::pickRows($rows);
$k = array_column($p['kiloan'], 'nama'); $s = array_column($p['satuan'], 'nama');
ok('kiloan: varian Offline hilang, Online tanpa sufiks', $k === ['Reguler','Express 6 Jam','Setrika']);
ok('urutan kiloan: reguler, express, lalu kiloan', $k[0]==='Reguler' && $k[2]==='Setrika');
ok('layanan nonaktif tidak tampil', !in_array('Cuci Lama', $k, true));
ok('B2B & self-service & packing tidak tampil', !array_intersect(['Sprei B2B','Treatment Noda Khusus','Laundry Koin / SelfService','Sabun / Softener','Packing Satuan Lipat'], array_merge($k,$s)));
ok('Keset: varian Online menang, harga 8000', count(array_keys($s,'Keset'))===1 && $p['satuan'][array_search('Keset',$s)]['harga']===8000.0);
ok('prefix "Satuan - " dibuang', in_array('Gorden', $s, true));
ok('Bedcover Express (pcs) masuk satuan', in_array('Bedcover Express', $s, true));
ok('harga Express 6 Jam = Online (22000)', $p['kiloan'][1]['harga']===22000.0);
ok('estimasi 48 jam = 2 hari', PublicLinks::estimasi(48)==='2 hari');
ok('estimasi 6 jam = 6 jam', PublicLinks::estimasi(6)==='6 jam');
ok('estimasi 12 jam = 12 jam', PublicLinks::estimasi(12)==='12 jam');
ok('rupiah', PublicLinks::rupiah(8000)==='Rp8.000');
ok('telepon', PublicLinks::telepon('081292546330')==='0812-9254-6330');
exit(empty($GLOBALS['fail']) ? 0 : 1);
