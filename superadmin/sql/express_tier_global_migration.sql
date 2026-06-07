-- ══════════════════════════════════════════════════════
-- Migration: Express Tier GLOBAL (per tenant) + per-item
--
-- Mengubah desain Phase 3a (tier per layanan) → tier global:
-- - Tenant define daftar tier sekali (Express 12 Jam, Kilat 3 Jam, dll)
-- - Di POS, kasir pilih tier PER ITEM (1 nota bisa campur reguler &
--   express)
-- - biaya_express disimpan per item; biaya_tambahan nota = SUM
--
-- ══════════════════════════════════════════════════════

-- 1) Tabel tier global per tenant
CREATE TABLE IF NOT EXISTS hl_express_tier (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL,
  outlet_id       INT          DEFAULT NULL  COMMENT 'NULL = berlaku semua outlet',
  nama_tier       VARCHAR(50)  NOT NULL  COMMENT '"Express 12 Jam", "Kilat 3 Jam"',
  estimasi_jam    INT          NOT NULL  COMMENT 'jam dari masuk → siap',
  tipe_biaya      ENUM('flat','percent') NOT NULL DEFAULT 'percent',
  nilai_biaya     DECIMAL(12,2) NOT NULL  COMMENT 'kalau percent: %; kalau flat: Rp',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  urutan          INT          DEFAULT 0,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tenant_outlet_tier (tenant_id, outlet_id, nama_tier),
  INDEX idx_tenant_active (tenant_id, is_active, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Per-item tier columns
ALTER TABLE hl_transaksi_item
  ADD COLUMN IF NOT EXISTS express_tier_nama VARCHAR(50) DEFAULT NULL
    COMMENT 'Snapshot nama tier yg dipilih utk item ini (NULL = reguler)'
    AFTER catatan_item,
  ADD COLUMN IF NOT EXISTS biaya_express DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'Biaya tambahan utk item ini (flat atau subtotal × percent)'
    AFTER express_tier_nama;

-- 3) Drop tabel per-layanan (Phase 3a lama) — desain digantikan oleh global
--    Aman: kalau ada data tier per-layanan, perlu migrasi manual ke global
--    (jarang ada krn fitur baru).
DROP TABLE IF EXISTS hl_layanan_express_tier;

-- Catatan: hl_transaksi.biaya_tambahan & express_tier_nama (Phase 2 & 3a)
-- tetap dipakai sebagai SUM cache + label dominant (snapshot nama tier
-- yg paling banyak dipakai di nota). Diisi otomatis saat POS save.
