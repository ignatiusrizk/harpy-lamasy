-- ══════════════════════════════════════════════════════
-- Migration: Splash Screen Edukatif (3 Skenario)
--
-- 1. Onboarding   — first login, sampai 3 setup step done
-- 2. What's New   — feature announcement dari superadmin
-- 3. Tips Harian  — random 1x/hari per user, rotate semua tips
--
-- Schema:
-- - hl_splash_seen : track per user (idempotent via UNIQUE)
-- - hl_splash_tips : master tips (NULL tenant_id = global)
-- ══════════════════════════════════════════════════════

-- ─────────────────────────────────────────────
-- 1. SPLASH SEEN — track per user × splash × ref
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_splash_seen (
  id          INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT          NOT NULL,
  user_id     INT          NOT NULL,
  splash_type ENUM('onboarding','whats_new','tips') NOT NULL,
  ref_id      VARCHAR(100) NULL                COMMENT 'onboarding=NULL · whats_new=ann_id · tips=tipsId_YYYY-MM-DD',
  seen_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_seen (user_id, splash_type, ref_id),
  INDEX idx_user (user_id),
  INDEX idx_tenant (tenant_id),
  INDEX idx_type_date (splash_type, seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 2. SPLASH TIPS — master tips harian
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_splash_tips (
  id          INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT          NULL                COMMENT 'NULL = global untuk semua tenant',
  judul       VARCHAR(100) NOT NULL,
  konten      TEXT         NOT NULL,
  icon        VARCHAR(10)  NOT NULL DEFAULT '💡',
  cta_label   VARCHAR(50)  NULL                COMMENT 'Misal: Coba Sekarang',
  cta_url     VARCHAR(200) NULL                COMMENT 'Internal path, misal /ai',
  urutan      INT          NOT NULL DEFAULT 0,
  is_active   TINYINT      NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_active (is_active, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 3. SEED 8 TIPS DEFAULT (tenant_id NULL = global)
-- Pakai NOT EXISTS biar idempotent kalau migrasi di-rerun
-- ─────────────────────────────────────────────
-- Pakai FROM DUAL biar bisa pakai WHERE NOT EXISTS langsung (idempotent).
-- Jangan pakai inline subquery dengan literal sebagai column name —
-- MariaDB akan error "Duplicate column name" kalau ada literal yang sama
-- di 2 posisi (misal judul = cta_label).

INSERT INTO hl_splash_tips (tenant_id, judul, konten, icon, cta_label, cta_url, urutan)
SELECT NULL, 'AI Briefing Harian',
       'Mulai hari dengan AI Briefing — ringkasan kondisi outlet dan rekomendasi tindakan otomatis setiap pagi.',
       '🤖', 'Coba AI Briefing', '/dashboard?ai_briefing=1', 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hl_splash_tips WHERE judul='AI Briefing Harian' AND tenant_id IS NULL);

INSERT INTO hl_splash_tips (tenant_id, judul, konten, icon, cta_label, cta_url, urutan)
SELECT NULL, 'Kanban Board',
       'Kelola antrian order lebih visual dengan Kanban Board. Drag untuk update status, timer countdown per order.',
       '🗂️', 'Buka Kanban', '/kanban', 2
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hl_splash_tips WHERE judul='Kanban Board' AND tenant_id IS NULL);

INSERT INTO hl_splash_tips (tenant_id, judul, konten, icon, cta_label, cta_url, urutan)
SELECT NULL, 'Program Poin Loyalti',
       'Aktifkan poin loyalti untuk pelanggan setia. Setiap Rp 8.000 transaksi = 1 poin yang bisa ditukar reward.',
       '⭐', 'Setup Poin', '/loyalty', 3
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hl_splash_tips WHERE judul='Program Poin Loyalti' AND tenant_id IS NULL);

INSERT INTO hl_splash_tips (tenant_id, judul, konten, icon, cta_label, cta_url, urutan)
SELECT NULL, 'Drop Point Mitra',
       'Perluas jangkauan tanpa buka cabang baru. Daftarkan warung atau kos sekitar sebagai drop point.',
       '📍', 'Tambah Drop Point', '/droppoint', 4
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hl_splash_tips WHERE judul='Drop Point Mitra' AND tenant_id IS NULL);

INSERT INTO hl_splash_tips (tenant_id, judul, konten, icon, cta_label, cta_url, urutan)
SELECT NULL, 'Laporan Keuangan Lengkap',
       'LAMASY punya Neraca, Arus Kas, dan Rasio Keuangan. Lengkap untuk pengajuan KUR ke bank.',
       '📊', 'Lihat Laporan', '/laporan', 5
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hl_splash_tips WHERE judul='Laporan Keuangan Lengkap' AND tenant_id IS NULL);

INSERT INTO hl_splash_tips (tenant_id, judul, konten, icon, cta_label, cta_url, urutan)
SELECT NULL, 'Import Data dari Sistem Lama',
       'Pindah dari Smartlink atau Excel? AI kami bantu mapping kolom otomatis. Upload file apa saja.',
       '📥', 'Import Data', '/import', 6
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hl_splash_tips WHERE judul='Import Data dari Sistem Lama' AND tenant_id IS NULL);

INSERT INTO hl_splash_tips (tenant_id, judul, konten, icon, cta_label, cta_url, urutan)
SELECT NULL, 'Kustomisasi Struk',
       'Buat struk dengan logo dan branding outlet kamu. Support thermal printer dan PDF untuk invoice B2B.',
       '🖨️', 'Kustomisasi Struk', '/struk', 7
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hl_splash_tips WHERE judul='Kustomisasi Struk' AND tenant_id IS NULL);

INSERT INTO hl_splash_tips (tenant_id, judul, konten, icon, cta_label, cta_url, urutan)
SELECT NULL, 'Inventori Bahan Baku',
       'Track stok deterjen, parfum, dan plastik kemasan. Alert otomatis saat stok menipis.',
       '📦', 'Kelola Inventori', '/inventori', 8
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hl_splash_tips WHERE judul='Inventori Bahan Baku' AND tenant_id IS NULL);

-- Verify
-- SELECT 'hl_splash_seen' AS tbl, COUNT(*) AS cnt FROM hl_splash_seen
-- UNION ALL SELECT 'hl_splash_tips', COUNT(*) FROM hl_splash_tips
-- UNION ALL SELECT 'tips_global', COUNT(*) FROM hl_splash_tips WHERE tenant_id IS NULL;
