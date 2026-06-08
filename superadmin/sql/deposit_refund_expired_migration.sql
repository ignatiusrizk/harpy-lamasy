-- ══════════════════════════════════════════════════════
-- Migration: Deposit Refund Workflow + Saldo Expired
--
-- 2 fitur lanjutan deposit wallet:
-- 1. Refund flow: customer minta saldo balik jadi cash, owner approve
-- 2. Expired: bonus saldo punya masa berlaku (mis. 6 bulan)
-- ══════════════════════════════════════════════════════

-- ── Tambah kolom expired_at di topup ──
-- saat user topup, sistem catat expired_at = created_at + N hari
-- (N dari tenant config). Cron job set saldo expired & catat di usage.
ALTER TABLE hl_deposit_topup
  ADD COLUMN IF NOT EXISTS expired_at DATE DEFAULT NULL
    COMMENT 'NULL = tidak expire. Bonus saldo bisa expire (cron job).'
    AFTER bukti_bayar,
  ADD INDEX IF NOT EXISTS idx_expired (expired_at);

-- ── Tabel refund request (mengikuti pola approval inbox) ──
CREATE TABLE IF NOT EXISTS hl_deposit_refund (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL,
  outlet_id       INT          NOT NULL,
  pelanggan_id    INT          NOT NULL,
  jumlah_refund   DECIMAL(12,2) NOT NULL,
  metode_refund   VARCHAR(30)  NOT NULL DEFAULT 'cash',
  alasan          TEXT         NOT NULL,
  saldo_sebelum   DECIMAL(12,2) NOT NULL DEFAULT 0,
  status          ENUM('pending','approved','rejected','executed') NOT NULL DEFAULT 'pending',
  requested_by    INT          NOT NULL,
  requested_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  reviewed_by     INT          DEFAULT NULL,
  reviewed_at     TIMESTAMP    NULL DEFAULT NULL,
  review_note     TEXT         DEFAULT NULL,
  usage_id        INT          DEFAULT NULL  COMMENT 'Link ke hl_deposit_usage saat approved',
  INDEX idx_tenant_status (tenant_id, status, requested_at),
  INDEX idx_pelanggan (pelanggan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Setting: durasi expired default per tenant (di outlets atau saas_tenants) ──
-- Simpan di outlets supaya per-outlet
ALTER TABLE outlets
  ADD COLUMN IF NOT EXISTS deposit_expired_hari INT NOT NULL DEFAULT 0
    COMMENT '0 = tidak expire, >0 = saldo expire N hari setelah topup';
