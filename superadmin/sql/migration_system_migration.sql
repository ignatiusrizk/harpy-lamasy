-- ══════════════════════════════════════════════════════════════
-- MIGRATION SYSTEM — LAMASY Data Import
-- Jalankan via phpMyAdmin satu kali.
-- Semua pakai IF NOT EXISTS / ADD COLUMN IF NOT EXISTS
-- sehingga aman dijalankan ulang.
-- ══════════════════════════════════════════════════════════════

-- ── 1. MIGRATION JOBS ─────────────────────────────────────────
-- Track setiap proses import (self-service & assisted)
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_migration_jobs (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id            INT NOT NULL,
  outlet_id            INT NOT NULL,

  -- Siapa yang import
  imported_by_user     INT  NULL,                -- user_id tenant (self-service)
  imported_by_admin    INT  NULL,                -- superadmin_id (assisted)
  is_assisted          TINYINT NOT NULL DEFAULT 0, -- 0=self, 1=assisted

  -- Entitas yang diimport
  entity_type          ENUM(
    'layanan',
    'pelanggan',
    'karyawan',
    'transaksi',
    'poin_pelanggan'
  ) NOT NULL,

  -- File
  file_name            VARCHAR(255) NOT NULL,
  file_path            VARCHAR(500) NOT NULL,
  file_size            INT          NULL,
  file_type            VARCHAR(10)  NULL,        -- csv, xlsx, xls

  -- AI Mapping
  raw_headers          JSON         NULL,        -- header asli dari file
  ai_mapping           JSON         NULL,        -- hasil mapping AI
  mapping_confirmed    TINYINT      NOT NULL DEFAULT 0,
  mapping_confirmed_at DATETIME     NULL,

  -- Status
  status               ENUM(
    'uploaded',     -- file sudah diupload, belum di-map
    'ai_mapping',   -- sedang memanggil Claude API
    'mapped',       -- mapping selesai, menunggu konfirmasi
    'importing',    -- proses import sedang berjalan
    'completed',    -- semua baris berhasil
    'failed',       -- gagal total (0 baris berhasil)
    'partial'       -- sebagian berhasil
  ) NOT NULL DEFAULT 'uploaded',

  -- Hasil
  total_rows           INT     NOT NULL DEFAULT 0,
  success_rows         INT     NOT NULL DEFAULT 0,
  failed_rows          INT     NOT NULL DEFAULT 0,
  skipped_rows         INT     NOT NULL DEFAULT 0,
  error_log            JSON    NULL,             -- [{baris,data,error}]

  -- Billing assisted migration
  assisted_fee         INT     NOT NULL DEFAULT 0, -- Rp 200.000
  assisted_paid        TINYINT NOT NULL DEFAULT 0,

  -- Timestamps
  started_at           DATETIME    NULL,
  completed_at         DATETIME    NULL,
  created_at           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_mj_tenant  (tenant_id),
  INDEX idx_mj_outlet  (outlet_id),
  INDEX idx_mj_status  (status),
  INDEX idx_mj_entity  (entity_type),
  INDEX idx_mj_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 2. AI MAPPING TEMPLATES ────────────────────────────────────
-- Cache mapping yang sudah dikonfirmasi — dipakai ulang
-- jika header signature sama (MD5 dari sorted lower-case headers).
-- Menghindari re-call Claude API untuk format yang sudah dikenal.
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_migration_mapping_templates (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  entity_type      VARCHAR(50)  NOT NULL,
  source_system    VARCHAR(100) NULL,            -- smartlink, ilaundy, excel, dll
  header_signature VARCHAR(32)  NOT NULL,        -- MD5(lower(header1,header2,...))
  mapping          JSON         NOT NULL,        -- mapping yang sudah terbukti benar
  usage_count      INT          NOT NULL DEFAULT 1,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_mmt_entity    (entity_type),
  INDEX idx_mmt_signature (header_signature),
  UNIQUE KEY uk_mmt_entity_sig (entity_type, header_signature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 3. ALTER hl_transaksi — tambah kolom is_imported ──────────
-- Tandai transaksi hasil import agar bisa difilter terpisah
-- dari data live. Default 0 = data live/normal.
-- ──────────────────────────────────────────────────────────────
ALTER TABLE hl_transaksi
  ADD COLUMN IF NOT EXISTS is_imported   TINYINT  NOT NULL DEFAULT 0 AFTER created_by,
  ADD COLUMN IF NOT EXISTS migration_job_id INT    NULL              AFTER is_imported;

-- Index untuk query filter data import
CREATE INDEX IF NOT EXISTS idx_trx_imported
  ON hl_transaksi (tenant_id, outlet_id, is_imported);


-- ── 4. Tambah feature cost ke referensi ───────────────────────
-- Tidak ada tabel untuk ini (COSTS ada di CoinLedger.php),
-- tapi kita dokumentasikan di sini untuk audit:
-- 'ai_migration_mapping' => 1000 coin (self-service)
-- Assisted migration tidak deduct coin (tercover fee Rp 200.000)
-- Mapping dari cache => GRATIS (0 coin)


-- ══════════════════════════════════════════════════════════════
-- VERIFIKASI (uncomment untuk cek setelah run)
-- ══════════════════════════════════════════════════════════════
-- SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME IN ('hl_migration_jobs','hl_migration_mapping_templates');

-- SHOW COLUMNS FROM hl_transaksi LIKE 'is_imported';
-- SHOW COLUMNS FROM hl_transaksi LIKE 'migration_job_id';
