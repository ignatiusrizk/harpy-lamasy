-- Toggle per-outlet: wajib isi No. HP pelanggan di POS atau opsional.
-- Default 0 (opsional) — sesuai perilaku yg baru dideploy (commit 2366597),
-- supaya outlet existing TIDAK berubah kecuali owner sengaja nyalain toggle
-- ini di Outlet & Nota Settings.
ALTER TABLE outlets ADD COLUMN telepon_wajib TINYINT(1) NOT NULL DEFAULT 0 AFTER nota_format;
