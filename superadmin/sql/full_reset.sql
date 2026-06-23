-- ════════════════════════════════════════════════════════════════
-- FULL DATA RESET — LAMASY Production
--
-- ⚠️  HIGHLY DESTRUCTIVE — IRREVERSIBLE
-- ⚠️  BACKUP DULU sebelum jalan: phpMyAdmin → Export → SQL → Save
--
-- Tujuan: kosongkan semua data tenant + customer.
-- Sisa: super_admins (Rizky), catalog SaaS (packages/pricing/banners/tips).
--
-- Cara run:
--   1. phpMyAdmin → pilih DB u269895997_harpy_master
--   2. Tab "SQL" → paste isi file ini → "Go"
--   3. Cek hasilnya: super_admins masih ada (Rizky), tabel lain kosong
--
-- Setelah jalan:
--   - Login tenant TIDAK BISA (semua hl_users hilang)
--   - Login SuperAdmin di /superadmin/login.php (Rizky tetap)
--   - Tenant baru bisa di-onboard via /superadmin/onboarding.php
-- ════════════════════════════════════════════════════════════════

-- ── PRE-FLIGHT CHECK ────────────────────────────────────────────
-- Pastikan Rizky ada sebelum delete super admin lain:
-- SELECT * FROM super_admins;
--
-- Kalau username Rizky bukan 'rizky', edit baris DELETE FROM super_admins di bawah.

-- ── DISABLE FK CHECKS ──────────────────────────────────────────
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_SAFE_UPDATES = 0;

-- ════════════════════════════════════════════════════════════════
-- KELOMPOK 1 — Tenant data (semua hl_* kecuali splash_tips)
-- ════════════════════════════════════════════════════════════════
TRUNCATE TABLE hl_absensi;
TRUNCATE TABLE hl_ai_cache;
TRUNCATE TABLE hl_ai_outreach_log;
TRUNCATE TABLE hl_ai_usage;
TRUNCATE TABLE hl_antar_jemput;
TRUNCATE TABLE hl_aset_tetap;
TRUNCATE TABLE hl_audit_log;
TRUNCATE TABLE hl_bahan;
TRUNCATE TABLE hl_bahan_mutasi;
TRUNCATE TABLE hl_bonus_rule;
TRUNCATE TABLE hl_bonus_rule_outlet;
TRUNCATE TABLE hl_broadcast;
TRUNCATE TABLE hl_broadcast_recipient;
TRUNCATE TABLE hl_checklist_submission;
TRUNCATE TABLE hl_checklist_template;
TRUNCATE TABLE hl_coa;
TRUNCATE TABLE hl_delete_request;
TRUNCATE TABLE hl_deposit_bonus_tier;
TRUNCATE TABLE hl_deposit_refund;
TRUNCATE TABLE hl_deposit_topup;
TRUNCATE TABLE hl_deposit_usage;
TRUNCATE TABLE hl_drop_points;
TRUNCATE TABLE hl_express_tier;
TRUNCATE TABLE hl_gaji;
TRUNCATE TABLE hl_gaji_komponen;
TRUNCATE TABLE hl_izin;
TRUNCATE TABLE hl_jurnal_manual;
TRUNCATE TABLE hl_karyawan_outlet;
TRUNCATE TABLE hl_kas;
TRUNCATE TABLE hl_kas_bank;
TRUNCATE TABLE hl_kas_bank_mutasi;
TRUNCATE TABLE hl_komisi_rekap;
TRUNCATE TABLE hl_kurir;
TRUNCATE TABLE hl_laporan_cache;
TRUNCATE TABLE hl_layanan;
TRUNCATE TABLE hl_layanan_express_tier;
TRUNCATE TABLE hl_layanan_master;
TRUNCATE TABLE hl_liabilitas;
TRUNCATE TABLE hl_login_attempts;
TRUNCATE TABLE hl_loyalty_log;
TRUNCATE TABLE hl_member_tier;
TRUNCATE TABLE hl_mesin;
TRUNCATE TABLE hl_mesin_cycle;
TRUNCATE TABLE hl_mesin_sesi;
TRUNCATE TABLE hl_migration_jobs;
TRUNCATE TABLE hl_migration_mapping_templates;
TRUNCATE TABLE hl_notif_log;
TRUNCATE TABLE hl_order_notes;
TRUNCATE TABLE hl_parfum;
TRUNCATE TABLE hl_pelanggan;
TRUNCATE TABLE hl_pelanggan_member;
TRUNCATE TABLE hl_permissions;
TRUNCATE TABLE hl_piutang;
TRUNCATE TABLE hl_poin_reward;
TRUNCATE TABLE hl_poin_reward_outlet;
TRUNCATE TABLE hl_promo;
TRUNCATE TABLE hl_proses_input;
TRUNCATE TABLE hl_proses_log;
TRUNCATE TABLE hl_rate_limits;
TRUNCATE TABLE hl_role_permissions;
TRUNCATE TABLE hl_roles;
TRUNCATE TABLE hl_shift_handover;
TRUNCATE TABLE hl_splash_seen;
TRUNCATE TABLE hl_transaksi;
TRUNCATE TABLE hl_transaksi_item;
TRUNCATE TABLE hl_users;
TRUNCATE TABLE hl_voucher;
TRUNCATE TABLE hl_zona_antar;

-- ════════════════════════════════════════════════════════════════
-- KELOMPOK 2 — Tenant container + registrasi
-- ════════════════════════════════════════════════════════════════
TRUNCATE TABLE outlets;
TRUNCATE TABLE tenants;
TRUNCATE TABLE coin_ledger;
TRUNCATE TABLE payments;
TRUNCATE TABLE email_verifications;
TRUNCATE TABLE registration_attempts;
TRUNCATE TABLE registration_requests;
TRUNCATE TABLE onboarding_progress;

-- ════════════════════════════════════════════════════════════════
-- KELOMPOK 3 — SaaS logs (fresh slate)
-- ════════════════════════════════════════════════════════════════
TRUNCATE TABLE saas_error_log;
TRUNCATE TABLE saas_wa_log;
TRUNCATE TABLE saas_impersonation_log;
TRUNCATE TABLE saas_manual_payments;
TRUNCATE TABLE saas_announcement_reads;
TRUNCATE TABLE superadmin_logs;
TRUNCATE TABLE support_tickets;
TRUNCATE TABLE support_ticket_replies;
TRUNCATE TABLE tenant_notes;

-- ════════════════════════════════════════════════════════════════
-- KELOMPOK 4 — Super admin cleanup (HATI-HATI)
-- ════════════════════════════════════════════════════════════════
-- Hanya Rizky yang tetap. Edit username 'rizky' kalau username asli berbeda.
-- (Cek dulu: SELECT id, username, name FROM super_admins;)
DELETE FROM super_admins WHERE username NOT IN ('rizky');

-- ════════════════════════════════════════════════════════════════
-- ⚠️  TIDAK DI-TRUNCATE (sengaja — catalog/master data):
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

-- ── RE-ENABLE FK CHECKS ────────────────────────────────────────
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
