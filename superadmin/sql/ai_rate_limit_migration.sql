-- ══════════════════════════════════════════════════════
-- Migration: AI Rate Limiting
--
-- Tambah kolom `daily_limit` ke `saas_coin_pricing` agar SuperAdmin
-- bisa atur quota harian per fitur AI tanpa deploy ulang.
--
-- 0 = unlimited. Default 0 untuk fitur non-AI.
-- ══════════════════════════════════════════════════════

-- 1. Kolom daily_limit
ALTER TABLE saas_coin_pricing
  ADD COLUMN IF NOT EXISTS daily_limit INT NOT NULL DEFAULT 0
    COMMENT '0 = unlimited. Limit per tenant per hari.'
  AFTER harga_minimum;

-- Index untuk lookup cepat di AIRateLimiter::getLimit()
ALTER TABLE saas_coin_pricing
  ADD INDEX IF NOT EXISTS idx_active_key (is_active, feature_key);

-- 2. Seed default limits untuk fitur AI yg sudah eksis
-- (idempotent — UPDATE hanya kalau daily_limit masih 0/NULL)
UPDATE saas_coin_pricing SET daily_limit = 1
  WHERE feature_key = 'ai_briefing'          AND (daily_limit = 0 OR daily_limit IS NULL);
UPDATE saas_coin_pricing SET daily_limit = 1
  WHERE feature_key = 'ai_briefing_hq'       AND (daily_limit = 0 OR daily_limit IS NULL);
UPDATE saas_coin_pricing SET daily_limit = 20
  WHERE feature_key = 'ai_analyst'           AND (daily_limit = 0 OR daily_limit IS NULL);
UPDATE saas_coin_pricing SET daily_limit = 30
  WHERE feature_key = 'ai_chat_data'         AND (daily_limit = 0 OR daily_limit IS NULL);
UPDATE saas_coin_pricing SET daily_limit = 200
  WHERE feature_key = 'ai_upselling'         AND (daily_limit = 0 OR daily_limit IS NULL);
UPDATE saas_coin_pricing SET daily_limit = 3
  WHERE feature_key = 'ai_churn_message'     AND (daily_limit = 0 OR daily_limit IS NULL);
UPDATE saas_coin_pricing SET daily_limit = 10
  WHERE feature_key = 'ai_migration_mapping' AND (daily_limit = 0 OR daily_limit IS NULL);
UPDATE saas_coin_pricing SET daily_limit = 10
  WHERE feature_key = 'ai_review'            AND (daily_limit = 0 OR daily_limit IS NULL);
UPDATE saas_coin_pricing SET daily_limit = 30
  WHERE feature_key = 'ai_insight_laporan'   AND (daily_limit = 0 OR daily_limit IS NULL);

-- Verify
SELECT feature_key, nama_fitur, harga_coin, daily_limit, is_active
FROM saas_coin_pricing
WHERE kategori = 'ai' OR feature_key LIKE 'ai_%'
ORDER BY daily_limit DESC, feature_key;
