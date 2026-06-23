-- ════════════════════════════════════════════════════════════════════
-- CRITICAL FIX: cross-tenant outlet_id pollution di hl_pelanggan
-- Tanggal: 2026-06-23
--
-- Bug: hl_pelanggan tenant 2 (Harpy Laundry) punya 291 customer dgn
-- outlet_id=1 yang sebenarnya milik tenant 1 (Nene Laundry). Akibat
-- import data lama tanpa tenant_id remap. UI HQ tampil nama outlet
-- salah karena JOIN outlets tidak filter tenant_id.
--
-- Fix: untuk tiap customer yang outlet_id-nya menunjuk ke outlet
-- BUKAN milik tenant-nya, redirect ke main outlet tenant tersebut.
-- ════════════════════════════════════════════════════════════════════

START TRANSACTION;

-- Step 1: Audit — berapa row affected per tenant
SELECT p.tenant_id, COUNT(*) AS bad_rows
FROM hl_pelanggan p
LEFT JOIN outlets o ON o.id = p.outlet_id AND o.tenant_id = p.tenant_id
WHERE o.id IS NULL OR p.outlet_id IS NULL OR p.outlet_id = 0
GROUP BY p.tenant_id;

-- Step 2: Fix — set outlet_id + registered_outlet_id ke main outlet tenant
UPDATE hl_pelanggan p
JOIN (
    SELECT o.tenant_id, MIN(o.id) AS main_id
    FROM outlets o
    WHERE o.is_main = 1 AND o.status IN ('trial','grace','active')
    GROUP BY o.tenant_id
) tmain ON tmain.tenant_id = p.tenant_id
LEFT JOIN outlets ocheck ON ocheck.id = p.outlet_id AND ocheck.tenant_id = p.tenant_id
   SET p.outlet_id = tmain.main_id,
       p.registered_outlet_id = tmain.main_id
 WHERE ocheck.id IS NULL;

SELECT ROW_COUNT() AS customers_fixed;

-- Fallback: tenant tanpa is_main=1 outlet → pakai outlet aktif paling rendah id
UPDATE hl_pelanggan p
JOIN (
    SELECT o.tenant_id, MIN(o.id) AS fallback_id
    FROM outlets o
    WHERE o.status IN ('trial','grace','active')
    GROUP BY o.tenant_id
) tfb ON tfb.tenant_id = p.tenant_id
LEFT JOIN outlets ocheck ON ocheck.id = p.outlet_id AND ocheck.tenant_id = p.tenant_id
   SET p.outlet_id = tfb.fallback_id,
       p.registered_outlet_id = tfb.fallback_id
 WHERE ocheck.id IS NULL;

SELECT ROW_COUNT() AS customers_fallback_fixed;

COMMIT;
