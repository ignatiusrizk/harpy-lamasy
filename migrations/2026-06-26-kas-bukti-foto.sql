ALTER TABLE hl_kas ADD COLUMN IF NOT EXISTS bukti_foto VARCHAR(255) NULL AFTER keterangan;
INSERT INTO saas_coin_pricing (feature_key, nama_fitur, harga_coin, daily_limit, is_active)
VALUES ('ai_kas_struk', 'AI Kas Struk', 50, 100, 1)
ON DUPLICATE KEY UPDATE harga_coin = VALUES(harga_coin);
