-- migrations/2026-08-31-biaya-lainnya-tier-unique.sql
-- Fix final review Task Biaya Lainnya Tier: tambah UNIQUE constraint nama
-- pada hl_biaya_lainnya_tier, mengikuti pola hl_express_tier
-- (lihat superadmin/sql/express_tier_per_outlet_migration.sql).
--
-- CATATAN: MySQL/MariaDB menganggap outlet_id NULL sbg nilai BERBEDA-BEDA
-- tiap baris utk keperluan UNIQUE index (NULL tidak dianggap duplikat
-- sesama NULL). Index ini efektif mencegah duplikat nama utk kombinasi
-- outlet SPESIFIK yang sama, tapi TIDAK menutup celah 2 baris global
-- (outlet_id NULL) sesama nama dari sisi DB semata — makanya validasi
-- manual di layanan.php action biaya_lainnya_save WAJIB tetap ada
-- (defense-in-depth, DB constraint ini backstop-nya saja).

ALTER TABLE hl_biaya_lainnya_tier
  ADD UNIQUE KEY uniq_tenant_outlet_biaya (tenant_id, outlet_id, nama);
