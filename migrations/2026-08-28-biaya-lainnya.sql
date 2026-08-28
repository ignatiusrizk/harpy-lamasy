-- migrations/2026-08-28-biaya-lainnya.sql
-- Biaya tambahan bebas (label+nominal manual per order), terpisah dari
-- biaya_tambahan (express/antar-jemput yang sudah ada & auto-derive tier).
ALTER TABLE hl_transaksi
  ADD COLUMN biaya_lainnya DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER biaya_tambahan,
  ADD COLUMN biaya_lainnya_label VARCHAR(100) NULL DEFAULT NULL AFTER biaya_lainnya;
