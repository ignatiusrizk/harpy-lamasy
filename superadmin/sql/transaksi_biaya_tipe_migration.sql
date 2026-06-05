-- ══════════════════════════════════════════════════════
-- Migration: Tambah kolom biaya_tambahan & tipe_order
--           di hl_transaksi
--
-- Tujuan:
-- - biaya_tambahan: track express fee, biaya service tambahan,
--   penjemputan, dll (default 0). Tetap di-include di total tagihan.
-- - tipe_order: kategori order (reguler/express/kilat/custom).
--   Untuk analytics segmentasi.
--
-- Backward compat:
-- - Default values ditetapkan supaya transaksi lama tidak perlu
--   migrasi data (otomatis biaya_tambahan=0, tipe_order='reguler').
-- - POS form sudah tetap berfungsi tanpa input baru.
--
-- Dipakai oleh:
-- - core/MigrationImporter.php — mapping "Tambahan Express",
--   "Biaya Service", "Jenis"
-- - core/AIMigrationMapper.php — schema target import
-- - pos.php — form opsional + INSERT (Phase 2)
-- - core/StrukGenerator.php — breakdown line (Phase 2)
-- ══════════════════════════════════════════════════════

ALTER TABLE hl_transaksi
  ADD COLUMN IF NOT EXISTS biaya_tambahan DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'Biaya express, service, jemput, dll. Sudah include di total.'
    AFTER diskon,
  ADD COLUMN IF NOT EXISTS tipe_order VARCHAR(20) NOT NULL DEFAULT 'reguler'
    COMMENT 'reguler/express/kilat/custom — utk analytics segmentasi'
    AFTER metode_bayar,
  ADD INDEX IF NOT EXISTS idx_tipe_order (tipe_order);
