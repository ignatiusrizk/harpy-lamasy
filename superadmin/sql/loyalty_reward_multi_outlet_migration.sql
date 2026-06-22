-- superadmin/sql/loyalty_reward_multi_outlet_migration.sql
-- Junction reward↔outlet untuk multi-outlet targeting

CREATE TABLE IF NOT EXISTS hl_poin_reward_outlet (
  reward_id INT NOT NULL,
  outlet_id INT NOT NULL,
  PRIMARY KEY (reward_id, outlet_id),
  INDEX idx_outlet (outlet_id),
  FOREIGN KEY (reward_id) REFERENCES hl_poin_reward(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- outlet_id legacy → nullable (logic baru pakai junction)
ALTER TABLE hl_poin_reward MODIFY outlet_id INT NULL;

-- Backfill: tiap existing reward → 1 junction row dengan outlet existing
INSERT IGNORE INTO hl_poin_reward_outlet (reward_id, outlet_id)
SELECT id, outlet_id FROM hl_poin_reward WHERE outlet_id IS NOT NULL;
