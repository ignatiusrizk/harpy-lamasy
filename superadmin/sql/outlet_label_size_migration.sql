-- Tambah kolom label_size di outlets — ukuran printer label (58 atau 80 mm)
ALTER TABLE outlets ADD COLUMN IF NOT EXISTS label_size VARCHAR(3) DEFAULT '80' AFTER nota_format;
