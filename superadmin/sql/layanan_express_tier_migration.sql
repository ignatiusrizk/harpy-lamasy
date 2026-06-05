-- ══════════════════════════════════════════════════════
-- Migration: hl_layanan_express_tier
--
-- Tujuan: Setiap layanan bisa punya beberapa tier express
--         (12 jam, 6 jam, 3 jam, kilat) dengan biaya tambahan
--         berupa flat (Rp tetap) atau percent (% dari subtotal item).
--
-- Lookup di POS: load semua tier dari layanan-layanan yang ada
--                dalam nota, gabung jadi dropdown (union by nama_tier).
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_layanan_express_tier (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL,
  outlet_id       INT          DEFAULT NULL  COMMENT 'NULL = berlaku semua outlet',
  layanan_id      INT          NOT NULL,
  nama_tier       VARCHAR(50)  NOT NULL  COMMENT 'mis. "Express 12 Jam", "Kilat 3 Jam"',
  estimasi_jam    INT          NOT NULL  COMMENT 'jam dari nota masuk → siap diambil',
  tipe_biaya      ENUM('flat','percent') NOT NULL DEFAULT 'percent',
  nilai_biaya     DECIMAL(12,2) NOT NULL  COMMENT 'kalau percent: %; kalau flat: Rp',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  urutan          INT          DEFAULT 0,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_layanan_tier (layanan_id, nama_tier),
  INDEX idx_tenant_layanan (tenant_id, layanan_id, is_active),
  FOREIGN KEY (layanan_id) REFERENCES hl_layanan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Track tier yang dipakai di transaksi (utk re-print struk & analytics)
ALTER TABLE hl_transaksi
  ADD COLUMN IF NOT EXISTS express_tier_nama VARCHAR(50) DEFAULT NULL
    COMMENT 'Nama tier yang dipakai (snapshot — tier bisa dihapus tanpa rusak history)'
    AFTER tipe_order;
