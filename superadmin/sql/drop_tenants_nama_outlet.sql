-- Migration: drop tenants.nama_outlet
-- Run AFTER deploying code changes that no longer write to this column.
-- Step 1: backfill nama_perusahaan for any tenants that don't have it set yet.
-- Step 2: drop the column.

-- 1. Backfill
UPDATE tenants
   SET nama_perusahaan = nama_outlet
 WHERE (nama_perusahaan IS NULL OR nama_perusahaan = '')
   AND nama_outlet IS NOT NULL
   AND nama_outlet != '';

-- 2. Drop column
ALTER TABLE tenants DROP COLUMN nama_outlet;
