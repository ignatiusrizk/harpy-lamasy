-- superadmin/sql/antar_jemput_migration.sql
-- Sistem Antar Jemput laundry

CREATE TABLE IF NOT EXISTS hl_kurir (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  user_id INT NULL,
  nama VARCHAR(100) NOT NULL,
  no_hp VARCHAR(20),
  kendaraan VARCHAR(50),
  aktif TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outlet_aktif (tenant_id, outlet_id, aktif),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_antar_jemput (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  tipe ENUM('jemput','antar') NOT NULL,
  transaksi_id INT NULL,
  pelanggan_id INT NULL,
  nama VARCHAR(100) NOT NULL,
  telepon VARCHAR(20),
  alamat TEXT NULL,
  zona_id INT NULL,
  fee INT DEFAULT 0,
  slot_waktu DATETIME NULL,
  kurir_id INT NULL,
  status ENUM('pending','assigned','menuju','sampai','done','cancel') DEFAULT 'pending',
  catatan TEXT,
  foto_bukti VARCHAR(255),
  signature_path VARCHAR(255),
  created_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  done_at DATETIME NULL,
  INDEX idx_outlet_status (tenant_id, outlet_id, status, created_at),
  INDEX idx_kurir_status (kurir_id, status),
  INDEX idx_transaksi (transaksi_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_zona_antar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  nama VARCHAR(60) NOT NULL,
  fee INT NOT NULL DEFAULT 0,
  aktif TINYINT(1) DEFAULT 1,
  INDEX idx_outlet_aktif (tenant_id, outlet_id, aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE outlets ADD COLUMN IF NOT EXISTS antar_mode ENUM('free','zona') DEFAULT 'free' AFTER label_size;
