-- migrations/2026-10-19-antri-setrika.sql
-- Status baru 'antri_setrika' antara kering dan setrika.
-- Spec: docs/superpowers/specs/2026-10-19-antri-setrika-design.md
-- hl_proses_log.status_lama/status_baru = VARCHAR(50), tidak perlu diubah.
-- WAJIB dijalankan SEBELUM kode di-push.
ALTER TABLE hl_transaksi
  MODIFY COLUMN status_proses
  ENUM('masuk','cuci','kering','antri_setrika','setrika','siap','diambil') DEFAULT 'masuk';
