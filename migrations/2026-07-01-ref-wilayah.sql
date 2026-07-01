-- =====================================================================
-- Alamat Outlet Bertingkat — tabel referensi wilayah + kolom outlets
-- Sumber data (Permendagri, dataset cahyadsn):
--   wilayah:        https://raw.githubusercontent.com/cahyadsn/wilayah/master/db/wilayah.sql
--   wilayah_kodepos:https://raw.githubusercontent.com/cahyadsn/wilayah_kodepos/master/db/wilayah_kodepos.sql
--
-- Reproducible & idempotent. Collation di-pin ke utf8mb4_unicode_ci agar
-- JOIN dgn tabel staging dataset (yang juga unicode_ci) tak error
-- "Illegal mix of collations" di MariaDB (server default bisa uca1400_ai_ci).
-- =====================================================================

-- 1) Tabel referensi wilayah (global, TIDAK ter-tenant-scope)
CREATE TABLE IF NOT EXISTS ref_wilayah (
  kode        VARCHAR(13)  NOT NULL PRIMARY KEY,
  nama        VARCHAR(120) NOT NULL,
  level       TINYINT      NOT NULL,     -- 1=prov, 2=kota/kab, 3=kec, 4=kel
  parent_kode VARCHAR(13)  NULL,
  kodepos     VARCHAR(5)   NULL,
  KEY idx_parent (parent_kode),
  KEY idx_level_parent (level, parent_kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Kolom alamat terstruktur di outlets (nullable → backward-compatible).
--    IF NOT EXISTS supaya re-run aman (MariaDB 10.8+). Bila versi lama,
--    jalankan manual hanya kolom yang belum ada.
ALTER TABLE outlets
  ADD COLUMN IF NOT EXISTS provinsi     VARCHAR(100) NULL AFTER kota,
  ADD COLUMN IF NOT EXISTS kecamatan    VARCHAR(100) NULL AFTER provinsi,
  ADD COLUMN IF NOT EXISTS kelurahan    VARCHAR(100) NULL AFTER kecamatan,
  ADD COLUMN IF NOT EXISTS wilayah_kode VARCHAR(13)  NULL AFTER kelurahan;

-- 3) SEED — jalankan setelah mengimpor kedua dataset ke tabel staging:
--      curl -s -o wilayah.sql          <URL wilayah>
--      curl -s -o wilayah_kodepos.sql  <URL wilayah_kodepos>
--      mysql < wilayah.sql            # membuat & mengisi tabel `wilayah(kode,nama)`
--      mysql < wilayah_kodepos.sql    # membuat & mengisi `wilayah_kodepos(kode,kodepos)`
--    lalu jalankan blok di bawah (idempoten: TRUNCATE + DROP staging di akhir).

TRUNCATE ref_wilayah;

INSERT INTO ref_wilayah (kode, nama, level, parent_kode)
SELECT w.kode, w.nama,
       (LENGTH(w.kode) - LENGTH(REPLACE(w.kode, '.', '')) + 1) AS level,
       CASE WHEN LOCATE('.', w.kode) = 0 THEN NULL
            ELSE LEFT(w.kode, LENGTH(w.kode) - LOCATE('.', REVERSE(w.kode))) END AS parent_kode
FROM wilayah w;

UPDATE ref_wilayah r
JOIN wilayah_kodepos k ON k.kode = r.kode
SET r.kodepos = LEFT(k.kodepos, 5)
WHERE r.level = 4;

DROP TABLE wilayah;
DROP TABLE wilayah_kodepos;

-- Verifikasi:
--   SELECT level, COUNT(*) FROM ref_wilayah GROUP BY level ORDER BY level;
--   -- ekspektasi: 1≈38, 2≈514, 3≈7.285, 4≈83.762 (semua kel punya kodepos)
