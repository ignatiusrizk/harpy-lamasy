-- ============================================================
-- CATATAN SEBELUM MENJALANKAN:
-- 1) Jalankan dulu: SHOW COLUMNS FROM hl_transaksi LIKE 'status_proses';
--    dan: SELECT status_proses, COUNT(*) FROM hl_transaksi GROUP BY 1;
-- 2) MODIFY di bawah harus mencantumkan SEMUA nilai enum yang ada. Kode repo
--    juga menyebut 'selesai','batal','dibatalkan' — bila ENUM live memuatnya,
--    tambahkan ke daftar. Pertahankan NOT NULL bila kolom live memilikinya.
-- 3) ALTER ini membangun ulang tabel dan mengunci hl_transaksi untuk SEMUA
--    tenant: jalankan di luar jam sibuk.
-- ============================================================
-- migrations/2026-10-19-antri-setrika.sql
-- Status baru 'antri_setrika' antara kering dan setrika.
-- Spec: docs/superpowers/specs/2026-10-19-antri-setrika-design.md
-- hl_proses_log.status_lama/status_baru = VARCHAR(50), tidak perlu diubah.
-- WAJIB dijalankan SEBELUM kode di-push.
ALTER TABLE hl_transaksi
  MODIFY COLUMN status_proses
  ENUM('masuk','cuci','kering','antri_setrika','setrika','siap','diambil') DEFAULT 'masuk';
