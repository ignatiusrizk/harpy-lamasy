-- ══════════════════════════════════════════════════════
-- Migration: Penghapusan Workflow (Approval-Based Delete)
-- Inspired by Smartlink — kasir submit hapus, owner approve.
-- Compliance + anti-fraud.
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_delete_request (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL,
  outlet_id       INT          NOT NULL,
  entity_type     ENUM('transaksi','kas','pelanggan') NOT NULL DEFAULT 'transaksi',
  entity_id       INT          NOT NULL,
  entity_snapshot TEXT         DEFAULT NULL  COMMENT 'JSON snapshot entity sebelum dihapus, utk audit',
  alasan          TEXT         NOT NULL,
  requested_by    INT          NOT NULL,
  requested_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  status          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  reviewed_by     INT          DEFAULT NULL,
  reviewed_at     TIMESTAMP    NULL DEFAULT NULL,
  review_note     TEXT         DEFAULT NULL,
  INDEX idx_tenant_status (tenant_id, status, requested_at),
  INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
