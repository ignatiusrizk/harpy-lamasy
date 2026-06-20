-- ══════════════════════════════════════════════════════
-- Backfill: kolom yang missing dari migrasi sebelumnya
--
-- Cek `information_schema.columns` di awal session menemukan 5 kolom
-- yang harusnya ada (dari migrasi terdahulu) tapi belum di-apply:
--   - hl_transaksi.express_tier_id
--   - hl_transaksi_item.express_tier_id
--   - hl_layanan.express_tier_id
--   - hl_pelanggan.member_tier_id
--   - hl_pelanggan.saldo_expires_at
--
-- Idempotent: pakai ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+).
-- ══════════════════════════════════════════════════════

-- Express Tier per ORDER (dominant tier, untuk struk header)
ALTER TABLE hl_transaksi
  ADD COLUMN IF NOT EXISTS express_tier_id INT NULL
    COMMENT 'Dominant express tier untuk order ini (NULL = reguler)'
    AFTER tipe_order;

-- Express Tier per ITEM (user pilih tier saat tambah item di POS)
ALTER TABLE hl_transaksi_item
  ADD COLUMN IF NOT EXISTS express_tier_id INT NULL
    COMMENT 'Express tier per-item (NULL = reguler/inherit dari layanan)';

-- Express Tier default per LAYANAN (saat layanan = express, link ke tier)
ALTER TABLE hl_layanan
  ADD COLUMN IF NOT EXISTS express_tier_id INT NULL
    COMMENT 'Default tier kalau layanan ini express';

-- Member Tier link di pelanggan
ALTER TABLE hl_pelanggan
  ADD COLUMN IF NOT EXISTS member_tier_id INT NULL
    COMMENT 'Tier member aktif (Gold/Silver/VIP)';

-- Saldo expiry untuk deposit (kalau ada kebijakan expired)
ALTER TABLE hl_pelanggan
  ADD COLUMN IF NOT EXISTS saldo_expires_at DATETIME NULL
    COMMENT 'Tanggal expired saldo deposit (NULL = no expiry)';

-- Voucher per outlet (dari voucher_per_outlet_migration yg gak fully apply)
ALTER TABLE hl_voucher
  ADD COLUMN IF NOT EXISTS outlet_id INT NULL
    COMMENT 'Outlet scope voucher (NULL = global tenant)'
  AFTER tenant_id;

-- Index untuk lookup tier (non-unique, opsional tapi helpful)
ALTER TABLE hl_transaksi
  ADD INDEX IF NOT EXISTS idx_express_tier (tenant_id, outlet_id, express_tier_id);

ALTER TABLE hl_pelanggan
  ADD INDEX IF NOT EXISTS idx_member_tier (tenant_id, outlet_id, member_tier_id);

ALTER TABLE hl_voucher
  ADD INDEX IF NOT EXISTS idx_voucher_outlet (tenant_id, outlet_id, is_used);

-- Verify
-- SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns
--  WHERE table_schema=DATABASE()
--    AND ((table_name='hl_transaksi' AND column_name='express_tier_id')
--      OR (table_name='hl_transaksi_item' AND column_name='express_tier_id')
--      OR (table_name='hl_layanan' AND column_name='express_tier_id')
--      OR (table_name='hl_pelanggan' AND column_name IN ('member_tier_id','saldo_expires_at')));
