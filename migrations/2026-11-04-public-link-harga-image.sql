-- migrations/2026-11-04-public-link-harga-image.sql
-- Daftar harga publik = gambar yang diunggah (disajikan apa adanya), bukan hasil generate.
-- harga_image: path relatif gambar; harga_generated_at dipakai sbg "terakhir diganti".
ALTER TABLE hl_public_link ADD COLUMN IF NOT EXISTS harga_image VARCHAR(255) NULL;
UPDATE hl_public_link SET harga_image='/assets/pricelist/pricelist-harpy.jpg', harga_generated_at='2026-11-04 14:55:00'
 WHERE slug='harpy' AND harga_image IS NULL;
