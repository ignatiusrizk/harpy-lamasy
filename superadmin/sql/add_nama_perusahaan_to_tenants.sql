-- ════════════════════════════════════════════════════════
-- Tambah kolom nama_perusahaan ke tabel tenants
-- Diperlukan untuk menyimpan nama brand/perusahaan
-- yang diinput saat self-registration (register.php Step 1)
-- ════════════════════════════════════════════════════════

ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS nama_perusahaan VARCHAR(100) NULL
    AFTER nama_outlet;

-- Back-fill dari registration_requests untuk tenant yang sudah ada
UPDATE tenants t
  JOIN registration_requests rr ON rr.tenant_id = t.id
   SET t.nama_perusahaan = rr.nama_perusahaan
 WHERE rr.nama_perusahaan IS NOT NULL
   AND t.nama_perusahaan IS NULL;
