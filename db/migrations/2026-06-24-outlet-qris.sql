-- Static QRIS POS — Phase 1
-- Tambah 3 kolom ke outlets untuk store QRIS image per outlet.

ALTER TABLE outlets
  ADD COLUMN qris_image VARCHAR(255) NULL AFTER status,
  ADD COLUMN qris_uploaded_at DATETIME NULL AFTER qris_image,
  ADD COLUMN qris_label VARCHAR(100) NULL COMMENT 'Display label, mis. "BCA - PT Rizky Laundry"' AFTER qris_uploaded_at;
