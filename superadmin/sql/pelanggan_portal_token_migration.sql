-- superadmin/sql/pelanggan_portal_token_migration.sql
-- Tambah portal_token untuk auth pelanggan via QR struk

ALTER TABLE hl_pelanggan ADD COLUMN IF NOT EXISTS portal_token VARCHAR(64) UNIQUE NULL AFTER catatan;

-- Backfill existing pelanggan dengan token random
-- Pakai SHA2(UUID + random) untuk uniqueness; tidak pakai bin2hex(random_bytes) karena bukan MySQL native
UPDATE hl_pelanggan
   SET portal_token = SUBSTRING(SHA2(CONCAT(UUID(), RAND(), id), 256), 1, 32)
 WHERE portal_token IS NULL;
