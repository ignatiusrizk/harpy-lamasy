-- Investor & Bagi Hasil
CREATE TABLE IF NOT EXISTS hl_investor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(100) NOT NULL,
  telepon VARCHAR(20) NULL,
  catatan TEXT NULL,
  scope ENUM('tenant','outlet') NOT NULL DEFAULT 'tenant',
  outlet_id INT NULL,
  modal_disetor BIGINT NOT NULL DEFAULT 0,
  persentase DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  joined_at DATE NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_scope (tenant_id, scope, outlet_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_bagi_hasil (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  investor_id INT NOT NULL,
  periode VARCHAR(7) NOT NULL,
  laba_basis BIGINT NOT NULL,
  persentase DECIMAL(5,2) NOT NULL,
  jumlah BIGINT NOT NULL,
  status ENUM('pending','dibayar') NOT NULL DEFAULT 'pending',
  kas_id INT NULL,
  jurnal_id INT NULL,
  dibayar_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_inv_periode (tenant_id, investor_id, periode),
  INDEX idx_periode (tenant_id, periode, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill permission investor.manage untuk tenant existing
-- Schema actual: hl_permissions(kode,modul,aksi,deskripsi), hl_role_permissions(role_id,permission_id,filter_data)
-- Role owner bernama 'Owner' (capital O), dapat semua permission
INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT id, 'investor.manage', 'investor', 'manage', 'Kelola investor & bagi hasil'
FROM tenants;

-- Map ke role Owner: join hl_permissions untuk dapat permission_id
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT p.tenant_id, r.id, p.id, 'all'
FROM hl_permissions p
JOIN hl_roles r ON r.tenant_id = p.tenant_id AND r.nama = 'Owner'
WHERE p.kode = 'investor.manage';
