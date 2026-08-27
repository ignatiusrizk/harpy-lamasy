-- migrations/2026-08-28-qris-di-struk.sql
-- Toggle "Tampilkan QRIS" di Kustomisasi Struk (retail).
-- Kolom show_rekening/rekening_bank/rekening_nomor/rekening_atas_nama
-- SUDAH ADA (dipakai invoice B2B) — tidak perlu migrasi utk itu.
ALTER TABLE hl_struk_template
  ADD COLUMN show_qris TINYINT(1) NULL DEFAULT 1 AFTER show_sisa_bayar;
