CREATE TABLE IF NOT EXISTS hl_shift (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL, outlet_id INT NOT NULL,
  nama VARCHAR(50) NOT NULL,
  jam_mulai TIME NOT NULL, jam_selesai TIME NOT NULL,
  toleransi_telat_menit INT NOT NULL DEFAULT 15,
  lembur_after_menit INT NOT NULL DEFAULT 30,
  is_active TINYINT(1) NOT NULL DEFAULT 1, urutan INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_outlet (tenant_id, outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_jadwal_shift (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL, outlet_id INT NOT NULL,
  user_id INT NOT NULL, hari TINYINT NOT NULL, shift_id INT NOT NULL,
  UNIQUE KEY uq_user_hari (tenant_id, outlet_id, user_id, hari),
  KEY idx_outlet (tenant_id, outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE hl_absensi
  ADD COLUMN IF NOT EXISTS shift_id INT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS telat_menit INT NOT NULL DEFAULT 0 AFTER shift_id,
  ADD COLUMN IF NOT EXISTS lembur_menit INT NOT NULL DEFAULT 0 AFTER telat_menit;
