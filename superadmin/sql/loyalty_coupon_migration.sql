-- ════════════════════════════════════════════════════════════════════
-- Loyalty Kupon Phase 2 — extend hl_voucher untuk source loyalty
-- Tanggal: 2026-06-23 (Asia/Jakarta)
--
-- Konsep: pelanggan tukar saldo poin → kupon kode unik (yg bisa di-share
-- via WA ke teman atau dipakai sendiri saat order POS). Kasir input kupon
-- kode → auto-apply reward (sesuai tipe: diskon_nominal/persen/gratis_layanan).
--
-- Reuse hl_voucher infrastructure existing (kode + is_used + expired_at).
-- Tambah 3 kolom:
--   - reward_id     → link ke hl_poin_reward (kalau source=loyalty)
--   - pelanggan_id  → siapa yg generate kupon (untuk audit & wallet flow)
--   - source        → 'promo' (existing) atau 'loyalty' (new)
-- ════════════════════════════════════════════════════════════════════

ALTER TABLE hl_voucher
  ADD COLUMN reward_id INT NULL AFTER promo_id,
  ADD COLUMN pelanggan_id INT NULL AFTER reward_id,
  ADD COLUMN source ENUM('promo','loyalty') NOT NULL DEFAULT 'promo' AFTER pelanggan_id,
  ADD INDEX idx_source_pelanggan (tenant_id, source, pelanggan_id),
  ADD INDEX idx_kode (tenant_id, kode);

-- Tidak ada backfill — row existing semua source='promo' (default).
