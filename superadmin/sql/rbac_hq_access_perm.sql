-- F1 dual RBAC refactor — declare hq.access permission + backfill grants
-- Spec: docs/superpowers/specs/2026-06-24-f1-dual-rbac-refactor-design.md
-- Tanggal: 2026-06-24 WIB

START TRANSACTION;

-- 1. Declare hq.access permission per tenant (idempotent)
INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'hq.access', 'hq', 'access',
       'Akses halaman HQ view (konsolidasi multi-outlet)'
FROM tenants t
WHERE NOT EXISTS (
  SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='hq.access'
);

-- 2. Grant ke Owner system role (semua tenant)
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='owner'
  AND p.kode = 'hq.access';

-- 3. Heuristik backfill: role custom dengan nama Manager/Supervisor auto-grant
--    Owner bisa adjust manual via /hq/roles UI setelahnya
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE (LOWER(r.nama) LIKE '%manager%' OR LOWER(r.nama) LIKE '%supervisor%')
  AND p.kode = 'hq.access';

COMMIT;

-- Verify
SELECT t.id tenant_id, t.nama_perusahaan,
       (SELECT COUNT(*) FROM hl_permissions p
        WHERE p.tenant_id=t.id AND p.kode='hq.access') decl,
       (SELECT COUNT(*) FROM hl_role_permissions rp
        JOIN hl_permissions p2 ON p2.id=rp.permission_id
        WHERE rp.tenant_id=t.id AND p2.kode='hq.access') role_grants
FROM tenants t ORDER BY t.id;
