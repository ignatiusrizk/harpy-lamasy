-- Business Affiliate Program
CREATE TABLE IF NOT EXISTS hl_affiliate (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  telepon VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  kode VARCHAR(20) NOT NULL UNIQUE,
  rekening_bank VARCHAR(50) NULL,
  rekening_nomor VARCHAR(40) NULL,
  rekening_atas_nama VARCHAR(100) NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  saldo_komisi BIGINT NOT NULL DEFAULT 0,
  total_dibayar BIGINT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kode (kode), INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_affiliate_referral (
  id INT AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT NOT NULL,
  tenant_id INT NOT NULL,
  status ENUM('signup','activated') NOT NULL DEFAULT 'signup',
  komisi BIGINT NOT NULL DEFAULT 0,
  activated_at DATETIME NULL,
  payment_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tenant (tenant_id),
  INDEX idx_affiliate (affiliate_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_affiliate_payout (
  id INT AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT NOT NULL,
  jumlah BIGINT NOT NULL,
  status ENUM('requested','paid','rejected') NOT NULL DEFAULT 'requested',
  requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  catatan_sa TEXT NULL,
  INDEX idx_affiliate_status (affiliate_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Config komisi default (saas_billing_config)
INSERT IGNORE INTO saas_billing_config (key_name, value_text)
VALUES ('affiliate_commission', '100000');
