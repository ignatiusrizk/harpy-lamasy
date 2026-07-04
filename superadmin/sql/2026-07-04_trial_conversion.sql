-- ══════════════════════════════════════════════════════
-- Migrasi: Trial Conversion Strategy (Brief 1 + 2)
-- Tanggal: 2026-07-04
-- Dijalankan langsung ke DB produksi 2026-07-04 (sudah applied).
-- File ini untuk histori repo / replikasi di environment lain.
-- ══════════════════════════════════════════════════════

-- Brief 1 — Onboarding State Tracker
--   onboarding_step: registered → setup_done → first_order → activated
ALTER TABLE tenants
  ADD COLUMN onboarding_step VARCHAR(50) NOT NULL DEFAULT 'registered' AFTER status,
  ADD COLUMN onboarding_completed_at TIMESTAMP NULL DEFAULT NULL AFTER onboarding_step,
  -- Brief 2 — Trial AI Boost (toggle per tenant; SA bisa matikan)
  ADD COLUMN trial_ai_boost TINYINT(1) NOT NULL DEFAULT 1 AFTER onboarding_completed_at;

-- Backfill: tenant existing yang sudah punya outlet aktif/trial/grace
-- dianggap sudah lewat onboarding (jangan dipaksa ke checklist).
UPDATE tenants t
   SET onboarding_step = 'activated',
       onboarding_completed_at = COALESCE(onboarding_completed_at, NOW())
 WHERE EXISTS (
   SELECT 1 FROM outlets o
    WHERE o.tenant_id = t.id
      AND o.status IN ('active','trial','grace')
 );

-- Catatan: brief menyebut ALTER hl_ai_usage ADD is_trial_boost, TAPI hl_ai_usage
-- hanya ditulis oleh SaFinance/AIBudget (biaya API sisi SA), bukan oleh alur
-- fitur tenant. Trial-boost dilacak via coin_ledger (baris amount=0, deskripsi
-- '[TRIAL BOOST]') sehingga kolom itu TIDAK ditambahkan (menghindari dead schema).
