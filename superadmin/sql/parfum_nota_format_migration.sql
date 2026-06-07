-- ══════════════════════════════════════════════════════
-- Migration: Parfum field + Custom Nota Number Format
-- Inspired by Smartlink:
-- - "Parfum: parfum laundry" field di Pengaturan Transaksi
-- - "Nomor Nota Premium" — custom format prefix/suffix
-- ══════════════════════════════════════════════════════

-- 1. Parfum field per nota
ALTER TABLE hl_transaksi
  ADD COLUMN IF NOT EXISTS parfum VARCHAR(50) DEFAULT NULL
    COMMENT 'Pilihan parfum/pewangi cucian (mis. Lavender, Rose, Original)'
    AFTER catatan;

-- 2. Tenant settings utk format nota:
--    nota_prefix: prefix custom (default "HL-")
--    nota_format: template format (default "{PREFIX}{YYMMDD}-{COUNTER:3}")
--                 {PREFIX} {YYYY} {YY} {YYMMDD} {YYYYMMDD} {COUNTER:N} {OUTLET}
ALTER TABLE saas_tenants
  ADD COLUMN IF NOT EXISTS nota_prefix VARCHAR(20) DEFAULT 'HL-'
    COMMENT 'Prefix nota custom per tenant',
  ADD COLUMN IF NOT EXISTS nota_format VARCHAR(60) DEFAULT '{PREFIX}{YYMMDD}-{COUNTER:3}'
    COMMENT 'Template format nota. Token: {PREFIX} {YYMMDD} {COUNTER:N} {OUTLET}';

-- Master parfum (opsional — kalau tenant mau dropdown standar)
CREATE TABLE IF NOT EXISTS hl_parfum (
  id          INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT          NOT NULL,
  outlet_id   INT          DEFAULT NULL,
  nama        VARCHAR(50)  NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  urutan      INT          DEFAULT 0,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tenant_parfum (tenant_id, nama),
  INDEX idx_tenant_active (tenant_id, is_active, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
