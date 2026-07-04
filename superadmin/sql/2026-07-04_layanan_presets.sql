-- Preset layanan wizard onboarding (Data Accumulation · Komponen 2 · Opsi D)
-- Applied ke prod 2026-07-04.
CREATE TABLE IF NOT EXISTS saas_layanan_presets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  satuan VARCHAR(30) NOT NULL DEFAULT 'kg',
  kategori VARCHAR(50) NOT NULL DEFAULT 'Kiloan',
  urutan INT NOT NULL DEFAULT 0,
  default_checked TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO saas_layanan_presets (nama,satuan,kategori,urutan,default_checked) VALUES
 ('Cuci Kering Lipat','kg','Kiloan',1,1),
 ('Cuci Setrika','kg','Kiloan',2,1),
 ('Setrika Saja','kg','Kiloan',3,1),
 ('Cuci Express (1 hari)','kg','Kiloan',4,0),
 ('Bed Cover','pcs','Satuan',5,0),
 ('Selimut','pcs','Satuan',6,0),
 ('Sepatu','pasang','Satuan',7,0),
 ('Karpet','m²','Satuan',8,0);
