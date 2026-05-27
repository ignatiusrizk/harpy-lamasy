-- ════════════════════════════════════════════════════════
-- Tambah kolom nama_perusahaan dan kota ke tabel tenants
-- nama_perusahaan: nama brand/perusahaan dari self-registration
-- kota: kota tenant-level (dipisah dari kota per-outlet)
-- ════════════════════════════════════════════════════════

ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS nama_perusahaan VARCHAR(100) NULL
    AFTER nama_outlet;

ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS kota VARCHAR(100) NULL
    AFTER nama_perusahaan;

-- Back-fill nama_perusahaan dari registration_requests untuk tenant yang sudah ada
UPDATE tenants t
  JOIN registration_requests rr ON rr.tenant_id = t.id
   SET t.nama_perusahaan = rr.nama_perusahaan
 WHERE rr.nama_perusahaan IS NOT NULL
   AND t.nama_perusahaan IS NULL;

-- Back-fill kota dari registration_requests untuk tenant yang sudah ada
UPDATE tenants t
  JOIN registration_requests rr ON rr.tenant_id = t.id
   SET t.kota = rr.kota
 WHERE rr.kota IS NOT NULL
   AND t.kota IS NULL;
