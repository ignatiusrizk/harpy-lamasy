-- ══════════════════════════════════════════════════════════════════
-- CLEANUP: Hapus duplikat data super admin (packages, coin bundles)
-- Sekalian tambah UNIQUE constraint biar nggak duplikat lagi
-- ══════════════════════════════════════════════════════════════════

-- 1. CEK DULU duplikat saat ini (jalankan ini untuk review)
SELECT 'BUNDLES — Duplikat berdasarkan nama:' AS info;
SELECT nama, COUNT(*) AS jumlah, GROUP_CONCAT(id ORDER BY id) AS ids,
       MIN(id) AS keep_id
  FROM saas_coin_bundles
 GROUP BY nama
 HAVING COUNT(*) > 1;

SELECT 'PACKAGES — Duplikat berdasarkan slug:' AS info;
SELECT slug, COUNT(*) AS jumlah, GROUP_CONCAT(id ORDER BY id) AS ids,
       MIN(id) AS keep_id
  FROM saas_packages
 GROUP BY slug
 HAVING COUNT(*) > 1;

-- ──────────────────────────────────────────────────────────────────
-- 2. HAPUS duplikat — keep MIN(id), buang sisanya
-- ──────────────────────────────────────────────────────────────────

-- coin bundles
DELETE b1 FROM saas_coin_bundles b1
INNER JOIN saas_coin_bundles b2
  ON b1.nama = b2.nama AND b1.id > b2.id;

-- packages (kalau ada duplikat slug)
DELETE p1 FROM saas_packages p1
INNER JOIN saas_packages p2
  ON p1.slug = p2.slug AND p1.id > p2.id;

-- ──────────────────────────────────────────────────────────────────
-- 3. TAMBAH UNIQUE constraint biar nggak bisa duplikat lagi
-- ──────────────────────────────────────────────────────────────────

-- Drop dulu kalau ada (safe re-run)
ALTER TABLE saas_coin_bundles DROP INDEX IF EXISTS uq_nama;
ALTER TABLE saas_coin_bundles ADD CONSTRAINT uq_nama UNIQUE (nama);

-- saas_packages.slug sudah UNIQUE dari schema awal, tapi pastikan
-- (skip kalau sudah ada)

-- ──────────────────────────────────────────────────────────────────
-- 4. VERIFIKASI hasil
-- ──────────────────────────────────────────────────────────────────
SELECT 'Setelah cleanup:' AS info;
SELECT id, nama, harga, coin_didapat, bonus_pct, is_featured, is_active
  FROM saas_coin_bundles
 ORDER BY id;

SELECT id, nama, slug, setup_fee, coin_awal, max_outlets, is_active
  FROM saas_packages
 ORDER BY id;
