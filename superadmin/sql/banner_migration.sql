-- ══════════════════════════════════════════════════════
-- Migration: Dashboard Banner Carousel
-- Inspired by Smartlink dashboard banner (push feature & promo).
--
-- 2 source banner:
-- 1. saas_banners (super admin) — banner GLOBAL untuk semua tenant
--    (mis. "Feature Baru: Member Tier!", "Promo Topup Coin x2")
-- 2. (Phase berikutnya): hl_tenant_banner — banner internal per tenant
--    (announcement ke karyawan, dll)
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS saas_banners (
  id             INT          AUTO_INCREMENT PRIMARY KEY,
  judul          VARCHAR(100) NOT NULL,
  deskripsi      VARCHAR(255) DEFAULT NULL,
  cta_label      VARCHAR(40)  DEFAULT NULL  COMMENT 'Tombol text, mis. "Selengkapnya"',
  cta_url        VARCHAR(255) DEFAULT NULL  COMMENT 'URL tujuan klik (atau path /promo, /member, dll)',
  bg_gradient    VARCHAR(80)  DEFAULT 'linear-gradient(135deg,#0F7B6C,#10B981)'
                              COMMENT 'CSS gradient untuk bg banner',
  text_color     VARCHAR(20)  DEFAULT '#FFFFFF',
  icon           VARCHAR(20)  DEFAULT NULL  COMMENT 'Emoji icon (mis. 🎉, ⭐, 🚀)',
  target_tier    VARCHAR(50)  DEFAULT NULL  COMMENT 'NULL=semua tenant, atau "trial"/"active"/"premium" filter',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  urutan         INT          DEFAULT 0,
  starts_at      DATETIME     DEFAULT NULL  COMMENT 'NULL = langsung tampil',
  ends_at        DATETIME     DEFAULT NULL  COMMENT 'NULL = sampai dimatikan',
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_active_period (is_active, starts_at, ends_at, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample banners
INSERT INTO saas_banners (judul, deskripsi, cta_label, cta_url, icon, urutan, is_active) VALUES
  ('Member Tier System Baru!', 'Bikin tier Gold/Silver/VIP untuk customer royal — auto-diskon otomatis di POS.',
   'Atur Tier →', '/member', '⭐', 10, 1),
  ('AI Mapping Import Data', 'Import data dari Smartlink/iLaundy otomatis — AI yang petakan kolomnya.',
   'Import Sekarang →', '/import', '🤖', 20, 1),
  ('Express Tier per Item', 'Customer pilih express per layanan: 12 jam, 6 jam, kilat 3 jam — biaya auto-hitung.',
   'Kelola Tier →', '/layanan', '⚡', 30, 1);
