-- ══════════════════════════════════════════════════════
-- Migration: Inventori Bahan Baku
--
-- Track stok bahan habis pakai per outlet:
-- deterjen, parfum, pewangi, plastik kemasan, peralatan, dll.
--
-- Komponen:
-- - hl_bahan          : master bahan baku per outlet
-- - hl_bahan_mutasi   : log semua pergerakan stok (masuk/keluar/adjust/transfer)
-- - hl_bahan_stok     : VIEW computed stok terkini (stok_awal + sum mutasi)
--
-- Tipe mutasi:
-- - masuk    : restock / pembelian
-- - keluar   : pemakaian harian
-- - adjust   : koreksi stok manual
-- - transfer : pindah antar outlet (dicatat 2x: keluar di asal, masuk di tujuan)
-- ══════════════════════════════════════════════════════

-- ─────────────────────────────────────────────
-- 1. MASTER BAHAN BAKU
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_bahan (
  id            INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT          NOT NULL,
  outlet_id     INT          NOT NULL,
  nama          VARCHAR(100) NOT NULL                 COMMENT 'Misal: Deterjen Bukrim 1kg',
  kategori      ENUM(
                  'deterjen',
                  'parfum',
                  'pewangi',
                  'plastik_kemasan',
                  'peralatan',
                  'lainnya'
                )            NOT NULL DEFAULT 'lainnya',
  satuan        VARCHAR(20)  NOT NULL DEFAULT 'pcs'   COMMENT 'kg, liter, pcs, rol, dll',
  stok_awal     INT          NOT NULL DEFAULT 0       COMMENT 'Stok pembuka saat bahan dibuat',
  stok_minimum  INT          NOT NULL DEFAULT 5       COMMENT 'Alert jika stok_terkini <= ini',
  harga_beli    INT          NOT NULL DEFAULT 0       COMMENT 'Rp per satuan (terakhir)',
  supplier      VARCHAR(100) NULL,
  is_active     TINYINT      NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_tenant_outlet (tenant_id, outlet_id),
  INDEX idx_kategori (tenant_id, kategori),
  INDEX idx_active (tenant_id, outlet_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 2. MUTASI STOK (audit trail semua pergerakan)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_bahan_mutasi (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL,
  outlet_id       INT          NOT NULL,
  bahan_id        INT          NOT NULL,

  tipe            ENUM('masuk','keluar','adjust','transfer') NOT NULL,
  jumlah          INT          NOT NULL                COMMENT 'Selalu positif. Adjust bisa +/- via stok_sebelum/sesudah',
  stok_sebelum    INT          NOT NULL,
  stok_sesudah    INT          NOT NULL,

  -- Detail per tipe
  harga_beli      INT          NULL                    COMMENT 'Diisi jika tipe masuk (untuk hitung beban)',
  supplier        VARCHAR(100) NULL,
  catatan         VARCHAR(200) NULL,

  -- Transfer antar outlet
  outlet_tujuan_id INT         NULL                    COMMENT 'Diisi jika tipe transfer (outlet penerima)',
  transfer_pair_id INT         NULL                    COMMENT 'Self-ref ke mutasi pasangan (transfer keluar <> masuk)',

  input_by        INT          NOT NULL                COMMENT 'user_id staff/owner',
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_bahan (bahan_id),
  INDEX idx_tenant_outlet (tenant_id, outlet_id),
  INDEX idx_tipe (tenant_id, tipe),
  INDEX idx_date (created_at),
  INDEX idx_pair (transfer_pair_id),

  CONSTRAINT fk_bahan_mutasi_bahan
    FOREIGN KEY (bahan_id) REFERENCES hl_bahan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 3. VIEW STOK TERKINI (computed)
-- Query: SELECT * FROM hl_bahan_stok WHERE tenant_id=? AND outlet_id=?
-- ─────────────────────────────────────────────
DROP VIEW IF EXISTS hl_bahan_stok;

CREATE VIEW hl_bahan_stok AS
SELECT
  b.id,
  b.tenant_id,
  b.outlet_id,
  b.nama,
  b.kategori,
  b.satuan,
  b.stok_awal,
  b.stok_minimum,
  b.harga_beli,
  b.supplier,
  b.is_active,
  b.created_at,
  (
    b.stok_awal
    + COALESCE(SUM(
        CASE
          WHEN m.tipe = 'masuk'    THEN  m.jumlah
          WHEN m.tipe = 'keluar'   THEN -m.jumlah
          WHEN m.tipe = 'adjust'   THEN (m.stok_sesudah - m.stok_sebelum)
          WHEN m.tipe = 'transfer' THEN -m.jumlah
          ELSE 0
        END
      ), 0)
  ) AS stok_terkini,
  CASE
    WHEN (
      b.stok_awal + COALESCE(SUM(
        CASE
          WHEN m.tipe = 'masuk'    THEN  m.jumlah
          WHEN m.tipe = 'keluar'   THEN -m.jumlah
          WHEN m.tipe = 'adjust'   THEN (m.stok_sesudah - m.stok_sebelum)
          WHEN m.tipe = 'transfer' THEN -m.jumlah
          ELSE 0
        END
      ), 0)
    ) <= 0 THEN 'habis'
    WHEN (
      b.stok_awal + COALESCE(SUM(
        CASE
          WHEN m.tipe = 'masuk'    THEN  m.jumlah
          WHEN m.tipe = 'keluar'   THEN -m.jumlah
          WHEN m.tipe = 'adjust'   THEN (m.stok_sesudah - m.stok_sebelum)
          WHEN m.tipe = 'transfer' THEN -m.jumlah
          ELSE 0
        END
      ), 0)
    ) <= b.stok_minimum THEN 'minim'
    ELSE 'aman'
  END AS status_stok
FROM hl_bahan b
LEFT JOIN hl_bahan_mutasi m ON m.bahan_id = b.id
GROUP BY b.id;

-- ─────────────────────────────────────────────
-- 4. VERIFY
-- ─────────────────────────────────────────────
-- SELECT 'hl_bahan'        AS tbl, COUNT(*) AS rows FROM hl_bahan
-- UNION ALL
-- SELECT 'hl_bahan_mutasi' AS tbl, COUNT(*) AS rows FROM hl_bahan_mutasi
-- UNION ALL
-- SELECT 'hl_bahan_stok'   AS tbl, COUNT(*) AS rows FROM hl_bahan_stok;
