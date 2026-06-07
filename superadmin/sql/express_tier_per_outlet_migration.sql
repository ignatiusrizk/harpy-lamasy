-- ══════════════════════════════════════════════════════
-- Migration: Express Tier per Outlet
--
-- Update UNIQUE constraint hl_express_tier dari (tenant_id, nama_tier)
-- → (tenant_id, outlet_id, nama_tier). Memungkinkan tier dengan nama
-- sama di outlet berbeda (mis. "Express 12 Jam" beda di outlet A vs B).
--
-- MariaDB: kalau outlet_id NULL, baris berbeda otomatis tidak conflict
-- (MariaDB treats NULL as distinct di UNIQUE index).
-- ══════════════════════════════════════════════════════

-- Drop old unique
ALTER TABLE hl_express_tier DROP INDEX uniq_tenant_tier;

-- Add new unique with outlet_id
ALTER TABLE hl_express_tier
  ADD UNIQUE KEY uniq_tenant_outlet_tier (tenant_id, outlet_id, nama_tier);
