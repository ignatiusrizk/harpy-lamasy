-- Welcome Kit model pilihan — migration. Idempoten.
ALTER TABLE outlets
  ADD COLUMN IF NOT EXISTS welcome_kit_choice VARCHAR(40) NULL AFTER kode_pos;
ALTER TABLE saas_welcome_kit
  ADD COLUMN IF NOT EXISTS kit_nama VARCHAR(80) NULL AFTER items_json;

-- Migrasi config: welcome_kit_items (tunggal) → welcome_kit_options (1 opsi default 'standar').
-- Hanya set welcome_kit_options jika belum ada.
INSERT INTO saas_billing_config (key_name, value_text, description)
SELECT 'welcome_kit_options',
       CONCAT('[{"key":"standar","nama":"Standar","default":true,"items":',
              COALESCE((SELECT value_text FROM (SELECT value_text FROM saas_billing_config WHERE key_name='welcome_kit_items') x), '[]'),
              '}]'),
       'Opsi welcome kit (JSON array: key/nama/items/default)'
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM saas_billing_config WHERE key_name='welcome_kit_options') y);
