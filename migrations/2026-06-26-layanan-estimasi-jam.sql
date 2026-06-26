-- Tambah kolom estimasi_jam yang hilang di hl_layanan (kode layanan.php/pos.php
-- mengharapkannya; absennya bikin INSERT fatal → "Unexpected end of JSON input").
ALTER TABLE hl_layanan ADD COLUMN IF NOT EXISTS estimasi_jam INT DEFAULT 24 AFTER qty_minimum;
