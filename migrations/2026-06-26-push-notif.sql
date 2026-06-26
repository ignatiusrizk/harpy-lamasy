CREATE TABLE IF NOT EXISTS hl_device_token (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  user_id      INT NOT NULL,
  token        VARCHAR(255) NOT NULL,
  platform     VARCHAR(20) DEFAULT 'android',
  last_seen    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token),
  KEY idx_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_role_push_event (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  role_id      INT NOT NULL,
  event_kode   VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_role_event (role_id, event_kode),
  KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
