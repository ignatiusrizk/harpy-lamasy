-- ══════════════════════════════════════════════════════
-- QRIS Payment Integration — Schema Migration
-- 2026-06-24
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS saas_payments (
  id              INT PRIMARY KEY AUTO_INCREMENT,
  order_id        VARCHAR(60) UNIQUE NOT NULL COMMENT 'Format: LAM-{type}-{tenant_id}-{ts}-{rand6}',
  tenant_id       INT NOT NULL,
  type            ENUM('topup_coin','setup_fee','outlet_activation') NOT NULL,
  amount          INT NOT NULL COMMENT 'IDR exact, fee tidak include',

  ref_bundle_id   INT NULL COMMENT 'saas_coin_bundles.id kalau type=topup_coin',
  ref_package_id  INT NULL COMMENT 'saas_packages.id kalau type=setup_fee',
  ref_outlet_id   INT NULL COMMENT 'outlets.id kalau type=outlet_activation',

  midtrans_tx_id  VARCHAR(100) NULL,
  payment_type    VARCHAR(30) NULL,
  va_bank         VARCHAR(20) NULL,
  va_number       VARCHAR(50) NULL,
  qr_string       TEXT NULL,

  status          ENUM('pending','paid','expired','failed','cancelled') DEFAULT 'pending',
  paid_at         DATETIME NULL,
  expires_at      DATETIME NOT NULL,
  raw_response    JSON NULL,

  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_tenant_status (tenant_id, status, created_at),
  INDEX idx_status_expires (status, expires_at),
  INDEX idx_order (order_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS saas_billing_config (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  key_name    VARCHAR(50) UNIQUE NOT NULL,
  value_text  TEXT,
  description TEXT,
  updated_by  INT NULL,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO saas_billing_config (key_name, value_text, description) VALUES
('midtrans_env',           'sandbox', 'sandbox / production'),
('midtrans_server_key',    '',        'Server key Midtrans (masked di UI)'),
('midtrans_client_key',    '',        'Client key Midtrans'),
('outlet_activation_fee',  '800000',  'Fee aktivasi outlet ke-2+ (IDR)'),
('payment_expiry_minutes', '15',      'Payment expire after N menit');

-- Link coin_ledger ke payment yang trigger (audit trail)
ALTER TABLE coin_ledger
  ADD COLUMN payment_id INT NULL AFTER ref_id,
  ADD INDEX idx_payment (payment_id);
