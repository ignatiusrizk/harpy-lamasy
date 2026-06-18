-- ══════════════════════════════════════════════════════
-- Backfill: inventori permissions untuk tenant existing
--
-- Setelah inventori_bahan_migration.sql dijalankan, tambahkan
-- permission inventori.view & inventori.manage ke semua tenant
-- yang sudah ada (Owner + Admin dapet).
--
-- Untuk tenant baru, permission ini akan di-seed otomatis lewat
-- TenantProvisioner (task #10).
-- ══════════════════════════════════════════════════════

-- 1. Insert permissions untuk tiap tenant existing
INSERT INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT DISTINCT t.tenant_id, 'inventori.view', 'inventori', 'view', 'Lihat stok & riwayat bahan baku'
FROM hl_permissions t
WHERE NOT EXISTS (
  SELECT 1 FROM hl_permissions p
  WHERE p.tenant_id = t.tenant_id AND p.kode = 'inventori.view'
);

INSERT INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT DISTINCT t.tenant_id, 'inventori.manage', 'inventori', 'manage', 'Tambah/edit/hapus bahan & input mutasi stok'
FROM hl_permissions t
WHERE NOT EXISTS (
  SELECT 1 FROM hl_permissions p
  WHERE p.tenant_id = t.tenant_id AND p.kode = 'inventori.manage'
);

-- 2. Map ke role Owner (semua tenant) — semua akses
INSERT INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT p.tenant_id, r.id, p.id, 'all'
FROM hl_permissions p
JOIN hl_roles r ON r.tenant_id = p.tenant_id AND r.nama = 'Owner'
WHERE p.kode IN ('inventori.view','inventori.manage')
  AND NOT EXISTS (
    SELECT 1 FROM hl_role_permissions rp
    WHERE rp.tenant_id = p.tenant_id AND rp.role_id = r.id AND rp.permission_id = p.id
  );

-- 3. Map ke role Admin (semua tenant) — semua akses
INSERT INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT p.tenant_id, r.id, p.id, 'all'
FROM hl_permissions p
JOIN hl_roles r ON r.tenant_id = p.tenant_id AND r.nama = 'Admin'
WHERE p.kode IN ('inventori.view','inventori.manage')
  AND NOT EXISTS (
    SELECT 1 FROM hl_role_permissions rp
    WHERE rp.tenant_id = p.tenant_id AND rp.role_id = r.id AND rp.permission_id = p.id
  );

-- 4. Verify
-- SELECT tenant_id, kode FROM hl_permissions WHERE kode LIKE 'inventori%' ORDER BY tenant_id, kode;
