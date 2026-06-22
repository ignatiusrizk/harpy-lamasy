-- Tambah kolom catatan_internal di hl_transaksi (untuk catatan tim internal,
-- tidak di-share ke pelanggan). UI sudah ada di orders.php edit modal tapi
-- kolom belum di-ALTER → fix SQLSTATE 42S22 saat saveEdit.
ALTER TABLE hl_transaksi ADD COLUMN IF NOT EXISTS catatan_internal TEXT NULL AFTER catatan;
