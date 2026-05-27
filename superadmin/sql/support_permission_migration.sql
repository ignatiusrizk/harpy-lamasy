-- ══════════════════════════════════════════════════════════
-- support_permission_migration.sql
-- Tambah modul 'bantuan' ke sistem role & permission
--
-- Perubahan:
--   1. INSERT IGNORE bantuan.* ke hl_permissions (per tenant)
--   2. Assign ke owner, admin, kasir, karyawan system roles
--
-- Jalankan sekali di phpMyAdmin.
-- ══════════════════════════════════════════════════════════

-- ── Step 1: Tambah permissions untuk SEMUA tenant yang ada ──
-- Menggunakan CROSS JOIN tenants × permission rows
-- INSERT IGNORE aman dijalankan ulang (UNIQUE KEY kode)

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, v.kode, v.modul, v.aksi, v.deskripsi
FROM tenants t
CROSS JOIN (
  SELECT 'bantuan.view'   AS kode, 'bantuan' AS modul, 'view'   AS aksi, 'Akses halaman Support & Tiket'  AS deskripsi UNION ALL
  SELECT 'bantuan.submit', 'bantuan', 'submit', 'Kirim tiket support baru' UNION ALL
  SELECT 'bantuan.reply',  'bantuan', 'reply',  'Balas tiket support' UNION ALL
  SELECT 'bantuan.close',  'bantuan', 'close',  'Tutup & beri rating tiket'
) v;

-- ── Step 2: Assign ke Owner system role (semua permission) ──
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id)
SELECT r.tenant_id, r.id, p.id
FROM hl_roles r
JOIN hl_permissions p
  ON p.tenant_id = r.tenant_id AND p.modul = 'bantuan'
WHERE r.nama = 'Owner' AND r.is_system = 1;

-- ── Step 3: Assign ke Admin system role (semua) ──
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id)
SELECT r.tenant_id, r.id, p.id
FROM hl_roles r
JOIN hl_permissions p
  ON p.tenant_id = r.tenant_id AND p.modul = 'bantuan'
WHERE r.nama = 'Admin' AND r.is_system = 1;

-- ── Step 4: Assign ke Kasir (view + submit + reply + close) ──
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id)
SELECT r.tenant_id, r.id, p.id
FROM hl_roles r
JOIN hl_permissions p
  ON p.tenant_id = r.tenant_id
  AND p.kode IN ('bantuan.view','bantuan.submit','bantuan.reply','bantuan.close')
WHERE r.nama = 'Kasir' AND r.is_system = 1;

-- ── Step 5: Assign ke Karyawan (view + submit + reply + close) ──
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id)
SELECT r.tenant_id, r.id, p.id
FROM hl_roles r
JOIN hl_permissions p
  ON p.tenant_id = r.tenant_id
  AND p.kode IN ('bantuan.view','bantuan.submit','bantuan.reply','bantuan.close')
WHERE r.nama = 'Karyawan' AND r.is_system = 1;

-- ── Verifikasi ──────────────────────────────────────────────
SELECT 'Permissions added:' AS info, COUNT(*) AS cnt
FROM hl_permissions WHERE modul = 'bantuan';

SELECT 'Role mappings added:' AS info, COUNT(*) AS cnt
FROM hl_role_permissions rp
JOIN hl_permissions p ON p.id = rp.permission_id
WHERE p.modul = 'bantuan';
