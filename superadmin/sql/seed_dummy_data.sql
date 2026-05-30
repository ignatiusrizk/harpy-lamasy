-- ══════════════════════════════════════════════════════════════════
-- LaMaSy — Dummy Data Seed (5 Tenants, 8 Outlets, Connected Data)
-- Run via phpMyAdmin atau mysql client
-- ══════════════════════════════════════════════════════════════════
-- Password SEMUA akun demo: Demo1234!
-- bcrypt hash (cost 10): $2y$10$ZPzHp.pCr7UugGmFjtLsieC8Aaz0G3n3nFz5/05R1eyrwudzZQTSy
--
-- 5 Tenant:
--   T2: CV Bersih Kilat Mandiri  — ACTIVE,    3 outlet, Yogyakarta
--   T3: Fresh Laundry Semarang   — TRIAL,     1 outlet, Semarang
--   T4: Laundry Express Maju     — GRACE,     1 outlet, Solo
--   T5: Bunda Wangi Laundry      — ACTIVE,    2 outlet, Surabaya
--   T6: Quick Wash 24 Jam        — SUSPENDED, 1 outlet, Bandung
-- ══════════════════════════════════════════════════════════════════

-- ──────────────────────────────────────────────────────────────────
-- CLEANUP: hapus data dummy lama jika sudah pernah dijalankan
-- (aman dijalankan berkali-kali, hanya hapus tenant T2-T6 di bawah)
-- ──────────────────────────────────────────────────────────────────
SET @cleanup_emails := "'budi.hartono@gmail.com','maya@freshlaundry.id','hendra.laundry@gmail.com','sari.bunda@bundawangilaundry.com','deni.quickwash@gmail.com'";
DELETE FROM hl_loyalty_log    WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_komisi_rekap   WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_drop_points    WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_absensi        WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_gaji           WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_kas            WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_transaksi_item WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_transaksi      WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_promo          WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_pelanggan      WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_layanan        WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM hl_users          WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM coin_ledger       WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM payments          WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM saas_manual_payments WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM support_tickets   WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM tenant_notes      WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM superadmin_logs   WHERE target_tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM outlets           WHERE tenant_id IN (SELECT id FROM tenants WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails));
DELETE FROM tenants           WHERE FIND_IN_SET(CONCAT("'",email,"'"), @cleanup_emails);

SET @PASS := '$2y$10$ZPzHp.pCr7UugGmFjtLsieC8Aaz0G3n3nFz5/05R1eyrwudzZQTSy';
SET @SAID := 1; -- superadmin id (sesuaikan kalau perlu)

-- ──────────────────────────────────────────────────────────────────
-- PACKAGES & COIN BUNDLES (skip kalau sudah ada)
-- ──────────────────────────────────────────────────────────────────
INSERT IGNORE INTO saas_packages (nama, slug, deskripsi, setup_fee, coin_awal, trial_hari, max_outlets, urutan) VALUES
('Starter','starter','1 outlet — laundry rumahan',300000,30000,30,1,1),
('Growth','growth','Hingga 3 outlet — bisnis berkembang',500000,60000,30,3,2),
('Pro','pro','Hingga 10 outlet — franchise laundry',1000000,150000,30,10,3);

INSERT IGNORE INTO saas_coin_bundles (nama, harga, coin_didapat, bonus_pct, is_featured, urutan) VALUES
('Mini Pack',50000,10000,0.00,0,1),
('Basic Pack',100000,22000,10.00,0,2),
('Popular Pack',250000,60000,20.00,1,3),
('Power Pack',500000,130000,30.00,0,4);

SET @pkgStarter := (SELECT id FROM saas_packages WHERE slug='starter');
SET @pkgGrowth  := (SELECT id FROM saas_packages WHERE slug='growth');

-- ══════════════════════════════════════════════════════════════════
-- TENANT 2: CV Bersih Kilat Mandiri (ACTIVE, 3 outlet, Yogyakarta)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO tenants
(slug, nama_perusahaan, kota, owner_name, owner_wa, email, phone,
 status, coin_balance, coin_mode, total_outlets, max_outlets,
 loyalty_enabled, loyalty_rupiah_per_poin, loyalty_poin_value,
 package_id, package_assigned_at, registration_source, password_hash,
 trial_ends_at, provisioned_at, registered_at, verified_at)
VALUES
('bersih-kilat','CV Bersih Kilat Mandiri','Yogyakarta',
 'Budi Hartono','628123456789','budi.hartono@gmail.com','628123456789',
 'active',45000,'shared',3,3,1,1000,100,
 @pkgGrowth, NOW(),'assisted',@PASS,
 DATE_SUB(NOW(),INTERVAL 60 DAY),
 DATE_SUB(NOW(),INTERVAL 90 DAY),
 DATE_SUB(NOW(),INTERVAL 91 DAY),
 DATE_SUB(NOW(),INTERVAL 90 DAY));
SET @T2 := LAST_INSERT_ID();

INSERT INTO outlets (tenant_id,nama_outlet,slug,alamat,kota,telepon,status,is_main,setup_done,activated_at,trial_starts_at,trial_ends_at) VALUES
(@T2,'Bersih Kilat - Malioboro','bersih-kilat-malioboro',
 'Jl. Malioboro No. 45, Gedongtengen, Yogyakarta','Yogyakarta','0274-512345',
 'active',1,1, DATE_SUB(NOW(),INTERVAL 90 DAY), DATE_SUB(NOW(),INTERVAL 120 DAY), DATE_SUB(NOW(),INTERVAL 90 DAY));
SET @O2A := LAST_INSERT_ID();

INSERT INTO outlets (tenant_id,nama_outlet,slug,alamat,kota,telepon,status,is_main,setup_done,activated_at,trial_starts_at,trial_ends_at) VALUES
(@T2,'Bersih Kilat - Parangtritis','bersih-kilat-parangtritis',
 'Jl. Parangtritis No. 22, Sewon, Bantul','Yogyakarta','0274-987654',
 'active',0,1, DATE_SUB(NOW(),INTERVAL 60 DAY), DATE_SUB(NOW(),INTERVAL 90 DAY), DATE_SUB(NOW(),INTERVAL 60 DAY));
SET @O2B := LAST_INSERT_ID();

INSERT INTO outlets (tenant_id,nama_outlet,slug,alamat,kota,telepon,status,is_main,setup_done,activated_at,trial_starts_at,trial_ends_at) VALUES
(@T2,'Bersih Kilat - Kaliurang','bersih-kilat-kaliurang',
 'Jl. Kaliurang KM 7, Sleman, Yogyakarta','Yogyakarta','0274-555111',
 'active',0,1, DATE_SUB(NOW(),INTERVAL 30 DAY), DATE_SUB(NOW(),INTERVAL 60 DAY), DATE_SUB(NOW(),INTERVAL 30 DAY));
SET @O2C := LAST_INSERT_ID();

-- ══════════════════════════════════════════════════════════════════
-- TENANT 3: Fresh Laundry Semarang (TRIAL, 1 outlet)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO tenants
(slug, nama_perusahaan, kota, owner_name, owner_wa, email, phone,
 status, coin_balance, coin_mode, total_outlets, max_outlets,
 package_id, package_assigned_at, registration_source, password_hash,
 trial_ends_at, provisioned_at, registered_at, verified_at)
VALUES
('fresh-laundry','Fresh Laundry Semarang','Semarang',
 'Maya Putri Ariani','6285678901234','maya@freshlaundry.id','6285678901234',
 'trial',28000,'shared',1,1,
 @pkgStarter, NOW(),'self_service',@PASS,
 DATE_ADD(NOW(),INTERVAL 15 DAY), NOW(),
 DATE_SUB(NOW(),INTERVAL 15 DAY), DATE_SUB(NOW(),INTERVAL 15 DAY));
SET @T3 := LAST_INSERT_ID();

INSERT INTO outlets (tenant_id,nama_outlet,slug,alamat,kota,telepon,status,is_main,setup_done,trial_starts_at,trial_ends_at) VALUES
(@T3,'Fresh Laundry Semarang','fresh-laundry-smg',
 'Jl. Pemuda No. 78, Semarang Tengah','Semarang','024-3456789',
 'trial',1,1, DATE_SUB(NOW(),INTERVAL 15 DAY), DATE_ADD(NOW(),INTERVAL 15 DAY));
SET @O3 := LAST_INSERT_ID();

-- ══════════════════════════════════════════════════════════════════
-- TENANT 4: Laundry Express Maju (GRACE, 1 outlet)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO tenants
(slug, nama_perusahaan, kota, owner_name, owner_wa, email, phone,
 status, coin_balance, coin_mode, total_outlets, max_outlets,
 package_id, package_assigned_at, registration_source, password_hash,
 trial_ends_at, provisioned_at, registered_at, verified_at)
VALUES
('express-maju','Laundry Express Maju','Solo',
 'Hendra Kurniawan','6281398765432','hendra.laundry@gmail.com','6281398765432',
 'grace',3500,'shared',1,1,
 @pkgStarter, NOW(),'assisted',@PASS,
 DATE_SUB(NOW(),INTERVAL 5 DAY),
 DATE_SUB(NOW(),INTERVAL 35 DAY),
 DATE_SUB(NOW(),INTERVAL 36 DAY),
 DATE_SUB(NOW(),INTERVAL 35 DAY));
SET @T4 := LAST_INSERT_ID();

INSERT INTO outlets (tenant_id,nama_outlet,slug,alamat,kota,telepon,status,is_main,setup_done,trial_starts_at,trial_ends_at,grace_ends_at) VALUES
(@T4,'Express Maju - Solo','express-maju-solo',
 'Jl. Slamet Riyadi No. 12, Laweyan, Solo','Solo','0271-123456',
 'grace',1,1, DATE_SUB(NOW(),INTERVAL 35 DAY), DATE_SUB(NOW(),INTERVAL 5 DAY), DATE_ADD(NOW(),INTERVAL 25 DAY));
SET @O4 := LAST_INSERT_ID();

-- ══════════════════════════════════════════════════════════════════
-- TENANT 5: Bunda Wangi Laundry (ACTIVE, 2 outlet, Surabaya)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO tenants
(slug, nama_perusahaan, kota, owner_name, owner_wa, email, phone,
 status, coin_balance, coin_mode, total_outlets, max_outlets,
 loyalty_enabled, loyalty_rupiah_per_poin, loyalty_poin_value,
 package_id, package_assigned_at, registration_source, password_hash,
 trial_ends_at, provisioned_at, registered_at, verified_at)
VALUES
('bunda-wangi','PT Bunda Wangi Sejahtera','Surabaya',
 'Sari Indrawati','628156789012','sari.bunda@bundawangilaundry.com','628156789012',
 'active',78000,'per_outlet',2,3,1,1000,100,
 @pkgGrowth, NOW(),'assisted',@PASS,
 DATE_SUB(NOW(),INTERVAL 90 DAY),
 DATE_SUB(NOW(),INTERVAL 120 DAY),
 DATE_SUB(NOW(),INTERVAL 121 DAY),
 DATE_SUB(NOW(),INTERVAL 120 DAY));
SET @T5 := LAST_INSERT_ID();

INSERT INTO outlets (tenant_id,nama_outlet,slug,alamat,kota,telepon,status,coin_balance,is_main,setup_done,activated_at,trial_starts_at,trial_ends_at) VALUES
(@T5,'Bunda Wangi - Manyar','bunda-wangi-manyar',
 'Jl. Manyar Kertoarjo V/12, Surabaya','Surabaya','031-5945678',
 'active',40000,1,1, DATE_SUB(NOW(),INTERVAL 120 DAY), DATE_SUB(NOW(),INTERVAL 150 DAY), DATE_SUB(NOW(),INTERVAL 120 DAY));
SET @O5A := LAST_INSERT_ID();

INSERT INTO outlets (tenant_id,nama_outlet,slug,alamat,kota,telepon,status,coin_balance,is_main,setup_done,activated_at,trial_starts_at,trial_ends_at) VALUES
(@T5,'Bunda Wangi - Rungkut','bunda-wangi-rungkut',
 'Jl. Rungkut Mejoyo Selatan III/27, Surabaya','Surabaya','031-8703456',
 'active',38000,0,1, DATE_SUB(NOW(),INTERVAL 75 DAY), DATE_SUB(NOW(),INTERVAL 105 DAY), DATE_SUB(NOW(),INTERVAL 75 DAY));
SET @O5B := LAST_INSERT_ID();

-- ══════════════════════════════════════════════════════════════════
-- TENANT 6: Quick Wash 24 Jam (SUSPENDED, 1 outlet, Bandung)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO tenants
(slug, nama_perusahaan, kota, owner_name, owner_wa, email, phone,
 status, coin_balance, coin_mode, total_outlets, max_outlets,
 package_id, package_assigned_at, registration_source, password_hash,
 trial_ends_at, provisioned_at, registered_at, verified_at)
VALUES
('quick-wash','Quick Wash 24 Jam','Bandung',
 'Deni Setiawan','6281234561111','deni.quickwash@gmail.com','6281234561111',
 'suspended',0,'shared',1,1,
 @pkgStarter, NOW(),'self_service',@PASS,
 DATE_SUB(NOW(),INTERVAL 60 DAY),
 DATE_SUB(NOW(),INTERVAL 180 DAY),
 DATE_SUB(NOW(),INTERVAL 181 DAY),
 DATE_SUB(NOW(),INTERVAL 180 DAY));
SET @T6 := LAST_INSERT_ID();

INSERT INTO outlets (tenant_id,nama_outlet,slug,alamat,kota,telepon,status,is_main,setup_done,activated_at,trial_starts_at,trial_ends_at) VALUES
(@T6,'Quick Wash - Dago','quick-wash-dago',
 'Jl. Ir. H. Djuanda No. 88, Coblong, Bandung','Bandung','022-2503456',
 'suspended',1,1, DATE_SUB(NOW(),INTERVAL 180 DAY), DATE_SUB(NOW(),INTERVAL 210 DAY), DATE_SUB(NOW(),INTERVAL 180 DAY));
SET @O6 := LAST_INSERT_ID();

-- ══════════════════════════════════════════════════════════════════
-- USERS (hl_users) — semua tenant
-- ══════════════════════════════════════════════════════════════════

-- T2 Outlet A (Malioboro) ─────────────────────────────────
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T2,@O2A,'budi.owner',  @PASS,'Budi Hartono',    'owner','Pemilik Usaha','08123456789','2023-01-01',0,1);
SET @U_BUDI := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T2,@O2A,'dewi.manager',@PASS,'Dewi Rahayu',     'manager','Manager Outlet','08234567890','2023-02-01',3500000,1);
SET @U_DEWI := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T2,@O2A,'sari.kasir2', @PASS,'Sari Wulandari',  'kasir','Kasir','08345678901','2023-03-15',2500000,1);
SET @U_SARI := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T2,@O2A,'ahmad.staff', @PASS,'Ahmad Fauzi',     'staff','Operator Cuci','08456789012','2023-04-01',2200000,1);
SET @U_AHMAD := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T2,@O2A,'rizal.kurir', @PASS,'Rizal Firmansyah','kurir','Kurir','08567890123','2023-05-01',2000000,1);
SET @U_RIZAL := LAST_INSERT_ID();

-- T2 Outlet B (Parangtritis) ──────────────────────────────
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T2,@O2B,'eko.manager2',@PASS,'Eko Prasetyo',  'manager','Manager Outlet','08678901234','2023-06-01',3200000,1);
SET @U_EKO := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T2,@O2B,'rina.kasir2', @PASS,'Rina Marlina',  'kasir','Kasir','08789012345','2023-07-01',2400000,1);
SET @U_RINA := LAST_INSERT_ID();

-- T2 Outlet C (Kaliurang) ─────────────────────────────────
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T2,@O2C,'siska.manager3',@PASS,'Siska Permatasari','manager','Manager Outlet','08890123456','2026-04-15',3000000,1);
SET @U_SISKA := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T2,@O2C,'yuli.kasir3',  @PASS,'Yuli Andriani',    'kasir',  'Kasir',         '08901234567','2026-04-15',2300000,1);
SET @U_YULI := LAST_INSERT_ID();

-- T3 Fresh Laundry ────────────────────────────────────────
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T3,@O3,'maya.owner', @PASS,'Maya Putri Ariani','owner','Pemilik Usaha','08567891234','2026-05-15',0,1);
SET @U_MAYA := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T3,@O3,'andi.kasir3',@PASS,'Andi Setiawan',    'kasir','Kasir',         '08678902345','2026-05-15',2300000,1);
SET @U_ANDI := LAST_INSERT_ID();

-- T4 Express Maju ─────────────────────────────────────────
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T4,@O4,'hendra.owner',@PASS,'Hendra Kurniawan','owner','Pemilik Usaha','08139876543',  '2026-04-25',0,1);
SET @U_HENDRA := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T4,@O4,'tono.staff',  @PASS,'Sutono',          'staff','Operator',     '08912345678',  '2026-04-25',2100000,1);
SET @U_TONO := LAST_INSERT_ID();

-- T5 Outlet A (Manyar) ────────────────────────────────────
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T5,@O5A,'sari.bunda',  @PASS,'Sari Indrawati','owner','Pemilik','08156789012','2026-01-30',0,1);
SET @U_SARIB := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T5,@O5A,'wahyu.mgr5',  @PASS,'Wahyu Hidayat','manager','Manager Outlet','08167890123','2026-02-01',3400000,1);
SET @U_WAHYU := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T5,@O5A,'intan.ksr5',  @PASS,'Intan Permata','kasir','Kasir','08178901234','2026-02-15',2600000,1);
SET @U_INTAN := LAST_INSERT_ID();

-- T5 Outlet B (Rungkut) ───────────────────────────────────
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T5,@O5B,'bowo.mgr5b', @PASS,'Bowo Santoso','manager','Manager Outlet','08189012345','2026-03-15',3200000,1);
SET @U_BOWO := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T5,@O5B,'mira.ksr5b', @PASS,'Mira Anggraini','kasir','Kasir','08190123456','2026-03-15',2500000,1);
SET @U_MIRA := LAST_INSERT_ID();

-- T6 Quick Wash (SUSPENDED — user dimatikan) ──────────────
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T6,@O6,'deni.owner',@PASS,'Deni Setiawan','owner','Pemilik','08123456111','2025-11-15',0,0);
SET @U_DENI := LAST_INSERT_ID();
INSERT INTO hl_users (tenant_id,outlet_id,username,password,nama,role,jabatan,telepon,tgl_masuk,gaji_pokok,is_active) VALUES
(@T6,@O6,'aris.kasir6',@PASS,'Aris Pratama','kasir','Kasir','08123456222','2025-11-15',2400000,0);
SET @U_ARIS := LAST_INSERT_ID();

-- ══════════════════════════════════════════════════════════════════
-- LAYANAN (hl_layanan) — per outlet
-- ══════════════════════════════════════════════════════════════════

-- T2 Outlet A: 10 layanan ────────────────────────────────
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Cuci Kiloan','cuci','kg',7000,1);
SET @L_2A_KIL := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Cuci Express (1 Hari)','cuci','kg',12000,2);
SET @L_2A_EXP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Cuci + Lipat','cuci','kg',8000,3);
SET @L_2A_LIP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Cuci + Setrika','cuci','kg',10000,4);
SET @L_2A_SET := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Setrika Saja','setrika','kg',5000,5);
SET @L_2A_STR := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Cuci Sepatu','khusus','pasang',35000,6);
SET @L_2A_SEP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Cuci Tas','khusus','item',50000,7);
SET @L_2A_TAS := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Cuci Bedcover','khusus','item',25000,8);
SET @L_2A_BED := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Cuci Karpet','khusus','kg',15000,9);
SET @L_2A_KAR := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2A,'Cuci B2B Korporat','b2b','kg',6500,10);
SET @L_2A_B2B := LAST_INSERT_ID();

-- T2 Outlet B: 7 layanan ─────────────────────────────────
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2B,'Cuci Kiloan','cuci','kg',7000,1);
SET @L_2B_KIL := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2B,'Cuci Express (1 Hari)','cuci','kg',12000,2);
SET @L_2B_EXP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2B,'Cuci + Setrika','cuci','kg',10000,3);
SET @L_2B_SET := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2B,'Cuci + Lipat','cuci','kg',8000,4);
SET @L_2B_LIP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2B,'Cuci Sepatu','khusus','pasang',35000,5);
SET @L_2B_SEP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2B,'Cuci Bedcover','khusus','item',25000,6);
SET @L_2B_BED := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2B,'Cuci B2B Korporat','b2b','kg',6500,7);
SET @L_2B_B2B := LAST_INSERT_ID();

-- T2 Outlet C: 5 layanan (baru buka) ─────────────────────
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2C,'Cuci Kiloan','cuci','kg',7500,1);
SET @L_2C_KIL := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2C,'Cuci Express (1 Hari)','cuci','kg',13000,2);
SET @L_2C_EXP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2C,'Cuci + Setrika','cuci','kg',11000,3);
SET @L_2C_SET := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2C,'Setrika Saja','setrika','kg',5500,4);
SET @L_2C_STR := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T2,@O2C,'Cuci Bedcover','khusus','item',28000,5);
SET @L_2C_BED := LAST_INSERT_ID();

-- T3 Fresh Laundry: 4 layanan ────────────────────────────
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T3,@O3,'Cuci Kiloan','cuci','kg',8000,1);
SET @L_3_KIL := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T3,@O3,'Cuci Express','cuci','kg',13000,2);
SET @L_3_EXP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T3,@O3,'Cuci + Setrika','cuci','kg',11000,3);
SET @L_3_SET := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T3,@O3,'Cuci Sepatu','khusus','pasang',38000,4);
SET @L_3_SEP := LAST_INSERT_ID();

-- T4 Express Maju: 3 layanan ─────────────────────────────
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T4,@O4,'Cuci Kiloan','cuci','kg',7500,1);
SET @L_4_KIL := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T4,@O4,'Cuci Express','cuci','kg',12500,2);
SET @L_4_EXP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T4,@O4,'Cuci + Setrika','cuci','kg',10500,3);
SET @L_4_SET := LAST_INSERT_ID();

-- T5 Outlet A (Manyar): 8 layanan ────────────────────────
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5A,'Cuci Kiloan Premium','cuci','kg',9000,1);
SET @L_5A_KIL := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5A,'Cuci Express (Same Day)','cuci','kg',15000,2);
SET @L_5A_EXP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5A,'Cuci + Setrika','cuci','kg',12000,3);
SET @L_5A_SET := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5A,'Setrika Saja','setrika','kg',6000,4);
SET @L_5A_STR := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5A,'Cuci Sepatu','khusus','pasang',45000,5);
SET @L_5A_SEP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5A,'Cuci Tas','khusus','item',60000,6);
SET @L_5A_TAS := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5A,'Cuci Bedcover','khusus','item',30000,7);
SET @L_5A_BED := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5A,'Cuci B2B Korporat','b2b','kg',7500,8);
SET @L_5A_B2B := LAST_INSERT_ID();

-- T5 Outlet B (Rungkut): 5 layanan ───────────────────────
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5B,'Cuci Kiloan Premium','cuci','kg',9000,1);
SET @L_5B_KIL := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5B,'Cuci Express (Same Day)','cuci','kg',15000,2);
SET @L_5B_EXP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5B,'Cuci + Setrika','cuci','kg',12000,3);
SET @L_5B_SET := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5B,'Cuci Bedcover','khusus','item',30000,4);
SET @L_5B_BED := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan) VALUES (@T5,@O5B,'Cuci B2B Korporat','b2b','kg',7500,5);
SET @L_5B_B2B := LAST_INSERT_ID();

-- T6 Quick Wash: 3 layanan (suspended) ───────────────────
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan,is_active) VALUES (@T6,@O6,'Cuci Kiloan','cuci','kg',7000,1,0);
SET @L_6_KIL := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan,is_active) VALUES (@T6,@O6,'Cuci Express','cuci','kg',12000,2,0);
SET @L_6_EXP := LAST_INSERT_ID();
INSERT INTO hl_layanan (tenant_id,outlet_id,nama,kategori,satuan,harga,urutan,is_active) VALUES (@T6,@O6,'Cuci + Setrika','cuci','kg',10000,3,0);
SET @L_6_SET := LAST_INSERT_ID();

-- ══════════════════════════════════════════════════════════════════
-- PELANGGAN (hl_pelanggan)
-- ══════════════════════════════════════════════════════════════════

-- T2 Outlet A (Malioboro): 11 pelanggan ──────────────────
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Siti Rahayu','08111222333','retail','langsung',350,18); SET @P_SITI := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Agus Santoso','08222333444','retail','langsung',120,9); SET @P_AGUS := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Dewi Lestari','08333444555','retail','langsung',520,26); SET @P_DEWI := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Rizki Pratama','08444555666','retail','langsung',60,4); SET @P_RIZKI := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Warung Pak Haji','08555666777','korporat','bulanan',0,8); SET @P_WARUNG := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Hotel Melati Indah','0274-123456','korporat','bulanan',0,6); SET @P_HOTEL := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Ratna Wulandari','08666777888','retail','langsung',180,11); SET @P_RATNA := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Joko Siswanto','08777888999','retail','langsung',80,6); SET @P_JOKO := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Yunita Sari','08888999000','retail','langsung',240,13); SET @P_YUNITA := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Bapak Darmanto','08999000111','retail','langsung',90,7); SET @P_DARMAN := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2A,'Kost Bu Rini','08121212121','bulanan','bulanan',0,4); SET @P_KOST := LAST_INSERT_ID();

-- T2 Outlet B (Parangtritis): 5 pelanggan ────────────────
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2B,'Sumiati','08111333555','retail','langsung',130,8); SET @P_SUMI := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2B,'PP Al-Hikmah Bantul','08222444666','korporat','bulanan',0,4); SET @P_PP := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2B,'Wirawan','08333555777','retail','langsung',60,5); SET @P_WIRA := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2B,'Sri Mulyani','08444666888','retail','langsung',200,12); SET @P_SRI := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2B,'CV Maju Bersama','08555777999','korporat','bulanan',0,3); SET @P_CVMB := LAST_INSERT_ID();

-- T2 Outlet C (Kaliurang): 4 pelanggan ───────────────────
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2C,'Asep Sulaiman','08161616161','retail','langsung',40,3); SET @P_ASEP := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2C,'Linda Wati','08171717171','retail','langsung',60,4); SET @P_LINDA := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2C,'Anita Permata','08181818181','retail','langsung',30,2); SET @P_ANITA := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T2,@O2C,'Vila Kaliurang','08191919191','korporat','bulanan',0,2); SET @P_VILA := LAST_INSERT_ID();

-- T3 Fresh Laundry: 3 pelanggan ──────────────────────────
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T3,@O3,'Budi Setiawan','08123456780','retail','langsung',0,2); SET @P_BUDIS := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T3,@O3,'Laila Kusuma','08234567891','retail','langsung',0,1); SET @P_LAILA := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T3,@O3,'Indra Wijaya','08345678902','retail','langsung',0,1); SET @P_INDRA := LAST_INSERT_ID();

-- T4 Express Maju: 4 pelanggan ───────────────────────────
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T4,@O4,'Bambang Riyadi','08198765432','retail','langsung',0,5); SET @P_BAMB := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T4,@O4,'Tini Susanti','08187654321','retail','langsung',0,3); SET @P_TINI := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T4,@O4,'Kost Pak Imam','08176543210','bulanan','bulanan',0,2); SET @P_KIMAM := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T4,@O4,'Endah Pratiwi','08165432109','retail','langsung',0,2); SET @P_ENDAH := LAST_INSERT_ID();

-- T5 Outlet A (Manyar): 7 pelanggan ──────────────────────
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5A,'Ratih Kumala','08131313131','retail','langsung',420,21); SET @P_RATIH := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5A,'Hendro Susilo','08141414141','retail','langsung',150,9); SET @P_HENDRO := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5A,'Hotel Garden Suites','031-5111222','korporat','bulanan',0,12); SET @P_GARDEN := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5A,'Reni Mulia','08151515151','retail','langsung',280,15); SET @P_RENI := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5A,'Joni Tanaka','08161617181','retail','langsung',90,6); SET @P_JONI := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5A,'Restoran Sederhana','031-5222333','korporat','bulanan',0,8); SET @P_RESTO := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5A,'Vania Putri','08171818191','retail','langsung',60,4); SET @P_VANIA := LAST_INSERT_ID();

-- T5 Outlet B (Rungkut): 5 pelanggan ─────────────────────
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5B,'Andika Putra','08181919101','retail','langsung',180,11); SET @P_ANDIK := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5B,'Maria Dewi','08191020111','retail','langsung',230,14); SET @P_MARIA := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5B,'Apartemen Puncak','031-5333444','korporat','bulanan',0,7); SET @P_APAR := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5B,'Rina Hartono','08202021121','retail','langsung',95,7); SET @P_RINAH := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order) VALUES (@T5,@O5B,'Pak Slamet','08212122131','retail','langsung',45,3); SET @P_SLAM := LAST_INSERT_ID();

-- T6 Quick Wash: 3 pelanggan (data lama, sudah suspended) ─
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order,is_active) VALUES (@T6,@O6,'Diana Putri','08223232141','retail','langsung',0,8,0); SET @P_DIANA := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order,is_active) VALUES (@T6,@O6,'Roni Saputra','08234343151','retail','langsung',0,5,0); SET @P_RONI := LAST_INSERT_ID();
INSERT INTO hl_pelanggan (tenant_id,outlet_id,nama,telepon,tipe,metode_bayar,poin_balance,total_order,is_active) VALUES (@T6,@O6,'Citra Lestari','08245454161','retail','langsung',0,4,0); SET @P_CITRA := LAST_INSERT_ID();

-- ══════════════════════════════════════════════════════════════════
-- PROMO (hl_promo)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_promo (tenant_id,outlet_id,nama,deskripsi,tipe,nilai,min_transaksi,berlaku_dari,berlaku_sampai,kuota,terpakai,is_active,created_by) VALUES
(@T2,@O2A,'Promo Lebaran 10%','Diskon 10% untuk cuci kiloan','persen',10,20000,'2026-03-01','2026-04-30',100,47,0,@U_DEWI),
(@T2,@O2A,'Promo Sabtu Hemat','Diskon Rp 5.000 min. transaksi 30.000','nominal',5000,30000,'2026-05-01','2026-06-30',200,12,1,@U_DEWI),
(@T2,@O2B,'Promo Pembukaan','Diskon 15% untuk 50 pelanggan pertama','persen',15,0,'2026-03-25','2026-04-30',50,28,0,@U_EKO),
(@T2,@O2C,'Grand Opening 20%','Diskon 20% untuk grand opening outlet baru','persen',20,0,'2026-05-01','2026-05-31',100,18,1,@U_SISKA),
(@T5,@O5A,'Member Bulan Ini 10%','Diskon 10% untuk member aktif','persen',10,50000,'2026-05-01','2026-05-31',150,42,1,@U_WAHYU),
(@T5,@O5B,'Weekend Flash 15%','Diskon 15% setiap Sabtu-Minggu','persen',15,40000,'2026-04-01','2026-06-30',100,25,1,@U_BOWO);

-- ══════════════════════════════════════════════════════════════════
-- TRANSAKSI — T2 Outlet A (Malioboro) — Maret 2026
-- Pattern: INSERT hl_transaksi → SET @T:=LAST_INSERT_ID() → INSERT items + kas
-- ══════════════════════════════════════════════════════════════════

-- Maret 1: Siti Rahayu — Cuci Kiloan 5kg lunas
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260303-001','2026-03-03',@P_SITI,'Siti Rahayu','08111222333',35000,0,35000,0,0,'cash','lunas','diambil','2026-03-05',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',5,7000,35000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-03-03','masuk','transaksi','Pembayaran HL-20260303-001 — Siti Rahayu',35000,'HL-20260303-001',@U_SARI);

-- Maret 3: Dewi Lestari — Express 3kg + Sepatu lunas
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260303-002','2026-03-03',@P_DEWI,'Dewi Lestari','08333444555',71000,0,71000,0,0,'qris','lunas','diambil','2026-03-04',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T2,@O2A,@T_M,@L_2A_EXP,'Cuci Express','kg',3,12000,36000),
(@T2,@O2A,@T_M,@L_2A_SEP,'Cuci Sepatu','pasang',1,35000,35000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-03-03','masuk','transaksi','Pembayaran HL-20260303-002 — Dewi Lestari',71000,'HL-20260303-002',@U_SARI);

-- Maret 5: Warung Pak Haji — B2B 25kg belum bayar
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260305-001','2026-03-05',@P_WARUNG,'Warung Pak Haji','08555666777',162500,0,162500,0,162500,'transfer','belum_bayar','diambil','2026-03-08',@U_DEWI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_B2B,'Cuci B2B','kg',25,6500,162500);

-- Maret 8: Ratna Wulandari — Kiloan 4kg lunas
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260308-001','2026-03-08',@P_RATNA,'Ratna Wulandari','08666777888',28000,0,28000,0,0,'cash','lunas','diambil','2026-03-10',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',4,7000,28000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-03-08','masuk','transaksi','Pembayaran HL-20260308-001 — Ratna Wulandari',28000,'HL-20260308-001',@U_SARI);

-- Maret 8: Agus Santoso — Cuci+Setrika 3kg
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260308-002','2026-03-08',@P_AGUS,'Agus Santoso','08222333444',30000,0,30000,0,0,'cash','lunas','diambil','2026-03-10',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_SET,'Cuci+Setrika','kg',3,10000,30000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-03-08','masuk','transaksi','Pembayaran HL-20260308-002 — Agus Santoso',30000,'HL-20260308-002',@U_SARI);

-- Maret 10: Hotel Melati — B2B 40kg belum bayar
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260310-001','2026-03-10',@P_HOTEL,'Hotel Melati Indah','0274-123456',260000,0,260000,0,260000,'transfer','belum_bayar','diambil','2026-03-13',@U_DEWI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_B2B,'Cuci B2B',NULL,40,6500,260000);

-- Maret 14: Yunita Sari — Kiloan 6kg + Bedcover
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260314-001','2026-03-14',@P_YUNITA,'Yunita Sari','08888999000',67000,0,67000,0,0,'qris','lunas','diambil','2026-03-16',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',6,7000,42000),
(@T2,@O2A,@T_M,@L_2A_BED,'Bedcover','item',1,25000,25000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-03-14','masuk','transaksi','Pembayaran HL-20260314-001 — Yunita Sari',67000,'HL-20260314-001',@U_SARI);

-- Maret 18: Siti Rahayu — Kiloan 5kg
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260318-001','2026-03-18',@P_SITI,'Siti Rahayu','08111222333',35000,0,35000,0,0,'cash','lunas','diambil','2026-03-20',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',5,7000,35000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-03-18','masuk','transaksi','Pembayaran HL-20260318-001 — Siti Rahayu',35000,'HL-20260318-001',@U_SARI);

-- Maret 20: Dewi Lestari — Express 4kg
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260320-001','2026-03-20',@P_DEWI,'Dewi Lestari','08333444555',48000,0,48000,0,0,'qris','lunas','diambil','2026-03-21',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_EXP,'Cuci Express','kg',4,12000,48000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-03-20','masuk','transaksi','Pembayaran HL-20260320-001 — Dewi Lestari',48000,'HL-20260320-001',@U_SARI);

-- Maret 25: Warung Pak Haji — B2B 28kg belum bayar
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260325-001','2026-03-25',@P_WARUNG,'Warung Pak Haji','08555666777',182000,0,182000,0,182000,'transfer','belum_bayar','diambil','2026-03-28',@U_DEWI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_B2B,'Cuci B2B','kg',28,6500,182000);

-- Maret 28: Kost Bu Rini — Kiloan 8kg
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260328-001','2026-03-28',@P_KOST,'Kost Bu Rini','08121212121',56000,0,56000,0,0,'transfer','lunas','diambil','2026-03-31',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',8,7000,56000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-03-28','masuk','transaksi','Pembayaran HL-20260328-001 — Kost Bu Rini',56000,'HL-20260328-001',@U_SARI);

-- T2 Outlet A — April 2026 ──────────────────────────────────
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260402-001','2026-04-02',@P_SITI,'Siti Rahayu','08111222333',35000,0,35000,0,0,'cash','lunas','diambil','2026-04-04',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',5,7000,35000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-04-02','masuk','transaksi','Pembayaran HL-20260402-001',35000,'HL-20260402-001',@U_SARI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260402-002','2026-04-02',@P_HOTEL,'Hotel Melati Indah','0274-123456',292500,0,292500,0,292500,'transfer','belum_bayar','diambil','2026-04-06',@U_DEWI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_B2B,'Cuci B2B','kg',45,6500,292500);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260408-001','2026-04-08',@P_AGUS,'Agus Santoso','08222333444',98000,0,98000,0,0,'cash','lunas','diambil','2026-04-10',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',4,7000,28000),
(@T2,@O2A,@T_M,@L_2A_SEP,'Cuci Sepatu','pasang',2,35000,70000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-04-08','masuk','transaksi','Pembayaran HL-20260408-001',98000,'HL-20260408-001',@U_SARI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260410-001','2026-04-10',@P_WARUNG,'Warung Pak Haji','08555666777',195000,0,195000,0,195000,'transfer','belum_bayar','diambil','2026-04-13',@U_DEWI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_B2B,'Cuci B2B','kg',30,6500,195000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260418-001','2026-04-18',@P_SITI,'Siti Rahayu','08111222333',85000,0,85000,0,0,'qris','lunas','diambil','2026-04-20',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',5,7000,35000),
(@T2,@O2A,@T_M,@L_2A_BED,'Bedcover','item',2,25000,50000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-04-18','masuk','transaksi','Pembayaran HL-20260418-001',85000,'HL-20260418-001',@U_SARI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260420-001','2026-04-20',@P_DEWI,'Dewi Lestari','08333444555',48000,0,48000,0,0,'qris','lunas','diambil','2026-04-21',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_EXP,'Cuci Express','kg',4,12000,48000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-04-20','masuk','transaksi','Pembayaran HL-20260420-001',48000,'HL-20260420-001',@U_SARI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260424-001','2026-04-24',@P_KOST,'Kost Bu Rini','08121212121',63000,0,63000,0,0,'transfer','lunas','diambil','2026-04-27',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',9,7000,63000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-04-24','masuk','transaksi','Pembayaran HL-20260424-001',63000,'HL-20260424-001',@U_SARI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260425-001','2026-04-25',@P_HOTEL,'Hotel Melati Indah','0274-123456',273000,0,273000,0,273000,'transfer','belum_bayar','diambil','2026-04-29',@U_DEWI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_B2B,'Cuci B2B','kg',42,6500,273000);

-- T2 Outlet A — Mei 2026 (sebagian masih aktif) ─────────────
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260503-001','2026-05-03',@P_SITI,'Siti Rahayu','08111222333',35000,0,35000,0,0,'cash','lunas','diambil','2026-05-05',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',5,7000,35000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-05-03','masuk','transaksi','Pembayaran HL-20260503-001',35000,'HL-20260503-001',@U_SARI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260505-001','2026-05-05',@P_HOTEL,'Hotel Melati Indah','0274-123456',247000,0,247000,0,247000,'transfer','belum_bayar','siap','2026-05-09',@U_DEWI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_B2B,'Cuci B2B','kg',38,6500,247000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260512-001','2026-05-12',@P_RATNA,'Ratna Wulandari','08666777888',28000,0,28000,0,0,'cash','lunas','diambil','2026-05-14',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',4,7000,28000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-05-12','masuk','transaksi','Pembayaran HL-20260512-001',28000,'HL-20260512-001',@U_SARI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260522-001','2026-05-22',@P_YUNITA,'Yunita Sari','08888999000',42000,0,42000,0,0,'qris','lunas','diambil','2026-05-24',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',6,7000,42000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-05-22','masuk','transaksi','Pembayaran HL-20260522-001',42000,'HL-20260522-001',@U_SARI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260526-001','2026-05-26',@P_JOKO,'Joko Siswanto','08777888999',35000,0,35000,0,35000,'cash','belum_bayar','cuci','2026-05-28',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',5,7000,35000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260528-001','2026-05-28',@P_DEWI,'Dewi Lestari','08333444555',121000,0,121000,0,0,'qris','lunas','masuk','2026-05-30',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T2,@O2A,@T_M,@L_2A_TAS,'Cuci Tas','item',2,50000,100000),
(@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',3,7000,21000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-05-28','masuk','transaksi','Pembayaran HL-20260528-001',121000,'HL-20260528-001',@U_SARI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2A,'HL-20260529-001','2026-05-29',@P_KOST,'Kost Bu Rini','08121212121',56000,0,56000,0,0,'transfer','lunas','masuk','2026-06-01',@U_SARI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2A,@T_M,@L_2A_KIL,'Cuci Kiloan','kg',8,7000,56000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2A,'2026-05-29','masuk','transaksi','Pembayaran HL-20260529-001',56000,'HL-20260529-001',@U_SARI);

-- ══════════════════════════════════════════════════════════════════
-- TRANSAKSI — T2 Outlet B (Parangtritis) Maret–Mei
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260315-101','2026-03-15',@P_SUMI,'Sumiati','08111333555',28000,0,28000,0,0,'cash','lunas','diambil','2026-03-17',@U_RINA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2B,@T_M,@L_2B_KIL,'Cuci Kiloan','kg',4,7000,28000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2B,'2026-03-15','masuk','transaksi','Pembayaran HL-20260315-101',28000,'HL-20260315-101',@U_RINA);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260315-102','2026-03-15',@P_PP,'PP Al-Hikmah Bantul','08222444666',227500,0,227500,0,227500,'transfer','belum_bayar','diambil','2026-03-19',@U_EKO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2B,@T_M,@L_2B_B2B,'Cuci B2B','kg',35,6500,227500);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260318-101','2026-03-18',@P_SRI,'Sri Mulyani','08444666888',70000,0,70000,0,0,'cash','lunas','diambil','2026-03-20',@U_RINA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T2,@O2B,@T_M,@L_2B_KIL,'Cuci Kiloan','kg',5,7000,35000),
(@T2,@O2B,@T_M,@L_2B_SEP,'Cuci Sepatu','pasang',1,35000,35000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2B,'2026-03-18','masuk','transaksi','Pembayaran HL-20260318-101',70000,'HL-20260318-101',@U_RINA);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260325-101','2026-03-25',@P_CVMB,'CV Maju Bersama','08555777999',130000,0,130000,0,130000,'transfer','belum_bayar','diambil','2026-03-28',@U_EKO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2B,@T_M,@L_2B_B2B,'Cuci B2B','kg',20,6500,130000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260405-101','2026-04-05',@P_SUMI,'Sumiati','08111333555',60000,0,60000,0,0,'cash','lunas','diambil','2026-04-07',@U_RINA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T2,@O2B,@T_M,@L_2B_KIL,'Cuci Kiloan','kg',5,7000,35000),
(@T2,@O2B,@T_M,@L_2B_BED,'Bedcover','item',1,25000,25000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2B,'2026-04-05','masuk','transaksi','Pembayaran HL-20260405-101',60000,'HL-20260405-101',@U_RINA);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260408-101','2026-04-08',@P_PP,'PP Al-Hikmah Bantul','08222444666',247000,0,247000,0,247000,'transfer','belum_bayar','diambil','2026-04-12',@U_EKO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2B,@T_M,@L_2B_B2B,'Cuci B2B','kg',38,6500,247000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260415-101','2026-04-15',@P_SRI,'Sri Mulyani','08444666888',42000,0,42000,0,0,'cash','lunas','diambil','2026-04-17',@U_RINA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2B,@T_M,@L_2B_KIL,'Cuci Kiloan','kg',6,7000,42000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2B,'2026-04-15','masuk','transaksi','Pembayaran HL-20260415-101',42000,'HL-20260415-101',@U_RINA);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260418-101','2026-04-18',@P_CVMB,'CV Maju Bersama','08555777999',143000,0,143000,0,143000,'transfer','belum_bayar','diambil','2026-04-22',@U_EKO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2B,@T_M,@L_2B_B2B,'Cuci B2B','kg',22,6500,143000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260508-101','2026-05-08',@P_PP,'PP Al-Hikmah Bantul','08222444666',260000,0,260000,0,260000,'transfer','belum_bayar','diambil','2026-05-12',@U_EKO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2B,@T_M,@L_2B_B2B,'Cuci B2B','kg',40,6500,260000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260515-101','2026-05-15',@P_SUMI,'Sumiati','08111333555',49000,0,49000,0,0,'qris','lunas','siap','2026-05-16',@U_RINA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T2,@O2B,@T_M,@L_2B_EXP,'Cuci Express','kg',2,12000,24000),
(@T2,@O2B,@T_M,@L_2B_BED,'Bedcover','item',1,25000,25000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2B,'2026-05-15','masuk','transaksi','Pembayaran HL-20260515-101',49000,'HL-20260515-101',@U_RINA);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2B,'HL-20260524-101','2026-05-24',@P_CVMB,'CV Maju Bersama','08555777999',117000,0,117000,0,117000,'transfer','belum_bayar','cuci','2026-05-28',@U_EKO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2B,@T_M,@L_2B_B2B,'Cuci B2B','kg',18,6500,117000);

-- ══════════════════════════════════════════════════════════════════
-- TRANSAKSI — T2 Outlet C (Kaliurang) — baru buka Mei
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2C,'HL-20260502-201','2026-05-02',@P_ASEP,'Asep Sulaiman','08161616161',30000,6000,24000,0,0,'cash','lunas','diambil','2026-05-04',@U_YULI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2C,@T_M,@L_2C_KIL,'Cuci Kiloan','kg',4,7500,30000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2C,'2026-05-02','masuk','transaksi','Pembayaran HL-20260502-201',24000,'HL-20260502-201',@U_YULI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2C,'HL-20260506-201','2026-05-06',@P_LINDA,'Linda Wati','08171717171',39000,0,39000,0,0,'cash','lunas','diambil','2026-05-08',@U_YULI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T2,@O2C,@T_M,@L_2C_KIL,'Cuci Kiloan','kg',2,7500,15000),
(@T2,@O2C,@T_M,@L_2C_BED,'Bedcover','item',1,28000,28000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2C,'2026-05-06','masuk','transaksi','Pembayaran HL-20260506-201',39000,'HL-20260506-201',@U_YULI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2C,'HL-20260510-201','2026-05-10',@P_VILA,'Vila Kaliurang','08191919191',180000,0,180000,0,180000,'transfer','belum_bayar','diambil','2026-05-13',@U_SISKA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2C,@T_M,@L_2C_KIL,'Cuci Kiloan Vila','kg',24,7500,180000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2C,'HL-20260516-201','2026-05-16',@P_ASEP,'Asep Sulaiman','08161616161',33000,6600,26400,0,0,'qris','lunas','diambil','2026-05-18',@U_YULI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2C,@T_M,@L_2C_SET,'Cuci+Setrika','kg',3,11000,33000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2C,'2026-05-16','masuk','transaksi','Pembayaran HL-20260516-201',26400,'HL-20260516-201',@U_YULI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2C,'HL-20260522-201','2026-05-22',@P_ANITA,'Anita Permata','08181818181',26000,0,26000,0,0,'cash','lunas','siap','2026-05-24',@U_YULI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2C,@T_M,@L_2C_EXP,'Cuci Express','kg',2,13000,26000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2C,'2026-05-22','masuk','transaksi','Pembayaran HL-20260522-201',26000,'HL-20260522-201',@U_YULI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T2,@O2C,'HL-20260528-201','2026-05-28',@P_LINDA,'Linda Wati','08171717171',22500,4500,18000,0,0,'cash','lunas','cuci','2026-05-30',@U_YULI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T2,@O2C,@T_M,@L_2C_KIL,'Cuci Kiloan','kg',3,7500,22500);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T2,@O2C,'2026-05-28','masuk','transaksi','Pembayaran HL-20260528-201',18000,'HL-20260528-201',@U_YULI);

-- ══════════════════════════════════════════════════════════════════
-- TRANSAKSI — T5 Outlet A (Manyar) Maret-Mei
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260305-001','2026-03-05',@P_RATIH,'Ratih Kumala','08131313131',54000,0,54000,0,0,'qris','lunas','diambil','2026-03-07',@U_INTAN);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5A,@T_M,@L_5A_KIL,'Cuci Kiloan Premium','kg',6,9000,54000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5A,'2026-03-05','masuk','transaksi','Pembayaran HL-20260305-001',54000,'HL-20260305-001',@U_INTAN);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260308-001','2026-03-08',@P_GARDEN,'Hotel Garden Suites','031-5111222',525000,0,525000,0,525000,'transfer','belum_bayar','diambil','2026-03-12',@U_WAHYU);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5A,@T_M,@L_5A_B2B,'Cuci B2B','kg',70,7500,525000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260315-001','2026-03-15',@P_RENI,'Reni Mulia','08151515151',105000,0,105000,0,0,'cash','lunas','diambil','2026-03-17',@U_INTAN);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T5,@O5A,@T_M,@L_5A_KIL,'Cuci Kiloan Premium','kg',5,9000,45000),
(@T5,@O5A,@T_M,@L_5A_SEP,'Cuci Sepatu','pasang',1,45000,45000),
(@T5,@O5A,@T_M,@L_5A_BED,'Bedcover','item',1,30000,30000);
-- 45+45+30 = 120000 — koreksi subtotal di insert
UPDATE hl_transaksi SET subtotal=120000,total=120000 WHERE id=@T_M;
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5A,'2026-03-15','masuk','transaksi','Pembayaran HL-20260315-001',120000,'HL-20260315-001',@U_INTAN);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260322-001','2026-03-22',@P_HENDRO,'Hendro Susilo','08141414141',36000,0,36000,0,0,'cash','lunas','diambil','2026-03-24',@U_INTAN);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5A,@T_M,@L_5A_KIL,'Cuci Kiloan Premium','kg',4,9000,36000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5A,'2026-03-22','masuk','transaksi','Pembayaran HL-20260322-001',36000,'HL-20260322-001',@U_INTAN);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260328-001','2026-03-28',@P_RESTO,'Restoran Sederhana','031-5222333',300000,0,300000,0,0,'transfer','lunas','diambil','2026-03-31',@U_WAHYU);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5A,@T_M,@L_5A_B2B,'Cuci B2B','kg',40,7500,300000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5A,'2026-03-28','masuk','transaksi','Pembayaran HL-20260328-001',300000,'HL-20260328-001',@U_WAHYU);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260404-001','2026-04-04',@P_RATIH,'Ratih Kumala','08131313131',75000,0,75000,0,0,'qris','lunas','diambil','2026-04-06',@U_INTAN);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T5,@O5A,@T_M,@L_5A_EXP,'Cuci Express','kg',5,15000,75000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5A,'2026-04-04','masuk','transaksi','Pembayaran HL-20260404-001',75000,'HL-20260404-001',@U_INTAN);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260410-001','2026-04-10',@P_GARDEN,'Hotel Garden Suites','031-5111222',562500,0,562500,0,562500,'transfer','belum_bayar','diambil','2026-04-14',@U_WAHYU);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5A,@T_M,@L_5A_B2B,'Cuci B2B','kg',75,7500,562500);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260418-001','2026-04-18',@P_RENI,'Reni Mulia','08151515151',96000,9600,86400,0,0,'qris','lunas','diambil','2026-04-20',@U_INTAN);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5A,@T_M,@L_5A_SET,'Cuci+Setrika','kg',8,12000,96000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5A,'2026-04-18','masuk','transaksi','Pembayaran HL-20260418-001',86400,'HL-20260418-001',@U_INTAN);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260505-001','2026-05-05',@P_RATIH,'Ratih Kumala','08131313131',54000,0,54000,0,0,'qris','lunas','diambil','2026-05-07',@U_INTAN);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5A,@T_M,@L_5A_KIL,'Cuci Kiloan Premium','kg',6,9000,54000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5A,'2026-05-05','masuk','transaksi','Pembayaran HL-20260505-001',54000,'HL-20260505-001',@U_INTAN);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260512-001','2026-05-12',@P_GARDEN,'Hotel Garden Suites','031-5111222',600000,0,600000,0,600000,'transfer','belum_bayar','diambil','2026-05-16',@U_WAHYU);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5A,@T_M,@L_5A_B2B,'Cuci B2B','kg',80,7500,600000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260520-001','2026-05-20',@P_VANIA,'Vania Putri','08171818191',60000,0,60000,0,0,'cash','lunas','siap','2026-05-22',@U_INTAN);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES
(@T5,@O5A,@T_M,@L_5A_TAS,'Cuci Tas','item',1,60000,60000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5A,'2026-05-20','masuk','transaksi','Pembayaran HL-20260520-001',60000,'HL-20260520-001',@U_INTAN);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5A,'HL-20260527-001','2026-05-27',@P_HENDRO,'Hendro Susilo','08141414141',45000,0,45000,0,0,'cash','lunas','cuci','2026-05-29',@U_INTAN);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5A,@T_M,@L_5A_KIL,'Cuci Kiloan Premium','kg',5,9000,45000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5A,'2026-05-27','masuk','transaksi','Pembayaran HL-20260527-001',45000,'HL-20260527-001',@U_INTAN);

-- ══════════════════════════════════════════════════════════════════
-- TRANSAKSI — T5 Outlet B (Rungkut) Maret-Mei
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5B,'HL-20260320-101','2026-03-20',@P_ANDIK,'Andika Putra','08181919101',45000,0,45000,0,0,'cash','lunas','diambil','2026-03-22',@U_MIRA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5B,@T_M,@L_5B_KIL,'Cuci Kiloan Premium','kg',5,9000,45000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5B,'2026-03-20','masuk','transaksi','Pembayaran HL-20260320-101',45000,'HL-20260320-101',@U_MIRA);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5B,'HL-20260325-101','2026-03-25',@P_APAR,'Apartemen Puncak','031-5333444',525000,0,525000,0,525000,'transfer','belum_bayar','diambil','2026-03-29',@U_BOWO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5B,@T_M,@L_5B_B2B,'Cuci B2B','kg',70,7500,525000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5B,'HL-20260410-101','2026-04-10',@P_MARIA,'Maria Dewi','08191020111',72000,0,72000,0,0,'qris','lunas','diambil','2026-04-12',@U_MIRA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5B,@T_M,@L_5B_SET,'Cuci+Setrika','kg',6,12000,72000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5B,'2026-04-10','masuk','transaksi','Pembayaran HL-20260410-101',72000,'HL-20260410-101',@U_MIRA);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5B,'HL-20260418-101','2026-04-18',@P_APAR,'Apartemen Puncak','031-5333444',600000,0,600000,0,600000,'transfer','belum_bayar','diambil','2026-04-22',@U_BOWO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5B,@T_M,@L_5B_B2B,'Cuci B2B','kg',80,7500,600000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5B,'HL-20260425-101','2026-04-25',@P_RINAH,'Rina Hartono','08202021121',54000,0,54000,0,0,'cash','lunas','diambil','2026-04-27',@U_MIRA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5B,@T_M,@L_5B_KIL,'Cuci Kiloan Premium','kg',6,9000,54000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5B,'2026-04-25','masuk','transaksi','Pembayaran HL-20260425-101',54000,'HL-20260425-101',@U_MIRA);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5B,'HL-20260510-101','2026-05-10',@P_MARIA,'Maria Dewi','08191020111',60000,9000,51000,0,0,'qris','lunas','diambil','2026-05-12',@U_MIRA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5B,@T_M,@L_5B_EXP,'Cuci Express','kg',4,15000,60000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5B,'2026-05-10','masuk','transaksi','Pembayaran HL-20260510-101',51000,'HL-20260510-101',@U_MIRA);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5B,'HL-20260518-101','2026-05-18',@P_APAR,'Apartemen Puncak','031-5333444',675000,0,675000,0,675000,'transfer','belum_bayar','diambil','2026-05-22',@U_BOWO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5B,@T_M,@L_5B_B2B,'Cuci B2B','kg',90,7500,675000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T5,@O5B,'HL-20260524-101','2026-05-24',@P_ANDIK,'Andika Putra','08181919101',45000,0,45000,0,0,'cash','lunas','siap','2026-05-26',@U_MIRA);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T5,@O5B,@T_M,@L_5B_KIL,'Cuci Kiloan Premium','kg',5,9000,45000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T5,@O5B,'2026-05-24','masuk','transaksi','Pembayaran HL-20260524-101',45000,'HL-20260524-101',@U_MIRA);

-- ══════════════════════════════════════════════════════════════════
-- TRANSAKSI — T4 Express Maju (sudah grace, transaksi menurun)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T4,@O4,'HL-20260428-001','2026-04-28',@P_BAMB,'Bambang Riyadi','08198765432',37500,0,37500,0,0,'cash','lunas','diambil','2026-04-30',@U_TONO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T4,@O4,@T_M,@L_4_KIL,'Cuci Kiloan','kg',5,7500,37500);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T4,@O4,'2026-04-28','masuk','transaksi','Pembayaran HL-20260428-001',37500,'HL-20260428-001',@U_TONO);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T4,@O4,'HL-20260502-001','2026-05-02',@P_KIMAM,'Kost Pak Imam','08176543210',60000,0,60000,0,60000,'transfer','belum_bayar','diambil','2026-05-05',@U_TONO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T4,@O4,@T_M,@L_4_KIL,'Cuci Kiloan','kg',8,7500,60000);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T4,@O4,'HL-20260512-001','2026-05-12',@P_TINI,'Tini Susanti','08187654321',31500,0,31500,0,0,'cash','lunas','diambil','2026-05-14',@U_TONO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T4,@O4,@T_M,@L_4_SET,'Cuci+Setrika','kg',3,10500,31500);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T4,@O4,'2026-05-12','masuk','transaksi','Pembayaran HL-20260512-001',31500,'HL-20260512-001',@U_TONO);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T4,@O4,'HL-20260520-001','2026-05-20',@P_BAMB,'Bambang Riyadi','08198765432',37500,0,37500,0,0,'cash','lunas','siap','2026-05-22',@U_TONO);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T4,@O4,@T_M,@L_4_KIL,'Cuci Kiloan','kg',5,7500,37500);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T4,@O4,'2026-05-20','masuk','transaksi','Pembayaran HL-20260520-001',37500,'HL-20260520-001',@U_TONO);

-- ══════════════════════════════════════════════════════════════════
-- TRANSAKSI — T3 Fresh Laundry (trial, baru jalan 15 hari)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T3,@O3,'HL-20260520-001','2026-05-20',@P_BUDIS,'Budi Setiawan','08123456780',32000,0,32000,0,0,'cash','lunas','diambil','2026-05-22',@U_ANDI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T3,@O3,@T_M,@L_3_KIL,'Cuci Kiloan','kg',4,8000,32000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T3,@O3,'2026-05-20','masuk','transaksi','Pembayaran HL-20260520-001',32000,'HL-20260520-001',@U_ANDI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T3,@O3,'HL-20260525-001','2026-05-25',@P_LAILA,'Laila Kusuma','08234567891',38000,0,38000,0,0,'qris','lunas','siap','2026-05-27',@U_ANDI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T3,@O3,@T_M,@L_3_SEP,'Cuci Sepatu','pasang',1,38000,38000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T3,@O3,'2026-05-25','masuk','transaksi','Pembayaran HL-20260525-001',38000,'HL-20260525-001',@U_ANDI);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T3,@O3,'HL-20260528-001','2026-05-28',@P_INDRA,'Indra Wijaya','08345678902',33000,0,33000,0,0,'cash','lunas','cuci','2026-05-30',@U_ANDI);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T3,@O3,@T_M,@L_3_SET,'Cuci+Setrika','kg',3,11000,33000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T3,@O3,'2026-05-28','masuk','transaksi','Pembayaran HL-20260528-001',33000,'HL-20260528-001',@U_ANDI);

-- ══════════════════════════════════════════════════════════════════
-- TRANSAKSI — T6 Quick Wash (SUSPENDED, data historis Q4 2025)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T6,@O6,'HL-20251205-001','2025-12-05',@P_DIANA,'Diana Putri','08223232141',28000,0,28000,0,0,'cash','lunas','diambil','2025-12-07',@U_ARIS);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T6,@O6,@T_M,@L_6_KIL,'Cuci Kiloan','kg',4,7000,28000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T6,@O6,'2025-12-05','masuk','transaksi','Pembayaran HL-20251205-001',28000,'HL-20251205-001',@U_ARIS);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T6,@O6,'HL-20251215-001','2025-12-15',@P_RONI,'Roni Saputra','08234343151',35000,0,35000,0,0,'cash','lunas','diambil','2025-12-17',@U_ARIS);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T6,@O6,@T_M,@L_6_KIL,'Cuci Kiloan','kg',5,7000,35000);
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by) VALUES (@T6,@O6,'2025-12-15','masuk','transaksi','Pembayaran HL-20251215-001',35000,'HL-20251215-001',@U_ARIS);

INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,pelanggan_id,nama_pelanggan,telepon,subtotal,diskon,total,dp,sisa_bayar,metode_bayar,status_bayar,status_proses,estimasi_selesai,created_by) VALUES
(@T6,@O6,'HL-20260120-001','2026-01-20',@P_CITRA,'Citra Lestari','08245454161',40000,0,40000,0,40000,'cash','belum_bayar','diambil','2026-01-22',@U_ARIS);
SET @T_M := LAST_INSERT_ID();
INSERT INTO hl_transaksi_item (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal) VALUES (@T6,@O6,@T_M,@L_6_SET,'Cuci+Setrika','kg',4,10000,40000);

-- ══════════════════════════════════════════════════════════════════
-- KAS KELUAR (biaya operasional)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_kas (tenant_id,outlet_id,tanggal,tipe,kategori,keterangan,jumlah,created_by) VALUES
-- T2 Outlet A
(@T2,@O2A,'2026-03-01','keluar','gaji','Gaji karyawan Februari 2026',9700000,@U_DEWI),
(@T2,@O2A,'2026-03-02','keluar','operasional','Sabun & deterjen cuci',450000,@U_DEWI),
(@T2,@O2A,'2026-03-10','keluar','operasional','Listrik bulan Maret',380000,@U_DEWI),
(@T2,@O2A,'2026-03-15','keluar','operasional','Plastik & kantong laundry',85000,@U_SARI),
(@T2,@O2A,'2026-03-20','keluar','operasional','Servis mesin cuci #2',250000,@U_DEWI),
(@T2,@O2A,'2026-03-31','keluar','operasional','Air PDAM bulan Maret',175000,@U_DEWI),
(@T2,@O2A,'2026-04-01','keluar','gaji','Gaji karyawan Maret 2026',9700000,@U_DEWI),
(@T2,@O2A,'2026-04-05','keluar','operasional','Deterjen & pewangi bulk',520000,@U_DEWI),
(@T2,@O2A,'2026-04-10','keluar','operasional','Listrik bulan April',395000,@U_DEWI),
(@T2,@O2A,'2026-04-22','keluar','operasional','Plastik laundry & hanger',110000,@U_SARI),
(@T2,@O2A,'2026-04-30','keluar','operasional','Air PDAM bulan April',180000,@U_DEWI),
(@T2,@O2A,'2026-05-01','keluar','gaji','Gaji karyawan April 2026',9700000,@U_DEWI),
(@T2,@O2A,'2026-05-08','keluar','operasional','Deterjen & softener',480000,@U_DEWI),
(@T2,@O2A,'2026-05-15','keluar','operasional','Listrik bulan Mei (est.)',400000,@U_DEWI),
(@T2,@O2A,'2026-05-28','keluar','operasional','Bensin kurir + parkir',300000,@U_DEWI),
-- T2 Outlet B
(@T2,@O2B,'2026-03-25','keluar','gaji','Gaji karyawan (prorate) Maret',4760000,@U_EKO),
(@T2,@O2B,'2026-03-28','keluar','operasional','Setup awal',680000,@U_EKO),
(@T2,@O2B,'2026-04-01','keluar','gaji','Gaji karyawan April 2026',5600000,@U_EKO),
(@T2,@O2B,'2026-04-12','keluar','operasional','Deterjen & sabun cuci',320000,@U_EKO),
(@T2,@O2B,'2026-04-20','keluar','operasional','Listrik & air bulan April',310000,@U_EKO),
(@T2,@O2B,'2026-05-01','keluar','gaji','Gaji karyawan Mei 2026',5600000,@U_EKO),
(@T2,@O2B,'2026-05-18','keluar','operasional','Deterjen, pewangi & kantong',400000,@U_EKO),
-- T2 Outlet C (baru buka Mei)
(@T2,@O2C,'2026-05-01','keluar','operasional','Setup deterjen & perlengkapan awal',1200000,@U_SISKA),
(@T2,@O2C,'2026-05-10','keluar','operasional','Listrik & air Mei',280000,@U_SISKA),
(@T2,@O2C,'2026-05-25','keluar','operasional','Plastik & kantong laundry',95000,@U_YULI),
-- T5 Outlet A
(@T5,@O5A,'2026-03-01','keluar','gaji','Gaji Februari',6000000,@U_WAHYU),
(@T5,@O5A,'2026-03-10','keluar','operasional','Deterjen premium Surabaya',650000,@U_WAHYU),
(@T5,@O5A,'2026-03-25','keluar','operasional','Servis mesin cuci',300000,@U_WAHYU),
(@T5,@O5A,'2026-04-01','keluar','gaji','Gaji Maret',6000000,@U_WAHYU),
(@T5,@O5A,'2026-04-15','keluar','operasional','Listrik & PDAM April',520000,@U_WAHYU),
(@T5,@O5A,'2026-05-01','keluar','gaji','Gaji April',6000000,@U_WAHYU),
(@T5,@O5A,'2026-05-15','keluar','operasional','Deterjen & pewangi Mei',680000,@U_WAHYU),
-- T5 Outlet B
(@T5,@O5B,'2026-03-25','keluar','gaji','Gaji Maret (prorate)',2840000,@U_BOWO),
(@T5,@O5B,'2026-04-01','keluar','gaji','Gaji April',5700000,@U_BOWO),
(@T5,@O5B,'2026-04-15','keluar','operasional','Deterjen & listrik',420000,@U_BOWO),
(@T5,@O5B,'2026-05-01','keluar','gaji','Gaji Mei',5700000,@U_BOWO),
(@T5,@O5B,'2026-05-20','keluar','operasional','Deterjen & operasional',380000,@U_BOWO),
-- T4 Express Maju (kas tipis menjelang grace)
(@T4,@O4,'2026-05-01','keluar','gaji','Gaji April (terlambat)',2100000,@U_HENDRA),
(@T4,@O4,'2026-05-15','keluar','operasional','Deterjen seadanya',150000,@U_HENDRA);

-- ══════════════════════════════════════════════════════════════════
-- GAJI (hl_gaji)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_gaji (tenant_id,outlet_id,user_id,bulan,gaji_pokok,bonus,potongan,total,status,catatan,dibayar_at,created_by) VALUES
-- T2 — April dibayar
(@T2,@O2A,@U_DEWI, '2026-04',3500000,500000,0,4000000,'dibayar','Dibayar tunai','2026-04-01 09:00:00',@U_BUDI),
(@T2,@O2A,@U_SARI, '2026-04',2500000,250000,0,2750000,'dibayar','Dibayar tunai','2026-04-01 09:00:00',@U_BUDI),
(@T2,@O2A,@U_AHMAD,'2026-04',2200000,200000,0,2400000,'dibayar','Dibayar tunai','2026-04-01 09:00:00',@U_BUDI),
(@T2,@O2A,@U_RIZAL,'2026-04',2000000,150000,0,2150000,'dibayar','Dibayar tunai','2026-04-01 09:00:00',@U_BUDI),
(@T2,@O2B,@U_EKO,  '2026-04',3200000,400000,0,3600000,'dibayar','Dibayar transfer','2026-04-01 09:00:00',@U_BUDI),
(@T2,@O2B,@U_RINA, '2026-04',2400000,200000,0,2600000,'dibayar','Dibayar transfer','2026-04-01 09:00:00',@U_BUDI),
-- T2 — Mei pending
(@T2,@O2A,@U_DEWI, '2026-05',3500000,0,0,3500000,'pending','Menunggu approval owner',NULL,@U_BUDI),
(@T2,@O2A,@U_SARI, '2026-05',2500000,0,0,2500000,'pending','Menunggu approval owner',NULL,@U_BUDI),
(@T2,@O2A,@U_AHMAD,'2026-05',2200000,0,0,2200000,'pending','Menunggu approval owner',NULL,@U_BUDI),
(@T2,@O2A,@U_RIZAL,'2026-05',2000000,0,0,2000000,'pending','Menunggu approval owner',NULL,@U_BUDI),
(@T2,@O2B,@U_EKO,  '2026-05',3200000,0,0,3200000,'pending','Menunggu approval owner',NULL,@U_BUDI),
(@T2,@O2B,@U_RINA, '2026-05',2400000,0,0,2400000,'pending','Menunggu approval owner',NULL,@U_BUDI),
(@T2,@O2C,@U_SISKA,'2026-05',3000000,0,0,3000000,'pending','Outlet baru — bulan pertama',NULL,@U_BUDI),
(@T2,@O2C,@U_YULI, '2026-05',2300000,0,0,2300000,'pending','Outlet baru — bulan pertama',NULL,@U_BUDI),
-- T5 — April dibayar, Mei pending
(@T5,@O5A,@U_WAHYU,'2026-04',3400000,300000,0,3700000,'dibayar','Dibayar bank transfer','2026-04-01 10:00:00',@U_SARIB),
(@T5,@O5A,@U_INTAN,'2026-04',2600000,200000,0,2800000,'dibayar','Dibayar bank transfer','2026-04-01 10:00:00',@U_SARIB),
(@T5,@O5B,@U_BOWO, '2026-04',3200000,250000,0,3450000,'dibayar','Dibayar bank transfer','2026-04-01 10:00:00',@U_SARIB),
(@T5,@O5B,@U_MIRA, '2026-04',2500000,150000,0,2650000,'dibayar','Dibayar bank transfer','2026-04-01 10:00:00',@U_SARIB),
(@T5,@O5A,@U_WAHYU,'2026-05',3400000,0,0,3400000,'pending','Menunggu approval',NULL,@U_SARIB),
(@T5,@O5A,@U_INTAN,'2026-05',2600000,0,0,2600000,'pending','Menunggu approval',NULL,@U_SARIB),
(@T5,@O5B,@U_BOWO, '2026-05',3200000,0,0,3200000,'pending','Menunggu approval',NULL,@U_SARIB),
(@T5,@O5B,@U_MIRA, '2026-05',2500000,0,0,2500000,'pending','Menunggu approval',NULL,@U_SARIB),
-- T4 — April terlambat, Mei belum dibayar
(@T4,@O4,@U_TONO,'2026-04',2100000,0,0,2100000,'dibayar','Dibayar terlambat (telat 1 minggu)','2026-05-01 14:00:00',@U_HENDRA),
(@T4,@O4,@U_TONO,'2026-05',2100000,0,0,2100000,'pending','Kas usaha terbatas — owner sedang cari pinjaman',NULL,@U_HENDRA);

-- ══════════════════════════════════════════════════════════════════
-- ABSENSI (hl_absensi) — 14 hari terakhir, hadir semua kecuali contoh izin
-- ══════════════════════════════════════════════════════════════════
-- Generate per-user: loop manual untuk hari kerja terakhir
-- Senin–Jumat, 14 hari ke belakang (dari hari ini)
INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T2,@O2A,@U_DEWI, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:00','17:00','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T2,@O2A,@U_SARI, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:00','16:00','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T2,@O2A,@U_AHMAD, DATE_SUB(CURDATE(),INTERVAL n DAY),
  IF(n=7,NULL,'07:30'), IF(n=7,NULL,'15:30'), IF(n=7,'izin','hadir') FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T2,@O2A,@U_RIZAL, DATE_SUB(CURDATE(),INTERVAL n DAY),'09:00','18:00','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T2,@O2B,@U_EKO, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:00','17:00','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T2,@O2B,@U_RINA, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:30','16:30','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T2,@O2C,@U_SISKA, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:00','17:00','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T2,@O2C,@U_YULI, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:00','16:00','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T5,@O5A,@U_WAHYU, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:00','17:00','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T5,@O5A,@U_INTAN, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:30','16:30','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T5,@O5B,@U_BOWO, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:00','17:00','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

INSERT INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,jam_masuk,jam_keluar,status)
SELECT @T5,@O5B,@U_MIRA, DATE_SUB(CURDATE(),INTERVAL n DAY),'08:30','16:30','hadir' FROM
(SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14) AS days
WHERE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n DAY)) NOT IN (1,7);

-- ══════════════════════════════════════════════════════════════════
-- LOYALTY LOG (hl_loyalty_log)
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_loyalty_log (tenant_id,outlet_id,pelanggan_id,type,poin,balance_after,keterangan,created_at) VALUES
-- Siti Rahayu — total 350
(@T2,@O2A,@P_SITI,'earn',50,50,'Earn order Maret','2026-03-03 10:00:00'),
(@T2,@O2A,@P_SITI,'earn',50,100,'Earn order Maret','2026-03-18 10:00:00'),
(@T2,@O2A,@P_SITI,'earn',50,150,'Earn order April','2026-04-02 10:00:00'),
(@T2,@O2A,@P_SITI,'earn',50,200,'Earn order April','2026-04-18 10:00:00'),
(@T2,@O2A,@P_SITI,'earn',50,250,'Earn order Mei','2026-05-03 10:00:00'),
(@T2,@O2A,@P_SITI,'redeem',150,100,'Tukar poin diskon','2026-05-07 14:00:00'),
(@T2,@O2A,@P_SITI,'earn',50,150,'Earn order Mei','2026-05-24 10:00:00'),
(@T2,@O2A,@P_SITI,'earn',200,350,'Earn order Mei (tas+kiloan)','2026-05-28 10:00:00'),
-- Dewi Lestari — total 520
(@T2,@O2A,@P_DEWI,'earn',85,85,'Earn order Maret','2026-03-03 11:00:00'),
(@T2,@O2A,@P_DEWI,'earn',50,135,'Earn order Maret','2026-03-20 11:00:00'),
(@T2,@O2A,@P_DEWI,'earn',50,185,'Earn order April','2026-04-05 11:00:00'),
(@T2,@O2A,@P_DEWI,'earn',85,270,'Earn order April','2026-04-20 11:00:00'),
(@T2,@O2A,@P_DEWI,'earn',50,320,'Earn order April','2026-04-28 11:00:00'),
(@T2,@O2A,@P_DEWI,'earn',50,370,'Earn order Mei','2026-05-07 11:00:00'),
(@T2,@O2A,@P_DEWI,'redeem',100,270,'Tukar poin Mei','2026-05-10 14:00:00'),
(@T2,@O2A,@P_DEWI,'earn',150,420,'Earn order Mei','2026-05-28 11:00:00'),
(@T2,@O2A,@P_DEWI,'earn',100,520,'Bonus pelanggan setia','2026-05-29 09:00:00'),
-- Yunita Sari — total 240
(@T2,@O2A,@P_YUNITA,'earn',60,60,'Earn order Maret','2026-03-14 10:00:00'),
(@T2,@O2A,@P_YUNITA,'earn',50,110,'Earn order April','2026-04-07 10:00:00'),
(@T2,@O2A,@P_YUNITA,'earn',50,160,'Earn order April','2026-04-28 10:00:00'),
(@T2,@O2A,@P_YUNITA,'earn',50,210,'Earn order Mei','2026-05-22 10:00:00'),
(@T2,@O2A,@P_YUNITA,'redeem',50,160,'Tukar poin','2026-05-25 14:00:00'),
(@T2,@O2A,@P_YUNITA,'earn',80,240,'Earn order Mei','2026-05-28 10:00:00'),
-- T5 Ratih Kumala — total 420
(@T5,@O5A,@P_RATIH,'earn',75,75,'Earn order Maret','2026-03-05 10:00:00'),
(@T5,@O5A,@P_RATIH,'earn',60,135,'Earn order Maret','2026-03-15 10:00:00'),
(@T5,@O5A,@P_RATIH,'earn',75,210,'Earn order April','2026-04-04 10:00:00'),
(@T5,@O5A,@P_RATIH,'earn',60,270,'Earn order April','2026-04-18 10:00:00'),
(@T5,@O5A,@P_RATIH,'earn',75,345,'Earn order Mei','2026-05-05 10:00:00'),
(@T5,@O5A,@P_RATIH,'earn',75,420,'Bonus loyalty 6 bulan','2026-05-28 10:00:00'),
-- T5 Maria Dewi — total 230
(@T5,@O5B,@P_MARIA,'earn',75,75,'Earn order April','2026-04-10 10:00:00'),
(@T5,@O5B,@P_MARIA,'earn',60,135,'Earn order April','2026-04-25 10:00:00'),
(@T5,@O5B,@P_MARIA,'earn',75,210,'Earn order Mei','2026-05-10 10:00:00'),
(@T5,@O5B,@P_MARIA,'earn',20,230,'Bonus referral','2026-05-15 10:00:00');

-- ══════════════════════════════════════════════════════════════════
-- DROP POINTS & KOMISI REKAP
-- ══════════════════════════════════════════════════════════════════
INSERT INTO hl_drop_points (tenant_id,outlet_id,nama_mitra,alamat,wa,komisi_model,komisi_per_kg,periode_rekap,status) VALUES
(@T2,@O2A,'Toko Kelontong Pak Rahmat','Jl. Gejayan No. 15, Sleman','08111000999','per_kg',500,'bulanan','aktif');
SET @DP1 := LAST_INSERT_ID();

INSERT INTO hl_drop_points (tenant_id,outlet_id,nama_mitra,alamat,wa,komisi_model,komisi_persen,periode_rekap,status) VALUES
(@T2,@O2A,'Barbershop Rapi','Jl. Colombo No. 7, Caturtunggal','08222111000','persen',5.00,'bulanan','aktif');
SET @DP2 := LAST_INSERT_ID();

INSERT INTO hl_drop_points (tenant_id,outlet_id,nama_mitra,alamat,wa,komisi_model,komisi_per_kg,periode_rekap,status) VALUES
(@T5,@O5A,'Mini Market Sumber Rejeki','Jl. Manyar Jaya, Surabaya','08111888777','per_kg',600,'bulanan','aktif');
SET @DP3 := LAST_INSERT_ID();

INSERT INTO hl_komisi_rekap (tenant_id,outlet_id,drop_point_id,periode_start,periode_end,total_order,total_kg,total_omset,total_komisi,status,dibayar_at) VALUES
(@T2,@O2A,@DP1,'2026-04-01','2026-04-30',12,58.5,409500,29250,'dibayar','2026-05-02 10:00:00'),
(@T2,@O2A,@DP1,'2026-05-01','2026-05-30',8,42.0,294000,21000,'pending',NULL),
(@T5,@O5A,@DP3,'2026-04-01','2026-04-30',10,55.0,495000,33000,'dibayar','2026-05-01 11:00:00');

-- ══════════════════════════════════════════════════════════════════
-- KEUANGAN: Aset Tetap, Kas Bank, Liabilitas
-- ══════════════════════════════════════════════════════════════════
-- DILEWATI: hl_aset_tetap / hl_kas_bank / hl_liabilitas butuh coa_id
-- yang di-seed otomatis via FinancialCalculator::seedCoa() pas tenant
-- baru dibuat (PHP). Untuk dummy SQL, owner bisa input manual via UI
-- Keuangan (hq/keuangan.php → tab Aset Tetap / Pinjaman / Kas Bank).
-- Laporan SAK EMKM tetap jalan dari data kas + transaksi yang sudah ada.

-- ══════════════════════════════════════════════════════════════════
-- COIN LEDGER
-- ══════════════════════════════════════════════════════════════════
INSERT INTO coin_ledger (tenant_id,outlet_id,type,amount,feature_used,description,balance_after,created_at) VALUES
-- T2 history
(@T2,@O2A,'topup',60000,NULL,'Top-up paket Growth (setup)',60000,'2023-01-01 10:00:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif order selesai',59900,'2026-03-05 09:30:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif order selesai',59800,'2026-03-10 09:30:00'),
(@T2,@O2A,'deduct',500,'ai_briefing','AI briefing Maret',59300,'2026-03-15 09:30:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif order selesai',59200,'2026-03-20 09:30:00'),
(@T2,@O2A,'deduct',100,'daily_report','Laporan harian',59100,'2026-03-31 23:50:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif order selesai',59000,'2026-04-05 09:30:00'),
(@T2,@O2A,'deduct',200,'ai_analyst','Analisis April',58800,'2026-04-10 09:30:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif order selesai',58700,'2026-04-15 09:30:00'),
(@T2,@O2A,'deduct',500,'ai_briefing','AI briefing April',58200,'2026-04-20 09:30:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif order selesai',58100,'2026-04-25 09:30:00'),
(@T2,@O2A,'deduct',100,'daily_report','Laporan harian',58000,'2026-04-30 23:50:00'),
(@T2,@O2A,'topup',60000,NULL,'Beli Popular Pack 250k',118000,'2026-05-01 11:00:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif',117900,'2026-05-03 09:30:00'),
(@T2,@O2A,'deduct',30,'ai_churn_message','Pesan retensi pelanggan diam',117870,'2026-05-05 09:30:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif',117770,'2026-05-10 09:30:00'),
(@T2,@O2A,'deduct',200,'ai_analyst','Analisis bulanan',117570,'2026-05-15 09:30:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif',117470,'2026-05-20 09:30:00'),
(@T2,@O2A,'deduct',500,'ai_briefing','AI briefing Mei',116970,'2026-05-25 09:30:00'),
(@T2,@O2A,'deduct',100,'send_wa_notif','WA notif',116870,'2026-05-28 09:30:00'),
-- T3 trial
(@T3,@O3,'topup',30000,NULL,'Trial coin awal',30000,'2026-05-15 10:00:00'),
(@T3,@O3,'deduct',500,'ai_briefing','AI briefing pertama',29500,'2026-05-16 09:30:00'),
(@T3,@O3,'deduct',100,'send_wa_notif','WA notif',29400,'2026-05-20 09:30:00'),
(@T3,@O3,'deduct',1000,'ai_migration_mapping','Mapping migrasi data',28400,'2026-05-22 09:30:00'),
(@T3,@O3,'deduct',100,'send_wa_notif','WA notif',28300,'2026-05-25 09:30:00'),
(@T3,@O3,'deduct',100,'send_wa_notif','WA notif',28200,'2026-05-28 09:30:00'),
-- T4 grace (coin tipis)
(@T4,@O4,'topup',30000,NULL,'Trial coin awal',30000,'2026-04-25 10:00:00'),
(@T4,@O4,'deduct',100,'send_wa_notif','WA notif',29900,'2026-04-28 09:30:00'),
(@T4,@O4,'deduct',100,'send_wa_notif','WA notif',29800,'2026-05-02 09:30:00'),
(@T4,@O4,'deduct',500,'ai_briefing','AI briefing',29300,'2026-05-05 09:30:00'),
(@T4,@O4,'deduct',100,'send_wa_notif','WA notif',29200,'2026-05-12 09:30:00'),
(@T4,@O4,'deduct',100,'send_wa_notif','WA notif',29100,'2026-05-20 09:30:00'),
-- T5 high usage tenant
(@T5,@O5A,'topup',60000,NULL,'Setup paket Growth',60000,'2026-01-30 10:00:00'),
(@T5,@O5A,'topup',60000,NULL,'Beli Popular Pack',120000,'2026-03-01 10:00:00'),
(@T5,@O5A,'deduct',100,'send_wa_notif','WA',119900,'2026-03-05 09:30:00'),
(@T5,@O5A,'deduct',500,'ai_briefing','Briefing',119400,'2026-03-15 09:30:00'),
(@T5,@O5A,'deduct',200,'ai_analyst','Analyst',119200,'2026-03-20 09:30:00'),
(@T5,@O5A,'deduct',100,'send_wa_notif','WA',119100,'2026-04-04 09:30:00'),
(@T5,@O5A,'deduct',80,'ai_briefing_hq','Briefing HQ',119020,'2026-04-10 09:30:00'),
(@T5,@O5A,'deduct',500,'export_pdf','Export laporan',118520,'2026-04-15 09:30:00'),
(@T5,@O5A,'deduct',100,'wa_blast','Blast promo April',118420,'2026-04-20 09:30:00'),
(@T5,@O5A,'topup',60000,NULL,'Beli Popular Pack',178420,'2026-05-01 10:00:00'),
(@T5,@O5A,'deduct',500,'ai_briefing','Briefing Mei',177920,'2026-05-05 09:30:00'),
(@T5,@O5A,'deduct',500,'export_pdf','Export laporan keuangan',177420,'2026-05-10 09:30:00'),
(@T5,@O5A,'deduct',200,'invoice_b2b','Invoice Hotel Garden',177220,'2026-05-15 09:30:00'),
(@T5,@O5A,'deduct',100,'wa_blast','Blast promo Mei',177120,'2026-05-25 09:30:00');

-- ══════════════════════════════════════════════════════════════════
-- PAYMENTS & SAAS MANUAL PAYMENTS
-- ══════════════════════════════════════════════════════════════════
INSERT INTO payments (tenant_id,outlet_id,type,amount,coin_amount,gateway_ref,notes,status,paid_at) VALUES
(@T2,@O2A,'setup_fee',500000,60000,'TRF-BCA-20230101','Setup paket Growth + coin awal','success','2023-01-01 09:00:00'),
(@T2,@O2A,'coin_topup',250000,60000,NULL,'Beli Popular Pack Mei 2026','success','2026-05-01 11:00:00'),
(@T3,@O3,'setup_fee',300000,30000,'QRIS-20260515','Setup Starter (self-service)','success','2026-05-15 10:00:00'),
(@T4,@O4,'setup_fee',300000,30000,'TRF-BCA-20260425','Setup Starter','success','2026-04-25 10:00:00'),
(@T5,@O5A,'setup_fee',500000,60000,'TRF-MDR-20260130','Setup paket Growth','success','2026-01-30 09:00:00'),
(@T5,@O5A,'coin_topup',250000,60000,'TRF-MDR-20260301','Popular Pack Maret','success','2026-03-01 10:00:00'),
(@T5,@O5A,'coin_topup',250000,60000,'TRF-MDR-20260501','Popular Pack Mei','success','2026-05-01 10:00:00'),
(@T6,@O6,'setup_fee',300000,30000,'TRF-BCA-20251115','Setup Starter','success','2025-11-15 09:00:00');

INSERT INTO saas_manual_payments (tenant_id,superadmin_id,type,nominal_dibayar,coin_dikreditkan,metode,nama_pengirim,ref_transfer,tanggal_bayar,catatan,status,notif_wa_sent,notif_wa_sent_at) VALUES
(@T2,@SAID,'setup_fee',500000,60000,'transfer_bca','Budi Hartono','BCA-REF-20230101-7891234','2023-01-01','Setup awal — 3 outlet Yogyakarta','confirmed',1,'2023-01-01 10:30:00'),
(@T2,@SAID,'coin_topup',250000,60000,'transfer_bca','Budi Hartono','BCA-REF-20260501-3456789','2026-05-01','Topup Popular Pack — AI campaign','confirmed',1,'2026-05-01 11:15:00'),
(@T3,@SAID,'setup_fee',300000,30000,'qris','Maya Putri','QRIS-20260515-1122334','2026-05-15','Setup Starter — self service','confirmed',0,NULL),
(@T4,@SAID,'setup_fee',300000,30000,'transfer_bca','Hendra K','BCA-REF-20260425-2233445','2026-04-25','Setup Starter — assisted','confirmed',1,'2026-04-25 11:00:00'),
(@T5,@SAID,'setup_fee',500000,60000,'transfer_mandiri','Sari Indrawati','MDR-REF-20260130-5544332','2026-01-30','Setup awal Growth — 2 outlet Surabaya','confirmed',1,'2026-01-30 11:00:00'),
(@T5,@SAID,'coin_topup',250000,60000,'transfer_mandiri','Sari Indrawati','MDR-REF-20260301-6655443','2026-03-01','Topup Popular Maret','confirmed',1,'2026-03-01 10:30:00'),
(@T5,@SAID,'coin_topup',250000,60000,'transfer_mandiri','Sari Indrawati','MDR-REF-20260501-7766554','2026-05-01','Topup Popular Mei','confirmed',1,'2026-05-01 10:30:00'),
(@T6,@SAID,'setup_fee',300000,30000,'transfer_bca','Deni Setiawan','BCA-REF-20251115-8877665','2025-11-15','Setup Starter','confirmed',1,'2025-11-15 10:00:00');

-- ══════════════════════════════════════════════════════════════════
-- SUPERADMIN ACTIVITY: Tickets, Notes, Logs
-- ══════════════════════════════════════════════════════════════════
INSERT INTO support_tickets (tenant_id,superadmin_id,channel,subject,type,message,created_at) VALUES
(@T2,@SAID,'wa','Cara ekspor laporan keuangan ke PDF','onboarding','Gimana cara export PDF laporan keuangan? Tombolnya nggak muncul.','2026-03-20 14:00:00'),
(@T2,@SAID,'email','Coin cepat habis — minta rincian','billing','Mohon kirimkan rincian pemakaian coin bulan April. Terima kasih.','2026-04-25 10:00:00'),
(@T2,@SAID,'wa','Permintaan tambah outlet ke-3','support','Mau buka outlet ke-3 di Kaliurang, paket Growth masih cukup kan?','2026-04-10 09:00:00'),
(@T3,@SAID,'wa','Mesin tidak bisa connect','support','Baru daftar tapi login error terus. Password sudah benar.','2026-05-16 16:00:00'),
(@T3,@SAID,'email','Cara import pelanggan dari Excel','onboarding','Saya punya 50 pelanggan lama dari Excel, gimana cara importnya?','2026-05-25 11:00:00'),
(@T4,@SAID,'call','Perpanjangan layanan setelah grace','churn_risk','Owner Express Maju minta keringanan 7 hari karena baru dapat pelanggan korporat.','2026-05-25 09:00:00'),
(@T5,@SAID,'wa','Migration dari aplikasi lama','onboarding','Punya data dari aplikasi lama sekitar 500 transaksi. Bisa di-import nggak?','2026-02-01 10:00:00'),
(@T5,@SAID,'email','Fitur invoice B2B Hotel','support','Hotel Garden minta invoice resmi PPN, bisa generate nggak?','2026-04-15 14:00:00'),
(@T6,@SAID,'wa','PERHATIAN: tidak ada respon 30 hari','churn_risk','Owner Quick Wash tidak respon sejak Januari. Akan disuspend.','2026-02-15 09:00:00');

INSERT INTO tenant_notes (tenant_id,superadmin_id,note,is_pinned,created_at) VALUES
(@T2,@SAID,'Owner aktif & responsif. 3 outlet, omzet bagus. Kandidat upgrade ke Pro.',1,'2026-03-15 10:00:00'),
(@T2,@SAID,'Brief 20 Mei: minta fitur notif WA untuk reminder ambil laundry.',0,'2026-05-20 14:00:00'),
(@T2,@SAID,'Outlet ke-3 (Kaliurang) baru buka 1 Mei — masih kosong transaksi awal.',0,'2026-05-05 09:00:00'),
(@T3,@SAID,'Trial baru 15 hari. Owner perempuan muda, antusias. Perlu onboarding lebih lanjut.',0,'2026-05-16 10:00:00'),
(@T3,@SAID,'Sudah dibantu setup migrasi dari Excel — 47 pelanggan berhasil di-import.',0,'2026-05-26 15:00:00'),
(@T4,@SAID,'PERHATIAN: grace period. Sudah dihubungi 2x via WA, belum ada konfirmasi.',1,'2026-05-26 09:00:00'),
(@T4,@SAID,'Kondisi bisnis menurun — owner sedang cari modal tambahan dari KUR.',0,'2026-05-20 11:00:00'),
(@T5,@SAID,'TOP TENANT — Bunda Wangi, omzet >20jt/bulan. Pakai semua fitur AI.',1,'2026-04-01 10:00:00'),
(@T5,@SAID,'Owner perempuan profesional, latar belakang akuntan. Sangat detail.',0,'2026-03-15 14:00:00'),
(@T6,@SAID,'SUSPENDED sejak Februari — tidak respon, kemungkinan tutup usaha.',1,'2026-02-20 09:00:00');

INSERT INTO superadmin_logs (superadmin_id,action,target_tenant_id,description,ip_address,created_at) VALUES
(@SAID,'provision_tenant',@T2,'Provisioning: CV Bersih Kilat Mandiri (Growth)','127.0.0.1','2023-01-01 09:30:00'),
(@SAID,'topup_coin',@T2,'Topup setup 60.000 coin','127.0.0.1','2023-01-01 10:30:00'),
(@SAID,'topup_coin',@T2,'Topup Popular Pack 60.000 coin','127.0.0.1','2026-05-01 11:15:00'),
(@SAID,'provision_tenant',@T3,'Provisioning: Fresh Laundry (self-service)','127.0.0.1','2026-05-15 10:00:00'),
(@SAID,'provision_tenant',@T4,'Provisioning: Express Maju (assisted)','127.0.0.1','2026-04-25 11:00:00'),
(@SAID,'view_tenant',@T4,'Cek status grace — kontak owner','127.0.0.1','2026-05-26 09:00:00'),
(@SAID,'provision_tenant',@T5,'Provisioning: Bunda Wangi (Growth)','127.0.0.1','2026-01-30 11:00:00'),
(@SAID,'topup_coin',@T5,'Topup Popular Pack Mei','127.0.0.1','2026-05-01 10:30:00'),
(@SAID,'provision_tenant',@T6,'Provisioning: Quick Wash (Starter)','127.0.0.1','2025-11-15 10:00:00'),
(@SAID,'suspend_tenant',@T6,'Suspend karena tidak respon 60+ hari','127.0.0.1','2026-02-20 14:00:00');

-- ══════════════════════════════════════════════════════════════════
-- PLATFORM HEALTH — 29 hari terakhir
-- ══════════════════════════════════════════════════════════════════
INSERT INTO saas_platform_health
(tanggal,total_tenant_aktif,total_tenant_trial,total_tenant_grace,
 tenant_login_hari_ini,total_transaksi,total_wa_terkirim,total_ai_calls,
 total_ai_cost_coin,total_coin_terjual,total_coin_dipakai,
 total_revenue_hari,total_error_php,total_wa_gagal,total_ai_error)
SELECT DATE_SUB(CURDATE(),INTERVAL n DAY),
  IF(n>15,2,IF(n>5,3,2)),                       -- aktif
  IF(n>15,1,IF(n>5,2,1)),                       -- trial
  IF(n>5,0,1),                                  -- grace
  4 + (RAND()*4),                               -- login
  6 + (RAND()*10),                              -- transaksi
  3 + (RAND()*8),                               -- wa
  FLOOR(RAND()*4),                              -- ai_calls
  FLOOR(RAND()*4)*300 + FLOOR(RAND()*8)*100,    -- ai_cost
  IF(n=15,60000,IF(n=1,60000,0)),               -- coin_terjual
  FLOOR(RAND()*4)*300 + FLOOR(RAND()*8)*100,    -- coin_dipakai
  IF(n=15,500000,IF(n=1,250000,0)),             -- revenue
  FLOOR(RAND()*3),                              -- error_php
  FLOOR(RAND()*2),                              -- wa_gagal
  FLOOR(RAND()*2)                               -- ai_error
FROM (
  SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 
  UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION SELECT 20
  UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24 UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
) AS d;

-- ══════════════════════════════════════════════════════════════════
-- SUMMARY
-- ══════════════════════════════════════════════════════════════════
-- Verifikasi data — jalankan query ini setelah import:
SELECT 'Tenants' AS jenis, COUNT(*) AS jumlah FROM tenants WHERE id IN (@T2,@T3,@T4,@T5,@T6)
UNION SELECT 'Outlets',  COUNT(*) FROM outlets   WHERE id IN (@O2A,@O2B,@O2C,@O3,@O4,@O5A,@O5B,@O6)
UNION SELECT 'Users',    COUNT(*) FROM hl_users  WHERE tenant_id IN (@T2,@T3,@T4,@T5,@T6)
UNION SELECT 'Layanan',  COUNT(*) FROM hl_layanan WHERE tenant_id IN (@T2,@T3,@T4,@T5,@T6)
UNION SELECT 'Pelanggan',COUNT(*) FROM hl_pelanggan WHERE tenant_id IN (@T2,@T3,@T4,@T5,@T6)
UNION SELECT 'Transaksi',COUNT(*) FROM hl_transaksi WHERE tenant_id IN (@T2,@T3,@T4,@T5,@T6)
UNION SELECT 'Kas entries',COUNT(*) FROM hl_kas WHERE tenant_id IN (@T2,@T3,@T4,@T5,@T6);

-- ══════════════════════════════════════════════════════════════════
-- LOGIN DEMO (password semua: Demo1234!)
-- ══════════════════════════════════════════════════════════════════
-- HQ Login (via /login.php pakai email):
--   T2 (active):    budi.hartono@gmail.com
--   T3 (trial):     maya@freshlaundry.id
--   T4 (grace):     hendra.laundry@gmail.com
--   T5 (active):    sari.bunda@bundawangilaundry.com
--   T6 (suspended): deni.quickwash@gmail.com
--
-- Outlet Login (via /login.php pakai username):
--   T2 Malioboro    : budi.owner / dewi.manager / sari.kasir2 / ahmad.staff / rizal.kurir
--   T2 Parangtritis : eko.manager2 / rina.kasir2
--   T2 Kaliurang    : siska.manager3 / yuli.kasir3
--   T3 Semarang     : maya.owner / andi.kasir3
--   T4 Solo         : hendra.owner / tono.staff
--   T5 Manyar       : sari.bunda / wahyu.mgr5 / intan.ksr5
--   T5 Rungkut      : bowo.mgr5b / mira.ksr5b
