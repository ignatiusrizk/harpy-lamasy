-- ══════════════════════════════════════════════════════
-- platform_intelligence_migration.sql
-- Task 3 — Platform Intelligence: health, error, impersonation, WA log
-- Jalankan SEKALI di phpMyAdmin / mysql client
-- ══════════════════════════════════════════════════════

-- ══════════════════════════════
-- 1. PLATFORM HEALTH LOG
-- Rekam usage stats harian (auto-insert via PlatformHealthRecorder)
-- ══════════════════════════════
CREATE TABLE IF NOT EXISTS saas_platform_health (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  tanggal               DATE        NOT NULL,

  -- Tenant stats
  total_tenant_aktif    INT         DEFAULT 0,
  total_tenant_trial    INT         DEFAULT 0,
  total_tenant_grace    INT         DEFAULT 0,
  tenant_login_hari_ini INT         DEFAULT 0,

  -- Usage stats
  total_transaksi       INT         DEFAULT 0,
  total_wa_terkirim     INT         DEFAULT 0,
  total_ai_calls        INT         DEFAULT 0,
  total_ai_cost_coin    INT         DEFAULT 0,

  -- Coin & revenue stats
  total_coin_terjual    INT         DEFAULT 0,
  total_coin_dipakai    INT         DEFAULT 0,
  total_revenue_hari    INT         DEFAULT 0,

  -- Error stats
  total_error_php       INT         DEFAULT 0,
  total_wa_gagal        INT         DEFAULT 0,
  total_ai_error        INT         DEFAULT 0,

  created_at            TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_tanggal (tanggal),
  INDEX idx_tanggal (tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════
-- 2. ERROR LOG
-- Catat error PHP & sistem dengan deduplication
-- ══════════════════════════════
CREATE TABLE IF NOT EXISTS saas_error_log (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  tanggal          DATE         NOT NULL,
  jam              TIME         NOT NULL,

  -- Konteks
  tenant_id        INT          NULL,
  outlet_id        INT          NULL,
  user_id          INT          NULL,
  url              VARCHAR(500) NULL,
  method           VARCHAR(10)  NULL,

  -- Detail error
  error_type       VARCHAR(100) NOT NULL,
  error_code       VARCHAR(50)  NULL,
  error_message    TEXT         NOT NULL,
  stack_trace      TEXT         NULL,
  request_data     TEXT         NULL,

  -- Frekuensi (dedup key: tanggal + type + message hash)
  occurrence_count INT          DEFAULT 1,
  first_seen       DATETIME     NOT NULL,
  last_seen        DATETIME     NOT NULL,

  -- Status
  status           ENUM('new','acknowledged','resolved') DEFAULT 'new',
  resolved_by      INT          NULL,
  resolved_at      DATETIME     NULL,
  resolution_note  TEXT         NULL,

  INDEX idx_tanggal (tanggal),
  INDEX idx_type    (error_type),
  INDEX idx_status  (status),
  INDEX idx_tenant  (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════
-- 3. IMPERSONATION LOG
-- Setiap sesi impersonation wajib tercatat
-- ══════════════════════════════
CREATE TABLE IF NOT EXISTS saas_impersonation_log (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  superadmin_id        INT         NOT NULL,
  tenant_id            INT         NOT NULL,
  outlet_id            INT         NULL,
  reason               VARCHAR(200) NOT NULL,
  started_at           DATETIME    NOT NULL,
  ended_at             DATETIME    NULL,
  duration_seconds     INT         NULL,
  ip_address           VARCHAR(45) NULL,
  INDEX idx_superadmin (superadmin_id),
  INDEX idx_tenant     (tenant_id),
  INDEX idx_started    (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════
-- 4. WA DELIVERY LOG
-- Track semua pengiriman/pembuatan WA link dari sistem
-- ══════════════════════════════
CREATE TABLE IF NOT EXISTS saas_wa_log (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id      INT          NULL,
  outlet_id      INT          NULL,
  type           VARCHAR(50)  NOT NULL,
  wa_target      VARCHAR(20)  NOT NULL,
  pesan_preview  VARCHAR(200) NULL,
  status         ENUM('sent','failed','pending') DEFAULT 'pending',
  error_message  TEXT         NULL,
  sent_at        DATETIME     NULL,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  INDEX idx_status (status),
  INDEX idx_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
