-- Supplier DB + Purchase Order
CREATE TABLE IF NOT EXISTS hl_supplier (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(100) NOT NULL,
  kontak_nama VARCHAR(100) NULL,
  telepon VARCHAR(20) NULL,
  alamat TEXT NULL,
  term_pembayaran VARCHAR(50) NULL,
  catatan TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_po (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  supplier_id INT NOT NULL,
  no_po VARCHAR(40) NOT NULL,
  tanggal DATE NOT NULL,
  status ENUM('draft','dipesan','diterima','batal') NOT NULL DEFAULT 'draft',
  total BIGINT NOT NULL DEFAULT 0,
  catatan TEXT NULL,
  input_by INT NULL,
  dipesan_at DATETIME NULL,
  diterima_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_no_po (tenant_id, no_po),
  INDEX idx_outlet_status (tenant_id, outlet_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_po_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_id INT NOT NULL,
  tenant_id INT NOT NULL,
  bahan_id INT NOT NULL,
  qty INT NOT NULL,
  harga_satuan INT NOT NULL,
  subtotal BIGINT NOT NULL,
  mutasi_id INT NULL,
  INDEX idx_po (po_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- supplier_id link opsional (backward compat: supplier text tetap)
ALTER TABLE hl_bahan ADD COLUMN supplier_id INT NULL AFTER supplier;
