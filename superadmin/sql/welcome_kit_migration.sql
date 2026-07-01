-- Welcome Kit Fisik + Alamat Outlet Wajib — migration
-- Idempoten. Sudah diterapkan ke PROD 2026-06-30; file ini untuk reproducibility (fresh DB).
-- Catatan: kolom `trigger` adalah reserved word MariaDB → WAJIB di-backtick.

ALTER TABLE outlets
  ADD COLUMN IF NOT EXISTS penerima VARCHAR(120) NULL AFTER telepon,
  ADD COLUMN IF NOT EXISTS kode_pos VARCHAR(10) NULL AFTER penerima;

CREATE TABLE IF NOT EXISTS saas_welcome_kit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  payment_id INT NULL,
  `trigger` VARCHAR(24) NOT NULL,
  penerima VARCHAR(120) NULL,
  hp VARCHAR(20) NULL,
  alamat TEXT NULL,
  kota VARCHAR(100) NULL,
  kode_pos VARCHAR(10) NULL,
  items_json TEXT NULL,
  status ENUM('pending','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  kurir VARCHAR(60) NULL,
  resi VARCHAR(80) NULL,
  shipped_at DATETIME NULL,
  delivered_at DATETIME NULL,
  catatan VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_payment (payment_id),
  KEY idx_tenant (tenant_id),
  KEY idx_outlet (outlet_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO saas_billing_config (key_name, value_text, description) VALUES
 ('welcome_kit_enabled', '1', 'Aktifkan welcome kit fisik saat aktivasi outlet'),
 ('welcome_kit_items', '[{"nama":"Roll kertas thermal 58mm","qty":2},{"nama":"Plastik packing","qty":1},{"nama":"Solasi roll","qty":1}]', 'Isi welcome kit (JSON: nama+qty)')
ON DUPLICATE KEY UPDATE key_name = key_name;
