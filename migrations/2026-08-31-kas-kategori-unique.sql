-- migrations/2026-08-31-kas-kategori-unique.sql
-- Fix final review Kelola Kategori Kas: tambah UNIQUE constraint nama+tipe
-- pada hl_kas_kategori, mengikuti pola hl_biaya_lainnya_tier / hl_express_tier
-- (lihat migrations/2026-08-31-biaya-lainnya-tier-unique.sql).
--
-- CATATAN: hl_kas_kategori TIDAK PUNYA outlet_id (tenant-wide), jadi lebih
-- simpel dari kasus Biaya Lainnya. Constraint ini cuma cegah duplikat EXACT
-- nama+tipe yg sama (mis. "Modal" masuk 2x) — TIDAK menutup celah nama sama
-- dipakai di tipe BEDA (mis. "Modal" masuk sudah ada, lalu "Modal" keluar
-- ditambah — lolos dari sisi DB krn tipe beda jadi key beda). Makanya
-- validasi manual di kas.php action kas_kategori_save WAJIB tetap ada
-- (defense-in-depth, DB constraint ini backstop-nya saja).

ALTER TABLE hl_kas_kategori
  ADD UNIQUE KEY uniq_tenant_nama_tipe (tenant_id, nama, tipe);
