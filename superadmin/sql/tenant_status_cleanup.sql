-- ══════════════════════════════════════════════════════
-- tenant_status_cleanup.sql
-- Hapus 'trial' dari tenants.status enum
-- Trial adalah konsep outlet, bukan tenant
-- ══════════════════════════════════════════════════════

-- 1. Migrate data: tenant dengan status='trial' → 'active'
UPDATE tenants
SET status = 'active'
WHERE status = 'trial';

-- 2. Alter enum: hilangkan 'trial' dari pilihan
ALTER TABLE tenants
  MODIFY COLUMN status ENUM('pending_verification','active','suspended','closed')
  DEFAULT 'pending_verification';

-- 3. Verifikasi
SELECT status, COUNT(*) as total FROM tenants GROUP BY status;
