-- superadmin/sql/produksi_input_migration.sql
-- Tabel input form per-stage untuk /produksi.php

CREATE TABLE IF NOT EXISTS hl_proses_input (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  transaksi_id INT NOT NULL,
  stage VARCHAR(20) NOT NULL,
  karyawan_id INT NOT NULL,
  data_json JSON,
  foto_paths TEXT,
  catatan TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outlet_order (tenant_id, outlet_id, transaksi_id),
  INDEX idx_stage_time (stage, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
