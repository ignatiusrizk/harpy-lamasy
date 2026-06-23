-- ════════════════════════════════════════════════════════════════
-- SA Activity Notifications — schema additions
-- Date: 2026-06-23
-- ════════════════════════════════════════════════════════════════

-- 1) Add email + notify_enabled ke super_admins
ALTER TABLE super_admins
  ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER name,
  ADD COLUMN notify_enabled TINYINT(1) DEFAULT 1 AFTER email;

-- Backfill Rizky email (ganti 'rizky' dengan username actual kalau beda)
UPDATE super_admins SET email = 'halo@harpy.id' WHERE username = 'rizky' AND email IS NULL;

-- 2) Throttle log untuk dedup 1-menit per event-type
CREATE TABLE IF NOT EXISTS saas_sa_notif_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  event_type  VARCHAR(50)  NOT NULL,
  ref_id      VARCHAR(100) DEFAULT NULL,
  subject     VARCHAR(255),
  recipients  TEXT,
  sent_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_throttle (event_type, sent_at),
  INDEX idx_ref (ref_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verify
SELECT 'super_admins schema' AS check_step, COUNT(*) AS rows_with_email
  FROM super_admins WHERE email IS NOT NULL
UNION ALL
SELECT 'notif log table', (SELECT COUNT(*) FROM saas_sa_notif_log);
