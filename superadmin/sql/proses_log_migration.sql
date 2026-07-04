-- ══════════════════════════════════════════════════════════════
-- PROSES LOG MIGRATION — LAMASY
-- Jalankan via phpMyAdmin / run_proses_log.php satu kali.
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_proses_log (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id      INT          NULL,
  transaksi_id   INT          NOT NULL,
  status_lama    VARCHAR(50)  NULL,
  status_baru    VARCHAR(50)  NOT NULL,
  tipe           VARCHAR(50)  NULL DEFAULT 'manual',
  catatan        TEXT         NULL,
  oleh           VARCHAR(100) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_transaksi (transaksi_id),
  INDEX idx_tenant    (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
