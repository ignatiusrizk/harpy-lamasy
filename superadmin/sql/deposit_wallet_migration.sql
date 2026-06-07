-- ══════════════════════════════════════════════════════
-- Migration: Top Up Deposit (Prepaid Wallet Customer)
--
-- Inspired by Smartlink "Top Up Deposit" — customer top up saldo dulu,
-- nanti bayar nota pakai saldo (atau hybrid: deposit + cash).
--
-- Benefit:
-- - Customer: harga lebih murah lewat bonus topup (Topup 100k → +10k)
-- - Tenant: cashflow di muka, customer lock-in, retention naik
--
-- Schema:
-- - hl_pelanggan.saldo_deposit: balance current
-- - hl_deposit_topup: history topup (audit trail)
-- - hl_deposit_usage: history pemakaian (link ke transaksi)
-- - hl_deposit_bonus_tier: konfigurasi bonus per range topup
-- ══════════════════════════════════════════════════════

-- 1. Saldo column di pelanggan
ALTER TABLE hl_pelanggan
  ADD COLUMN IF NOT EXISTS saldo_deposit DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'Saldo deposit/wallet customer'
    AFTER catatan;

-- 2. History topup (kredit ke saldo)
CREATE TABLE IF NOT EXISTS hl_deposit_topup (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL,
  outlet_id       INT          NOT NULL,
  pelanggan_id    INT          NOT NULL,
  jumlah          DECIMAL(12,2) NOT NULL  COMMENT 'Jumlah dibayar customer (cash)',
  bonus           DECIMAL(12,2) NOT NULL DEFAULT 0  COMMENT 'Bonus dari tenant',
  total_kredit    DECIMAL(12,2) NOT NULL  COMMENT 'jumlah + bonus = total masuk ke saldo',
  metode_bayar    VARCHAR(30)  NOT NULL DEFAULT 'cash',
  bukti_bayar     VARCHAR(255) DEFAULT NULL,
  saldo_sebelum   DECIMAL(12,2) NOT NULL DEFAULT 0,
  saldo_sesudah   DECIMAL(12,2) NOT NULL DEFAULT 0,
  catatan         TEXT         DEFAULT NULL,
  created_by      INT          DEFAULT NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_pel (tenant_id, pelanggan_id, created_at),
  INDEX idx_outlet_date (outlet_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. History pemakaian saldo (debit dari saldo, link ke transaksi)
CREATE TABLE IF NOT EXISTS hl_deposit_usage (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL,
  outlet_id       INT          NOT NULL,
  pelanggan_id    INT          NOT NULL,
  transaksi_id    INT          DEFAULT NULL  COMMENT 'Link ke nota yg dibayar',
  jumlah          DECIMAL(12,2) NOT NULL  COMMENT 'Jumlah dipotong dari saldo',
  saldo_sebelum   DECIMAL(12,2) NOT NULL DEFAULT 0,
  saldo_sesudah   DECIMAL(12,2) NOT NULL DEFAULT 0,
  catatan         TEXT         DEFAULT NULL,
  created_by      INT          DEFAULT NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_pel (tenant_id, pelanggan_id, created_at),
  INDEX idx_transaksi (transaksi_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tier bonus topup (tenant define: "Topup 100k+ → +10%")
CREATE TABLE IF NOT EXISTS hl_deposit_bonus_tier (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL,
  outlet_id       INT          DEFAULT NULL  COMMENT 'NULL = berlaku semua outlet',
  min_topup       DECIMAL(12,2) NOT NULL  COMMENT 'Topup minimum untuk dapat bonus ini',
  bonus_tipe      ENUM('persen','nominal') NOT NULL DEFAULT 'persen',
  bonus_nilai     DECIMAL(12,2) NOT NULL  COMMENT 'kalau persen: %; kalau nominal: Rp',
  label           VARCHAR(80)  DEFAULT NULL  COMMENT 'Mis. "Topup 100k+ Bonus 10%"',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  urutan          INT          DEFAULT 0,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_active (tenant_id, is_active, min_topup)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
