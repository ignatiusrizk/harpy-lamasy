-- ══════════════════════════════════════════════════════
-- add_outlet_pending_status.sql
-- Tambah status 'pending' ke outlets untuk flow payment verification
-- 'pending' = outlet dibuat tapi pembayaran belum dikonfirmasi superadmin
--
-- Aman dijalankan ulang (MODIFY COLUMN idempoten jika nilai sama)
-- Jalankan di: phpMyAdmin → database master → SQL
-- ══════════════════════════════════════════════════════

-- Tambah 'pending' ke ENUM status outlets
-- Urutan: pending (baru dibuat, belum bayar) → trial / active → grace → suspended → closed
ALTER TABLE outlets
  MODIFY COLUMN status
    ENUM('pending','trial','grace','active','suspended','closed')
    NOT NULL DEFAULT 'trial';

-- Tambah request_type ke registration_requests untuk membedakan
-- flow "new tenant" vs "tambah outlet existing tenant"
ALTER TABLE registration_requests
  ADD COLUMN IF NOT EXISTS request_type
    ENUM('new_tenant','add_outlet') NOT NULL DEFAULT 'new_tenant';

-- Index untuk mempercepat lookup outlet payments
ALTER TABLE registration_requests
  ADD INDEX IF NOT EXISTS idx_outlet_id (outlet_id);

-- ══════════════════════════════════════════════════════
-- SELESAI
-- Verifikasi: SHOW COLUMNS FROM outlets WHERE Field='status';
-- Expected: enum('pending','trial','grace','active','suspended','closed')
-- ══════════════════════════════════════════════════════
