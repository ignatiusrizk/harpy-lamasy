-- migrations/2026-08-31-biaya-lainnya-tier.sql
-- Redesign Biaya Lainnya: dari free-text manual jadi master data (tier),
-- otomatis diterapkan ke semua order. Lihat spec
-- docs/superpowers/specs/2026-08-31-biaya-lainnya-tier-design.md

-- 1) Tabel master — pola identik hl_express_tier, tanpa estimasi_jam
CREATE TABLE hl_biaya_lainnya_tier (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NULL,
  nama VARCHAR(50) NOT NULL,
  tipe_biaya ENUM('flat','percent') NOT NULL DEFAULT 'flat',
  nilai_biaya DECIMAL(12,2) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  urutan INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_active (tenant_id, is_active)
);

-- 2) Tabel detail snapshot per order (bisa >1 baris per order)
CREATE TABLE hl_transaksi_biaya_lainnya (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  transaksi_id INT NOT NULL,
  nama VARCHAR(50) NOT NULL,
  nominal DECIMAL(12,2) NOT NULL,
  INDEX idx_transaksi (transaksi_id)
);

-- 3) Kolom lama yg sudah tidak relevan (fitur free-text belum pernah
--    dipakai order nyata — aman dihapus tanpa migrasi data)
ALTER TABLE hl_transaksi DROP COLUMN biaya_lainnya_label;
