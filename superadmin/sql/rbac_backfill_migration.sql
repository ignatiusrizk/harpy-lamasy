-- ════════════════════════════════════════════════════════════════════
-- Migration: backfill RBAC + dedupe Kurir role
-- Tanggal: 2026-06-23
--
-- Konteks: TenantProvisioner cuma jalan saat tenant baru. Permission yang
-- ditambah setelah itu (bonus_rule.manage, antar.view, antar.manage,
-- antar.kurir, produksi.work) tidak otomatis ke existing tenants. Plus
-- ada duplicate row Kurir di hl_roles dari migrasi antar-jemput yang
-- re-seed tanpa idempotency check.
--
-- Effect:
--   1. Tambah row hl_permissions baru per tenant kalau belum ada (5 perm)
--   2. Grant ke role yang sesuai per seed pattern
--   3. Dedupe duplicate Kurir role (keep lowest id), move users + permissions
-- ════════════════════════════════════════════════════════════════════

START TRANSACTION;

-- ── Step 1: Tambah permission yang missing ke hl_permissions ─────────
-- Idempotent: INSERT IGNORE pakai composite key tenant_id + kode

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'bonus_rule.manage', 'bonus_rule', 'manage', 'Kelola master bonus & penalti rule (HQ)'
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='bonus_rule.manage');

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'antar.view', 'antar', 'view', 'Lihat list antar jemput & report'
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='antar.view');

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'antar.manage', 'antar', 'manage', 'Create antar jemput, assign kurir, kelola master'
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='antar.manage');

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'antar.kurir', 'antar', 'kurir', 'Akses /kurir mobile (untuk role kurir)'
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='antar.kurir');

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'produksi.work', 'produksi', 'work', 'Akses /produksi & update stage order'
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='produksi.work');

-- ── Step 2: Grant ke Owner role (semua 5 perm) ───────────────────────
-- Owner system role dapat semua permission.
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='owner'
  AND p.kode IN ('bonus_rule.manage','antar.view','antar.manage','antar.kurir','produksi.work');

-- ── Step 3: Grant ke Admin role (post-tightening: antar.view, antar.manage, produksi.work)
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='admin'
  AND p.kode IN ('antar.view','antar.manage','produksi.work');

-- ── Step 4: Grant ke Kasir role (antar.view, produksi.work) ─────────
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='kasir'
  AND p.kode IN ('antar.view','produksi.work');

-- ── Step 5: Grant ke Karyawan role (antar.view, produksi.work) ──────
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='karyawan'
  AND p.kode IN ('antar.view','produksi.work');

-- ── Step 6: Dedupe Kurir roles + grant antar.kurir ──────────────────
-- Untuk tiap tenant yang punya >1 Kurir role: pindahkan user assignment
-- ke Kurir dengan id terkecil, lalu hapus row Kurir duplicate.

-- 6a. Build map tenant → keep_id (lowest Kurir id)
DROP TEMPORARY TABLE IF EXISTS tmp_kurir_keep;
CREATE TEMPORARY TABLE tmp_kurir_keep AS
SELECT tenant_id, MIN(id) AS keep_id
FROM hl_roles
WHERE is_system=1 AND LOWER(TRIM(nama))='kurir'
GROUP BY tenant_id;

-- 6b. Pindahkan user role_id dari Kurir duplicate ke keep_id
UPDATE hl_users u
JOIN hl_roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id
JOIN tmp_kurir_keep k ON k.tenant_id=u.tenant_id
SET u.role_id = k.keep_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='kurir'
  AND r.id != k.keep_id;

-- 6c. Hapus role_permissions untuk Kurir duplicate (akan di-grant ulang ke keep_id)
DELETE rp FROM hl_role_permissions rp
JOIN hl_roles r ON r.id=rp.role_id AND r.tenant_id=rp.tenant_id
JOIN tmp_kurir_keep k ON k.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='kurir'
  AND r.id != k.keep_id;

-- 6d. Hapus row Kurir duplicate
DELETE r FROM hl_roles r
JOIN tmp_kurir_keep k ON k.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='kurir'
  AND r.id != k.keep_id;

-- 6e. Grant antar.kurir ke Kurir role yang tersisa
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='kurir'
  AND p.kode = 'antar.kurir';

DROP TEMPORARY TABLE IF EXISTS tmp_kurir_keep;

COMMIT;

-- ════════════════════════════════════════════════════════════════════
-- Verifikasi setelah jalan migration:
-- SELECT r.nama, COUNT(rp.id) perm_count FROM hl_roles r
-- LEFT JOIN hl_role_permissions rp ON rp.role_id=r.id
-- WHERE r.tenant_id=1 GROUP BY r.id, r.nama;
--
-- Expected:
--   Owner    49 (was 44)
--   Admin    37 (was 34 → +3: antar.view, antar.manage, produksi.work)
--   Kasir    19 (was 17 → +2: antar.view, produksi.work)
--   Karyawan 10 (was 8  → +2: antar.view, produksi.work)
--   Kurir    1  (was 0  → +1: antar.kurir) — single row, duplicate dropped
-- ════════════════════════════════════════════════════════════════════
