-- ══════════════════════════════════════════════════════
-- add_outlet_pending_status.sql
-- Tambah status 'pending' ke outlets untuk flow payment verification
-- 'pending' = outlet dibuat tapi pembayaran belum dikonfirmasi superadmin
--
-- AMAN dijalankan ulang — menggunakan syntax yang kompatibel dengan
-- MySQL 5.7, MySQL 8.x, MariaDB 10.x
-- Jalankan di: phpMyAdmin → database master → SQL
-- ══════════════════════════════════════════════════════

-- STEP 1: Tambah 'pending' ke ENUM status outlets
-- Urutan: pending → trial / active → grace → suspended → closed
ALTER TABLE outlets
  MODIFY COLUMN status
    ENUM('pending','trial','grace','active','suspended','closed')
    NOT NULL DEFAULT 'trial';

-- STEP 2: Tambah kolom request_type ke registration_requests
-- Syntax tanpa IF NOT EXISTS (kompatibel semua versi MySQL/MariaDB)
-- Abaikan error "Duplicate column name" kalau kolom sudah ada
ALTER TABLE registration_requests
  ADD COLUMN request_type
    ENUM('new_tenant','add_outlet') NOT NULL DEFAULT 'new_tenant';

-- STEP 3: Index untuk outlet_id (defensive — skip jika sudah ada)
-- Jalankan terpisah jika STEP 2 gagal karena kolom sudah ada
ALTER TABLE registration_requests
  ADD INDEX idx_rr_outlet_id (outlet_id);

-- ══════════════════════════════════════════════════════
-- VERIFIKASI setelah migration:
-- 1. SHOW COLUMNS FROM outlets WHERE Field='status';
--    Expected: enum('pending','trial','grace','active','suspended','closed')
-- 2. SHOW COLUMNS FROM registration_requests WHERE Field='request_type';
--    Expected: enum('new_tenant','add_outlet')  NOT NULL  DEFAULT: new_tenant
-- ══════════════════════════════════════════════════════
