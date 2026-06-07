-- ══════════════════════════════════════════════════════
-- Migration: Voucher per Outlet
--
-- hl_voucher tambah kolom outlet_id (nullable):
-- - NULL = voucher berlaku di SEMUA outlet (cross-outlet, default)
-- - INT  = voucher cuma bisa dipakai di outlet itu
--
-- Use case:
-- - Promo grand opening Cibubur → voucher khusus outlet Cibubur
-- - Voucher loyalty tahunan → berlaku semua outlet (NULL)
-- ══════════════════════════════════════════════════════

ALTER TABLE hl_voucher
  ADD COLUMN IF NOT EXISTS outlet_id INT DEFAULT NULL
    COMMENT 'NULL = berlaku semua outlet, INT = khusus outlet itu'
    AFTER promo_id,
  ADD INDEX IF NOT EXISTS idx_outlet_used (outlet_id, is_used);
