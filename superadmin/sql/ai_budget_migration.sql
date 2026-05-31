-- ══════════════════════════════════════════════════════════════════
-- AI Budget Cap & Usage Tracking — Migration
-- A. Limit AI usage per tenant per hari (anti-abuse + revenue protection)
-- C. Track token usage untuk margin monitoring
-- ══════════════════════════════════════════════════════════════════

-- 1. Tambah kolom budget di tenants (default 10.000 coin/hari ≈ 60 AI calls)
ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS ai_daily_budget_coin INT NOT NULL DEFAULT 10000
  AFTER coin_balance;

-- 2. Tabel untuk track usage AI per call (token in/out, est cost, coin charge)
CREATE TABLE IF NOT EXISTS hl_ai_usage (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT          NOT NULL,
    outlet_id          INT          NULL,
    feature_key        VARCHAR(50)  NOT NULL,
    tokens_in          INT          NOT NULL DEFAULT 0,
    tokens_out         INT          NOT NULL DEFAULT 0,
    cost_estimated_idr INT          NOT NULL DEFAULT 0,
    coin_charged       INT          NOT NULL DEFAULT 0,
    model              VARCHAR(50)  NULL,
    from_cache         TINYINT(1)   DEFAULT 0,
    created_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_date (tenant_id, created_at),
    INDEX idx_feature     (feature_key),
    INDEX idx_date        (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verifikasi
SELECT 'Migration OK. Default budget: 10000 coin/hari per tenant.' AS info;
