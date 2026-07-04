-- Fitur "Belum Diambil" (pickup reminder): daftar order siap ≥2 hari yg belum diambil,
-- tampil di dashboard + tombol Ingatkan (WA). Kolom pelacak anti-spam.
ALTER TABLE hl_transaksi
  ADD COLUMN reminder_last_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN reminder_count INT NOT NULL DEFAULT 0;
