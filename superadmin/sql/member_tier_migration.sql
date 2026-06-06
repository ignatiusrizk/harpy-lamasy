-- ══════════════════════════════════════════════════════
-- Migration: Member Tier System
--
-- Inspired by Smartlink "Jenis Member" feature.
--
-- Sebelumnya hl_pelanggan.tipe cuma 'retail' | 'member' (flat).
-- Sekarang tenant bisa define multi-tier:
--   - "Member Gold" — 12 bulan, biaya Rp 50.000, diskon otomatis 10%
--   - "Member VIP" — seumur hidup, biaya Rp 200.000, diskon 20%
--   - "Member Free" — 3 bulan, gratis, no diskon
--
-- Tabel:
--   - hl_member_tier: definisi tier (nama, validity, fee, benefit)
--   - hl_pelanggan_member: pelanggan terdaftar di tier mana, expiry
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_member_tier (
  id                INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id         INT          NOT NULL,
  outlet_id         INT          DEFAULT NULL  COMMENT 'NULL = berlaku semua outlet',
  nama_tier         VARCHAR(50)  NOT NULL  COMMENT 'Gold, Silver, VIP, dll',
  masa_aktif_tipe   ENUM('bulan','tahun','seumur') NOT NULL DEFAULT 'bulan',
  masa_aktif_nilai  INT          NOT NULL DEFAULT 12  COMMENT 'jumlah bulan/tahun. Ignored if seumur.',
  biaya_pendaftaran DECIMAL(12,2) NOT NULL DEFAULT 0  COMMENT '0 = gratis',
  diskon_persen     DECIMAL(5,2) NOT NULL DEFAULT 0  COMMENT 'auto-diskon di setiap transaksi member',
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  urutan            INT          DEFAULT 0,
  created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tenant_tier (tenant_id, nama_tier),
  INDEX idx_tenant_active (tenant_id, is_active, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_pelanggan_member (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id       INT          NOT NULL,
  outlet_id       INT          DEFAULT NULL,
  pelanggan_id    INT          NOT NULL,
  member_tier_id  INT          NOT NULL,
  tgl_mulai       DATE         NOT NULL,
  tgl_kadaluarsa  DATE         DEFAULT NULL  COMMENT 'NULL = seumur hidup',
  biaya_dibayar   DECIMAL(12,2) NOT NULL DEFAULT 0,
  status          ENUM('aktif','expired','dibatalkan') NOT NULL DEFAULT 'aktif',
  catatan         TEXT         DEFAULT NULL,
  registered_by   INT          DEFAULT NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pelanggan (pelanggan_id, status),
  INDEX idx_tier (member_tier_id),
  INDEX idx_expiry (tgl_kadaluarsa, status),
  FOREIGN KEY (pelanggan_id)   REFERENCES hl_pelanggan(id)    ON DELETE CASCADE,
  FOREIGN KEY (member_tier_id) REFERENCES hl_member_tier(id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
