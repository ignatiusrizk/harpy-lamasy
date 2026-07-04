-- Ambang "Belum Diambil" bisa diatur per-outlet (default 2 hari).
ALTER TABLE outlets ADD COLUMN pickup_reminder_days TINYINT NOT NULL DEFAULT 2;
