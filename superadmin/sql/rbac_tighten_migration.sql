-- ════════════════════════════════════════════════════════════════════
-- Migration: tighten RBAC defaults
-- Tanggal: 2026-06-23
--
-- Konteks:
--   - karyawan.php sebelumnya map nama role custom → string enum 'superadmin'
--     kalau nama mengandung "owner"/"super", yang trigger wildcard bypass
--     permission table di TenantResolver::can() & loadPermissions().
--   - Admin default role grant terlalu permissive (hampir semua kecuali 3 excludes),
--     sehingga user yang di-assign default Admin punya akses CRUD layanan/promo/
--     inventori/mesin/bonus_rule meskipun owner berasumsi "admin scope terbatas".
--
-- Effect:
--   1. Revoke permission sensitif dari Admin role default di semua tenant
--   2. Sanitize hl_users.role yang nilainya 'superadmin' tapi role_id menunjuk ke
--      role bukan-Owner (artinya dapat dari karyawan.php mapping lama, BUKAN
--      tenant owner asli yang dibuat TenantProvisioner)
-- ════════════════════════════════════════════════════════════════════

START TRANSACTION;

-- ── Step 1: Revoke grant sensitif dari Admin role (per tenant) ───────
DELETE rp FROM hl_role_permissions rp
JOIN hl_roles r ON r.id = rp.role_id AND r.tenant_id = rp.tenant_id
JOIN hl_permissions p ON p.id = rp.permission_id AND p.tenant_id = rp.tenant_id
WHERE r.is_system = 1
  AND LOWER(TRIM(r.nama)) = 'admin'
  AND p.kode IN (
    'layanan.create', 'layanan.edit', 'layanan.delete',
    'promo.create', 'promo.delete',
    'inventori.manage', 'mesin.manage',
    'bonus_rule.manage'
  );

-- ── Step 2: Sanitize hl_users.role string ────────────────────────────
-- User dengan role='superadmin' string + role_id menunjuk ke Owner role asli
-- (is_system=1, nama='Owner') → KEEP ('superadmin' = tenant owner asli)
-- User dengan role='superadmin' string + role_id menunjuk ke role lain
-- (custom atau non-Owner) → DOWNGRADE ke 'staff' karena dapat dari
-- karyawan.php mapping lama yang sudah di-fix.
UPDATE hl_users u
LEFT JOIN hl_roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id
SET u.role = 'staff'
WHERE u.role = 'superadmin'
  AND (r.id IS NULL OR r.is_system != 1 OR LOWER(TRIM(r.nama)) != 'owner');

-- ── Step 3: Sanitize role='admin' yang seharusnya 'staff' ───────────
-- User dengan role='admin' string + role_id menunjuk ke role BUKAN Admin system
-- (artinya custom role yang mengandung kata "admin"/"manager" di nama lama)
-- → DOWNGRADE ke 'staff' agar permission cuma dari role_id table lookup.
UPDATE hl_users u
LEFT JOIN hl_roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id
SET u.role = 'staff'
WHERE u.role = 'admin'
  AND (r.id IS NULL OR r.is_system != 1 OR LOWER(TRIM(r.nama)) != 'admin');

COMMIT;

-- Audit query (jalankan setelah migration untuk verifikasi):
-- SELECT u.id, u.username, u.role, r.nama AS role_name, r.is_system
-- FROM hl_users u LEFT JOIN hl_roles r ON r.id=u.role_id
-- WHERE u.role IN ('superadmin','admin') ORDER BY u.tenant_id, u.id;
