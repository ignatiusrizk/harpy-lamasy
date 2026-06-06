-- ══════════════════════════════════════════════════════
-- Migration: qty_minimum di hl_layanan + foto_pickup di hl_transaksi
--
-- Inspired by Smartlink competitive research:
-- - "Minimal order transaksi X KG" per layanan
-- - "Dokumentasi Pengambilan" (foto bukti saat pelanggan ambil cucian)
-- ══════════════════════════════════════════════════════

ALTER TABLE hl_layanan
  ADD COLUMN IF NOT EXISTS qty_minimum DECIMAL(10,2) NOT NULL DEFAULT 0
    COMMENT 'Minimum kuantitas per order (mis. 1 KG, 2 PCS). 0 = tidak ada minimum.'
    AFTER harga;

ALTER TABLE hl_transaksi
  ADD COLUMN IF NOT EXISTS foto_pickup VARCHAR(255) DEFAULT NULL
    COMMENT 'Foto kondisi cucian saat diambil pelanggan (dokumentasi pickup)'
    AFTER bukti_bayar;
