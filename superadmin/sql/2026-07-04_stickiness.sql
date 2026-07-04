-- Stickiness Score (Strategi #5) — applied ke prod 2026-07-04.
CREATE TABLE IF NOT EXISTS hl_stickiness_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant_event (tenant_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_stickiness_score (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  tanggal DATE NOT NULL,
  score TINYINT NOT NULL DEFAULT 0,
  data_ada TINYINT(1) DEFAULT 0,
  laporan_dilihat TINYINT(1) DEFAULT 0,
  portal_token TINYINT(1) DEFAULT 0,
  staf_aktif TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_tenant_date (tenant_id, tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
