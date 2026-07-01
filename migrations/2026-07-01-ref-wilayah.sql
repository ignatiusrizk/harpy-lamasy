-- Tabel referensi wilayah (global, di-seed dari dataset Permendagri cahyadsn)
CREATE TABLE IF NOT EXISTS ref_wilayah (
  kode        VARCHAR(13)  NOT NULL PRIMARY KEY,
  nama        VARCHAR(120) NOT NULL,
  level       TINYINT      NOT NULL,     -- 1=prov,2=kota,3=kec,4=kel
  parent_kode VARCHAR(13)  NULL,
  kodepos     VARCHAR(5)   NULL,
  KEY idx_parent (parent_kode),
  KEY idx_level_parent (level, parent_kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kolom alamat terstruktur di outlets (nullable, backward-compatible)
-- Jalankan hanya jika belum ada (lihat Step 3 untuk guard).
ALTER TABLE outlets
  ADD COLUMN provinsi     VARCHAR(100) NULL AFTER kota,
  ADD COLUMN kecamatan    VARCHAR(100) NULL AFTER provinsi,
  ADD COLUMN kelurahan    VARCHAR(100) NULL AFTER kecamatan,
  ADD COLUMN wilayah_kode VARCHAR(13)  NULL AFTER kelurahan;
