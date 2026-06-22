-- superadmin/sql/auto_bonus_migration.sql
-- Auto-bonus payroll: master rule + junction multi-outlet + breakdown komponen

CREATE TABLE IF NOT EXISTS hl_bonus_rule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(100) NOT NULL,
  tipe ENUM('hadir_penuh','tepat_waktu','lembur','zero_izin','penalti_telat') NOT NULL,
  threshold INT NOT NULL DEFAULT 0,
  amount INT NOT NULL DEFAULT 0,
  amount_per_unit TINYINT(1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_bonus_rule_outlet (
  rule_id INT NOT NULL,
  outlet_id INT NOT NULL,
  PRIMARY KEY (rule_id, outlet_id),
  INDEX idx_outlet (outlet_id),
  FOREIGN KEY (rule_id) REFERENCES hl_bonus_rule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_gaji_komponen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gaji_id INT NOT NULL,
  jenis VARCHAR(40) NOT NULL,
  rule_id INT NULL,
  nama VARCHAR(100) NOT NULL,
  amount INT NOT NULL,
  keterangan TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gaji (gaji_id),
  FOREIGN KEY (gaji_id) REFERENCES hl_gaji(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
