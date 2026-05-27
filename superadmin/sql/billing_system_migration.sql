-- ══════════════════════════════════════════════════════
-- billing_system_migration.sql
-- Billing & Payment System — LaMaSy SuperAdmin
--
-- Jalankan SETELAH semua migration sebelumnya sudah dijalankan:
--   outlet_migration.sql
--   self_registration_migration.sql
--   tenant_status_cleanup.sql
--
-- Aman dijalankan ulang — semua pakai IF NOT EXISTS / INSERT IGNORE
-- ══════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- ════════════════════════════════════════════════════════
-- BAGIAN 1: saas_packages
-- Paket berlangganan LaMaSy — bisa di-CRUD via packages.php
-- ════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS saas_packages (
  id          INT          AUTO_INCREMENT PRIMARY KEY,
  nama        VARCHAR(100) NOT NULL,                    -- 'Starter', 'Pro', 'Enterprise'
  slug        VARCHAR(50)  UNIQUE NOT NULL,             -- 'starter', 'pro', 'enterprise'
  deskripsi   TEXT         NULL,

  -- Harga & coin
  setup_fee   INT          NOT NULL DEFAULT 0,          -- setup fee satu kali (Rp)
  coin_awal   INT          NOT NULL DEFAULT 50000,      -- coin yang dikreditkan saat aktivasi

  -- Trial
  trial_hari  INT          NOT NULL DEFAULT 30,         -- durasi trial outlet (hari)

  -- Batas outlet
  max_outlets INT          NOT NULL DEFAULT 1,          -- 0 = unlimited

  -- Flag
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  is_custom   TINYINT(1)   NOT NULL DEFAULT 0,          -- 1 = enterprise / custom pricing

  -- Tampilan
  urutan      INT          NOT NULL DEFAULT 0,          -- urutan tampil di UI (ASC)

  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_active (is_active, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════
-- BAGIAN 2: saas_coin_bundles
-- Paket topup coin — tampil ke tenant saat request topup
-- ════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS saas_coin_bundles (
  id           INT           AUTO_INCREMENT PRIMARY KEY,
  nama         VARCHAR(100)  NOT NULL,                  -- 'Paket Hemat', 'Paket Standar', ...
  harga        INT           NOT NULL,                  -- harga dalam Rupiah
  coin_didapat INT           NOT NULL,                  -- coin yang diterima tenant
  bonus_pct    DECIMAL(5,2)  NOT NULL DEFAULT 0.00,     -- % bonus coin (0 = tidak ada)
  is_active    TINYINT(1)    NOT NULL DEFAULT 1,
  is_featured  TINYINT(1)    NOT NULL DEFAULT 0,        -- tampilkan lebih prominent di UI
  urutan       INT           NOT NULL DEFAULT 0,

  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_active (is_active, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════
-- BAGIAN 3: saas_manual_payments
-- Record setiap pembayaran manual yang dikonfirmasi superadmin.
-- Terpisah dari tabel `payments` lama (yang disiapkan untuk gateway).
-- ════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS saas_manual_payments (
  id               INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id        INT          NOT NULL,
  superadmin_id    INT          NOT NULL,               -- siapa yang input/konfirmasi

  -- Tipe & referensi paket/bundle
  type             ENUM('setup_fee','coin_topup','adjustment','custom')
                                NOT NULL,
  package_id       INT          NULL,                   -- FK ke saas_packages (jika setup_fee)
  bundle_id        INT          NULL,                   -- FK ke saas_coin_bundles (jika coin_topup)

  -- Nominal
  nominal_dibayar  INT          NOT NULL DEFAULT 0,     -- Rp yang masuk ke Harpy
  coin_dikreditkan INT          NOT NULL DEFAULT 0,     -- coin yang ditambahkan ke tenant

  -- Detail pembayaran
  metode           ENUM(
                     'transfer_bca',
                     'transfer_mandiri',
                     'transfer_bri',
                     'transfer_bni',
                     'qris',
                     'cash',
                     'lainnya'
                   )            NOT NULL DEFAULT 'transfer_bca',
  nama_pengirim    VARCHAR(100) NULL,
  ref_transfer     VARCHAR(100) NULL,                   -- no. referensi / kode unik
  tanggal_bayar    DATE         NOT NULL,
  bukti_url        VARCHAR(255) NULL,                   -- path upload bukti transfer

  -- Catatan internal (tidak dikirim ke tenant)
  catatan          TEXT         NULL,

  -- Alasan adjustment (khusus type='adjustment')
  adjustment_reason ENUM(
                      'kompensasi_downtime',
                      'bonus_referral',
                      'koreksi_error',
                      'promo',
                      'lainnya'
                    )           NULL,

  -- Status & notifikasi
  status           ENUM('pending','confirmed','cancelled')
                                NOT NULL DEFAULT 'confirmed',
  notif_wa_sent    TINYINT(1)   NOT NULL DEFAULT 0,
  notif_wa_sent_at DATETIME     NULL,

  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_tenant  (tenant_id),
  INDEX idx_sa      (superadmin_id),
  INDEX idx_date    (tanggal_bayar),
  INDEX idx_type    (type),
  INDEX idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════
-- BAGIAN 4: ALTER tenants
-- Tambah kolom paket & batas outlet
-- ════════════════════════════════════════════════════════

ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS package_id          INT      NULL
    COMMENT 'FK ke saas_packages',
  ADD COLUMN IF NOT EXISTS package_assigned_at DATETIME NULL
    COMMENT 'Kapan paket terakhir di-assign',
  ADD COLUMN IF NOT EXISTS max_outlets         INT      NOT NULL DEFAULT 1
    COMMENT 'Batas jumlah outlet aktif (0=unlimited), di-sync dari paket saat aktivasi';

-- ════════════════════════════════════════════════════════
-- BAGIAN 5: SEED — Paket default
-- 3 paket: Starter, Pro, Enterprise
-- Harga bisa diupdate via packages.php setelah dijalankan
-- ════════════════════════════════════════════════════════

INSERT IGNORE INTO saas_packages
  (id, nama, slug, deskripsi, setup_fee, coin_awal, trial_hari, max_outlets, is_custom, urutan)
VALUES
  (1, 'Starter',
      'starter',
      'Cocok untuk laundry 1 outlet. Sudah include semua fitur inti: POS, absensi, laporan, notifikasi.',
      300000, 50000, 30, 1, 0, 1),

  (2, 'Pro',
      'pro',
      'Untuk bisnis dengan beberapa outlet. Semua fitur Starter + multi-outlet management & HQ dashboard.',
      500000, 100000, 30, 5, 0, 2),

  (3, 'Enterprise',
      'enterprise',
      'Solusi custom untuk jaringan laundry besar. Harga, coin awal, dan batas outlet sesuai kesepakatan.',
      0, 200000, 30, 0, 1, 3);

-- ════════════════════════════════════════════════════════
-- BAGIAN 6: SEED — Coin bundle default
-- 4 bundle: Hemat → Besar
-- Harga & coin bisa diupdate via packages.php
-- ════════════════════════════════════════════════════════

INSERT IGNORE INTO saas_coin_bundles
  (id, nama, harga, coin_didapat, bonus_pct, is_featured, urutan)
VALUES
  (1, 'Paket Hemat',    50000,   50000,  0.00, 0, 1),
  (2, 'Paket Standar', 100000, 110000, 10.00, 1, 2),
  (3, 'Paket Value',   250000, 280000, 12.00, 0, 3),
  (4, 'Paket Besar',   500000, 600000, 20.00, 0, 4);

-- ════════════════════════════════════════════════════════
-- SELESAI
-- Verifikasi:
--   SELECT * FROM saas_packages;
--   SELECT * FROM saas_coin_bundles;
--   DESCRIBE saas_manual_payments;
--   SHOW COLUMNS FROM tenants LIKE 'package%';
--   SHOW COLUMNS FROM tenants LIKE 'max_outlets';
-- ════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 1;
