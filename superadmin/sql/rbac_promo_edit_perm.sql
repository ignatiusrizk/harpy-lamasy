-- ════════════════════════════════════════════════════════════════════
-- Migration: declare promo.edit permission + grant ke Owner
-- Tanggal: 2026-06-23
--
-- F5 fix: promo.php check `hasPermission('promo.edit')` tapi permission
-- ini tidak pernah declared di TenantProvisioner. Setelah F5 split logic
-- (create utk new, edit utk existing), perm ini harus exist supaya owner
-- bisa edit promo via UI.
-- Admin di-exclude (sudah di adminExclude di TenantProvisioner).
-- ════════════════════════════════════════════════════════════════════

START TRANSACTION;

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'promo.edit', 'promo', 'edit', 'Edit promo existing'
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='promo.edit');

-- Grant ke Owner (admin di-exclude)
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='owner'
  AND p.kode = 'promo.edit';

COMMIT;
