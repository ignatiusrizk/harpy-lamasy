INSERT INTO saas_coin_pricing (feature_key, nama_fitur, harga_coin, daily_limit, is_active)
VALUES ('ai_voice_order', 'AI Voice Order', 50, 100, 1)
ON DUPLICATE KEY UPDATE harga_coin = VALUES(harga_coin);
