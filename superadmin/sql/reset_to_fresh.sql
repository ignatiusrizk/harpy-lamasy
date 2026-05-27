-- ══════════════════════════════════════════════════════════════
-- reset_to_fresh.sql — Kosongin semua data tenant untuk fresh trial
--
-- DIPERTAHANKAN (tidak dihapus):
--   ✅ super_admins          — akun login superadmin
--   ✅ saas_packages          — pilihan paket langganan
--   ✅ saas_coin_bundles      — pilihan bundle coin
--   ✅ saas_announcements     — pengumuman yang sudah dibuat
--
-- DIHAPUS (semua data operasional):
--   ❌ tenants, outlets, users, roles, permissions
--   ❌ orders, kas, layanan, pelanggan, promo, voucher
--   ❌ absensi, gaji, hr, karyawan assignments
--   ❌ payments, coin ledger, billing
--   ❌ support tickets, notes, announcement reads
--   ❌ broadcast, checklist, AI, drop points, dll
--
-- Cara pakai: Copy-paste ke phpMyAdmin → SQL tab → Execute
-- TRUNCATE otomatis reset AUTO_INCREMENT.
-- ══════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- ── Platform & Registration ──────────────────────────────────
TRUNCATE TABLE registration_requests;
TRUNCATE TABLE registration_attempts;
TRUNCATE TABLE email_verifications;
TRUNCATE TABLE onboarding_progress;
TRUNCATE TABLE superadmin_logs;

-- ── Tenant & Outlet ──────────────────────────────────────────
TRUNCATE TABLE outlets;
TRUNCATE TABLE tenants;

-- ── Users & Role/Permission (semua tenant-scoped) ────────────
TRUNCATE TABLE hl_role_permissions;
TRUNCATE TABLE hl_permissions;
TRUNCATE TABLE hl_roles;
TRUNCATE TABLE hl_users;
TRUNCATE TABLE hl_login_attempts;
TRUNCATE TABLE hl_rate_limits;

-- ── Karyawan assignments (jika tabel ada) ────────────────────
-- Tabel ini mungkin belum ada di semua install — skip jika error
SET @tbl = 'hl_karyawan_outlet';
SET @exists = (SELECT COUNT(*) FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = @tbl);
SET @sql = IF(@exists > 0, CONCAT('TRUNCATE TABLE `', @tbl, '`'), 'SELECT 1');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ── Operasional ──────────────────────────────────────────────
TRUNCATE TABLE hl_transaksi_item;
TRUNCATE TABLE hl_transaksi;
TRUNCATE TABLE hl_order_notes;
TRUNCATE TABLE hl_kas;
TRUNCATE TABLE hl_layanan;
TRUNCATE TABLE hl_layanan_master;

-- ── Pelanggan & CRM ──────────────────────────────────────────
TRUNCATE TABLE hl_pelanggan;
TRUNCATE TABLE hl_promo;
TRUNCATE TABLE hl_voucher;
TRUNCATE TABLE hl_poin_reward;
TRUNCATE TABLE hl_loyalty_log;
TRUNCATE TABLE hl_piutang;

-- ── HR ───────────────────────────────────────────────────────
TRUNCATE TABLE hl_absensi;
TRUNCATE TABLE hl_izin;
TRUNCATE TABLE hl_gaji;
TRUNCATE TABLE hl_komisi_rekap;
TRUNCATE TABLE hl_shift_handover;

-- ── Billing & Coin ───────────────────────────────────────────
TRUNCATE TABLE coin_ledger;
TRUNCATE TABLE payments;
TRUNCATE TABLE saas_manual_payments;

-- ── Support & Komunikasi ─────────────────────────────────────
TRUNCATE TABLE support_ticket_replies;
TRUNCATE TABLE support_tickets;
TRUNCATE TABLE tenant_notes;
TRUNCATE TABLE saas_announcement_reads;

-- ── Broadcast & Notifikasi ───────────────────────────────────
TRUNCATE TABLE hl_broadcast_recipient;
TRUNCATE TABLE hl_broadcast;
TRUNCATE TABLE hl_notif_log;

-- ── Checklist ────────────────────────────────────────────────
TRUNCATE TABLE hl_checklist_submission;
TRUNCATE TABLE hl_checklist_template;

-- ── Drop Points ──────────────────────────────────────────────
TRUNCATE TABLE hl_drop_points;

-- ── AI & Analytics ───────────────────────────────────────────
TRUNCATE TABLE hl_ai_cache;
TRUNCATE TABLE hl_ai_outreach_log;

-- ── Audit Log ────────────────────────────────────────────────
TRUNCATE TABLE hl_audit_log;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Verifikasi hasil ─────────────────────────────────────────
SELECT
  (SELECT COUNT(*) FROM super_admins)     AS superadmin_count,
  (SELECT COUNT(*) FROM saas_packages)    AS packages_count,
  (SELECT COUNT(*) FROM saas_coin_bundles) AS coin_bundles_count,
  (SELECT COUNT(*) FROM saas_announcements) AS announcements_count,
  (SELECT COUNT(*) FROM tenants)          AS tenants_count,      -- harus 0
  (SELECT COUNT(*) FROM hl_users)         AS users_count,        -- harus 0
  (SELECT COUNT(*) FROM coin_ledger)      AS coin_ledger_count;  -- harus 0
