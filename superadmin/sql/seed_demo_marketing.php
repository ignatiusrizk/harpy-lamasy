<?php
// CARA PAKAI: php superadmin/sql/seed_demo_marketing.php | /opt/homebrew/opt/mysql-client/bin/mysql
// Isi tenant Demo (2) dgn data marketing 7 hari (omset naik, pipeline penuh, kas sehat, absensi, VIP)
// + loyalty: program poin ON, katalog 3 reward, poin pelanggan (portal tampil lengkap).
// Aman diulang: pelanggan/reward idempoten (NOT EXISTS), poin pakai GREATEST, no_order offset waktu anti-bentrok.
// Generator SQL data dummy marketing — tenant Demo (2) / outlet Demo Laundry Pusat (2)
// Cerita: laundry ramai, omset 7 hari naik, hari ini ~Rp 950rb (target 1jt → bar ~95%)
mt_srand(20260704);

$TID=2; $OID=2; $KASIR=3; // Rani Kasir
$today = new DateTime('today');

$layanan = [ // id, nama, satuan, harga
  [2,'Cuci + Kering Reguler','kg',5000],
  [3,'Cuci + Kering Express','kg',8000],
  [4,'Cuci + Setrika Reguler','kg',8000],
  [5,'Cuci + Setrika Express','kg',12000],
  [6,'Setrika Saja','kg',4000],
  [8,'Selimut / Bed Cover','pcs',25000],
  [9,'Sepatu','pcs',35000],
];

// pelanggan baru [nama, telepon]
$plgBaru = [
  ['Fitri Handayani','081377710001'],['Agus Salim','081377710002'],
  ['Maya Puspita','081377710003'],['Rizal Fauzi','081377710004'],
  ['Lina Marlina','081377710005'],['Hendra Gunawan','081377710006'],
  ['Putri Ayu Ningsih','081377710007'],['Doni Saputra','081377710008'],
  ['Sri Rahayu','081377710009'],['Yoga Prasetyo','081377710010'],
  ['Ratna Sari Dewi','081377710011'],['Fajar Nugroho','081377710012'],
  ['Hotel Melati Indah','021555700013'],['Kost Putri Anggrek','081377710014'],
];
// pelanggan lama (sudah ada di DB, referensi via telepon)
$plgLama = [
  ['Budi Santoso','081211110001'],['Siti Aminah','081211110002'],
  ['Rina Wulandari','081211110003'],['Andi Pratama','081211110004'],
  ['CV Maju Bersama','021555600001'],['Dewi Lestari','081211110006'],
];
$semuaPlg = array_merge($plgLama, $plgBaru);

$sql = [];
$sql[] = "-- SEED MARKETING DEMO — generated ".date('c');
$sql[] = "SET NAMES utf8mb4;";

// 1) pelanggan baru — portal_token WAJIB diisi (tanpa ini pelanggan tak bisa masuk portal;
//    POS/customer.php aslinya selalu set bin2hex(random_bytes(16)))
foreach ($plgBaru as $i => [$nm,$tel]) {
  $created = (clone $today)->modify('-'.mt_rand(8,40).' days')->format('Y-m-d H:i:s');
  $ptok = bin2hex(random_bytes(16));
  $sql[] = "INSERT INTO hl_pelanggan (tenant_id,outlet_id,registered_outlet_id,nama,telepon,tipe,is_active,segmen,portal_token,created_at)
    SELECT $TID,$OID,$OID,'".addslashes($nm)."','$tel','umum',1,'baru','$ptok','$created'
    WHERE NOT EXISTS (SELECT 1 FROM hl_pelanggan WHERE tenant_id=$TID AND telepon='$tel');";
}
// Backfill token utk pelanggan demo lama yang belum punya (MD5 = 32 hex, format sama)
$sql[] = "UPDATE hl_pelanggan SET portal_token=MD5(CONCAT(id,'-',RAND(),'-',NOW())) WHERE tenant_id=$TID AND (portal_token IS NULL OR portal_token='');";

function plgSub($tel){ global $TID; return "(SELECT id FROM hl_pelanggan WHERE tenant_id=$TID AND telepon='$tel' LIMIT 1)"; }

// 2) orders 7 hari — [tanggalOffset => [jumlahOrder, statusMixHariIni?]]
$hariRencana = [6=>10, 5=>11, 4=>10, 3=>12, 2=>13, 1=>14, 0=>18];
// status_proses utk HARI INI (18 order): campur pipeline
$statusHariIni = array_merge(
  array_fill(0,3,'masuk'), array_fill(0,3,'cuci'), array_fill(0,2,'kering'),
  array_fill(0,2,'setrika'), array_fill(0,4,'siap'), array_fill(0,4,'diambil'));
$metodeArr = ['cash','cash','qris','qris','transfer'];

$noUrut = [];
$orders = []; // simpan utk kas & log
foreach ($hariRencana as $off => $n) {
  $tgl = (clone $today)->modify("-$off days");
  $ymd = $tgl->format('Y-m-d'); $no6 = $tgl->format('ymd');
  for ($i=0; $i<$n; $i++) {
    $seq = str_pad(($noUrut[$ymd] = ($noUrut[$ymd] ?? (100 + (time() % 700))) + 1), 3, '0', STR_PAD_LEFT);
    $no = "DMO{$no6}-{$seq}";
    [$pnm,$ptel] = $semuaPlg[mt_rand(0, count($semuaPlg)-1)];
    // 1-2 item
    $items = []; $total = 0;
    $nItem = (mt_rand(1,100) <= 30) ? 2 : 1;
    for ($j=0; $j<$nItem; $j++) {
      [$lid,$lnm,$sat,$hrg] = $layanan[mt_rand(0, count($layanan)-1)];
      $qty = $sat==='kg' ? mt_rand(3,9) : mt_rand(1,2);
      $sub = $qty*$hrg; $total += $sub;
      $items[] = [$lid,$lnm,$sat,$qty,$hrg,$sub];
    }
    // pelanggan besar sesekali (hotel/kost) → qty gede
    if (in_array($pnm, ['Hotel Melati Indah','Kost Putri Anggrek','CV Maju Bersama']) ) {
      $items = [[4,'Cuci + Setrika Reguler','kg',mt_rand(15,28),8000,0]];
      $items[0][5] = $items[0][3]*8000; $total = $items[0][5];
    }
    $jam = str_pad(mt_rand(8,18),2,'0',STR_PAD_LEFT).':'.str_pad(mt_rand(0,59),2,'0',STR_PAD_LEFT).':00';
    $createdAt = "$ymd $jam";
    if ($off > 0) { $sp='diambil'; $sb='lunas'; }
    else { $sp=$statusHariIni[$i]; $sb = ($i%6==4)?'dp':(($i%6==5)?'belum_bayar':'lunas'); }
    $dp = $sb==='dp' ? (int)floor($total*0.5/1000)*1000 : ($sb==='lunas' ? $total : 0);
    $sisa = $total - $dp;
    $mtd = $sb==='belum_bayar' ? 'NULL' : "'".$metodeArr[mt_rand(0,4)]."'";
    $est = (clone $tgl)->modify('+1 day')->format('Y-m-d 17:00:00');
    $tglSel = $sp==='diambil' ? "'$ymd'" : 'NULL';
    $sql[] = "INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,tgl_selesai,created_by,created_at)
      VALUES ($TID,$OID,'$no','$ymd',".plgSub($ptel).",'".addslashes($pnm)."','$ptel',$total,$total,$dp,$sisa,$mtd,'$sb','$sp','$est',$tglSel,$KASIR,'$createdAt');";
    $sql[] = "SET @tx = LAST_INSERT_ID();";
    foreach ($items as [$lid,$lnm,$sat,$qty,$hrg,$sub]) {
      $sql[] = "INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal)
        VALUES ($TID,$OID,@tx,$lid,'".addslashes($lnm)."','$sat',$qty,$hrg,$sub);";
    }
    $orders[] = ['no'=>$no,'ymd'=>$ymd,'jam'=>$jam,'total'=>$total,'dp'=>$dp,'sb'=>$sb,'sp'=>$sp,'plg'=>$pnm];
  }
}

// 3) 2 order lama status SIAP sejak 3 hari (utk kartu Belum Diambil + tombol Ingatkan WA)
$old = (clone $today)->modify('-3 days'); $oymd=$old->format('Y-m-d'); $ono=$old->format('ymd');
$belum = [
  ['Maya Puspita','081377710003',42000,'lunas'],
  ['Doni Saputra','081377710008',56000,'belum_bayar'],
];
foreach ($belum as $k => [$nm,$tel,$tot,$sb]) {
  $seq = str_pad(($noUrut[$oymd] = ($noUrut[$oymd] ?? (100 + (time() % 700))) + 1),3,'0',STR_PAD_LEFT);
  $no = "DMO{$ono}-{$seq}";
  $dp = $sb==='lunas'?$tot:0;
  $sql[] = "INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by,created_at)
    VALUES ($TID,$OID,'$no','$oymd',".plgSub($tel).",'".addslashes($nm)."','$tel',$tot,$tot,$dp,".($tot-$dp).",".($sb==='lunas'?"'cash'":'NULL').",'$sb','siap','$oymd 17:00:00',$KASIR,'$oymd 10:1$k:00');";
  $sql[] = "INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal)
    VALUES ($TID,$OID,LAST_INSERT_ID(),4,'Cuci + Setrika Reguler','kg',".($tot/8000*1).",8000,$tot);";
  $sql[] = "INSERT INTO hl_proses_log (tenant_id,transaksi_id,status_lama,status_baru,tipe,oleh,created_at)
    VALUES ($TID,(SELECT id FROM hl_transaksi WHERE tenant_id=$TID AND no_order='$no'),'setrika','siap','manual','Rani Kasir','$oymd 15:3$k:00');";
}

// 4) proses_log 'siap' utk order siap HARI INI (biar konsisten, tak masuk kartu belum-diambil)
foreach ($orders as $o) {
  if ($o['sp']==='siap' && $o['ymd']===$today->format('Y-m-d')) {
    $sql[] = "INSERT INTO hl_proses_log (tenant_id,transaksi_id,status_lama,status_baru,tipe,oleh,created_at)
      VALUES ($TID,(SELECT id FROM hl_transaksi WHERE tenant_id=$TID AND no_order='{$o['no']}'),'setrika','siap','manual','Rani Kasir','{$o['ymd']} 13:40:00');";
  }
}

// 5) KAS — masuk per order berbayar + keluar operasional
foreach ($orders as $o) {
  $bayar = $o['sb']==='lunas' ? $o['total'] : ($o['sb']==='dp' ? $o['dp'] : 0);
  if ($bayar<=0) continue;
  $ket = ($o['sb']==='dp'?'DP order ':'Pembayaran order ').$o['no'].' — '.$o['plg'];
  $sql[] = "INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by,created_at)
    VALUES ($TID,$OID,'{$o['ymd']}','masuk','order','".addslashes($ket)."',$bayar,'{$o['no']}',$KASIR,'{$o['ymd']} {$o['jam']}');";
}
$keluar = [
  [5,'bahan','Beli deterjen & parfum refill',85000],
  [3,'listrik','Token listrik outlet',150000],
  [2,'operasional','Galon + plastik packing',45000],
  [0,'bahan','Plastik packing & hanger',35000],
];
foreach ($keluar as [$off,$kat,$ket,$jml]) {
  $d=(clone $today)->modify("-$off days")->format('Y-m-d');
  $sql[] = "INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,created_by,created_at)
    VALUES ($TID,$OID,'$d','keluar','$kat','".addslashes($ket)."',$jml,$KASIR,'$d 09:30:00');";
}

// 6) absensi hari ini
$td=$today->format('Y-m-d');
$sql[] = "INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,status,created_at)
  SELECT $TID,$OID,3,'$td','07:52:00','hadir','$td 07:52:00' WHERE NOT EXISTS (SELECT 1 FROM hl_absensi WHERE tenant_id=$TID AND user_id=3 AND tanggal='$td');";
$sql[] = "INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,status,created_at)
  SELECT $TID,$OID,4,'$td','07:58:00','hadir','$td 07:58:00' WHERE NOT EXISTS (SELECT 1 FROM hl_absensi WHERE tenant_id=$TID AND user_id=4 AND tanggal='$td');";

// 7) agregat pelanggan + segmen VIP
$sql[] = "UPDATE hl_pelanggan p SET
  total_order=(SELECT COUNT(*) FROM hl_transaksi t WHERE t.tenant_id=$TID AND t.pelanggan_id=p.id),
  last_transaksi=(SELECT MAX(t.created_at) FROM hl_transaksi t WHERE t.tenant_id=$TID AND t.pelanggan_id=p.id)
  WHERE p.tenant_id=$TID;";
$sql[] = "UPDATE hl_pelanggan SET segmen='vip' WHERE tenant_id=$TID AND total_order>=5;";

// 8) LOYALTY: program poin ON + katalog reward (idempoten by nama) + poin pelanggan
//    → portal pelanggan demo tampil lengkap: Hadiah Tersedia dgn mix ✅ bisa-tukar / ⏳ kurang
$sql[] = "UPDATE tenants SET loyalty_enabled=1 WHERE id=$TID;";
$rewards = [ // nama, deskripsi, poin, nilai_rp
  ['Parfum Premium Upgrade', 'Upgrade parfum premium 1x cuci',            50,  5000],
  ['Diskon Rp 20.000',       'Potongan langsung utk transaksi berikutnya',100, 20000],
  ['Gratis Cuci 5 Kg',       'Cuci kering reguler 5kg gratis',            250, 25000],
];
foreach ($rewards as [$rnm,$rds,$rpoin,$rnilai]) {
  $sql[] = "INSERT INTO hl_poin_reward (tenant_id, nama_reward, deskripsi, poin_dibutuhkan, tipe, nilai, is_active, created_at)
    SELECT $TID,'".addslashes($rnm)."','".addslashes($rds)."',$rpoin,'diskon',$rnilai,1,NOW()
    WHERE NOT EXISTS (SELECT 1 FROM hl_poin_reward WHERE tenant_id=$TID AND nama_reward='".addslashes($rnm)."');";
}
// Poin bervariasi: sebagian bisa redeem 2 reward, sebagian 1, sebagian nanggung (drama "butuh X lagi")
$poinMap = ['081377710003'=>280, '081377710001'=>120, '081377710008'=>95, '081377710005'=>140,
            '081211110001'=>65,  '081211110002'=>540, '021555600001'=>1200, '081211110006'=>80];
foreach ($poinMap as $ptel => $pval) {
  $sql[] = "UPDATE hl_pelanggan SET poin_balance=GREATEST(poin_balance,$pval) WHERE tenant_id=$TID AND telepon='$ptel';";
}

echo implode("\n", $sql)."\n";
