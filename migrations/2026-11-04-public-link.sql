-- migrations/2026-11-04-public-link.sql
-- Link publik permanen (QRIS & daftar harga) tanpa ID di URL: /qris, /harga.
-- Satu baris = satu tenant/outlet tujuan. Endpoint publik HANYA melayani baris ini.
CREATE TABLE IF NOT EXISTS hl_public_link (
  slug               VARCHAR(40)  NOT NULL PRIMARY KEY,
  tenant_id          INT          NOT NULL,
  outlet_id          INT          NOT NULL,
  merchant_nama      VARCHAR(120) NOT NULL,
  harga_hash         CHAR(32)     NULL,
  harga_generated_at DATETIME     NULL,
  created_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO hl_public_link (slug, tenant_id, outlet_id, merchant_nama)
VALUES ('harpy', 18, 13, 'PT Harpy Sinergi Mandiri');
