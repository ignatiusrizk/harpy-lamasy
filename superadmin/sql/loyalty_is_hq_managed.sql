-- Migration: add is_hq_managed flag to hl_poin_reward
-- Run once per tenant DB (or on the shared DB if multi-tenant shared schema).
ALTER TABLE hl_poin_reward
  ADD COLUMN IF NOT EXISTS is_hq_managed TINYINT(1) NOT NULL DEFAULT 0;
