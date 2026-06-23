-- ════════════════════════════════════════════════════════════════
-- AI Briefing — Progressive Pricing Tiers
-- Date: 2026-06-24
--
-- Per user request:
--   "kok jadi topup coin? di kenakan biaya tambahan aja.
--    misal pertama 80, kedua 100, ketiga 150, dst hingga maks 5x"
--
-- Schema: add pricing_tiers JSON column ke saas_coin_pricing
--   - NULL: flat harga_coin (existing behavior)
--   - JSON array: progressive price per call. Length = max daily calls.
-- ════════════════════════════════════════════════════════════════

ALTER TABLE saas_coin_pricing
  ADD COLUMN pricing_tiers VARCHAR(255) DEFAULT NULL
  COMMENT 'JSON array of progressive prices [80,100,150,250,400]. NULL = flat harga_coin.';

-- HQ Briefing tiers (sesuai user spec)
UPDATE saas_coin_pricing
   SET pricing_tiers = '[80,100,150,250,400]',
       daily_limit = 5
 WHERE feature_key = 'ai_briefing_hq';

-- Outlet Briefing tiers (scaled proportional dari base 500, multiplier sama)
UPDATE saas_coin_pricing
   SET pricing_tiers = '[500,625,940,1565,2500]',
       daily_limit = 5
 WHERE feature_key = 'ai_briefing';

-- Verify
SELECT feature_key, nama_fitur, harga_coin, daily_limit, pricing_tiers
  FROM saas_coin_pricing
 WHERE feature_key LIKE '%briefing%';
