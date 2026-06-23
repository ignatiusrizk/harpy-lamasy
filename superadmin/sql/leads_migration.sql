-- Lead capture dari exit-intent modal landing page
CREATE TABLE IF NOT EXISTS hl_leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  source VARCHAR(50) DEFAULT 'exit_intent',
  user_agent VARCHAR(500),
  ip_address VARCHAR(45),
  referrer VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
