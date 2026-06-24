-- Custom Payment Methods per Outlet — Phase 1
-- Tambah table hl_payment_methods + backfill 3 default rows untuk semua outlet existing.

CREATE TABLE IF NOT EXISTS hl_payment_methods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  code VARCHAR(30) NOT NULL,
  label VARCHAR(50) NOT NULL,
  emoji VARCHAR(8) DEFAULT '💳',
  is_builtin TINYINT(1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  sort_order INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_outlet_code (tenant_id, outlet_id, code),
  INDEX idx_outlet_active (outlet_id, is_active, sort_order)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: seed 3 built-in methods untuk semua outlet existing.
-- INSERT IGNORE skip outlets yang sudah punya (idempotent re-run safe via UNIQUE constraint).
INSERT IGNORE INTO hl_payment_methods (tenant_id, outlet_id, code, label, emoji, is_builtin, sort_order)
SELECT o.tenant_id, o.id, 'cash',     'Tunai',         '💵', 1, 1 FROM outlets o
UNION ALL
SELECT o.tenant_id, o.id, 'transfer', 'Transfer Bank', '🏦', 1, 2 FROM outlets o
UNION ALL
SELECT o.tenant_id, o.id, 'qris',     'QRIS',          '📱', 1, 3 FROM outlets o;
