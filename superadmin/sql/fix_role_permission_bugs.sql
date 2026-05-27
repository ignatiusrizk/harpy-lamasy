-- ══════════════════════════════════════════════════════════════
-- fix_role_permission_bugs.sql
--
-- Perbaiki 2 bug di sistem role & permission:
--
-- BUG 1: hl_role_permissions yang di-seed TenantProvisioner lama
--        tidak punya filter_data → muncul unchecked di settings UI
--        Fix: SET filter_data = 'all' WHERE filter_data IS NULL
--
-- BUG 2: trial_ends_at tenant yang dibuat via TenantProvisioner lama
--        di-set +30 hari, harusnya +7 hari (sesuai kode add-outlet.php)
--        Fix: UPDATE tenants yang masih trial, trial_ends_at dikoreksi
--        ke provisioned_at + 7 hari (jika lebih dari 7 hari dari sekarang)
--
-- Cara pakai: Jalankan 1x di phpMyAdmin → SQL tab → Execute
-- Aman dijalankan berulang (idempotent).
-- ══════════════════════════════════════════════════════════════

-- ── BUG 1: Isi filter_data NULL di hl_role_permissions ─────────
UPDATE hl_role_permissions
   SET filter_data = 'all'
 WHERE filter_data IS NULL;

SELECT ROW_COUNT() AS rows_fixed_filter_data;

-- ── BUG 2: Koreksi trial_ends_at tenant yang masih trial ───────
-- Hanya tenant yang trial_ends_at > NOW() + 7 hari (artinya set ke 30 hari lama)
-- Koreksi: set ke provisioned_at + 7 hari (batas maksimum wajar)
-- NOTE: Tenant yang sudah past trial atau sudah active tidak terpengaruh
UPDATE tenants
   SET trial_ends_at = DATE_ADD(provisioned_at, INTERVAL 7 DAY)
 WHERE status = 'trial'
   AND trial_ends_at > DATE_ADD(NOW(), INTERVAL 7 DAY)
   AND provisioned_at IS NOT NULL;

SELECT ROW_COUNT() AS rows_fixed_trial_duration;

-- ── Verifikasi hasil ────────────────────────────────────────────
SELECT
  (SELECT COUNT(*) FROM hl_role_permissions WHERE filter_data IS NULL) AS remaining_null_filter,   -- harus 0
  (SELECT COUNT(*) FROM tenants WHERE status='trial' AND trial_ends_at > DATE_ADD(NOW(), INTERVAL 7 DAY)) AS tenants_still_30d;  -- harus 0
