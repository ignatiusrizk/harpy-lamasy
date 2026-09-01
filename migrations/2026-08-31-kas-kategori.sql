-- migrations/2026-08-31-kas-kategori.sql
-- Kelola Kategori Kas: dari hardcode di kas.php jadi data terkelola.
-- Lihat spec: docs/superpowers/specs/2026-08-31-kas-kategori-design.md

CREATE TABLE hl_kas_kategori (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(50) NOT NULL,
  tipe ENUM('masuk','keluar') NOT NULL,
  emoji VARCHAR(10) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  urutan INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_tipe_active (tenant_id, tipe, is_active)
);

-- Seed 12 kategori existing (persis nama+emoji dari kas.php) ke SETIAP
-- tenant yang sudah ada, supaya dropdown tidak berubah begitu fitur live.
INSERT INTO hl_kas_kategori (tenant_id, nama, tipe, emoji, urutan)
SELECT t.tenant_id, k.nama, k.tipe, k.emoji, k.urutan
FROM (SELECT DISTINCT tenant_id FROM outlets) t
CROSS JOIN (
  SELECT 'Penjualan Laundry' AS nama, 'masuk' AS tipe, '💰' AS emoji, 1 AS urutan
  UNION ALL SELECT 'Pelunasan Order',   'masuk', '🧾', 2
  UNION ALL SELECT 'Pendapatan Lain',   'masuk', '➕', 3
  UNION ALL SELECT 'Modal',             'masuk', '🏦', 4
  UNION ALL SELECT 'Gaji Karyawan',     'keluar', '👥', 5
  UNION ALL SELECT 'Bahan & Deterjen',  'keluar', '🧴', 6
  UNION ALL SELECT 'Listrik & Air',     'keluar', '⚡', 7
  UNION ALL SELECT 'Sewa Tempat',       'keluar', '🏠', 8
  UNION ALL SELECT 'Peralatan',         'keluar', '🔧', 9
  UNION ALL SELECT 'Transportasi',      'keluar', '🛵', 10
  UNION ALL SELECT 'Operasional',       'keluar', '⚙️', 11
  UNION ALL SELECT 'Lain-lain',         'keluar', '📌', 12
) k;
