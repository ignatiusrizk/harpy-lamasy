-- ══════════════════════════════════════════════════════
-- self_registration_migration.sql — LAMASY 2-Entity Architecture
-- Fase 1: Tenant (Account) + Outlet sebagai 2 entitas terpisah
-- Tenant = akun perusahaan, gratis selamanya
-- Outlet = unit operasional, punya lifecycle: trial → grace → suspended → purged
--
-- Aman dijalankan ulang (IF NOT EXISTS / IF EXISTS di semua statement)
-- Jalankan SETELAH outlet_migration.sql
-- ══════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- ════════════════════════════════════════
-- BAGIAN 1 — Update tenants table
-- Tenant = akun company, free forever, identified by email
-- ════════════════════════════════════════

-- Tambah kolom baru ke tenants
ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS email               VARCHAR(150)  UNIQUE NULL,
  ADD COLUMN IF NOT EXISTS phone               VARCHAR(20)   NULL,
  ADD COLUMN IF NOT EXISTS registration_source ENUM('self_service','assisted') DEFAULT 'assisted',
  ADD COLUMN IF NOT EXISTS registered_at       DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS verified_at         DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS password_hash       VARCHAR(255)  NULL,
  ADD COLUMN IF NOT EXISTS nik                 VARCHAR(20)   NULL;

-- Update status ENUM tenants: tambah pending_verification & closed
-- MariaDB: MODIFY COLUMN untuk ganti ENUM definition
ALTER TABLE tenants
  MODIFY COLUMN status ENUM('pending_verification','trial','active','suspended','closed') DEFAULT 'pending_verification';

-- ════════════════════════════════════════
-- BAGIAN 2 — Update outlets table
-- Outlet = unit operasional dengan lifecycle lengkap
-- ════════════════════════════════════════

-- Tambah kolom lifecycle ke outlets
ALTER TABLE outlets
  ADD COLUMN IF NOT EXISTS trial_starts_at    DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS trial_ends_at      DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS grace_ends_at      DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS purge_at           DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS activated_at       DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS trial_coin_balance INT           DEFAULT 0,
  ADD COLUMN IF NOT EXISTS coin_balance       INT           DEFAULT 0;

-- Update status ENUM outlets: trial → grace → active → suspended → closed
ALTER TABLE outlets
  MODIFY COLUMN status ENUM('trial','grace','active','suspended','closed') DEFAULT 'trial';

-- ════════════════════════════════════════
-- BAGIAN 3 — email_verifications table
-- Token-based email verification, 24h expiry, max 3 resends
-- ════════════════════════════════════════

CREATE TABLE IF NOT EXISTS email_verifications (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT          NOT NULL,
  email        VARCHAR(150) NOT NULL,
  token        VARCHAR(64)  NOT NULL UNIQUE,
  type         ENUM('registration','password_reset','email_change') DEFAULT 'registration',
  expires_at   DATETIME     NOT NULL,
  used_at      DATETIME     NULL,
  resend_count TINYINT      DEFAULT 0,
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_token     (token),
  INDEX idx_tenant    (tenant_id),
  INDEX idx_email     (email),
  INDEX idx_expires   (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ════════════════════════════════════════
-- BAGIAN 4 — registration_attempts table
-- Rate limiting: max 3 registrations/day per IP
-- ════════════════════════════════════════

CREATE TABLE IF NOT EXISTS registration_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ip_address   VARCHAR(45)  NOT NULL,
  email        VARCHAR(150) NULL,
  owner_wa     VARCHAR(20)  NULL,
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip      (ip_address),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ════════════════════════════════════════
-- BAGIAN 5 — Update hl_users
-- User tenant punya email sendiri dan verifikasi
-- ════════════════════════════════════════

ALTER TABLE hl_users
  ADD COLUMN IF NOT EXISTS email          VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS email_verified TINYINT(1)   DEFAULT 0,
  ADD COLUMN IF NOT EXISTS outlet_id      INT          NOT NULL DEFAULT 1;

-- Index untuk lookup email
ALTER TABLE hl_users ADD INDEX IF NOT EXISTS idx_email (email);

-- ════════════════════════════════════════
-- BAGIAN 6 — coin_ledger: tambah ref_id jika belum ada
-- (CoinLedger.php sudah pakai ref_id tapi migration lama mungkin belum)
-- ════════════════════════════════════════

ALTER TABLE coin_ledger
  ADD COLUMN IF NOT EXISTS ref_id VARCHAR(100) NULL;

-- ════════════════════════════════════════
-- BAGIAN 7 — Hapus data lama Harpy Johar (tenant_id=1)
-- AMAN: Harpy Johar pakai DB berbeda (bukan master).
-- Di master DB ini, tenant_id=1 hanya data dummy dari migration lama.
-- ════════════════════════════════════════

-- Hapus data operasional dummy
DELETE FROM hl_transaksi_item WHERE tenant_id = 1;
DELETE FROM hl_transaksi      WHERE tenant_id = 1;
DELETE FROM hl_pelanggan      WHERE tenant_id = 1;
DELETE FROM hl_layanan        WHERE tenant_id = 1;
DELETE FROM hl_kas            WHERE tenant_id = 1;
DELETE FROM hl_audit_log      WHERE tenant_id = 1;
DELETE FROM hl_absensi        WHERE tenant_id = 1;
DELETE FROM hl_izin           WHERE tenant_id = 1;
DELETE FROM hl_gaji           WHERE tenant_id = 1;
DELETE FROM hl_promo          WHERE tenant_id = 1;
DELETE FROM hl_voucher        WHERE tenant_id = 1;

-- Hapus users lama (kecuali super_admins yang punya table terpisah)
DELETE FROM hl_users WHERE tenant_id = 1;

-- Hapus outlet dummy
DELETE FROM outlets WHERE id = 1;

-- Hapus tenant dummy (tenant_id=1 dari migration lama)
DELETE FROM tenants WHERE id = 1;

-- Reset auto_increment agar mulai dari 1 lagi (fresh start)
ALTER TABLE tenants  AUTO_INCREMENT = 1;
ALTER TABLE outlets  AUTO_INCREMENT = 1;
ALTER TABLE hl_users AUTO_INCREMENT = 1;

-- ════════════════════════════════════════
-- BAGIAN 8 — registration_requests: update kolom
-- Sinkronkan dengan model baru
-- ════════════════════════════════════════

ALTER TABLE registration_requests
  ADD COLUMN IF NOT EXISTS email     VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS nik       VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS captcha_passed TINYINT DEFAULT 0;

-- Update status ENUM agar match flow baru
ALTER TABLE registration_requests
  MODIFY COLUMN status ENUM(
    'pending',
    'email_sent',
    'email_verified',
    'payment_pending',
    'provisioning',
    'completed',
    'failed',
    'cancelled'
  ) DEFAULT 'pending';

SET FOREIGN_KEY_CHECKS = 1;

-- ════════════════════════════════════════
-- SELESAI
-- Cek: tenants harus kosong, outlets harus kosong
-- Test: /ERP/harpy/register.php → flow self-registration
-- ════════════════════════════════════════
