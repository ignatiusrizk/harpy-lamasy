<?php
/**
 * seed_dummy.php — LaMaSy Realistic Dummy Data Seeder
 *
 * Run from project root:
 *   php superadmin/sql/seed_dummy.php
 *
 * Demo password for ALL accounts: Demo1234!
 *
 * Tenants created:
 *   [2] Laundry Bersih Kilat — active, 2 outlet, Yogyakarta
 *       HQ login : budi.hartono@gmail.com / Demo1234!
 *       Users    : budi.owner, dewi.manager, sari.kasir2, ahmad.staff, rizal.kurir (outlet 1)
 *                  eko.manager2, rina.kasir2 (outlet 2)
 *
 *   [3] Fresh Laundry — trial 30 hari, 1 outlet, Semarang
 *       HQ login : maya@freshlaundry.id / Demo1234!
 *       Users    : maya.owner, andi.kasir3
 *
 *   [4] Laundry Express Maju — grace (trial habis), 1 outlet, Solo
 *       HQ login : hendra.laundry@gmail.com / Demo1234!
 *       Users    : hendra.owner, tono.staff
 */

define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/core/Database.php';

$db   = Database::get();
$pass = password_hash('Demo1234!', PASSWORD_BCRYPT);

// ── Idempotency guard ────────────────────────────────────
$exists = (int)$db->query("SELECT COUNT(*) FROM tenants WHERE email='budi.hartono@gmail.com'")->fetchColumn();
if ($exists > 0) {
    echo "Already seeded (budi.hartono@gmail.com exists). Hapus tenant tersebut untuk re-run.\n";
    exit(0);
}

echo "=== LaMaSy Dummy Data Seeder ===\n\n";

// ─────────────────────────────────────────────────────────
// 1. PACKAGES & COIN BUNDLES
// ─────────────────────────────────────────────────────────
echo "[1] saas_packages & saas_coin_bundles...\n";

if ((int)$db->query("SELECT COUNT(*) FROM saas_packages")->fetchColumn() === 0) {
    $db->exec("INSERT INTO saas_packages (nama, slug, deskripsi, setup_fee, coin_awal, trial_hari, max_outlets, urutan) VALUES
        ('Starter',  'starter', '1 outlet — cocok untuk laundry rumahan kecil', 300000,  30000, 30,  1, 1),
        ('Growth',   'growth',  'Hingga 3 outlet — untuk bisnis yang berkembang', 500000,  60000, 30,  3, 2),
        ('Pro',      'pro',     'Hingga 10 outlet — ideal untuk franchise laundry', 1000000, 150000, 30, 10, 3)
    ");
    echo "   ✓ 3 packages\n";
}

if ((int)$db->query("SELECT COUNT(*) FROM saas_coin_bundles")->fetchColumn() === 0) {
    $db->exec("INSERT INTO saas_coin_bundles (nama, harga, coin_didapat, bonus_pct, is_featured, urutan) VALUES
        ('Mini Pack',    50000,   10000,  0.00, 0, 1),
        ('Basic Pack',  100000,   22000, 10.00, 0, 2),
        ('Popular Pack',250000,   60000, 20.00, 1, 3),
        ('Power Pack',  500000,  130000, 30.00, 0, 4)
    ");
    echo "   ✓ 4 coin bundles\n";
}

$pkgs    = $db->query("SELECT id, slug FROM saas_packages")->fetchAll(PDO::FETCH_KEY_PAIR);
$pkgGrow = $pkgs['growth']  ?? 2;
$pkgStar = $pkgs['starter'] ?? 1;

// ─────────────────────────────────────────────────────────
// 2. TENANTS & OUTLETS
// ─────────────────────────────────────────────────────────
echo "[2] Tenants & Outlets...\n";

// ── Tenant 2: Active, 2 outlets ──────────────────────────
$db->prepare("INSERT INTO tenants
    (slug, nama_outlet, nama_perusahaan, owner_name, owner_wa, email, phone,
     status, coin_balance, coin_mode, total_outlets, max_outlets,
     loyalty_enabled, loyalty_rupiah_per_poin, loyalty_poin_value,
     package_id, package_assigned_at, registration_source, password_hash,
     trial_ends_at, provisioned_at, registered_at, verified_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,
            DATE_SUB(NOW(), INTERVAL 60 DAY),
            DATE_SUB(NOW(), INTERVAL 90 DAY),
            DATE_SUB(NOW(), INTERVAL 91 DAY),
            DATE_SUB(NOW(), INTERVAL 90 DAY))"
)->execute([
    'bersih-kilat', 'Laundry Bersih Kilat', 'CV Bersih Kilat Mandiri',
    'Budi Hartono', '628123456789', 'budi.hartono@gmail.com', '628123456789',
    'active', 45000, 'shared', 2, 3, 1, 1000, 100,
    $pkgGrow, 'assisted', $pass,
]);
$tid2 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO outlets
    (tenant_id, nama_outlet, slug, alamat, kota, telepon, status, coin_balance,
     is_main, setup_done, activated_at, trial_starts_at, trial_ends_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,
            DATE_SUB(NOW(), INTERVAL 90 DAY),
            DATE_SUB(NOW(), INTERVAL 120 DAY),
            DATE_SUB(NOW(), INTERVAL 90 DAY))"
)->execute([
    $tid2, 'Bersih Kilat - Malioboro', 'bersih-kilat-malioboro',
    'Jl. Malioboro No. 45, Gedongtengen, Yogyakarta', 'Yogyakarta', '0274-512345',
    'active', 0, 1, 1,
]);
$oid2a = (int)$db->lastInsertId();

$db->prepare("INSERT INTO outlets
    (tenant_id, nama_outlet, slug, alamat, kota, telepon, status, coin_balance,
     is_main, setup_done, activated_at, trial_starts_at, trial_ends_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,
            DATE_SUB(NOW(), INTERVAL 60 DAY),
            DATE_SUB(NOW(), INTERVAL 90 DAY),
            DATE_SUB(NOW(), INTERVAL 60 DAY))"
)->execute([
    $tid2, 'Bersih Kilat - Parangtritis', 'bersih-kilat-parangtritis',
    'Jl. Parangtritis No. 22, Sewon, Bantul, Yogyakarta', 'Yogyakarta', '0274-987654',
    'active', 0, 0, 1,
]);
$oid2b = (int)$db->lastInsertId();

echo "   ✓ Tenant 2 (active): tid=$tid2, outlet1=$oid2a, outlet2=$oid2b\n";

// ── Tenant 3: Trial ──────────────────────────────────────
$db->prepare("INSERT INTO tenants
    (slug, nama_outlet, owner_name, owner_wa, email, phone,
     status, coin_balance, coin_mode, total_outlets, max_outlets,
     package_id, package_assigned_at, registration_source, password_hash,
     trial_ends_at, provisioned_at, registered_at, verified_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,
            DATE_ADD(NOW(), INTERVAL 15 DAY),
            NOW(),
            DATE_SUB(NOW(), INTERVAL 15 DAY),
            DATE_SUB(NOW(), INTERVAL 15 DAY))"
)->execute([
    'fresh-laundry', 'Fresh Laundry Semarang', 'Maya Putri Ariani',
    '6285678901234', 'maya@freshlaundry.id', '6285678901234',
    'trial', 28000, 'shared', 1, 1,
    $pkgStar, 'self_service', $pass,
]);
$tid3 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO outlets
    (tenant_id, nama_outlet, slug, alamat, kota, telepon, status, is_main, setup_done,
     trial_starts_at, trial_ends_at)
    VALUES (?,?,?,?,?,?,?,?,?,
            DATE_SUB(NOW(), INTERVAL 15 DAY),
            DATE_ADD(NOW(), INTERVAL 15 DAY))"
)->execute([
    $tid3, 'Fresh Laundry Semarang', 'fresh-laundry-smg',
    'Jl. Pemuda No. 78, Semarang Tengah', 'Semarang', '024-3456789',
    'trial', 1, 1,
]);
$oid3 = (int)$db->lastInsertId();

echo "   ✓ Tenant 3 (trial): tid=$tid3, outlet=$oid3\n";

// ── Tenant 4: Grace ──────────────────────────────────────
$db->prepare("INSERT INTO tenants
    (slug, nama_outlet, owner_name, owner_wa, email, phone,
     status, coin_balance, coin_mode, total_outlets, max_outlets,
     package_id, package_assigned_at, registration_source, password_hash,
     trial_ends_at, provisioned_at, registered_at, verified_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,
            DATE_SUB(NOW(), INTERVAL 5 DAY),
            DATE_SUB(NOW(), INTERVAL 35 DAY),
            DATE_SUB(NOW(), INTERVAL 36 DAY),
            DATE_SUB(NOW(), INTERVAL 35 DAY))"
)->execute([
    'express-maju', 'Laundry Express Maju', 'Hendra Kurniawan',
    '6281398765432', 'hendra.laundry@gmail.com', '6281398765432',
    'grace', 3500, 'shared', 1, 1,
    $pkgStar, 'assisted', $pass,
]);
$tid4 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO outlets
    (tenant_id, nama_outlet, slug, alamat, kota, telepon, status, is_main, setup_done,
     trial_starts_at, trial_ends_at, grace_ends_at)
    VALUES (?,?,?,?,?,?,?,?,?,
            DATE_SUB(NOW(), INTERVAL 35 DAY),
            DATE_SUB(NOW(), INTERVAL 5 DAY),
            DATE_ADD(NOW(), INTERVAL 25 DAY))"
)->execute([
    $tid4, 'Laundry Express Solo', 'express-maju-solo',
    'Jl. Slamet Riyadi No. 12, Laweyan, Solo', 'Solo', '0271-123456',
    'grace', 1, 1,
]);
$oid4 = (int)$db->lastInsertId();

echo "   ✓ Tenant 4 (grace): tid=$tid4, outlet=$oid4\n";

// ─────────────────────────────────────────────────────────
// 3. USERS (hl_users)
// ─────────────────────────────────────────────────────────
echo "[3] hl_users...\n";

function insUser($db, $tid, $oid, $uname, $pass, $nama, $role, $jabatan, $telp, $masuk, $gaji) {
    $db->prepare("INSERT INTO hl_users
        (tenant_id, outlet_id, username, password, nama, role, jabatan, telepon, tgl_masuk, gaji_pokok, is_active)
        VALUES (?,?,?,?,?,?,?,?,?,?,1)")->execute([$tid,$oid,$uname,$pass,$nama,$role,$jabatan,$telp,$masuk,$gaji]);
    return (int)$db->lastInsertId();
}

// Tenant 2 — Outlet 1 (Malioboro)
$uOwner  = insUser($db,$tid2,$oid2a,'budi.owner',  $pass,'Budi Hartono',    'owner',  'Pemilik Usaha',   '08123456789','2023-01-01',0);
$uMgr1   = insUser($db,$tid2,$oid2a,'dewi.manager',$pass,'Dewi Rahayu',     'manager','Manager Outlet',  '08234567890','2023-02-01',3500000);
$uKasir1 = insUser($db,$tid2,$oid2a,'sari.kasir2', $pass,'Sari Wulandari',  'kasir',  'Kasir',           '08345678901','2023-03-15',2500000);
$uStaff1 = insUser($db,$tid2,$oid2a,'ahmad.staff', $pass,'Ahmad Fauzi',     'staff',  'Operator Cuci',   '08456789012','2023-04-01',2200000);
$uKurir1 = insUser($db,$tid2,$oid2a,'rizal.kurir', $pass,'Rizal Firmansyah','kurir',  'Kurir',           '08567890123','2023-05-01',2000000);

// Tenant 2 — Outlet 2 (Parangtritis)
$uMgr2   = insUser($db,$tid2,$oid2b,'eko.manager2',$pass,'Eko Prasetyo',  'manager','Manager Outlet','08678901234','2023-06-01',3200000);
$uKasir2 = insUser($db,$tid2,$oid2b,'rina.kasir2', $pass,'Rina Marlina',  'kasir',  'Kasir',         '08789012345','2023-07-01',2400000);

// Tenant 3 — Fresh Laundry
$uMaya   = insUser($db,$tid3,$oid3,'maya.owner', $pass,'Maya Putri Ariani','owner','Pemilik Usaha','08567891234','2026-05-15',0);
$uAndi   = insUser($db,$tid3,$oid3,'andi.kasir3',$pass,'Andi Setiawan',    'kasir','Kasir',        '08678902345','2026-05-15',2300000);

// Tenant 4 — Express Maju
$uHendra = insUser($db,$tid4,$oid4,'hendra.owner',$pass,'Hendra Kurniawan','owner','Pemilik Usaha','08139876543','2026-04-25',0);
$uTono   = insUser($db,$tid4,$oid4,'tono.staff',  $pass,'Sutono',          'staff','Operator',     '08912345678','2026-04-25',2100000);

echo "   ✓ 11 users seeded\n";

// ─────────────────────────────────────────────────────────
// 4. LAYANAN (hl_layanan)
// ─────────────────────────────────────────────────────────
echo "[4] hl_layanan...\n";

function insLayanan($db,$tid,$oid,$nama,$kat,$sat,$harga,$urut) {
    $db->prepare("INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (?,?,?,?,?,?,?)")
       ->execute([$tid,$oid,$nama,$kat,$sat,$harga,$urut]);
    return (int)$db->lastInsertId();
}

// Outlet 1
$L1 = [
    'kiloan'  => insLayanan($db,$tid2,$oid2a,'Cuci Kiloan',          'cuci',    'kg',    7000, 1),
    'express' => insLayanan($db,$tid2,$oid2a,'Cuci Express (1 Hari)','cuci',    'kg',   12000, 2),
    'lipat'   => insLayanan($db,$tid2,$oid2a,'Cuci + Lipat',         'cuci',    'kg',    8000, 3),
    'setrika' => insLayanan($db,$tid2,$oid2a,'Cuci + Setrika',       'cuci',    'kg',   10000, 4),
    'setrj'   => insLayanan($db,$tid2,$oid2a,'Setrika Saja',         'setrika', 'kg',    5000, 5),
    'sepatu'  => insLayanan($db,$tid2,$oid2a,'Cuci Sepatu',          'khusus',  'pasang',35000, 6),
    'tas'     => insLayanan($db,$tid2,$oid2a,'Cuci Tas',             'khusus',  'item',  50000, 7),
    'bedcvr'  => insLayanan($db,$tid2,$oid2a,'Cuci Bedcover/Selimut','khusus',  'item',  25000, 8),
    'karpet'  => insLayanan($db,$tid2,$oid2a,'Cuci Karpet',          'khusus',  'kg',   15000, 9),
    'b2b'     => insLayanan($db,$tid2,$oid2a,'Cuci B2B Korporat',    'b2b',     'kg',    6500,10),
];

// Outlet 2
$L2 = [
    'kiloan'  => insLayanan($db,$tid2,$oid2b,'Cuci Kiloan',          'cuci',    'kg',    7000, 1),
    'express' => insLayanan($db,$tid2,$oid2b,'Cuci Express (1 Hari)','cuci',    'kg',   12000, 2),
    'lipat'   => insLayanan($db,$tid2,$oid2b,'Cuci + Lipat',         'cuci',    'kg',    8000, 3),
    'setrika' => insLayanan($db,$tid2,$oid2b,'Cuci + Setrika',       'cuci',    'kg',   10000, 4),
    'sepatu'  => insLayanan($db,$tid2,$oid2b,'Cuci Sepatu',          'khusus',  'pasang',35000, 5),
    'bedcvr'  => insLayanan($db,$tid2,$oid2b,'Cuci Bedcover/Selimut','khusus',  'item',  25000, 6),
    'b2b'     => insLayanan($db,$tid2,$oid2b,'Cuci B2B Korporat',    'b2b',     'kg',    6500, 7),
];

// Fresh Laundry (3 layanan saja — masih setup)
insLayanan($db,$tid3,$oid3,'Cuci Kiloan','cuci','kg',8000,1);
insLayanan($db,$tid3,$oid3,'Cuci Express','cuci','kg',13000,2);
insLayanan($db,$tid3,$oid3,'Cuci + Setrika','cuci','kg',11000,3);

// Express Maju
insLayanan($db,$tid4,$oid4,'Cuci Kiloan','cuci','kg',7500,1);
insLayanan($db,$tid4,$oid4,'Cuci Express','cuci','kg',12500,2);

echo "   ✓ Layanan seeded\n";

// ─────────────────────────────────────────────────────────
// 5. PELANGGAN (hl_pelanggan)
// ─────────────────────────────────────────────────────────
echo "[5] hl_pelanggan...\n";

function insPel($db,$tid,$oid,$nama,$telp,$tipe,$metode,$poin=0,$total=0) {
    $db->prepare("INSERT INTO hl_pelanggan
        (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order)
        VALUES (?,?,?,?,?,?,?,?)")->execute([$tid,$oid,$nama,$telp,$tipe,$metode,$poin,$total]);
    return (int)$db->lastInsertId();
}

// Outlet 1 pelanggan
$P1 = [
    insPel($db,$tid2,$oid2a,'Siti Rahayu',        '08111222333','retail',  'langsung',350, 18),
    insPel($db,$tid2,$oid2a,'Agus Santoso',        '08222333444','retail',  'langsung',120,  9),
    insPel($db,$tid2,$oid2a,'Dewi Lestari',        '08333444555','retail',  'langsung',520, 26),
    insPel($db,$tid2,$oid2a,'Rizki Pratama',       '08444555666','retail',  'langsung', 60,  4),
    insPel($db,$tid2,$oid2a,'Warung Pak Haji',     '08555666777','korporat','bulanan',    0,  8),
    insPel($db,$tid2,$oid2a,'Hotel Melati Indah',  '0274-123456','korporat','bulanan',    0,  6),
    insPel($db,$tid2,$oid2a,'Ratna Wulandari',     '08666777888','retail',  'langsung',180, 11),
    insPel($db,$tid2,$oid2a,'Joko Siswanto',       '08777888999','retail',  'langsung', 80,  6),
    insPel($db,$tid2,$oid2a,'Yunita Sari',         '08888999000','retail',  'langsung',240, 13),
    insPel($db,$tid2,$oid2a,'Bapak Darmanto',      '08999000111','retail',  'langsung', 90,  7),
    insPel($db,$tid2,$oid2a,'Kost Bu Rini',        '08121212121','bulanan', 'bulanan',    0,  4),
];

// Outlet 2 pelanggan
$P2 = [
    insPel($db,$tid2,$oid2b,'Sumiati',             '08111333555','retail',  'langsung',130,  8),
    insPel($db,$tid2,$oid2b,'PP Al-Hikmah Bantul', '08222444666','korporat','bulanan',    0,  4),
    insPel($db,$tid2,$oid2b,'Wirawan',             '08333555777','retail',  'langsung', 60,  5),
    insPel($db,$tid2,$oid2b,'Sri Mulyani',         '08444666888','retail',  'langsung',200, 12),
    insPel($db,$tid2,$oid2b,'CV Maju Bersama',     '08555777999','korporat','bulanan',    0,  3),
];

// Fresh Laundry (pelanggan baru)
insPel($db,$tid3,$oid3,'Budi Setiawan','08123456780','retail','langsung',0,0);
insPel($db,$tid3,$oid3,'Laila Kusuma', '08234567891','retail','langsung',0,0);

echo "   ✓ Pelanggan seeded\n";

// ─────────────────────────────────────────────────────────
// 6. PROMO
// ─────────────────────────────────────────────────────────
echo "[6] hl_promo...\n";

$db->prepare("INSERT INTO hl_promo
    (tenant_id,outlet_id,nama,deskripsi,tipe,nilai,min_transaksi,berlaku_dari,berlaku_sampai,kuota,terpakai,is_active,created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'Promo Lebaran 10%','Diskon 10% untuk semua cuci kiloan',
    'persen',10,20000,'2026-03-01','2026-04-30',100,47,0,$uMgr1
]);
$db->prepare("INSERT INTO hl_promo
    (tenant_id,outlet_id,nama,deskripsi,tipe,nilai,min_transaksi,berlaku_dari,berlaku_sampai,kuota,terpakai,is_active,created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'Promo Sabtu Hemat','Diskon Rp 5.000 min. transaksi 30.000',
    'nominal',5000,30000,'2026-05-01','2026-06-30',200,12,1,$uMgr1
]);
$db->prepare("INSERT INTO hl_promo
    (tenant_id,outlet_id,nama,deskripsi,tipe,nilai,min_transaksi,berlaku_dari,berlaku_sampai,kuota,terpakai,is_active,created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2b,'Promo Pembukaan','Diskon 15% untuk 50 pelanggan pertama',
    'persen',15,0,'2026-03-25','2026-04-30',50,28,0,$uMgr2
]);

echo "   ✓ 3 promo seeded\n";

// ─────────────────────────────────────────────────────────
// 7. TRANSAKSI (hl_transaksi + item + kas)
// ─────────────────────────────────────────────────────────
echo "[7] hl_transaksi & items...\n";

$seqCount = []; // ["{oid}_{date}"] => count, untuk no_order

function noOrder($db, $oid, $tanggal, &$seqCount) {
    $key = "{$oid}_{$tanggal}";
    $seqCount[$key] = ($seqCount[$key] ?? 0) + 1;
    return 'HL-' . str_replace('-', '', $tanggal) . '-' . str_pad($seqCount[$key], 3, '0', STR_PAD_LEFT);
}

function insTrx($db, $tid, $oid, $no, $tgl, $pelId, $pelNama, $telp,
                $items, $diskon, $metode, $sBayar, $sProses, $estSelesai, $createdBy, &$seqCount) {
    $subtotal = 0;
    foreach ($items as $it) $subtotal += $it[2] * $it[3]; // [lid, nama, jumlah, harga, satuan]
    $total = $subtotal - $diskon;
    $dp    = $sBayar === 'dp' ? (int)round($total * 0.5) : 0;
    $sisa  = $sBayar === 'lunas' ? 0 : ($sBayar === 'dp' ? $total - $dp : $total);

    $db->prepare("INSERT INTO hl_transaksi
        (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,
         subtotal,diskon,total,dp,sisa_bayar,metode_bayar,
         status_bayar,status_proses,estimasi_selesai,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
        $tid,$oid,$no,$tgl,$pelId,$pelNama,$telp,
        $subtotal,$diskon,$total,$dp,$sisa,$metode,
        $sBayar,$sProses,$estSelesai,$createdBy,
    ]);
    $trxId = (int)$db->lastInsertId();

    foreach ($items as $it) {
        [$lid,$nama,$jml,$hrg,$sat] = $it;
        $db->prepare("INSERT INTO hl_transaksi_item
            (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal)
            VALUES (?,?,?,?,?,?,?,?,?)")->execute([
            $tid,$oid,$trxId,$lid,$nama,$sat,$jml,$hrg,($jml*$hrg),
        ]);
    }

    // Kas masuk untuk transaksi lunas / dp
    if ($sBayar === 'lunas' && $total > 0) {
        $db->prepare("INSERT INTO hl_kas
            (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by)
            VALUES (?,?,?,'masuk','transaksi',?,?,?,?)")->execute([
            $tid,$oid,$tgl,"Pembayaran $no — $pelNama",$total,$no,$createdBy,
        ]);
    } elseif ($sBayar === 'dp' && $dp > 0) {
        $db->prepare("INSERT INTO hl_kas
            (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by)
            VALUES (?,?,?,'masuk','transaksi',?,?,?,?)")->execute([
            $tid,$oid,$tgl,"DP $no — $pelNama",$dp,$no,$createdBy,
        ]);
    }

    return $trxId;
}

// ── Skenario Outlet 1 ─────────────────────────────────────
// Format item: [layanan_id, nama, jumlah, harga, satuan]
// Status proses sudah 'diambil' untuk semua Maret & April (selesai)

$trxData1 = [
    // Maret 2026
    ['2026-03-03',$P1[0],'Siti Rahayu',    '08111222333',[[$L1['kiloan'],'Cuci Kiloan',5,7000,'kg']], 0,'cash','lunas','diambil','2026-03-05',$uKasir1],
    ['2026-03-03',$P1[2],'Dewi Lestari',   '08333444555',[[$L1['express'],'Cuci Express',3,12000,'kg'],[$L1['sepatu'],'Cuci Sepatu',1,35000,'pasang']], 0,'qris','lunas','diambil','2026-03-04',$uKasir1],
    ['2026-03-05',$P1[4],'Warung Pak Haji','08555666777',[[$L1['b2b'],'Cuci B2B',25,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-03-08',$uMgr1],
    ['2026-03-08',$P1[6],'Ratna Wulandari','08666777888',[[$L1['kiloan'],'Cuci Kiloan',4,7000,'kg']], 0,'cash','lunas','diambil','2026-03-10',$uKasir1],
    ['2026-03-08',$P1[1],'Agus Santoso',   '08222333444',[[$L1['setrika'],'Cuci+Setrika',3,10000,'kg']], 0,'cash','lunas','diambil','2026-03-10',$uKasir1],
    ['2026-03-10',$P1[5],'Hotel Melati',   '0274-123456',[[$L1['b2b'],'Cuci B2B',40,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-03-13',$uMgr1],
    ['2026-03-12',$P1[8],'Yunita Sari',    '08888999000',[[$L1['kiloan'],'Cuci Kiloan',6,7000,'kg'],[$L1['bedcvr'],'Bedcover',1,25000,'item']], 0,'qris','lunas','diambil','2026-03-14',$uKasir1],
    ['2026-03-14',$P1[3],'Rizki Pratama',  '08444555666',[[$L1['lipat'],'Cuci+Lipat',4,8000,'kg']], 0,'cash','lunas','diambil','2026-03-16',$uKasir1],
    ['2026-03-17',$P1[0],'Siti Rahayu',    '08111222333',[[$L1['kiloan'],'Cuci Kiloan',5,7000,'kg']], 0,'cash','lunas','diambil','2026-03-19',$uKasir1],
    ['2026-03-18',$P1[9],'Bapak Darmanto', '08999000111',[[$L1['kiloan'],'Cuci Kiloan',3,7000,'kg']], 0,'cash','lunas','diambil','2026-03-20',$uKasir1],
    ['2026-03-20',$P1[2],'Dewi Lestari',   '08333444555',[[$L1['express'],'Cuci Express',4,12000,'kg']], 0,'qris','lunas','diambil','2026-03-21',$uKasir1],
    ['2026-03-22',$P1[7],'Joko Siswanto',  '08777888999',[[$L1['kiloan'],'Cuci Kiloan',5,7000,'kg'],[$L1['setrj'],'Setrika',2,5000,'kg']], 0,'cash','lunas','diambil','2026-03-24',$uKasir1],
    ['2026-03-25',$P1[4],'Warung Pak Haji','08555666777',[[$L1['b2b'],'Cuci B2B',28,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-03-28',$uMgr1],
    ['2026-03-27',$P1[6],'Ratna Wulandari','08666777888',[[$L1['setrika'],'Cuci+Setrika',3,10000,'kg']], 0,'cash','lunas','diambil','2026-03-29',$uKasir1],
    ['2026-03-28',$P1[10],'Kost Bu Rini',  '08121212121',[[$L1['kiloan'],'Cuci Kiloan',8,7000,'kg']], 0,'transfer','lunas','diambil','2026-03-31',$uKasir1],
    // April 2026
    ['2026-04-02',$P1[0],'Siti Rahayu',    '08111222333',[[$L1['kiloan'],'Cuci Kiloan',5,7000,'kg']], 0,'cash','lunas','diambil','2026-04-04',$uKasir1],
    ['2026-04-02',$P1[5],'Hotel Melati',   '0274-123456',[[$L1['b2b'],'Cuci B2B',45,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-04-06',$uMgr1],
    ['2026-04-05',$P1[2],'Dewi Lestari',   '08333444555',[[$L1['express'],'Cuci Express',3,12000,'kg']], 0,'qris','lunas','diambil','2026-04-06',$uKasir1],
    ['2026-04-07',$P1[8],'Yunita Sari',    '08888999000',[[$L1['kiloan'],'Cuci Kiloan',7,7000,'kg']], 0,'cash','lunas','diambil','2026-04-09',$uKasir1],
    ['2026-04-08',$P1[1],'Agus Santoso',   '08222333444',[[$L1['kiloan'],'Cuci Kiloan',4,7000,'kg'],[$L1['sepatu'],'Cuci Sepatu',2,35000,'pasang']], 0,'cash','lunas','diambil','2026-04-10',$uKasir1],
    ['2026-04-10',$P1[4],'Warung Pak Haji','08555666777',[[$L1['b2b'],'Cuci B2B',30,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-04-13',$uMgr1],
    ['2026-04-12',$P1[3],'Rizki Pratama',  '08444555666',[[$L1['lipat'],'Cuci+Lipat',3,8000,'kg']], 0,'cash','lunas','diambil','2026-04-14',$uKasir1],
    ['2026-04-14',$P1[6],'Ratna Wulandari','08666777888',[[$L1['kiloan'],'Cuci Kiloan',5,7000,'kg']], 0,'cash','lunas','diambil','2026-04-16',$uKasir1],
    ['2026-04-16',$P1[9],'Bapak Darmanto', '08999000111',[[$L1['setrika'],'Cuci+Setrika',4,10000,'kg']], 0,'cash','lunas','diambil','2026-04-18',$uKasir1],
    ['2026-04-18',$P1[0],'Siti Rahayu',    '08111222333',[[$L1['kiloan'],'Cuci Kiloan',5,7000,'kg'],[$L1['bedcvr'],'Bedcover',2,25000,'item']], 0,'qris','lunas','diambil','2026-04-20',$uKasir1],
    ['2026-04-20',$P1[2],'Dewi Lestari',   '08333444555',[[$L1['express'],'Cuci Express',4,12000,'kg']], 0,'qris','lunas','diambil','2026-04-21',$uKasir1],
    ['2026-04-22',$P1[7],'Joko Siswanto',  '08777888999',[[$L1['kiloan'],'Cuci Kiloan',4,7000,'kg']], 0,'cash','lunas','diambil','2026-04-24',$uKasir1],
    ['2026-04-24',$P1[10],'Kost Bu Rini',  '08121212121',[[$L1['kiloan'],'Cuci Kiloan',9,7000,'kg']], 0,'transfer','lunas','diambil','2026-04-27',$uKasir1],
    ['2026-04-25',$P1[5],'Hotel Melati',   '0274-123456',[[$L1['b2b'],'Cuci B2B',42,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-04-29',$uMgr1],
    ['2026-04-28',$P1[8],'Yunita Sari',    '08888999000',[[$L1['lipat'],'Cuci+Lipat',5,8000,'kg']], 0,'cash','lunas','diambil','2026-04-30',$uKasir1],
    // Mei 2026 (sebagian masih aktif)
    ['2026-05-03',$P1[0],'Siti Rahayu',    '08111222333',[[$L1['kiloan'],'Cuci Kiloan',5,7000,'kg']], 0,'cash','lunas','diambil','2026-05-05',$uKasir1],
    ['2026-05-05',$P1[5],'Hotel Melati',   '0274-123456',[[$L1['b2b'],'Cuci B2B',38,6500,'kg']], 0,'transfer','belum_bayar','siap','2026-05-09',$uMgr1],
    ['2026-05-07',$P1[2],'Dewi Lestari',   '08333444555',[[$L1['express'],'Cuci Express',3,12000,'kg']], 0,'qris','lunas','diambil','2026-05-08',$uKasir1],
    ['2026-05-10',$P1[4],'Warung Pak Haji','08555666777',[[$L1['b2b'],'Cuci B2B',27,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-05-13',$uMgr1],
    ['2026-05-12',$P1[6],'Ratna Wulandari','08666777888',[[$L1['kiloan'],'Cuci Kiloan',4,7000,'kg']], 0,'cash','lunas','diambil','2026-05-14',$uKasir1],
    ['2026-05-15',$P1[1],'Agus Santoso',   '08222333444',[[$L1['setrika'],'Cuci+Setrika',3,10000,'kg']], 0,'cash','lunas','diambil','2026-05-17',$uKasir1],
    ['2026-05-17',$P1[9],'Bapak Darmanto', '08999000111',[[$L1['kiloan'],'Cuci Kiloan',4,7000,'kg']], 0,'cash','lunas','diambil','2026-05-19',$uKasir1],
    ['2026-05-19',$P1[3],'Rizki Pratama',  '08444555666',[[$L1['lipat'],'Cuci+Lipat',4,8000,'kg']], 0,'cash','dp','kering','2026-05-21',$uKasir1],
    ['2026-05-22',$P1[8],'Yunita Sari',    '08888999000',[[$L1['kiloan'],'Cuci Kiloan',6,7000,'kg']], 0,'qris','lunas','diambil','2026-05-24',$uKasir1],
    ['2026-05-24',$P1[0],'Siti Rahayu',    '08111222333',[[$L1['express'],'Cuci Express',2,12000,'kg'],[$L1['bedcvr'],'Bedcover',1,25000,'item']], 0,'qris','lunas','siap','2026-05-25',$uKasir1],
    ['2026-05-26',$P1[7],'Joko Siswanto',  '08777888999',[[$L1['kiloan'],'Cuci Kiloan',5,7000,'kg']], 0,'cash','belum_bayar','cuci','2026-05-28',$uKasir1],
    ['2026-05-28',$P1[2],'Dewi Lestari',   '08333444555',[[$L1['tas'],'Cuci Tas',2,50000,'item'],[$L1['kiloan'],'Cuci Kiloan',3,7000,'kg']], 0,'qris','lunas','masuk','2026-05-30',$uKasir1],
    ['2026-05-29',$P1[10],'Kost Bu Rini',  '08121212121',[[$L1['kiloan'],'Cuci Kiloan',8,7000,'kg']], 0,'transfer','lunas','masuk','2026-06-01',$uKasir1],
];

$trxIds1 = [];
foreach ($trxData1 as $t) {
    $no = noOrder($db, $oid2a, $t[0], $seqCount);
    $trxIds1[] = insTrx($db,$tid2,$oid2a,$no,$t[0],$t[1],$t[2],$t[3],$t[4],$t[5],$t[6],$t[7],$t[8],$t[9],$t[10],$seqCount);
}

// ── Skenario Outlet 2 ─────────────────────────────────────
$trxData2 = [
    ['2026-03-15',$P2[0],'Sumiati',           '08111333555',[[$L2['kiloan'],'Cuci Kiloan',4,7000,'kg']], 0,'cash','lunas','diambil','2026-03-17',$uKasir2],
    ['2026-03-15',$P2[1],'PP Al-Hikmah',      '08222444666',[[$L2['b2b'],'Cuci B2B',35,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-03-19',$uMgr2],
    ['2026-03-18',$P2[3],'Sri Mulyani',        '08444666888',[[$L2['kiloan'],'Cuci Kiloan',5,7000,'kg'],[$L2['sepatu'],'Cuci Sepatu',1,35000,'pasang']], 0,'cash','lunas','diambil','2026-03-20',$uKasir2],
    ['2026-03-20',$P2[2],'Wirawan',            '08333555777',[[$L2['setrika'],'Cuci+Setrika',3,10000,'kg']], 0,'cash','lunas','diambil','2026-03-22',$uKasir2],
    ['2026-03-25',$P2[4],'CV Maju Bersama',    '08555777999',[[$L2['b2b'],'Cuci B2B',20,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-03-28',$uMgr2],
    ['2026-03-27',$P2[3],'Sri Mulyani',        '08444666888',[[$L2['kiloan'],'Cuci Kiloan',4,7000,'kg']], 0,'cash','lunas','diambil','2026-03-29',$uKasir2],
    ['2026-04-05',$P2[0],'Sumiati',            '08111333555',[[$L2['kiloan'],'Cuci Kiloan',5,7000,'kg'],[$L2['bedcvr'],'Bedcover',1,25000,'item']], 0,'cash','lunas','diambil','2026-04-07',$uKasir2],
    ['2026-04-08',$P2[1],'PP Al-Hikmah',       '08222444666',[[$L2['b2b'],'Cuci B2B',38,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-04-12',$uMgr2],
    ['2026-04-10',$P2[2],'Wirawan',            '08333555777',[[$L2['express'],'Cuci Express',2,12000,'kg']], 0,'qris','lunas','diambil','2026-04-11',$uKasir2],
    ['2026-04-15',$P2[3],'Sri Mulyani',        '08444666888',[[$L2['kiloan'],'Cuci Kiloan',6,7000,'kg']], 0,'cash','lunas','diambil','2026-04-17',$uKasir2],
    ['2026-04-18',$P2[4],'CV Maju Bersama',    '08555777999',[[$L2['b2b'],'Cuci B2B',22,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-04-22',$uMgr2],
    ['2026-04-22',$P2[0],'Sumiati',            '08111333555',[[$L2['lipat'],'Cuci+Lipat',4,8000,'kg']], 0,'cash','lunas','diambil','2026-04-24',$uKasir2],
    ['2026-04-25',$P2[3],'Sri Mulyani',        '08444666888',[[$L2['setrika'],'Cuci+Setrika',3,10000,'kg']], 0,'qris','lunas','diambil','2026-04-27',$uKasir2],
    ['2026-05-08',$P2[1],'PP Al-Hikmah',       '08222444666',[[$L2['b2b'],'Cuci B2B',40,6500,'kg']], 0,'transfer','belum_bayar','diambil','2026-05-12',$uMgr2],
    ['2026-05-12',$P2[3],'Sri Mulyani',        '08444666888',[[$L2['kiloan'],'Cuci Kiloan',5,7000,'kg']], 0,'cash','lunas','diambil','2026-05-14',$uKasir2],
    ['2026-05-15',$P2[0],'Sumiati',            '08111333555',[[$L2['express'],'Cuci Express',2,12000,'kg'],[$L2['bedcvr'],'Bedcover',1,25000,'item']], 0,'qris','lunas','siap','2026-05-16',$uKasir2],
    ['2026-05-20',$P2[2],'Wirawan',            '08333555777',[[$L2['kiloan'],'Cuci Kiloan',3,7000,'kg']], 0,'cash','lunas','diambil','2026-05-22',$uKasir2],
    ['2026-05-24',$P2[4],'CV Maju Bersama',    '08555777999',[[$L2['b2b'],'Cuci B2B',18,6500,'kg']], 0,'transfer','belum_bayar','cuci','2026-05-28',$uMgr2],
    ['2026-05-27',$P2[3],'Sri Mulyani',        '08444666888',[[$L2['kiloan'],'Cuci Kiloan',4,7000,'kg']], 0,'cash','belum_bayar','masuk','2026-05-29',$uKasir2],
];

foreach ($trxData2 as $t) {
    $no = noOrder($db, $oid2b, $t[0], $seqCount);
    insTrx($db,$tid2,$oid2b,$no,$t[0],$t[1],$t[2],$t[3],$t[4],$t[5],$t[6],$t[7],$t[8],$t[9],$t[10],$seqCount);
}

echo '   ✓ ' . (count($trxData1)+count($trxData2)) . " transaksi seeded (+ kas masuk terhubung)\n";

// ─────────────────────────────────────────────────────────
// 8. KAS KELUAR (biaya operasional)
// ─────────────────────────────────────────────────────────
echo "[8] hl_kas keluar (operasional)...\n";

$kasKeluar = [
    // Outlet 1 — Maret
    [$oid2a,'2026-03-01','keluar','gaji',      'Gaji karyawan Februari 2026',  9700000,$uMgr1],
    [$oid2a,'2026-03-02','keluar','operasional','Sabun & deterjen cuci',          450000,$uMgr1],
    [$oid2a,'2026-03-10','keluar','operasional','Listrik bulan Maret',             380000,$uMgr1],
    [$oid2a,'2026-03-15','keluar','operasional','Plastik & kantong laundry',        85000,$uKasir1],
    [$oid2a,'2026-03-20','keluar','operasional','Servis mesin cuci #2',            250000,$uMgr1],
    [$oid2a,'2026-03-31','keluar','operasional','Air PDAM bulan Maret',            175000,$uMgr1],
    // Outlet 1 — April
    [$oid2a,'2026-04-01','keluar','gaji',      'Gaji karyawan Maret 2026',      9700000,$uMgr1],
    [$oid2a,'2026-04-05','keluar','operasional','Deterjen & pewangi bulk',         520000,$uMgr1],
    [$oid2a,'2026-04-10','keluar','operasional','Listrik bulan April',             395000,$uMgr1],
    [$oid2a,'2026-04-22','keluar','operasional','Plastik laundry & hanger',        110000,$uKasir1],
    [$oid2a,'2026-04-30','keluar','operasional','Air PDAM bulan April',            180000,$uMgr1],
    // Outlet 1 — Mei
    [$oid2a,'2026-05-01','keluar','gaji',      'Gaji karyawan April 2026',      9700000,$uMgr1],
    [$oid2a,'2026-05-08','keluar','operasional','Deterjen & softener',             480000,$uMgr1],
    [$oid2a,'2026-05-15','keluar','operasional','Listrik bulan Mei (est.)',         400000,$uMgr1],
    [$oid2a,'2026-05-20','keluar','operasional','Kantong & plastik laundry',        95000,$uKasir1],
    [$oid2a,'2026-05-28','keluar','operasional','Bensin kurir + parkir bulan ini',  300000,$uMgr1],
    // Outlet 2 — Maret
    [$oid2b,'2026-03-25','keluar','gaji',      'Gaji karyawan (prorate) Maret', 4760000,$uMgr2],
    [$oid2b,'2026-03-28','keluar','operasional','Setup awal: deterjen & perlengkapan', 680000,$uMgr2],
    // Outlet 2 — April
    [$oid2b,'2026-04-01','keluar','gaji',      'Gaji karyawan April 2026',      5600000,$uMgr2],
    [$oid2b,'2026-04-12','keluar','operasional','Deterjen & sabun cuci',           320000,$uMgr2],
    [$oid2b,'2026-04-20','keluar','operasional','Listrik & air bulan April',       310000,$uMgr2],
    // Outlet 2 — Mei
    [$oid2b,'2026-05-01','keluar','gaji',      'Gaji karyawan Mei 2026',         5600000,$uMgr2],
    [$oid2b,'2026-05-18','keluar','operasional','Deterjen, pewangi & kantong',     400000,$uMgr2],
];

foreach ($kasKeluar as $k) {
    [$oid,$tgl,$tipe,$kat,$ket,$jml,$createdBy] = $k;
    $db->prepare("INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,created_by)
        VALUES (?,?,?,?,?,?,?,?)")->execute([$tid2,$oid,$tgl,$tipe,$kat,$ket,$jml,$createdBy]);
}

echo "   ✓ " . count($kasKeluar) . " kas keluar seeded\n";

// ─────────────────────────────────────────────────────────
// 9. GAJI (hl_gaji)
// ─────────────────────────────────────────────────────────
echo "[9] hl_gaji...\n";

$gajiData = [
    // April 2026 (dibayar)
    [$oid2a,$uMgr1,  '2026-04','2026-04-01','3500000',500000,0,4000000,'dibayar','2026-04-01',$uOwner],
    [$oid2a,$uKasir1,'2026-04','2026-04-01','2500000',250000,0,2750000,'dibayar','2026-04-01',$uOwner],
    [$oid2a,$uStaff1,'2026-04','2026-04-01','2200000',200000,0,2400000,'dibayar','2026-04-01',$uOwner],
    [$oid2a,$uKurir1,'2026-04','2026-04-01','2000000',150000,0,2150000,'dibayar','2026-04-01',$uOwner],
    [$oid2b,$uMgr2,  '2026-04','2026-04-01','3200000',400000,0,3600000,'dibayar','2026-04-01',$uOwner],
    [$oid2b,$uKasir2,'2026-04','2026-04-01','2400000',200000,0,2600000,'dibayar','2026-04-01',$uOwner],
    // Mei 2026 (pending)
    [$oid2a,$uMgr1,  '2026-05','2026-05-01','3500000',0,0,3500000,'pending',null,$uOwner],
    [$oid2a,$uKasir1,'2026-05','2026-05-01','2500000',0,0,2500000,'pending',null,$uOwner],
    [$oid2a,$uStaff1,'2026-05','2026-05-01','2200000',0,0,2200000,'pending',null,$uOwner],
    [$oid2a,$uKurir1,'2026-05','2026-05-01','2000000',0,0,2000000,'pending',null,$uOwner],
    [$oid2b,$uMgr2,  '2026-05','2026-05-01','3200000',0,0,3200000,'pending',null,$uOwner],
    [$oid2b,$uKasir2,'2026-05','2026-05-01','2400000',0,0,2400000,'pending',null,$uOwner],
];

foreach ($gajiData as $g) {
    [$oid,$uid,$bulan,$tgl,$gapok,$bonus,$pot,$total,$status,$dibayarAt,$createdBy] = $g;
    $db->prepare("INSERT INTO hl_gaji
        (tenant_id,outlet_id,user_id,bulan,gaji_pokok,bonus,potongan,total,status,catatan,dibayar_at,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
        $tid2,$oid,$uid,$bulan,$gapok,$bonus,$pot,$total,$status,
        $status==='dibayar' ? 'Dibayar tunai ke rekening masing-masing' : 'Menunggu persetujuan owner',
        $dibayarAt,$createdBy,
    ]);
}

echo "   ✓ " . count($gajiData) . " rekap gaji seeded\n";

// ─────────────────────────────────────────────────────────
// 10. ABSENSI (hl_absensi) — 14 hari terakhir
// ─────────────────────────────────────────────────────────
echo "[10] hl_absensi...\n";

$staffList1 = [[$uMgr1,'08:00','17:00'],[$uKasir1,'08:00','16:00'],[$uStaff1,'07:30','15:30'],[$uKurir1,'09:00','18:00']];
$staffList2 = [[$uMgr2,'08:00','17:00'],[$uKasir2,'08:30','16:30']];

for ($d = 14; $d >= 1; $d--) {
    $tgl = date('Y-m-d', strtotime("-{$d} days"));
    $dow = (int)date('N', strtotime($tgl)); // 1=Mon..7=Sun
    if ($dow >= 6) continue; // skip weekend

    foreach ($staffList1 as $s) {
        [$uid,$jin,$jout] = $s;
        $status = ($d === 7 && $uid === $uStaff1) ? 'izin' : 'hadir';
        $db->prepare("INSERT INTO hl_absensi
            (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
            VALUES (?,?,?,?,?,?,?)")->execute([
            $tid2,$oid2a,$uid,$tgl,
            $status==='hadir' ? $jin : null,
            $status==='hadir' ? $jout : null,
            $status,
        ]);
    }
    foreach ($staffList2 as $s) {
        [$uid,$jin,$jout] = $s;
        $db->prepare("INSERT INTO hl_absensi
            (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
            VALUES (?,?,?,?,?,?,?)")->execute([
            $tid2,$oid2b,$uid,$tgl,$jin,$jout,'hadir',
        ]);
    }
}

echo "   ✓ Absensi 14 hari seeded\n";

// ─────────────────────────────────────────────────────────
// 11. LOYALTY LOG (hl_loyalty_log)
// ─────────────────────────────────────────────────────────
echo "[11] hl_loyalty_log...\n";

// Siti Rahayu (P1[0]) — 350 poin, Dewi Lestari (P1[2]) — 520 poin, Yunita (P1[8]) — 240 poin
$loyaltyLogs = [
    [$P1[0],null,  'earn',  50, 50,  'Earn dari order Maret','2026-03-03'],
    [$P1[0],null,  'earn',  50,100,  'Earn dari order Maret','2026-03-17'],
    [$P1[0],null,  'earn',  50,150,  'Earn dari order April','2026-04-02'],
    [$P1[0],null,  'earn',  50,200,  'Earn dari order April','2026-04-18'],
    [$P1[0],null,  'earn',  50,250,  'Earn dari order Mei',  '2026-05-03'],
    [$P1[0],-150,  'redeem',150,100, 'Tukar poin diskon',    '2026-05-07'],
    [$P1[0],null,  'earn',  50,150,  'Earn dari order Mei',  '2026-05-24'],
    [$P1[0],null,  'earn', 200,350,  'Earn dari order Mei',  '2026-05-28'], // tas + kiloan
    [$P1[2],null,  'earn',  85,85,   'Earn dari order Maret','2026-03-03'],
    [$P1[2],null,  'earn',  50,135,  'Earn dari order Maret','2026-03-20'],
    [$P1[2],null,  'earn',  50,185,  'Earn dari order April','2026-04-05'],
    [$P1[2],null,  'earn',  85,270,  'Earn dari order April','2026-04-20'],
    [$P1[2],null,  'earn',  50,320,  'Earn dari order April','2026-04-28'],
    [$P1[2],null,  'earn',  50,370,  'Earn dari order Mei',  '2026-05-07'],
    [$P1[2],-100,  'redeem',100,270, 'Tukar poin Mei',       '2026-05-10'],
    [$P1[2],null,  'earn', 150,420,  'Earn dari order Mei',  '2026-05-28'], // tas 2 + kiloan
    [$P1[2],null,  'earn', 100,520,  'Bonus poin pelanggan setia','2026-05-29'],
    [$P1[8],null,  'earn',  60,60,   'Earn dari order Maret','2026-03-12'],
    [$P1[8],null,  'earn',  50,110,  'Earn dari order April','2026-04-07'],
    [$P1[8],null,  'earn',  50,160,  'Earn dari order April','2026-04-28'],
    [$P1[8],null,  'earn',  50,210,  'Earn dari order Mei',  '2026-05-22'],
    [$P1[8],-50,   'redeem', 50,160, 'Tukar poin Mei',       '2026-05-25'],
    [$P1[8],null,  'earn',  80,240,  'Earn dari order Mei',  '2026-05-28'],
    [$P2[3],null,  'earn',  50,50,   'Earn dari order Maret','2026-03-18'],
    [$P2[3],null,  'earn',  50,100,  'Earn dari order April','2026-04-15'],
    [$P2[3],null,  'earn',  50,150,  'Earn dari order April','2026-04-25'],
    [$P2[3],null,  'earn',  50,200,  'Earn dari order Mei',  '2026-05-12'],
];

foreach ($loyaltyLogs as $ll) {
    [$pelId,$trxId,$type,$poin,$balAfter,$ket,$tgl] = $ll;
    $oid = in_array($pelId,$P1) ? $oid2a : $oid2b;
    $db->prepare("INSERT INTO hl_loyalty_log
        (tenant_id,outlet_id,pelanggan_id,transaksi_id,type,poin,balance_after,keterangan,created_at)
        VALUES (?,?,?,?,?,?,?,?,?)")->execute([
        $tid2,$oid,$pelId,$trxId,$type,abs($poin),$balAfter,$ket,$tgl.' 10:00:00',
    ]);
}

echo "   ✓ " . count($loyaltyLogs) . " loyalty log entries seeded\n";

// ─────────────────────────────────────────────────────────
// 12. DROP POINT (hl_drop_points + hl_komisi_rekap)
// ─────────────────────────────────────────────────────────
echo "[12] hl_drop_points & komisi...\n";

$db->prepare("INSERT INTO hl_drop_points
    (tenant_id,outlet_id,nama_mitra,alamat,wa,komisi_model,komisi_per_kg,periode_rekap,status)
    VALUES (?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'Toko Kelontong Pak Rahmat',
    'Jl. Gejayan No. 15, Sleman','08111000999','per_kg',500,'bulanan','aktif',
]);
$dp1 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO hl_drop_points
    (tenant_id,outlet_id,nama_mitra,alamat,wa,komisi_model,komisi_persen,periode_rekap,status)
    VALUES (?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'Barbershop Rapi','Jl. Colombo No. 7, Caturtunggal',
    '08222111000','persen',5.00,'bulanan','aktif',
]);
$dp2 = (int)$db->lastInsertId();

// Rekap April (sudah dibayar)
$db->prepare("INSERT INTO hl_komisi_rekap
    (tenant_id,outlet_id,drop_point_id,periode_start,periode_end,total_order,total_kg,total_omset,total_komisi,status,dibayar_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,$dp1,'2026-04-01','2026-04-30',12,58.5,409500,29250,'dibayar','2026-05-02 10:00:00',
]);

echo "   ✓ 2 drop points, 1 rekap komisi seeded\n";

// ─────────────────────────────────────────────────────────
// 13. KEUANGAN (aset, kas bank, liabilitas)
// ─────────────────────────────────────────────────────────
echo "[13] Keuangan tables...\n";

// hl_aset_tetap
$db->prepare("INSERT INTO hl_aset_tetap
    (tenant_id,outlet_id,nama,kategori,tanggal_perolehan,nilai_perolehan,nilai_sisa,umur_ekonomis,metode_penyusutan,status)
    VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'Mesin Cuci LG 10kg (unit 1)','mesin',
    '2023-01-15',4500000,500000,48,'garis_lurus','aktif',
]);
$db->prepare("INSERT INTO hl_aset_tetap
    (tenant_id,outlet_id,nama,kategori,tanggal_perolehan,nilai_perolehan,nilai_sisa,umur_ekonomis,metode_penyusutan,status)
    VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'Mesin Cuci LG 10kg (unit 2)','mesin',
    '2023-06-01',4500000,500000,48,'garis_lurus','aktif',
]);
$db->prepare("INSERT INTO hl_aset_tetap
    (tenant_id,outlet_id,nama,kategori,tanggal_perolehan,nilai_perolehan,nilai_sisa,umur_ekonomis,metode_penyusutan,status)
    VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'Motor Honda Beat (kurir)','kendaraan',
    '2023-03-01',18000000,5000000,48,'garis_lurus','aktif',
]);
$db->prepare("INSERT INTO hl_aset_tetap
    (tenant_id,outlet_id,nama,kategori,tanggal_perolehan,nilai_perolehan,nilai_sisa,umur_ekonomis,metode_penyusutan,status)
    VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2b,'Mesin Cuci Samsung 8kg','mesin',
    '2023-09-01',3800000,500000,48,'garis_lurus','aktif',
]);

// hl_kas_bank
$db->prepare("INSERT INTO hl_kas_bank
    (tenant_id,outlet_id,nama_akun,bank,no_rekening,saldo_awal,saldo_akhir,per_tanggal)
    VALUES (?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'Rekening BCA Operasional','BCA','1234567890',5000000,8250000,'2026-04-30',
]);
$kbId = (int)$db->lastInsertId();

// hl_kas_bank_mutasi — beberapa mutasi di Mei
$mutasiData = [
    [$kbId,'masuk', 'Setoran kas harian',    2500000,'2026-05-05'],
    [$kbId,'keluar','Transfer gaji April',    9700000,'2026-05-01'],
    [$kbId,'masuk', 'Pembayaran Hotel Melati (B2B Apr)',273000,'2026-05-08'],
    [$kbId,'masuk', 'Setoran kas harian',    1800000,'2026-05-12'],
    [$kbId,'masuk', 'Setoran kas harian',    2100000,'2026-05-19'],
];
foreach ($mutasiData as $m) {
    [$kbid,$tipe,$ket,$jml,$tgl] = $m;
    $db->prepare("INSERT INTO hl_kas_bank_mutasi
        (tenant_id,outlet_id,kas_bank_id,tipe,keterangan,jumlah,tanggal)
        VALUES (?,?,?,?,?,?,?)")->execute([$tid2,$oid2a,$kbid,$tipe,$ket,$jml,$tgl]);
}

// hl_liabilitas — 1 pinjaman usaha
$db->prepare("INSERT INTO hl_liabilitas
    (tenant_id,outlet_id,nama,tipe,kreditur,tanggal_pinjam,saldo_awal,saldo_terbayar,bunga_persen,tenor_bulan,status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'KUR BRI Pengembangan Outlet 2','pinjaman_bank','BRI',
    '2023-09-01',30000000,15000000,6.00,36,'aktif',
]);

echo "   ✓ Aset tetap, kas bank, liabilitas seeded\n";

// ─────────────────────────────────────────────────────────
// 14. COIN LEDGER
// ─────────────────────────────────────────────────────────
echo "[14] coin_ledger...\n";

// Top-up saat pertama join
$db->prepare("INSERT INTO coin_ledger
    (tenant_id,outlet_id,type,amount,feature_used,description,balance_after,created_at)
    VALUES (?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'topup',60000,null,'Top-up paket Growth (setup)',60000,'2023-01-01 10:00:00',
]);

// Deductions over time
$coinDeducts = [
    ['send_wa_notif',100,'WA notif order selesai','2026-03-05',59900],
    ['send_wa_notif',100,'WA notif order selesai','2026-03-10',59800],
    ['ai_briefing',  500,'AI briefing harian Maret','2026-03-15',59300],
    ['send_wa_notif',100,'WA notif order selesai','2026-03-20',59200],
    ['daily_report', 100,'Laporan harian otomatis','2026-03-31',59100],
    ['send_wa_notif',100,'WA notif order selesai','2026-04-05',59000],
    ['ai_analyst',   200,'Analisis performa April','2026-04-10',58800],
    ['send_wa_notif',100,'WA notif order selesai','2026-04-15',58700],
    ['ai_briefing',  500,'AI briefing harian April','2026-04-20',58200],
    ['send_wa_notif',100,'WA notif order selesai','2026-04-25',58100],
    ['daily_report', 100,'Laporan harian otomatis','2026-04-30',58000],
    ['send_wa_notif',100,'WA notif order selesai','2026-05-03',57900],
    ['ai_churn_message',30,'Pesan retensi pelanggan diam','2026-05-05',57870],
    ['send_wa_notif',100,'WA notif order selesai','2026-05-10',57770],
    ['ai_analyst',   200,'Analisis performa bulanan','2026-05-15',57570],
    ['send_wa_notif',100,'WA notif order selesai','2026-05-20',57470],
    ['ai_briefing',  500,'AI briefing harian Mei','2026-05-25',56970],
    ['send_wa_notif',100,'WA notif order selesai','2026-05-28',56870],
];

foreach ($coinDeducts as $cd) {
    [$feat,$amt,$desc,$tgl,$balAfter] = $cd;
    $db->prepare("INSERT INTO coin_ledger
        (tenant_id,outlet_id,type,amount,feature_used,description,balance_after,created_at)
        VALUES (?,?,?,?,?,?,?,?)")->execute([
        $tid2,$oid2a,'deduct',$amt,$feat,$desc,$balAfter,$tgl.' 09:30:00',
    ]);
}

// Top-up tambahan (beli paket popular)
$db->prepare("INSERT INTO coin_ledger
    (tenant_id,outlet_id,type,amount,feature_used,description,balance_after,created_at)
    VALUES (?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'topup',60000,null,'Beli Popular Pack 250k',
    56870+60000,'2026-05-01 11:00:00',
]);

// Update tenant coin_balance ke angka realistic (setelah topup: 56870+60000 - beberapa deduct sesudahnya)
// Final balance sudah di-insert sebagai 45000 di awal — itu sudah cukup.

echo "   ✓ Coin ledger seeded\n";

// ─────────────────────────────────────────────────────────
// 15. PAYMENTS & MANUAL PAYMENTS
// ─────────────────────────────────────────────────────────
echo "[15] payments & saas_manual_payments...\n";

$db->prepare("INSERT INTO payments
    (tenant_id,outlet_id,type,amount,coin_amount,gateway_ref,notes,status,paid_at)
    VALUES (?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'setup_fee',500000,60000,'TRF-BCA-20230101',
    'Setup fee paket Growth + coin awal','success','2023-01-01 09:00:00',
]);

$db->prepare("INSERT INTO payments
    (tenant_id,outlet_id,type,amount,coin_amount,notes,status,paid_at)
    VALUES (?,?,?,?,?,?,?,?)")->execute([
    $tid2,$oid2a,'coin_topup',250000,60000,'Beli Popular Pack Mei 2026','success','2026-05-01 11:00:00',
]);

$saId = 1; // superadmin id
$db->prepare("INSERT INTO saas_manual_payments
    (tenant_id,superadmin_id,type,nominal_dibayar,coin_dikreditkan,metode,
     nama_pengirim,ref_transfer,tanggal_bayar,catatan,status,notif_wa_sent,notif_wa_sent_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$saId,'setup_fee',500000,60000,'transfer_bca',
    'Budi Hartono','BCA-REF-20230101-7891234','2023-01-01',
    'Setup awal paket Growth — 2 outlet Yogyakarta','confirmed',1,'2023-01-01 10:30:00',
]);

$db->prepare("INSERT INTO saas_manual_payments
    (tenant_id,superadmin_id,type,nominal_dibayar,coin_dikreditkan,metode,
     nama_pengirim,ref_transfer,tanggal_bayar,catatan,status,notif_wa_sent,notif_wa_sent_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid2,$saId,'coin_topup',250000,60000,'transfer_bca',
    'Budi Hartono','BCA-REF-20260501-3456789','2026-05-01',
    'Topup Popular Pack — balance menipis setelah kampanye AI','confirmed',1,'2026-05-01 11:15:00',
]);

// Tenant 3 setup fee
$db->prepare("INSERT INTO saas_manual_payments
    (tenant_id,superadmin_id,type,nominal_dibayar,coin_dikreditkan,metode,
     nama_pengirim,ref_transfer,tanggal_bayar,catatan,status,notif_wa_sent)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
    $tid3,$saId,'setup_fee',300000,28000,'qris',
    'Maya Putri','QRIS-20260515-1122334','2026-05-15',
    'Setup Starter Pack — self service registration','confirmed',0,
]);

echo "   ✓ Payments seeded\n";

// ─────────────────────────────────────────────────────────
// 16. SUPERADMIN ACTIVITY
// ─────────────────────────────────────────────────────────
echo "[16] Superadmin activity (tickets, notes, logs)...\n";

$tickets = [
    [$tid2,$saId,'wa','Pertanyaan cara ekspor laporan keuangan','onboarding',
     'Bagaimana cara export laporan keuangan ke PDF? Saya sudah coba tapi tidak muncul tombolnya.'],
    [$tid2,$saId,'email','Coin cepat habis — minta penjelasan rincian pemakaian','billing',
     'Kenapa coin habis cepat? Mohon kirimkan rincian pemakaian coin bulan April. Terima kasih.'],
    [$tid3,$saId,'wa','Mesin tidak bisa connect ke sistem','support',
     'Saya baru daftar tapi login terus error. Password sudah benar, mohon bantuannya.'],
    [$tid4,$saId,'call','Perpanjangan layanan setelah masa grace','churn_risk',
     'Owner Laundry Express minta keringanan perpanjangan trial 7 hari karena baru dapat pelanggan korporat.'],
];

foreach ($tickets as $tk) {
    [$ttid,$said,$ch,$subj,$type,$msg] = $tk;
    $db->prepare("INSERT INTO support_tickets (tenant_id,superadmin_id,channel,subject,type,message)
        VALUES (?,?,?,?,?,?)")->execute([$ttid,$said,$ch,$subj,$type,$msg]);
}

$notes = [
    [$tid2,$saId,1,'Owner aktif dan responsif. Pakai 2 outlet, omzet bagus. Kandidat upgrade ke Pro.'],
    [$tid2,$saId,0,'Brief 20 Mei: minta fitur notif WA untuk reminder ambil laundry.'],
    [$tid3,$saId,0,'Trial baru 15 hari. Owner perempuan muda, antusias. Perlu onboarding lebih lanjut.'],
    [$tid4,$saId,1,'PERHATIAN: grace period. Sudah dihubungi 2x via WA, belum ada konfirmasi perpanjangan.'],
];

foreach ($notes as $nt) {
    [$ttid,$said,$pin,$note] = $nt;
    $db->prepare("INSERT INTO tenant_notes (tenant_id,superadmin_id,note,is_pinned) VALUES (?,?,?,?)")
       ->execute([$ttid,$said,$note,$pin]);
}

$logs = [
    [$saId,'provision_tenant',$tid2,'Provisioning tenant baru: Laundry Bersih Kilat (paket Growth)'],
    [$saId,'topup_coin',      $tid2,'Manual topup 60.000 coin setup fee'],
    [$saId,'provision_tenant',$tid3,'Provisioning tenant baru: Fresh Laundry Semarang (self-service)'],
    [$saId,'provision_tenant',$tid4,'Provisioning tenant baru: Laundry Express Maju (assisted)'],
    [$saId,'topup_coin',      $tid2,'Konfirmasi topup Popular Pack 60.000 coin — Mei 2026'],
    [$saId,'view_tenant',     $tid4,'Cek status grace — belum ada pembayaran'],
];

foreach ($logs as $lg) {
    [$said,$act,$ttid,$desc] = $lg;
    $db->prepare("INSERT INTO superadmin_logs (superadmin_id,action,target_tenant_id,description,ip_address)
        VALUES (?,?,?,?,?)")->execute([$said,$act,$ttid,$desc,'127.0.0.1']);
}

echo "   ✓ " . count($tickets) . " tiket, " . count($notes) . " notes, " . count($logs) . " logs seeded\n";

// ─────────────────────────────────────────────────────────
// 17. PLATFORM HEALTH (saas_platform_health) — 30 hari
// ─────────────────────────────────────────────────────────
echo "[17] saas_platform_health (30 hari)...\n";

$phExists = (int)$db->query("SELECT COUNT(*) FROM saas_platform_health WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
if ($phExists < 5) {
    for ($i = 29; $i >= 1; $i--) {
        $tgl = date('Y-m-d', strtotime("-{$i} days"));
        $dow = (int)date('N', strtotime($tgl));
        $isWeekend = $dow >= 6;
        $aktif   = 3 - ($i > 20 ? 1 : 0); // mulai 2, naik ke 3 saat t3 join
        $trial   = $i > 15 ? 1 : ($i > 5 ? 2 : 1);
        $grace   = $i > 5 ? 0 : 1;
        $login   = $isWeekend ? rand(1,3) : rand(3,6);
        $trx     = $isWeekend ? rand(2,6) : rand(4,12);
        $wa      = rand(2, $trx);
        $ai      = rand(0,3);
        $coinP   = $ai * 200 + $wa * 100;
        $coinS   = $i === 30 ? 60000 : ($i === 1 ? 60000 : 0);
        $rev     = ($i === 30 ? 500000 : ($i === 1 ? 250000 : 0));
        $db->prepare("INSERT INTO saas_platform_health
            (tanggal,total_tenant_aktif,total_tenant_trial,total_tenant_grace,
             tenant_login_hari_ini,total_transaksi,total_wa_terkirim,
             total_ai_calls,total_ai_cost_coin,total_coin_terjual,total_coin_dipakai,
             total_revenue_hari,total_error_php,total_wa_gagal,total_ai_error)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $tgl,$aktif,$trial,$grace,$login,$trx,$wa,$ai,$coinP,$coinS,$coinP,$rev,
            rand(0,2),rand(0,1),rand(0,1),
        ]);
    }
    echo "   ✓ 29 hari platform health seeded\n";
} else {
    echo "   - platform health sudah ada, skip\n";
}

// ─────────────────────────────────────────────────────────
// SELESAI
// ─────────────────────────────────────────────────────────
echo "\n=== SELESAI ===\n";
echo "Tenant 2 (active):  tid=$tid2, outlet1=$oid2a (Malioboro), outlet2=$oid2b (Parangtritis)\n";
echo "Tenant 3 (trial):   tid=$tid3, outlet=$oid3 (Semarang)\n";
echo "Tenant 4 (grace):   tid=$tid4, outlet=$oid4 (Solo)\n";
echo "\nLogin demo:\n";
echo "  HQ: budi.hartono@gmail.com / Demo1234!\n";
echo "  HQ: maya\@freshlaundry.id   / Demo1234!\n";
echo "  HQ: hendra.laundry@gmail.com / Demo1234!\n";
echo "  Outlet kasir: sari.kasir2 / Demo1234!\n";
echo "  Outlet manager: dewi.manager / Demo1234!\n";
echo "\nData summary (Tenant 2):\n";
echo "  - " . count($trxData1) . " transaksi outlet 1 (Maret–Mei 2026)\n";
echo "  - " . count($trxData2) . " transaksi outlet 2 (Maret–Mei 2026)\n";
echo "  - Kas masuk otomatis dari transaksi lunas\n";
echo "  - " . count($kasKeluar) . " kas keluar operasional\n";
echo "  - " . count($gajiData) . " rekap gaji (Apr dibayar, Mei pending)\n";
echo "  - " . count($loyaltyLogs) . " loyalty log entries\n";
echo "  - Aset tetap, kas bank, liabilitas untuk laporan keuangan SAK EMKM\n";
