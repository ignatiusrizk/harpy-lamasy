-- ════════════════════════════════════════════════════════════════════
-- Migration: declare export.data permission + grant ke Owner
-- Tanggal: 2026-06-23
--
-- Permission baru untuk feature /hq/export (Self-serve Export Data).
-- Owner-only by default karena export = data exfiltration risk.
-- Admin TIDAK dapat (sudah di-exclude di TenantProvisioner adminExclude).
-- Owner bisa grant manual ke role lain via /hq/roles UI kalau perlu.
-- ════════════════════════════════════════════════════════════════════

START TRANSACTION;

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'export.data', 'export', 'data',
       'Download data tenant (orders, customers, gaji, dll) ke ZIP CSV'
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='export.data');

-- Grant ke Owner role
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='owner'
  AND p.kode = 'export.data';

COMMIT;

-- Expected post-migration: Owner perm_count +1 (52 dari 51)
