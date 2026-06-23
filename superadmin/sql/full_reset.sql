-- ════════════════════════════════════════════════════════════════
-- FULL DATA RESET — LAMASY Production
--
-- ⚠️  HIGHLY DESTRUCTIVE — IRREVERSIBLE
-- ⚠️  BACKUP DULU sebelum jalan: phpMyAdmin → Export → SQL → Save
--
-- Tujuan: kosongkan semua data tenant + customer.
-- Sisa: super_admins (Rizky), catalog SaaS (packages/pricing/banners/tips).
--
-- Cara run di phpMyAdmin:
--   1. Buka DB u269895997_harpy_master → tab SQL
--   2. Paste isi file ini
--   3. Delimiter field di bawah biarkan default ";"
--   4. Klik Go
--
-- (Script ini pakai DELIMITER directive internal, phpMyAdmin handles it)
-- ════════════════════════════════════════════════════════════════

-- ── PRE-FLIGHT CHECK (opsional, run separately dulu) ───────────
-- SELECT id, username, name FROM super_admins;
--   Catat username Rizky. Kalau bukan 'rizky', edit baris DELETE di bawah.

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_SAFE_UPDATES = 0;

-- ════════════════════════════════════════════════════════════════
-- Stored procedure: TRUNCATE semua tabel yang masuk daftar wipe
-- Auto-skip kalau tabel ga ada (handler 1146)
-- ════════════════════════════════════════════════════════════════
DROP PROCEDURE IF EXISTS lamasy_full_reset;

DELIMITER //

CREATE PROCEDURE lamasy_full_reset()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE tname VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT table_name FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND (
        (table_name LIKE 'hl\_%' AND table_name <> 'hl_splash_tips')
        OR table_name IN (
          'outlets','tenants','coin_ledger','payments',
          'email_verifications','registration_attempts',
          'registration_requests','onboarding_progress',
          'saas_error_log','saas_wa_log','saas_impersonation_log',
          'saas_manual_payments','saas_announcement_reads',
          'superadmin_logs','support_tickets',
          'support_ticket_replies','tenant_notes'
        )
      );
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  -- Force FK off di session level dalam procedure scope
  SET FOREIGN_KEY_CHECKS = 0;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO tname;
    IF done THEN LEAVE read_loop; END IF;
    -- DELETE FROM (bukan TRUNCATE) — TRUNCATE strict di FK relations
    -- meski FK_CHECKS=0. DELETE works dengan FK_CHECKS=0.
    SET @s = CONCAT('DELETE FROM `', tname, '`');
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
    -- Reset AUTO_INCREMENT supaya ID balik dari 1
    SET @s2 = CONCAT('ALTER TABLE `', tname, '` AUTO_INCREMENT = 1');
    PREPARE stmt2 FROM @s2;
    EXECUTE stmt2;
    DEALLOCATE PREPARE stmt2;
  END LOOP;
  CLOSE cur;

  SET FOREIGN_KEY_CHECKS = 1;
END //

DELIMITER ;

CALL lamasy_full_reset();
DROP PROCEDURE lamasy_full_reset;

-- ════════════════════════════════════════════════════════════════
-- KELOMPOK 4 — Super admin cleanup
-- HATI-HATI: edit username 'rizky' kalau username asli berbeda
-- ════════════════════════════════════════════════════════════════
DELETE FROM super_admins WHERE username NOT IN ('rizky');

-- ════════════════════════════════════════════════════════════════
-- TIDAK DI-TRUNCATE (sengaja — catalog/master data):
--   ✓ super_admins (Rizky)
--   ✓ saas_packages              (subscription packages)
--   ✓ saas_coin_pricing          (pricing fitur per coin)
--   ✓ saas_coin_pricing_history  (audit history pricing)
--   ✓ saas_coin_bundles          (bundle coin packages)
--   ✓ saas_announcements         (superadmin announcements catalog)
--   ✓ saas_banners               (superadmin banners catalog)
--   ✓ saas_platform_health       (historical platform metrics)
--   ✓ hl_splash_tips             (global tips catalog superadmin-managed)
-- ════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 1;
SET SQL_SAFE_UPDATES = 1;

-- ── VERIFY HASIL ───────────────────────────────────────────────
SELECT 'super_admins' AS tabel, COUNT(*) AS sisa FROM super_admins
UNION ALL SELECT 'tenants',       COUNT(*) FROM tenants
UNION ALL SELECT 'outlets',       COUNT(*) FROM outlets
UNION ALL SELECT 'hl_users',      COUNT(*) FROM hl_users
UNION ALL SELECT 'hl_pelanggan',  COUNT(*) FROM hl_pelanggan
UNION ALL SELECT 'hl_transaksi',  COUNT(*) FROM hl_transaksi
UNION ALL SELECT 'saas_packages (keep)',    COUNT(*) FROM saas_packages
UNION ALL SELECT 'saas_coin_pricing (keep)', COUNT(*) FROM saas_coin_pricing
UNION ALL SELECT 'hl_splash_tips (keep)',   COUNT(*) FROM hl_splash_tips;

-- Expected output:
--   super_admins        = 1 (Rizky)
--   tenants/outlets/hl_* (data tables) = 0
--   saas_packages/coin_pricing/splash_tips = >0 (catalog tetap)
