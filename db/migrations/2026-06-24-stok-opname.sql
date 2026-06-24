-- Stok Opname
CREATE TABLE IF NOT EXISTS hl_opname (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  tanggal DATE NOT NULL,
  status ENUM('draft','selesai') NOT NULL DEFAULT 'draft',
  total_item INT NOT NULL DEFAULT 0,
  total_selisih_item INT NOT NULL DEFAULT 0,
  nilai_selisih BIGINT NOT NULL DEFAULT 0,
  catatan TEXT NULL,
  input_by INT NULL,
  finalized_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_outlet (tenant_id, outlet_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_opname_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opname_id INT NOT NULL,
  tenant_id INT NOT NULL,
  bahan_id INT NOT NULL,
  stok_sistem INT NOT NULL,
  stok_fisik INT NULL,
  selisih INT NOT NULL DEFAULT 0,
  mutasi_id INT NULL,
  INDEX idx_opname (opname_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
