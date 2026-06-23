-- ════════════════════════════════════════════════════════════════════
-- Migration: tambah keuangan.view + keuangan.edit permission
-- Tanggal: 2026-06-23
--
-- Konteks: F2 fix mengubah hq_guard::requirePermission supaya konsultasi
-- tabel hl_role_permissions (bukan lagi suffix-only). hq/keuangan.php
-- check `requirePermission('keuangan.view')` dan `keuangan.edit` tapi
-- 2 permission ini tidak pernah declared di seed → setelah F2 fix
-- non-owner akan kena 403 di /hq/keuangan.
--
-- Migration ini:
--   1. INSERT IGNORE 2 permission baru ke tiap tenant
--   2. Grant ke Owner (semua), Admin (cuma keuangan.view)
-- ════════════════════════════════════════════════════════════════════

START TRANSACTION;

-- Step 1: tambah ke hl_permissions per tenant
INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'keuangan.view', 'keuangan', 'view', 'Lihat data keuangan formal (aset, pinjaman, kas bank, jurnal)'
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='keuangan.view');

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'keuangan.edit', 'keuangan', 'edit', 'Kelola data keuangan formal (HQ)'
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='keuangan.edit');

-- Step 2: grant ke Owner (kedua perm)
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='owner'
  AND p.kode IN ('keuangan.view','keuangan.edit');

-- Step 3: grant ke Admin (keuangan.view only — sensitif jadi tidak edit)
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='admin'
  AND p.kode = 'keuangan.view';

COMMIT;

-- Verifikasi:
-- SELECT r.nama, COUNT(rp.id) perm_count FROM hl_roles r
-- LEFT JOIN hl_role_permissions rp ON rp.role_id=r.id
-- WHERE r.tenant_id=1 GROUP BY r.id, r.nama;
-- Expected: Owner 51, Admin 38, lainnya unchanged.
