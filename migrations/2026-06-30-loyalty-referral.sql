ALTER TABLE tenants
  ADD COLUMN referral_enabled        TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN referral_poin_pengajak  INT NOT NULL DEFAULT 0,
  ADD COLUMN referral_poin_teman     INT NOT NULL DEFAULT 0,
  ADD COLUMN referral_max_per_pengajak INT NOT NULL DEFAULT 0;

ALTER TABLE hl_pelanggan
  ADD COLUMN referral_code VARCHAR(20) NULL,
  ADD KEY idx_referral_code (tenant_id, referral_code);

CREATE TABLE hl_referral (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  referrer_pelanggan_id INT NOT NULL,
  referee_pelanggan_id  INT NOT NULL,
  kode VARCHAR(20) NOT NULL,
  status ENUM('pending','paid','void') NOT NULL DEFAULT 'pending',
  referee_first_order_id INT NULL,
  poin_pengajak INT NOT NULL DEFAULT 0,
  poin_teman    INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at    DATETIME NULL,
  UNIQUE KEY uniq_referee (tenant_id, referee_pelanggan_id),
  KEY idx_referrer (tenant_id, referrer_pelanggan_id),
  KEY idx_status (tenant_id, status)
);
